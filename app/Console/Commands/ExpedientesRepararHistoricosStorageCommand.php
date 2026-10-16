<?php

namespace App\Console\Commands;

use App\Models\ExpedienteHistorico;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reparación CONTROLADA de expedientes históricos cuyo PDF ya no está en su
 * (disk, path) pero SÍ sigue en su (source_disk, source_path) — el caso del
 * P0 en expedientes copiados desde un árbol legacy. Copia (nunca mueve ni
 * borra) el PDF de vuelta a su ruta canónica en el disco de expedientes,
 * verifica SHA-256 y solo entonces actualiza `disk` en BD.
 *
 *   php artisan expedientes:reparar-historicos-storage --todos            # simula (defecto)
 *   php artisan expedientes:reparar-historicos-storage --colaborador=15 --aplicar
 *
 * --simular es el DEFECTO; sin --aplicar NO se escribe nada. Es idempotente:
 * una segunda corrida no vuelve a copiar (el canónico ya existe y coincide).
 * Nunca toca el PDF de origen ni borra el registro histórico.
 */
class ExpedientesRepararHistoricosStorageCommand extends Command
{
    protected $signature = 'expedientes:reparar-historicos-storage
        {--todos : Repara todos los expedientes históricos (por defecto si no se da --colaborador)}
        {--colaborador= : Id del colaborador a reparar}
        {--simular : Solo muestra el plan; no escribe nada (es el comportamiento por defecto)}
        {--aplicar : Aplica la reparación de verdad (copia y actualiza BD)}';

    protected $description = 'Repara expedientes históricos cuyo PDF ya no está en (disk,path) pero sí en (source_disk,source_path), copiándolo a su ruta canónica';

    public function handle(DocumentoStorageService $storage, AuditoriaService $auditoria): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $tieneHash = Schema::hasColumn('expedientes_historicos', 'hash');
        $discoCanonico = (string) config('expedientes.disk');
        $actor = User::role('super_admin')->orderBy('id')->first();

        $this->line($aplicar
            ? '<fg=red;options=bold>MODO APLICAR</>: se copiará el PDF al disco canónico y se actualizará la BD.'
            : '<fg=green>MODO SIMULACIÓN</> (defecto): no se escribe nada. Usa --aplicar para reparar de verdad.');

        $historicos = ExpedienteHistorico::query()
            ->when($this->option('colaborador'), fn ($q) => $q->where('colaborador_id', (int) $this->option('colaborador')))
            ->orderBy('id')
            ->get();

        if ($historicos->isEmpty()) {
            $this->info('No hay expedientes históricos con ese filtro.');

            return self::SUCCESS;
        }

        $conteo = ['reparables' => 0, 'copiados' => 0, 'idempotentes' => 0, 'faltante_total' => 0, 'disco_inexistente' => 0, 'hash_distinto' => 0, 'conflicto' => 0, 'errores' => 0];

        foreach ($historicos as $historico) {
            try {
                $this->procesar($historico, $storage, $auditoria, $actor, $tieneHash, $discoCanonico, $aplicar, $conteo);
            } catch (Throwable $e) {
                $conteo['errores']++;
                $this->error(sprintf('  Histórico #%d: %s', $historico->id, $e->getMessage()));
            }
        }

        $this->newLine();
        $this->table(['Categoría', 'Registros'], [
            ['Reparables detectados', $conteo['reparables']],
            [$aplicar ? 'Copiados y actualizados' : 'Se copiarían (simulado)', $conteo['copiados']],
            ['Ya correctos (idempotente, copia 0)', $conteo['idempotentes']],
            ['Sin origen (faltante total, intocables)', $conteo['faltante_total']],
            ['Disco inexistente en config', $conteo['disco_inexistente']],
            ['Hash de origen distinto al registrado (NO reparado)', $conteo['hash_distinto']],
            ['Conflicto: destino existe con otro contenido (NO reparado)', $conteo['conflicto']],
            ['Errores', $conteo['errores']],
        ]);

        if (! $aplicar && $conteo['copiados'] > 0) {
            $this->warn('Simulación: vuelve a ejecutar con --aplicar para reparar de verdad.');
        }

        return $conteo['errores'] > 0 || $conteo['hash_distinto'] > 0 || $conteo['conflicto'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $conteo
     */
    private function procesar(ExpedienteHistorico $historico, DocumentoStorageService $storage, AuditoriaService $auditoria, ?User $actor, bool $tieneHash, string $discoCanonico, bool $aplicar, array &$conteo): void
    {
        // Solo reparamos lo que coincide con el P0: (disk,path) ausente.
        if (! $this->discoConfigurado($historico->disk)) {
            $conteo['disco_inexistente']++;
            $this->line(sprintf('  <fg=red>✗</> #%d: disco canónico «%s» no está en config. Revisa configuración; no se toca nada.', $historico->id, $historico->disk));

            return;
        }

        if ($this->existe($historico->disk, $historico->path)) {
            // Ya está en su (disk,path): nada que reparar (idempotente).
            return;
        }

        if ($historico->source_disk === null || ! $this->discoConfigurado($historico->source_disk)) {
            $conteo['disco_inexistente']++;
            $this->line(sprintf('  <fg=red>✗</> #%d: no hay origen con disco configurado (source_disk=%s). No se puede reparar.', $historico->id, $historico->source_disk ?? '(ninguno)'));

            return;
        }

        $origen = Storage::disk($historico->source_disk);

        if (! $origen->exists($historico->source_path)) {
            $conteo['faltante_total']++;
            $this->line(sprintf('  <fg=red>✗</> #%d: faltante total (ni canónico ni origen). Intervención manual; no se toca la BD.', $historico->id));

            return;
        }

        $conteo['reparables']++;

        // Verifica la INTEGRIDAD del origen contra el hash registrado antes de
        // copiar: un origen alterado no se promueve a canónico.
        if ($tieneHash && $historico->hash !== '') {
            $hashOrigen = $storage->hashEnDisco($origen, $historico->source_path);

            if ($hashOrigen !== $historico->hash) {
                $conteo['hash_distinto']++;
                $this->line(sprintf('  <fg=red>✗</> #%d: el SHA-256 del origen no coincide con el registrado. NO se repara (posible archivo alterado).', $historico->id));

                return;
            }
        }

        if (! $aplicar) {
            $conteo['copiados']++;
            $this->line(sprintf('  <fg=yellow>→</> #%d: se copiaría %s:%s → %s:%s', $historico->id, $historico->source_disk, $historico->source_path, $discoCanonico, $historico->path));

            return;
        }

        $this->aplicar($historico, $storage, $auditoria, $actor, $origen, $discoCanonico, $conteo);
    }

    /**
     * @param  array<string, int>  $conteo
     */
    private function aplicar(ExpedienteHistorico $historico, DocumentoStorageService $storage, AuditoriaService $auditoria, ?User $actor, Filesystem $origen, string $discoCanonico, array &$conteo): void
    {
        // copiarDesdeDisco copia al disco canónico (config expedientes.disk),
        // sin sobrescribir, verifica el SHA-256 del destino y es idempotente:
        //  - 'duplicado'  → ya estaba con el mismo contenido,
        //  - 'conflicto'  → ya hay algo distinto en el destino (no toca nada),
        //  - 'copiado'    → se escribió y verificó.
        // No reinventamos la convención de ruta: el destino es la MISMA `path`
        // canónica que guardó la migración, solo que ahora en el disco canónico.
        $resultado = $storage->copiarDesdeDisco($origen, $historico->source_path, $historico->path);

        if ($resultado['estado'] === 'conflicto') {
            $conteo['conflicto']++;
            $this->line(sprintf('  <fg=red>✗</> #%d: el destino ya existe con OTRO contenido. No se sobrescribe ni se actualiza la BD.', $historico->id));

            return;
        }

        if ($resultado['estado'] === 'duplicado') {
            // El canónico ya existía y coincide: copia 0. Solo alineamos la
            // columna `disk` si todavía apuntaba a otro disco.
            $conteo['idempotentes']++;
            $this->alinearDisco($historico, $discoCanonico);

            return;
        }

        // 'copiado' + confirmado por copiarDesdeDisco (destino existe y su hash
        // coincide con el del origen). Recién ahora se escribe la BD.
        DB::transaction(function () use ($historico, $discoCanonico): void {
            $historico->forceFill(['disk' => $discoCanonico])->save();
        });

        $auditoria->registrar('historico_storage_reparado', $historico, $actor, [
            'colaborador_id' => $historico->colaborador_id,
            'hash' => $resultado['hash'],
            'size' => $resultado['size'],
            'estado_copia' => $resultado['estado'],
        ]);

        $conteo['copiados']++;
        $this->line(sprintf('  <fg=green>✓</> #%d: copiado al disco canónico y BD actualizada.', $historico->id));
    }

    private function alinearDisco(ExpedienteHistorico $historico, string $discoCanonico): void
    {
        if ($historico->disk === $discoCanonico) {
            return;
        }

        DB::transaction(function () use ($historico, $discoCanonico): void {
            $historico->forceFill(['disk' => $discoCanonico])->save();
        });
    }

    private function existe(string $disco, string $ruta): bool
    {
        try {
            return Storage::disk($disco)->exists($ruta);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function discoConfigurado(?string $disco): bool
    {
        return is_string($disco) && $disco !== '' && array_key_exists($disco, (array) config('filesystems.disks'));
    }
}
