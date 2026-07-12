<?php

namespace App\Commands;

use App\Libraries\Media\ImageVariantGenerator;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Backfill de variantes (thumbnails card/gallery) para mídias de imóvel
 * enviadas ANTES do ImageVariantGenerator existir. Idempotente e resumável:
 * pula mídias cuja variante _card já existe e imprime o último id processado
 * para retomada via --start-id. Nunca modifica o arquivo original.
 *
 * Uso:
 *   php spark media:variants                        # primeiras 500
 *   php spark media:variants --limit 1000           # lote maior
 *   php spark media:variants --start-id 4321        # retomar de onde parou
 *   php spark media:variants --dry-run              # só relata, não gera
 */
class GenerateMediaVariants extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'media:variants';
    protected $description = 'Gera thumbnails (card/gallery) para mídias de imóvel existentes, em lotes resumáveis.';
    protected $options     = [
        '--start-id' => 'Processa apenas mídias com id maior que este valor (retomada).',
        '--limit'    => 'Máximo de mídias por execução (default 500).',
        '--dry-run'  => 'Só relata o que seria gerado, sem gravar nada.',
    ];

    public function run(array $params)
    {
        $startId = (int) (CLI::getOption('start-id') ?? 0);
        $limit   = (int) (CLI::getOption('limit') ?? 500);
        $dryRun  = CLI::getOption('dry-run') !== null;

        $db = \Config\Database::connect();
        CLI::write('Conectado em: ' . $db->getDatabase(), 'yellow');

        // O valor de `tipo` variou ao longo da história do projeto: 'FOTO'
        // (dados legados/seeds), 'IMAGE' (upload do admin), 'imagem' (upload
        // da API). Filtra por todos — só vídeo fica de fora.
        $medias = $db->table('property_media')
                     ->select('id, url')
                     ->whereIn('LOWER(tipo)', ['foto', 'image', 'imagem'])
                     ->where('deleted_at', null)
                     ->where('id >', $startId)
                     ->orderBy('id', 'ASC')
                     ->limit($limit)
                     ->get()
                     ->getResult();

        if (empty($medias)) {
            CLI::write('Nada a processar a partir do id ' . $startId . '.', 'green');
            return;
        }

        $storage   = service('publicStorage');
        $generator = new ImageVariantGenerator();
        $generated = $skipped = $missing = 0;
        $lastId    = $startId;

        foreach ($medias as $media) {
            $lastId = (int) $media->id;
            $url    = ltrim((string) $media->url, '/');

            if ($url === '' || str_starts_with($url, 'http')) {
                $skipped++;
                continue;
            }

            if (! $storage->exists($url)) {
                $missing++;
                continue;
            }

            // Idempotência: _card já existe = mídia já processada.
            if ($storage->exists(ImageVariantGenerator::variantPath($url, 'card'))) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                CLI::write("  [dry-run] geraria variantes de {$url}");
                $generated++;
                continue;
            }

            // Copia o original para um tmp — generate() trabalha em cópias e o
            // original no storage nunca é tocado.
            $stream = $storage->readStream($url);
            if ($stream === null) {
                $missing++;
                continue;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'backfill_');
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);

            $generator->generate($tmp, $url);
            @unlink($tmp);
            $generated++;
        }

        CLI::write("Processadas: " . count($medias) . " | geradas: {$generated} | puladas: {$skipped} | sem arquivo: {$missing}", 'green');
        CLI::write("Último id processado: {$lastId} (retome com --start-id {$lastId})", 'cyan');
    }
}
