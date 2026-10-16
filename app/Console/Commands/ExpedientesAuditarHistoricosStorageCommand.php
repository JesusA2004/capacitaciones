<?php

namespace App\Console\Commands;

use App\Models\ExpedienteHistorico;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Diagnóstico SOLO LECTURA de los expedientes históricos (migración inicial,
 * docs/MIGRACION_INICIAL_EXPEDIENTES.md): verifica que el PDF registrado en
 * cada fila de `expedientes_historicos` realmente esté en su (disk, path), y
 * de no estarlo, si al menos sigue en su (source_disk, source_path). Nació
 * para el P0 «El PDF histórico no está en el almacenamiento.»: distingue
 * «archivo movido/borrado» de «el disco está montado en otra raíz» (imprime
 * la raíz efectiva de cada disco, si la config está cacheada y el usuario/SAPI
 * del proceso) para no mandar a nadie a copiar archivos que en realidad solo
 * están detrás de un montaje distinto.
 *
 * No escribe NADA (ni NAS ni BD). La reparación controlada vive aparte en
 * expedientes:reparar-historicos-storage.
 */
class ExpedientesAuditarHistoricosStorageCommand extends Command
{
    protected $signature = 'expedientes:auditar-historicos-storage
        {--todos : Audita todos los expedientes históricos (por defecto si no se da --colaborador)}
        {--colaborador= : Id del colaborador a auditar}
        {--limite= : Máximo de registros a analizar}
        {--ejemplos=10 : Cuántos ejemplos mostrar por cada categoría con problema}';

    protected $description = 'Audita, sin modificar nada, que cada expediente histórico tenga su PDF en (disk,path) o al menos en (source_disk,source_path)';

    public function handle(DocumentoStorageService $storage): int
    {
        $tieneHash = Schema::hasColumn('expedientes_historicos', 'hash');
        $ejemplosMax = max(0, (int) $this->option('ejemplos'));

        $consulta = ExpedienteHistorico::query()
            ->when($this->option('colaborador'), fn ($q) => $q->where('colaborador_id', (int) $this->option('colaborador')))
            ->orderBy('id');

        if ($this->option('limite')) {
            $consulta->limit((int) $this->option('limite'));
        }

        $historicos = $consulta->get();

        if ($historicos->isEmpty()) {
            $this->info('No hay expedientes históricos con ese filtro.');

            return self::SUCCESS;
        }

        $this->contexto($historicos, $storage, $tieneHash);

        $totales = [
            'total' => 0,
            'en_disk_path' => 0,
            'faltan_disk_path' => 0,
            'en_source' => 0,
            'reparable' => 0,
            'faltante_total' => 0,
            'disco_inexistente' => 0,
            'hash_correcto' => 0,
            'hash_distinto' => 0,
            'sin_hash_registrado' => 0,
        ];

        /** @var array<string, list<string>> $ejemplos */
        $ejemplos = ['faltan_disk_path' => [], 'reparable' => [], 'faltante_total' => [], 'disco_inexistente' => [], 'hash_distinto' => []];

        foreach ($historicos as $historico) {
            $this->clasificar($historico, $storage, $tieneHash, $totales, $ejemplos, $ejemplosMax);
        }

        $this->resumen($totales);
        $this->ejemplos($ejemplos, $ejemplosMax);

        // SOLO LECTURA: un hallazgo nunca es «fracaso» del comando, es el
        // reporte que se pedía. Se devuelve FAILURE solo para que un cron lo
        // marque en rojo cuando algo requiere acción.
        $requiereAccion = $totales['faltan_disk_path'] > 0 || $totales['disco_inexistente'] > 0 || $totales['hash_distinto'] > 0;

        return $requiereAccion ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  Collection<int, ExpedienteHistorico>  $historicos
     */
    private function contexto(Collection $historicos, DocumentoStorageService $storage, bool $tieneHash): void
    {
        $this->line('<fg=blue>Contexto del proceso</>');
        $this->line(sprintf('  Usuario del proceso: %s · SAPI: %s · Config cacheada: %s',
            get_current_user() ?: '(desconocido)',
            php_sapi_name(),
            app()->configurationIsCached() ? 'SÍ (php artisan config:clear si la raíz del NAS cambió)' : 'no',
        ));
        $this->line(sprintf('  Disco canónico de expedientes (config expedientes.disk): <options=bold>%s</>', (string) config('expedientes.disk')));
        $this->line(sprintf('  Columna de hash en expedientes_historicos: %s', $tieneHash ? 'sí (se verifica SHA-256 de los existentes)' : 'no (no se puede verificar integridad)'));

        $discos = $historicos->flatMap(fn (ExpedienteHistorico $h) => [$h->disk, $h->source_disk])
            ->filter(fn ($d) => is_string($d) && $d !== '')
            ->unique()
            ->values();

        $this->line('  Raíz efectiva de cada disco involucrado:');
        foreach ($discos as $disco) {
            $this->line($this->discoConfigurado($disco)
                ? sprintf('    - %s → %s', $disco, (string) config("filesystems.disks.{$disco}.root", '(sin root; driver remoto)'))
                : sprintf('    - <fg=red>%s → NO está en config(filesystems.disks)</>', $disco));
        }
        $this->newLine();
    }

    /**
     * @param  array<string, int>  $totales
     * @param  array<string, list<string>>  $ejemplos
     */
    private function clasificar(ExpedienteHistorico $historico, DocumentoStorageService $storage, bool $tieneHash, array &$totales, array &$ejemplos, int $ejemplosMax): void
    {
        $totales['total']++;

        $discoCanonicoOk = $this->discoConfigurado($historico->disk);
        $discoSourceOk = $historico->source_disk !== null && $this->discoConfigurado($historico->source_disk);

        if (! $discoCanonicoOk || ($historico->source_disk !== null && ! $discoSourceOk)) {
            $totales['disco_inexistente']++;
            $this->agregarEjemplo($ejemplos, 'disco_inexistente', $historico, $ejemplosMax);
        }

        $existeCanonico = $discoCanonicoOk && $this->existe($historico->disk, $historico->path);
        $existeSource = $discoSourceOk && $this->existe((string) $historico->source_disk, $historico->source_path);

        if ($existeCanonico) {
            $totales['en_disk_path']++;
            $this->verificarHash($historico, $storage, $tieneHash, $historico->disk, $historico->path, $totales, $ejemplos, $ejemplosMax);

            return;
        }

        $totales['faltan_disk_path']++;
        $this->agregarEjemplo($ejemplos, 'faltan_disk_path', $historico, $ejemplosMax);

        if ($existeSource) {
            $totales['en_source']++;
            $totales['reparable']++;
            $this->agregarEjemplo($ejemplos, 'reparable', $historico, $ejemplosMax);

            return;
        }

        $totales['faltante_total']++;
        $this->agregarEjemplo($ejemplos, 'faltante_total', $historico, $ejemplosMax);
    }

    /**
     * @param  array<string, int>  $totales
     * @param  array<string, list<string>>  $ejemplos
     */
    private function verificarHash(ExpedienteHistorico $historico, DocumentoStorageService $storage, bool $tieneHash, string $disco, string $ruta, array &$totales, array &$ejemplos, int $ejemplosMax): void
    {
        if (! $tieneHash || $historico->hash === '') {
            $totales['sin_hash_registrado']++;

            return;
        }

        try {
            $real = $storage->hashEnDisco(Storage::disk($disco), $ruta);
        } catch (Throwable $e) {
            // Existe pero no se pudo leer: no es un hash distinto, se reporta
            // como faltante para no afirmar integridad que no se comprobó.
            $totales['hash_distinto']++;
            $this->agregarEjemplo($ejemplos, 'hash_distinto', $historico, $ejemplosMax);

            return;
        }

        if ($real === $historico->hash) {
            $totales['hash_correcto']++;

            return;
        }

        $totales['hash_distinto']++;
        $this->agregarEjemplo($ejemplos, 'hash_distinto', $historico, $ejemplosMax);
    }

    /**
     * @param  array<string, int>  $totales
     */
    private function resumen(array $totales): void
    {
        $this->line('<fg=blue>Resumen</>');
        $this->table(['Categoría', 'Registros'], [
            ['Total analizado', $totales['total']],
            ['Encontrados en (disk, path)', $totales['en_disk_path']],
            ['Faltantes en (disk, path)', $totales['faltan_disk_path']],
            ['  …pero presentes en (source_disk, source_path) → REPARABLE', $totales['reparable']],
            ['  …tampoco en el origen → FALTANTE TOTAL', $totales['faltante_total']],
            ['Registros con disco inexistente en config', $totales['disco_inexistente']],
            ['Hash correcto (existente + hash registrado)', $totales['hash_correcto']],
            ['Hash DISTINTO (existente + hash registrado)', $totales['hash_distinto']],
            ['Existentes sin hash registrado (no verificable)', $totales['sin_hash_registrado']],
        ]);

        if ($totales['faltan_disk_path'] === 0 && $totales['disco_inexistente'] === 0 && $totales['hash_distinto'] === 0) {
            $this->info('Todos los expedientes históricos tienen su PDF íntegro en (disk, path). Nada que reparar.');

            return;
        }

        if ($totales['reparable'] > 0) {
            $this->warn(sprintf('%d registro(s) reparable(s): ejecuta `php artisan expedientes:reparar-historicos-storage --simular` para ver el plan.', $totales['reparable']));
        }

        if ($totales['faltante_total'] > 0) {
            $this->warn(sprintf('%d registro(s) sin PDF ni en (disk,path) ni en (source): requieren intervención manual (no hay de dónde copiar).', $totales['faltante_total']));
        }

        if ($totales['disco_inexistente'] > 0) {
            $this->warn(sprintf('%d registro(s) apuntan a un disco que no está en config(filesystems.disks): revisa la configuración antes de concluir que el archivo se perdió.', $totales['disco_inexistente']));
        }
    }

    /**
     * @param  array<string, list<string>>  $ejemplos
     */
    private function ejemplos(array $ejemplos, int $ejemplosMax): void
    {
        if ($ejemplosMax === 0) {
            return;
        }

        $titulos = [
            'faltan_disk_path' => 'Faltantes en (disk, path)',
            'reparable' => 'Reparables (presentes en el origen)',
            'faltante_total' => 'Faltantes totales (sin origen)',
            'disco_inexistente' => 'Disco inexistente en config',
            'hash_distinto' => 'Hash distinto al registrado',
        ];

        foreach ($titulos as $clave => $titulo) {
            if ($ejemplos[$clave] === []) {
                continue;
            }

            $this->newLine();
            $this->line(sprintf('<fg=yellow>Ejemplos — %s (hasta %d):</>', $titulo, $ejemplosMax));
            foreach ($ejemplos[$clave] as $linea) {
                $this->line('  '.$linea);
            }
        }
    }

    /**
     * @param  array<string, list<string>>  $ejemplos
     */
    private function agregarEjemplo(array &$ejemplos, string $categoria, ExpedienteHistorico $historico, int $ejemplosMax): void
    {
        if ($ejemplosMax === 0 || count($ejemplos[$categoria]) >= $ejemplosMax) {
            return;
        }

        $ejemplos[$categoria][] = sprintf(
            'histórico #%d · colaborador %s · disk=%s · path=%s · source_disk=%s · source_path=%s',
            $historico->id,
            $historico->colaborador_id !== null ? (string) $historico->colaborador_id : '(sin vincular)',
            $historico->disk,
            $historico->path,
            $historico->source_disk ?? '(ninguno)',
            $historico->source_path,
        );
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
