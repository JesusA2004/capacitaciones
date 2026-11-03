<?php

namespace App\Console\Commands;

use App\Services\Administracion\LimpiezaRegistrosPruebaService;
use App\Services\Administracion\PlanLimpiezaPrueba;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Limpieza DEFINITIVA y puntual de los dos candidatos ficticios de prueba
 * (Tito Iván López y Víctor Ricardo Arriola): base de datos y Synology. No es
 * una herramienta general: las personas están fijas en
 * LimpiezaRegistrosPruebaService::PERSONAS y se revalidan antes de borrar.
 *
 *   php artisan mantenimiento:limpiar-candidatos-prueba --dry-run
 *   php artisan mantenimiento:limpiar-candidatos-prueba --respaldo=/ruta/respaldo.sql.gz --confirmar="ELIMINAR CANDIDATOS DE PRUEBA 5 Y 4"
 */
class LimpiarCandidatosPruebaCommand extends Command
{
    protected $signature = 'mantenimiento:limpiar-candidatos-prueba
        {--dry-run : Solo muestra lo que se borraría, sin modificar nada}
        {--respaldo= : Ruta del respaldo SQL (.sql o .sql.gz) hecho en las últimas 24 horas}
        {--confirmar= : Frase de confirmación exacta}';

    protected $description = 'Borra definitivamente a los dos candidatos ficticios de prueba (BD + Synology), con simulación, respaldo verificado y confirmación';

    public function handle(LimpiezaRegistrosPruebaService $limpieza): int
    {
        $simulacion = (bool) $this->option('dry-run');
        $this->warn($simulacion ? 'SIMULACIÓN: no se modifica nada.' : 'LIMPIEZA DEFINITIVA: borra filas y archivos.');
        $this->line(sprintf('Base: %s · conexión: %s · disco de expedientes: %s', DB::getDatabaseName(), DB::getDriverName(), (string) config('expedientes.disk')));

        $plan = $limpieza->inventariar();
        $this->reportar($limpieza, $plan);

        if (! $plan->puedeEjecutarse()) {
            $this->error('No se puede limpiar: corrige lo anterior. No se modificó nada.');

            return self::FAILURE;
        }

        if ($simulacion) {
            $this->info('Simulación completa: todo coincide. Para ejecutar, pasa --respaldo y --confirmar (sin --dry-run).');

            return self::SUCCESS;
        }

        if ($this->esBaseRemotaDesdeDesarrollo()) {
            $this->error('Este entorno es de desarrollo y la base no es local: la limpieza real solo se ejecuta en el servidor. No se modificó nada.');

            return self::FAILURE;
        }

        $respaldo = trim((string) $this->option('respaldo'));

        if ($respaldo === '') {
            $this->error('Falta --respaldo=/ruta/del/respaldo.sql.gz (respaldo completo de la base de las últimas 24 horas). No se modificó nada.');

            return self::FAILURE;
        }

        $problemas = $limpieza->verificarRespaldo($respaldo);

        if ($problemas !== []) {
            foreach ($problemas as $problema) {
                $this->error($problema);
            }

            $this->error('Respaldo no válido. No se modificó nada.');

            return self::FAILURE;
        }

        $this->info(sprintf('Respaldo verificado: %s', $respaldo));

        $frase = (string) ($this->option('confirmar') ?? '');

        if ($frase === '' && $this->input->isInteractive()) {
            $frase = (string) $this->ask(sprintf('Escribe exactamente «%s» para borrar definitivamente', LimpiezaRegistrosPruebaService::FRASE_CONFIRMACION));
        }

        if (trim($frase) !== LimpiezaRegistrosPruebaService::FRASE_CONFIRMACION) {
            $this->error(sprintf('Confirmación incorrecta (se esperaba «%s»). No se modificó nada.', LimpiezaRegistrosPruebaService::FRASE_CONFIRMACION));

            return self::FAILURE;
        }

        $resultado = $limpieza->ejecutar($plan);

        $this->newLine();
        $this->info(sprintf('Limpieza %s aplicada: %d filas borradas en %d tablas, %d archivos y %d carpetas retirados.', $resultado['folio'], array_sum($resultado['borradas']), count($resultado['borradas']), $resultado['archivos'], $resultado['carpetas']));
        $this->line(sprintf('Copia recuperable (archivos + filas en JSON): %s', $resultado['cuarentena']));

        if ($resultado['pendientes'] !== []) {
            foreach ($resultado['pendientes'] as $pendiente) {
                $this->error($pendiente);
            }

            $this->error('La verificación final encontró pendientes (arriba). La base quedó confirmada; revisa los pendientes con la copia de la cuarentena.');

            return self::FAILURE;
        }

        $this->info('Verificación final: no quedan candidatos, colaboradores, usuarios, invitaciones, documentos ni archivos de estas personas.');

        return self::SUCCESS;
    }

    private function reportar(LimpiezaRegistrosPruebaService $limpieza, PlanLimpiezaPrueba $plan): void
    {
        $this->newLine();
        $this->info('1) Identidad y relaciones');
        $this->table(['Persona', 'Dato', 'Esperado', 'Encontrado', ''], array_map(fn (array $v): array => [$v['persona'], $v['dato'], $v['esperado'], $v['encontrado'], $v['ok'] ? 'OK' : 'NO COINCIDE'], $plan->validaciones));

        if ($plan->validaciones !== [] && array_filter($plan->validaciones, fn (array $v): bool => ! $v['ok']) !== []) {
            $this->reportarErrores($plan);

            return;
        }

        $borrado = $limpieza->conteoBorrado($plan);
        $this->newLine();
        $this->info(sprintf('2) Filas que se borran físicamente: %d', array_sum($borrado)));
        $this->table(['Tabla', 'Filas', 'IDs'], array_map(
            fn (string $tabla, int $filas): array => [$tabla, $filas, $this->resumirIds($plan->idsDe($tabla))],
            array_keys($borrado),
            $borrado,
        ));

        $anulado = $limpieza->conteoAnulado($plan);
        $this->newLine();
        $this->info('3) Registros de terceros que se conservan y se desvinculan (NULL)');

        if ($anulado === []) {
            $this->line('   Ninguno.');
        } else {
            $this->table(['Tabla.columna', 'Filas'], array_map(fn (string $c, int $f): array => [$c, $f], array_keys($anulado), $anulado));
        }

        $this->newLine();
        $this->info('4) Vacantes que se liberan (no se borran)');
        $this->line($plan->vacantes === [] ? '   Ninguna.' : '   #'.implode(', #', $plan->vacantes).' · después se resincronizan sus plazas automáticas.');

        $this->newLine();
        $this->info(sprintf('5) Archivos en almacenamiento: %d (%d presentes)', count($plan->archivos), count(array_filter($plan->archivos, fn (array $a): bool => $a['existe']))));
        $this->table(['Disco', 'Ruta', 'Origen', 'Existe', 'Bytes'], array_map(fn (array $a): array => [$a['disco'], $a['ruta'], $a['origen'], $a['existe'] ? 'sí' : 'NO', $a['tamano'] ?? '—'], $plan->archivos));
        $this->info('   Carpetas propias que se retiran:');
        $this->table(['Disco', 'Carpeta', 'Origen', 'Existe'], array_map(fn (array $c): array => [$c['disco'], $c['carpeta'], $c['origen'], $c['existe'] ? 'sí' : 'NO'], $plan->carpetas));

        if ($plan->advertencias !== []) {
            $this->newLine();
            $this->warn('Advertencias (no impiden ejecutar):');

            foreach ($plan->advertencias as $advertencia) {
                $this->warn('  - '.$advertencia);
            }
        }

        $this->reportarErrores($plan);
    }

    private function reportarErrores(PlanLimpiezaPrueba $plan): void
    {
        if ($plan->errores === []) {
            return;
        }

        $this->newLine();
        $this->error('Bloqueos (abortan la limpieza):');

        foreach ($plan->errores as $error) {
            $this->error('  - '.$error);
        }
    }

    /** @param  list<int|string>  $ids */
    private function resumirIds(array $ids): string
    {
        if ($ids === []) {
            return '(por criterio)';
        }

        $texto = implode(', ', array_slice($ids, 0, 12));

        return count($ids) > 12 ? sprintf('%s … (+%d)', $texto, count($ids) - 12) : $texto;
    }

    /** Protección: desde un equipo de desarrollo nunca se borra contra una base remota (producción). */
    private function esBaseRemotaDesdeDesarrollo(): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        $host = (string) config('database.connections.'.DB::getDefaultConnection().'.host', '');

        return ! in_array($host, ['', '127.0.0.1', 'localhost', '::1'], true) && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
}
