<?php

namespace App\Console\Commands;

use App\Enums\EstadoCuentaMigracion;
use App\Models\User;
use App\Services\Autenticacion\NombreUsuarioService;
use App\Services\Expedientes\MigracionInicial\EjecutorMigracion;
use App\Services\Expedientes\MigracionInicial\ExpedientesInitialMigrationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan expedientes:importar-inicial archivo.xlsx               (dry run)
 * php artisan expedientes:importar-inicial archivo.xlsx --dry-run     (dry run)
 * php artisan expedientes:importar-inicial archivo.xlsx --apply --confirm [--mover] [--usuario="Jesus Arizmendi"]
 *
 * Mismo servicio que la pantalla de RH (no duplica lógica). Sin --apply
 * nunca modifica BD ni NAS. Ver docs/MIGRACION_INICIAL_EXPEDIENTES.md.
 */
class ImportarExpedientesInicialCommand extends Command
{
    protected $signature = 'expedientes:importar-inicial
                            {archivo : Ruta del Excel (hoja BASE_GENERAL)}
                            {--dry-run : Solo analiza (es el comportamiento por defecto)}
                            {--apply : Ejecuta la migración}
                            {--confirm : Confirma la ejecución sin preguntar}
                            {--mover : Mueve en vez de copiar (solo origen legacy)}
                            {--usuario= : Usuario (username) de quien ejecuta (por defecto, el primer super_admin)}';

    protected $description = 'Migración inicial de colaboradores (Excel) y expedientes históricos del NAS';

    public function handle(ExpedientesInitialMigrationService $servicio): int
    {
        $archivo = (string) $this->argument('archivo');

        if (! is_file($archivo)) {
            $this->error("No existe el archivo: {$archivo}");

            return self::FAILURE;
        }

        $actor = $this->option('usuario')
            ? app(NombreUsuarioService::class)->buscar((string) $this->option('usuario'))
            : User::role('super_admin')->orderBy('id')->first();

        if ($actor === null) {
            $this->error('No se encontró el usuario que ejecuta (usa --usuario="Nombre Apellido").');

            return self::FAILURE;
        }

        try {
            $migracion = $servicio->analizar($archivo, basename($archivo), $actor);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $plan = $migracion->planArray();
        $this->info(sprintf('Análisis #%d — origen %s: %s', $migracion->id, $plan['origen']['modo'] ?? '', $plan['origen']['ruta'] ?? ''));
        $totales = $migracion->totales ?? [];
        $this->table(['Concepto', 'Total'], array_map(fn (string $k) => [$k, $totales[$k]], array_keys($totales)));

        // Usuarios propuestos (solo en memoria: el análisis no reserva nada).
        $this->table(
            ['Fila', 'Nombre', 'Empresa', 'Sucursal', 'Departamento', 'Puesto', 'Usuario propuesto', 'Estado cuenta', 'Estado importación'],
            array_map(fn (array $f) => [
                $f['fila'], $f['nombre_completo'], $f['empresa_nombre'] ?? $f['empresa_excel'] ?? '—', $f['sucursal_nombre'] ?? $f['sucursal_excel'] ?? '—',
                $f['departamento_nombre'] ?? $f['departamento_excel'] ?? '—', $f['puesto_nombre'] ?? $f['puesto_excel'] ?? '—',
                $f['cuenta']['usuario'] ?? '—', (EstadoCuentaMigracion::tryFrom((string) ($f['cuenta']['estado'] ?? '')) ?? EstadoCuentaMigracion::NoAplica)->etiqueta(), $f['operacion'],
            ], array_values(array_filter((array) ($plan['filas'] ?? []), 'is_array'))),
        );

        foreach (array_slice(array_values(array_filter((array) ($plan['filas'] ?? []), fn ($f) => is_array($f) && ($f['operacion'] ?? null) === 'conflicto')), 0, 50) as $f) {
            $this->warn(sprintf(' - Fila %d %s: %s', $f['fila'], $f['nombre_completo'], implode(' | ', $f['motivos'])));
        }

        foreach ($plan['sucursales_no_autorizadas_nas'] ?? [] as $s) {
            $this->warn(sprintf(' - Carpeta de sucursal no autorizada en el NAS (no se vincula): %s (%d carpetas)', $s['carpeta'], $s['expedientes']));
        }

        if (! $this->option('apply')) {
            $this->line('Dry run: no se modificó nada. Ejecuta con --apply --confirm para aplicar.');

            return self::SUCCESS;
        }

        if (! $this->option('confirm') && ! $this->confirm('¿Ejecutar la migración? Las filas en conflicto y los matches dudosos se omiten.')) {
            return self::SUCCESS;
        }

        $resultado = $servicio->aplicar($migracion, $actor, $this->option('mover') ? EjecutorMigracion::MODO_MOVER : EjecutorMigracion::MODO_COPIAR);
        $this->table(['Resultado', 'Total'], array_map(fn (string $k) => [$k, $resultado[$k]], array_keys($resultado)));
        $this->info('Manifiesto: storage/app/private/'.$migracion->fresh()?->manifiesto_path);

        return $resultado['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
