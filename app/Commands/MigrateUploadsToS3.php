<?php

namespace App\Commands;

use App\Libraries\Storage\S3StorageFactory;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Migra os uploads do disco local para os buckets S3 (Fase 3b), preservando
 * as MESMAS chaves relativas — por isso nenhuma coluna do banco precisa
 * mudar: quando storage.driver virar 's3', getPublicUrl()/readStream()
 * resolvem os mesmos caminhos no bucket.
 *
 * Mapeamento:
 *   public/uploads/**                 -> bucket PÚBLICO  (chave: uploads/...)
 *   writable/kyc/**                   -> bucket PRIVADO  (chave: kyc/...)
 *   writable/uploads/kyc/**           -> bucket PRIVADO  (chave: uploads/kyc/...)
 *
 * Idempotente (pula chaves já existentes no bucket) e NÃO-destrutivo (nunca
 * apaga o arquivo local — remova manualmente depois de validar).
 *
 * Fluxo recomendado:
 *   1. Configurar storage.s3.* no .env (driver ainda 'local').
 *   2. php spark storage:migrate-s3 --dry-run
 *   3. php spark storage:migrate-s3
 *   4. Trocar storage.driver = s3 e validar as páginas.
 */
class MigrateUploadsToS3 extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'storage:migrate-s3';
    protected $description = 'Envia os uploads locais para os buckets S3 configurados (idempotente, não apaga nada local).';
    protected $options     = [
        '--dry-run' => 'Só relata o que seria enviado.',
        '--only'    => 'Restringe a um disco: public | private.',
        '--limit'   => 'Máximo de arquivos por execução (default: sem limite).',
    ];

    public function run(array $params)
    {
        $dryRun = CLI::getOption('dry-run') !== null;
        $only   = CLI::getOption('only');
        $limit  = (int) (CLI::getOption('limit') ?? 0);

        $jobs = [];
        if ($only !== 'private') {
            $jobs[] = ['label' => 'público', 'disk' => fn () => S3StorageFactory::make(true), 'base' => FCPATH, 'dir' => 'uploads'];
        }
        if ($only !== 'public') {
            $jobs[] = ['label' => 'privado', 'disk' => fn () => S3StorageFactory::make(false), 'base' => WRITEPATH, 'dir' => 'kyc'];
            $jobs[] = ['label' => 'privado', 'disk' => fn () => S3StorageFactory::make(false), 'base' => WRITEPATH, 'dir' => 'uploads/kyc'];
        }

        $sentTotal = 0;

        foreach ($jobs as $job) {
            $root = $job['base'] . $job['dir'];
            if (! is_dir($root)) {
                continue;
            }

            try {
                $disk = $job['disk']();
            } catch (\RuntimeException $e) {
                CLI::error($e->getMessage());
                return;
            }

            CLI::write("Disco {$job['label']}: varrendo {$root}", 'yellow');
            [$sent, $skipped] = $this->migrateTree($disk, $job['base'], $job['dir'], $dryRun, $limit, $sentTotal);
            $sentTotal += $sent;
            CLI::write("  enviados: {$sent} | pulados (já no bucket): {$skipped}", 'green');

            if ($limit > 0 && $sentTotal >= $limit) {
                CLI::write("Limite de {$limit} atingido — rode novamente para continuar.", 'cyan');
                break;
            }
        }

        CLI::write($dryRun ? "[dry-run] Total que seria enviado: {$sentTotal}" : "Total enviado: {$sentTotal}", 'green');
        CLI::write('Arquivos locais NÃO foram removidos — valide e limpe manualmente.', 'cyan');
    }

    private function migrateTree($disk, string $base, string $dir, bool $dryRun, int $limit, int $alreadySent): array
    {
        $sent = $skipped = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base . $dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            if ($limit > 0 && ($alreadySent + $sent) >= $limit) {
                break;
            }

            $key = ltrim(str_replace($base, '', $file->getPathname()), '/');

            if ($disk->exists($key)) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                CLI::write("  [dry-run] {$key}");
                $sent++;
                continue;
            }

            // put() consome a origem — como a migração NÃO pode apagar o
            // arquivo local, envia uma cópia temporária.
            $tmp = tempnam(sys_get_temp_dir(), 's3mig_');
            if ($tmp === false || ! @copy($file->getPathname(), $tmp)) {
                CLI::error("  falha ao preparar cópia de {$key}");
                continue;
            }

            try {
                $disk->put($key, $tmp);
                $sent++;
            } catch (\Throwable $e) {
                @unlink($tmp);
                CLI::error("  falha ao enviar {$key}: " . $e->getMessage());
            }
        }

        return [$sent, $skipped];
    }
}
