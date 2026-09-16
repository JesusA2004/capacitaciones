<?php

namespace App\Console\Commands;

use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Console\Command;

/**
 * Verifica, sin modificar nada, que cada EmployeeDocument (y cada foto de
 * perfil) tenga un archivo real en el disco NAS, que stored_name coincida
 * con el nombre físico, y opcionalmente que el hash SHA-256 siga
 * coincidiendo. No calcula hash por defecto (recorrer miles de archivos es
 * costoso) — usar --hash solo cuando de verdad se necesite.
 */
class ExpedientesVerificarStorageCommand extends Command
{
    protected $signature = 'expedientes:verificar-storage {--hash : También verifica el SHA-256 de cada archivo (más lento)}';

    protected $description = 'Compara employee_documents/users.foto_path contra el disco NAS y reporta faltantes o inconsistencias, sin modificar nada';

    public function handle(DocumentoStorageService $storage): int
    {
        $verificarHash = (bool) $this->option('hash');
        $huboProblemas = false;

        $this->line('<fg=blue>Documentos de expediente</>');

        $documentos = EmployeeDocument::withTrashed()->where('disk', config('expedientes.disk'))->orderBy('id')->get();
        $this->withProgressBar($documentos, function (EmployeeDocument $documento) use ($storage, $verificarHash, &$huboProblemas) {
            $problema = $this->verificarDocumento($documento, $storage, $verificarHash);

            if ($problema !== null) {
                $huboProblemas = true;
                $this->newLine();
                $this->line("  <fg=red>✗</> employee_document_id={$documento->id}: {$problema}");
            }
        });
        $this->newLine(2);

        if (! $huboProblemas) {
            $this->line('  <fg=green>✓</> Todos los documentos tienen su archivo correcto en el NAS.');
        }

        if ($this->huboProblemasFotos($storage)) {
            $huboProblemas = true;
        }

        $this->newLine();

        if ($huboProblemas) {
            $this->warn('Verificación terminada con inconsistencias — ver detalle arriba. Nada se modificó.');

            return self::FAILURE;
        }

        $this->info('Verificación terminada sin problemas.');

        return self::SUCCESS;
    }

    private function verificarDocumento(EmployeeDocument $documento, DocumentoStorageService $storage, bool $verificarHash): ?string
    {
        if (! $storage->existe($documento->path)) {
            return "falta el archivo en «{$documento->path}».";
        }

        if ($documento->stored_name !== basename($documento->path)) {
            return "stored_name («{$documento->stored_name}») no coincide con el nombre real del archivo («".basename($documento->path).'»).';
        }

        if ($verificarHash && $documento->hash !== null && $storage->hashSha256($documento->path) !== $documento->hash) {
            return 'el SHA-256 del archivo ya no coincide con el registrado en BD.';
        }

        return null;
    }

    private function huboProblemasFotos(DocumentoStorageService $storage): bool
    {
        $this->line('<fg=blue>Fotos de perfil</>');

        $colaboradores = Colaborador::withTrashed()->whereNotNull('foto_path')->orderBy('id')->get();
        $huboProblemas = false;

        foreach ($colaboradores as $colaborador) {
            if (! $storage->existe($colaborador->foto_path)) {
                $huboProblemas = true;
                $this->line("  <fg=red>✗</> colaborador_id={$colaborador->id}: falta la foto en «{$colaborador->foto_path}».");
            }
        }

        if (! $huboProblemas) {
            $this->line('  <fg=green>✓</> Todas las fotos de perfil tienen su archivo en el NAS.');
        }

        return $huboProblemas;
    }
}
