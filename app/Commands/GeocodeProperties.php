<?php

namespace App\Commands;

use App\Libraries\Geo\GeocoderInterface;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Recalcula a coordenada de imóveis cuja localização no mapa é suspeita.
 *
 * Existe porque IntegrationSyncService::geocodeIfNeeded() sai cedo quando o
 * imóvel JÁ tem lat/lng: rodar o sync de novo não corrige uma única linha
 * errada. As coordenadas gravadas antes do conserto da UF (imóveis de Santa
 * Catarina apontando para Goiás) só saem daqui.
 *
 * Três motivos tornam uma coordenada suspeita:
 *   - fora-do-estado ... o pin não cai dentro da UF cadastrada no imóvel;
 *   - empilhada ....... a coordenada é idêntica à de outro imóvel, sinal de
 *                       que os dois caíram no mesmo centro de cidade/bairro;
 *   - sem-coordenada .. nunca foi geocodificado.
 *
 * Uso:
 *   php spark imoveis:geocodificar --dry-run          # só relata, não grava
 *   php spark imoveis:geocodificar                    # pede confirmação e grava
 *   php spark imoveis:geocodificar --conta 7          # limita a uma conta
 *   php spark imoveis:geocodificar --limit 50         # lote menor
 *   php spark imoveis:geocodificar --todos            # ignora o filtro de suspeita
 *
 * O Nominatim exige no máximo 1 req/s e NominatimGeocoder já aplica o
 * throttle: conte ~1s por degrau consultado (um lote de 130 imóveis leva
 * poucos minutos).
 */
class GeocodeProperties extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'imoveis:geocodificar';
    protected $description = 'Recalcula a coordenada de imóveis com localização suspeita (fora da UF, empilhada ou ausente).';
    protected $options     = [
        '--dry-run' => 'Só relata o que seria alterado, sem gravar nada.',
        '--conta'   => 'Restringe a uma conta (account_id).',
        '--limit'   => 'Máximo de imóveis por execução (default 200).',
        '--todos'   => 'Considera todos os imóveis, não só os de coordenada suspeita.',
        '--apenas-novos' => 'Só imóveis SEM coordenada. É o modo seguro para cron: converge, cada imóvel entra uma vez.',
        '--force'   => 'Não pede confirmação antes de gravar.',
    ];

    /**
     * Caixa envolvente aproximada de cada UF, folgada de propósito: serve para
     * responder "esse pin está plausivelmente neste estado?", não para definir
     * fronteira. Ilhas oceânicas (Noronha, Trindade) ficam de fora e seriam
     * reportadas como suspeitas — nenhum imóvel do catálogo está nelas.
     *
     * @var array<string, array{0:float,1:float,2:float,3:float}> UF => [latMin, latMax, lngMin, lngMax]
     */
    private const CAIXA_UF = [
        'AC' => [-11.15, -7.10, -74.00, -66.60],
        'AL' => [-10.50, -8.80, -38.25, -35.15],
        'AM' => [-9.85, 2.25, -73.80, -56.10],
        'AP' => [-1.25, 4.45, -54.90, -49.85],
        'BA' => [-18.35, -8.50, -46.65, -37.30],
        'CE' => [-7.90, -2.75, -41.45, -37.25],
        'DF' => [-16.05, -15.50, -48.30, -47.30],
        'ES' => [-21.30, -17.85, -41.90, -39.60],
        'GO' => [-19.50, -12.40, -53.25, -45.90],
        'MA' => [-10.30, -1.00, -48.80, -41.70],
        'MG' => [-22.95, -14.20, -51.10, -39.80],
        'MS' => [-24.10, -17.15, -58.20, -50.90],
        'MT' => [-18.10, -7.30, -61.70, -50.20],
        'PA' => [-9.90, 2.60, -58.95, -46.00],
        'PB' => [-8.35, -6.00, -38.80, -34.75],
        'PE' => [-9.50, -7.25, -41.40, -34.75],
        'PI' => [-10.95, -2.70, -46.00, -40.35],
        'PR' => [-26.75, -22.45, -54.65, -48.00],
        'RJ' => [-23.40, -20.70, -44.95, -40.90],
        'RN' => [-6.99, -4.80, -38.60, -34.90],
        'RO' => [-13.75, -7.95, -66.85, -59.75],
        'RR' => [-1.60, 5.30, -64.85, -58.85],
        'RS' => [-33.80, -27.05, -57.70, -49.65],
        'SC' => [-29.40, -25.90, -53.90, -48.30],
        'SE' => [-11.60, -9.45, -38.30, -36.35],
        'SP' => [-25.35, -19.75, -53.20, -44.10],
        'TO' => [-13.50, -5.15, -50.80, -45.65],
    ];

    public function run(array $params)
    {
        $dryRun = $this->flag($params, 'dry-run');
        $todos  = $this->flag($params, 'todos');
        $apenasNovos = $this->flag($params, 'apenas-novos');
        $force  = $this->flag($params, 'force');
        $conta  = $this->valor($params, 'conta');
        $limit  = (int) ($this->valor($params, 'limit') ?? 200);

        $db = \Config\Database::connect();
        CLI::write('Conectado em: ' . $db->getDatabase(), 'yellow');

        $builder = $db->table('properties')
                      ->select('id, account_id, rua, numero, bairro, cidade, estado, latitude, longitude, coordenadas_manuais')
                      ->where('deleted_at', null)
                      ->where('cidade IS NOT NULL')
                      ->where("TRIM(cidade) <>", '');

        // Modo cron: só quem nunca foi geocodificado. Sem isto, rodar de minuto
        // em minuto ficaria regeocodificando para sempre os imóveis
        // "empilhados" — que continuam empilhados, porque a rua deles não
        // existe no OpenStreetMap — e queimando a cota do Nominatim à toa.
        if ($apenasNovos) {
            $builder->groupStart()
                    ->where('latitude IS NULL')
                    ->orWhere('longitude IS NULL')
                    ->groupEnd();
        }

        if ($conta !== null) {
            $builder->where('account_id', (int) $conta);
        }

        $imoveis = $builder->orderBy('id', 'ASC')->get()->getResult();

        if ($imoveis === []) {
            CLI::write('Nenhum imóvel com cidade cadastrada.', 'yellow');

            return;
        }

        $empilhadas = $this->coordenadasRepetidas($db, $conta);
        $candidatos = [];
        $manuais    = 0;

        foreach ($imoveis as $imovel) {
            // Pino posto à mão é a única forma de posicionar endereço rural ou
            // de loteamento novo, que o OpenStreetMap não conhece. Sobrescrever
            // seria perder a informação de vez.
            if (! empty($imovel->coordenadas_manuais) && $imovel->coordenadas_manuais !== 'f') {
                $manuais++;

                continue;
            }

            $motivo = $todos ? 'todos' : $this->motivoSuspeita($imovel, $empilhadas);

            if ($motivo === null) {
                continue;
            }

            $candidatos[] = [$imovel, $motivo];

            if (count($candidatos) >= $limit) {
                break;
            }
        }

        if ($manuais > 0) {
            CLI::write($manuais . ' imóvel(is) pulado(s): coordenada posta à mão.', 'yellow');
        }

        if ($candidatos === []) {
            CLI::write('Nenhuma coordenada suspeita entre ' . count($imoveis) . ' imóveis.', 'green');

            return;
        }

        CLI::write('');
        CLI::write(count($candidatos) . ' imóvel(is) a regeocodificar (de ' . count($imoveis) . '):', 'yellow');

        $porMotivo = array_count_values(array_column($candidatos, 1));

        foreach ($porMotivo as $motivo => $quantos) {
            CLI::write('  ' . str_pad($motivo, 16) . $quantos);
        }

        if ($dryRun) {
            CLI::write('');
            CLI::write('--dry-run: nada gravado.', 'green');
            $this->amostra($candidatos);

            return;
        }

        if (! $force) {
            if (! $this->interativo()) {
                CLI::error('Sem terminal interativo para confirmar. Repita com --force (ou use --dry-run).');

                return;
            }

            CLI::write('');
            CLI::write('Serão ~' . count($candidatos) . ' consultas ao Nominatim, a ~1s cada.', 'yellow');

            if (strtolower(CLI::prompt('Gravar as novas coordenadas?', ['n', 's'])) !== 's') {
                CLI::write('Cancelado — nada foi alterado.', 'yellow');

                return;
            }
        }

        $this->processar($db, $candidatos, $this->geocoder());
    }

    /**
     * Lê uma flag das DUAS fontes: CLI::$options (execução real pelo spark) e
     * $params (helper command(), usado pelos testes — Commands::run() repassa
     * as opções por argumento e nunca popula CLI::$options). Mesmo cuidado que
     * ActivateSubscription já toma.
     */
    private function flag(array $params, string $nome): bool
    {
        return CLI::getOption($nome) !== null || array_key_exists($nome, $params);
    }

    private function valor(array $params, string $nome): ?string
    {
        $daCli = CLI::getOption($nome);

        if ($daCli !== null && $daCli !== true) {
            return (string) $daCli;
        }

        return isset($params[$nome]) ? (string) $params[$nome] : null;
    }

    /**
     * Sem isso, rodar sem --force fora de um terminal (cron, deploy script)
     * fica pendurado pra sempre esperando um enter que nunca vem.
     */
    private function interativo(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }

    /**
     * @param list<array{0:object, 1:string}> $candidatos
     */
    private function processar($db, array $candidatos, GeocoderInterface $geocoder): void
    {
        $atualizados = $semResultado = $inalterados = 0;

        CLI::write('');

        foreach ($candidatos as [$imovel, $motivo]) {
            $coordenadas = $geocoder->geocode([
                'rua'    => $imovel->rua,
                'numero' => $imovel->numero,
                'bairro' => $imovel->bairro,
                'cidade' => $imovel->cidade,
                'estado' => $imovel->estado,
            ]);

            if ($coordenadas === null) {
                $semResultado++;
                CLI::write(sprintf('  #%-6s %-14s sem resultado', $imovel->id, $motivo), 'red');

                continue;
            }

            $mesma = $imovel->latitude !== null
                && abs((float) $imovel->latitude - $coordenadas['lat']) < 0.000001
                && abs((float) $imovel->longitude - $coordenadas['lng']) < 0.000001;

            if ($mesma) {
                $inalterados++;

                continue;
            }

            $db->table('properties')
               ->where('id', $imovel->id)
               ->update([
                   'latitude'  => $coordenadas['lat'],
                   'longitude' => $coordenadas['lng'],
               ]);

            $atualizados++;
            CLI::write(sprintf(
                '  #%-6s %-14s %s,%s -> %s,%s',
                $imovel->id,
                $motivo,
                $imovel->latitude ?? '-',
                $imovel->longitude ?? '-',
                $coordenadas['lat'],
                $coordenadas['lng'],
            ), 'green');
        }

        CLI::write('');
        CLI::write("Atualizados: {$atualizados} | inalterados: {$inalterados} | sem resultado: {$semResultado}", 'yellow');

        if ($atualizados > 0) {
            CLI::write('Lembre de limpar o cache dos pins (public_map_pins_*) para o mapa refletir agora.', 'yellow');
        }
    }

    /**
     * O service resolve para NominatimGeocoder de verdade; o teste do comando
     * troca por dublê com Services::injectMock(), já que command() não deixa
     * injetar por construtor.
     */
    private function geocoder(): GeocoderInterface
    {
        return service('geocoder');
    }

    /**
     * Coordenadas compartilhadas por mais de um imóvel — a assinatura de quem
     * caiu no centro da cidade ou do bairro em vez do endereço.
     *
     * @return array<string, true>
     */
    private function coordenadasRepetidas($db, $conta): array
    {
        $builder = $db->table('properties')
                      ->select('latitude, longitude')
                      ->where('deleted_at', null)
                      ->where('latitude IS NOT NULL')
                      ->where('longitude IS NOT NULL')
                      ->groupBy('latitude, longitude')
                      ->having('COUNT(*) > 1');

        if ($conta !== null) {
            $builder->where('account_id', (int) $conta);
        }

        $repetidas = [];

        foreach ($builder->get()->getResult() as $linha) {
            $repetidas[$this->chaveCoordenada($linha->latitude, $linha->longitude)] = true;
        }

        return $repetidas;
    }

    /** @param array<string, true> $empilhadas */
    private function motivoSuspeita(object $imovel, array $empilhadas): ?string
    {
        if ($imovel->latitude === null || $imovel->longitude === null) {
            return 'sem-coordenada';
        }

        $lat = (float) $imovel->latitude;
        $lng = (float) $imovel->longitude;
        $uf  = strtoupper(trim((string) $imovel->estado));

        if (isset(self::CAIXA_UF[$uf])) {
            [$latMin, $latMax, $lngMin, $lngMax] = self::CAIXA_UF[$uf];

            if ($lat < $latMin || $lat > $latMax || $lng < $lngMin || $lng > $lngMax) {
                return 'fora-do-estado';
            }
        }

        if (isset($empilhadas[$this->chaveCoordenada($imovel->latitude, $imovel->longitude)])) {
            return 'empilhada';
        }

        return null;
    }

    /**
     * A comparação tem de bater com o GROUP BY do Postgres, que agrupa pelo
     * valor numérico — comparar a string crua separaria "-26.73" de "-26.730".
     */
    private function chaveCoordenada($lat, $lng): string
    {
        return sprintf('%.7F,%.7F', (float) $lat, (float) $lng);
    }

    /** @param list<array{0:object, 1:string}> $candidatos */
    private function amostra(array $candidatos): void
    {
        CLI::write('');
        CLI::write('Amostra (até 10):', 'yellow');

        foreach (array_slice($candidatos, 0, 10) as [$imovel, $motivo]) {
            CLI::write(sprintf(
                '  #%-6s %-14s %s, %s/%s  @ %s,%s',
                $imovel->id,
                $motivo,
                $imovel->bairro ?: '-',
                $imovel->cidade,
                $imovel->estado ?: '-',
                $imovel->latitude ?? '-',
                $imovel->longitude ?? '-',
            ));
        }
    }
}
