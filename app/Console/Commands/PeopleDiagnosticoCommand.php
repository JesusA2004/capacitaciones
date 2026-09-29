<?php

namespace App\Console\Commands;

use App\Enums\TipoNodoComercial;
use App\Models\EmployeeDocument;
use App\Models\HeadcountTarget;
use App\Models\OfficialFormat;
use App\Models\User;
use App\Services\Permisos\SincronizadorPermisosService;
use Composer\InstalledVersions;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Diagnóstico previo a considerar un entorno (dev/staging/VPS) "listo para
 * usar". Diagnóstico no destructivo; realiza una escritura temporal para
 * comprobar NAS (crea y borra un archivo de prueba en el disco «nas», ver
 * revisarStorageNas()) pero no toca ninguna tabla ni catálogo. Pensado para
 * correrse justo después de `migrate --force` + `db:seed --force` en un
 * deploy, o cuando algo no carga y no está claro si falta un import/seed —
 * ver docs/DEPLOY.md.
 *
 * Cada sección debe llegar hasta el final aunque falte una tabla/catálogo
 * completo (headcount_targets, official_formats, nodos_comerciales,
 * permissions, roles, etc.): por eso cada una se protege con
 * Schema::hasTable() o try/catch en vez de dejar que una excepción SQL
 * detenga el resto del diagnóstico a medias.
 */
class PeopleDiagnosticoCommand extends Command
{
    protected $signature = 'people:diagnostico';

    protected $description = 'Revisa columnas críticas, storage, catálogos y datos demo del portal RH sin modificar nada (solo escribe un archivo temporal de prueba en el disco NAS)';

    /**
     * Tabla => columnas que deben existir. Ver docs/DEPLOY.md, sección
     * "Verificar columnas críticas" — estas son las que distintas fases del
     * encargo agregaron sobre una base ya consolidada.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMNAS_CRITICAS = [
        'finiquito_calculos' => [],
        'vacantes' => ['plazas_requeridas', 'plazas_cubiertas', 'plazas_disponibles', 'motivo_cancelacion'],
        'nodos_comerciales' => [],
        'user_nodo_comercial' => [],
        'official_formats' => [],
        'official_format_generations' => [],
        'solicitudes_internas' => ['fecha_efectiva', 'tipo_baja', 'colaborador_objetivo_id'],
        'mobile_devices' => [],
        'users' => ['preferencias_ui'],
    ];

    private bool $huboProblemas = false;

    public function handle(): int
    {
        $this->revisarEntorno();
        $this->revisarBaseDeDatos();
        $this->revisarMigraciones();
        $this->revisarCache();
        $this->revisarCola();
        $this->revisarCorreo();
        $this->revisarLibreOffice();
        $this->revisarColumnasCriticas();
        $this->revisarStorageNas();
        $this->revisarEstructuraExpedientesNas();
        $this->revisarHeadcount();
        $this->revisarFormatosOficiales();
        $this->revisarColaboradoresIncompletos();
        $this->revisarRutasMatriz();
        $this->revisarPermisos();
        $this->revisarRolesDemo();

        if ($this->huboProblemas) {
            $this->newLine();
            $this->warn('Diagnóstico terminado con advertencias — revisa lo marcado arriba.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Diagnóstico terminado sin problemas.');

        return self::SUCCESS;
    }

    /**
     * APP_DEBUG=true en producción expone la pantalla de excepción de
     * Laravel (trazas, consultas, rutas, headers, cookies). Nunca se
     * imprimen valores secretos, solo si cada ajuste está bien o no.
     */
    private function revisarEntorno(): void
    {
        $this->line('<fg=blue>Entorno</>');

        $this->ok(sprintf('APP_ENV=%s', app()->environment()));

        if (app()->isProduction() && config('app.debug')) {
            $this->fallo('APP_DEBUG=true en producción: la pantalla de error expone trazas, consultas y cookies. Pon APP_DEBUG=false y corre `php artisan config:cache`.');
        } else {
            $this->ok(config('app.debug') ? 'APP_DEBUG=true (aceptable fuera de producción).' : 'APP_DEBUG=false.');
        }

        // fakerphp/faker es require-dev: si está instalado en producción, el
        // deploy corrió `composer install` sin --no-dev.
        if (app()->isProduction() && InstalledVersions::isInstalled('fakerphp/faker')) {
            $this->fallo('Hay paquetes de desarrollo instalados en producción: usa `composer install --no-dev --optimize-autoloader --no-interaction`.');
        }

        if (config('app.key') === null || config('app.key') === '') {
            $this->fallo('APP_KEY vacío — corre `php artisan key:generate` (una sola vez por entorno).');
        }
    }

    private function revisarBaseDeDatos(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Base de datos</>');

        try {
            DB::connection()->getPdo();
            $this->ok(sprintf('Conexión «%s» disponible.', DB::connection()->getName()));
        } catch (Throwable $e) {
            $this->fallo('No hay conexión a la base de datos (revisa DB_* en .env).');
        }
    }

    private function revisarMigraciones(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Migraciones</>');

        try {
            $migrador = app('migrator');

            if (! $migrador->repositoryExists()) {
                $this->fallo('No existe la tabla de migraciones — corre `php artisan migrate --force`.');

                return;
            }

            $archivos = array_keys($migrador->getMigrationFiles([database_path('migrations')]));
            $pendientes = array_values(array_diff($archivos, $migrador->getRepository()->getRan()));
        } catch (Throwable $e) {
            $this->fallo('No se pudo revisar el estado de las migraciones.');

            return;
        }

        if ($pendientes !== []) {
            $this->fallo(sprintf('%d migración(es) pendiente(s) (%s) — corre `php artisan migrate --force`.', count($pendientes), implode(', ', array_slice($pendientes, 0, 3)).(count($pendientes) > 3 ? '…' : '')));

            return;
        }

        $this->ok(sprintf('Las %d migraciones están aplicadas.', count($archivos)));
    }

    private function revisarCache(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Caché</>');

        $clave = 'people:diagnostico:'.uniqid();

        try {
            Cache::put($clave, 'ok', 30);
            $valor = Cache::get($clave);
            Cache::forget($clave);
            $valor === 'ok'
                ? $this->ok(sprintf('Caché «%s» lee y escribe.', config('cache.default')))
                : $this->fallo(sprintf('La caché «%s» no devolvió lo que se guardó.', config('cache.default')));
        } catch (Throwable $e) {
            $this->fallo(sprintf('La caché «%s» no responde.', config('cache.default')));
        }
    }

    private function revisarCola(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Cola</>');

        $conexion = config('queue.default');
        $this->ok(sprintf('Conexión de cola: %s.', is_string($conexion) ? $conexion : '—'));

        if ($conexion === 'sync' && app()->isProduction()) {
            $this->fallo('QUEUE_CONNECTION=sync en producción: correos y notificaciones se envían dentro de la petición (lento). Usa database/redis con un worker.');
        }

        try {
            if (Schema::hasTable('failed_jobs')) {
                $fallidos = DB::table('failed_jobs')->count();
                $fallidos > 0
                    ? $this->fallo(sprintf('%d trabajo(s) fallido(s) en la cola — revisa `php artisan queue:failed`.', $fallidos))
                    : $this->ok('Sin trabajos fallidos.');
            }

            if ($conexion === 'database' && Schema::hasTable('jobs')) {
                $viejos = DB::table('jobs')->where('created_at', '<', now()->subMinutes(15)->getTimestamp())->count();
                $viejos > 0
                    ? $this->fallo(sprintf('%d trabajo(s) esperando más de 15 min — ¿está corriendo `php artisan queue:work`?', $viejos))
                    : $this->ok('No hay trabajos atorados.');
            }
        } catch (Throwable $e) {
            $this->fallo('No se pudo leer el estado de la cola.');
        }
    }

    /**
     * Solo verifica que el correo esté configurado; nunca imprime usuario,
     * contraseña ni host.
     */
    private function revisarCorreo(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Correo</>');

        $mailer = config('mail.default');
        $remitente = config('mail.from.address');

        $this->ok(sprintf('Mailer: %s.', is_string($mailer) ? $mailer : '—'));

        if (app()->isProduction() && in_array($mailer, ['log', 'array', null], true)) {
            $this->fallo('En producción el mailer es «log»/«array»: ningún correo sale. Configura MAIL_MAILER (smtp, etc.).');
        }

        if (! is_string($remitente) || $remitente === '' || str_ends_with($remitente, '@example.com')) {
            $this->fallo('MAIL_FROM_ADDRESS no está configurado con un remitente real.');
        } else {
            $this->ok('Remitente configurado.');
        }
    }

    /**
     * FORMATOS_LIBREOFFICE_PATH (config('formatos_oficiales.libreoffice')):
     * sin él, Word → PDF usa el convertidor aproximado de PhpWord/DomPDF.
     */
    private function revisarLibreOffice(): void
    {
        $this->newLine();
        $this->line('<fg=blue>LibreOffice (Word → PDF)</>');

        $ruta = config('formatos_oficiales.libreoffice');

        if (! is_string($ruta) || $ruta === '') {
            $this->fallo('FORMATOS_LIBREOFFICE_PATH no está configurado: los PDF de plantillas Word serán aproximados.');

            return;
        }

        $this->ok(sprintf('Ruta configurada: %s', $ruta));

        try {
            $resultado = Process::timeout(30)->run([$ruta, '--version']);
            $resultado->successful()
                ? $this->ok(trim(strtok($resultado->output(), "\n") ?: 'LibreOffice responde.'))
                : $this->fallo('LibreOffice no respondió a --version (revisa permisos del usuario del servidor web).');
        } catch (Throwable $e) {
            $this->fallo('No se pudo ejecutar LibreOffice en la ruta configurada.');
        }
    }

    private function revisarColumnasCriticas(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Columnas/tablas críticas</>');

        foreach (self::COLUMNAS_CRITICAS as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                $this->fallo("Falta la tabla «{$tabla}» — ¿faltó correr `php artisan migrate`?");

                continue;
            }

            $this->ok("Tabla «{$tabla}» existe.");

            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    continue;
                }

                $this->fallo("Falta la columna «{$tabla}.{$columna}» — migración pendiente o corrida a medias.");
            }
        }
    }

    private function revisarStorageNas(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Storage (disco nas)</>');

        $ruta = 'diagnostico/'.uniqid('write-test-', true).'.tmp';

        try {
            Storage::disk('nas')->put($ruta, 'ok');
            Storage::disk('nas')->delete($ruta);
            $this->ok('El disco «nas» es escribible.');
        } catch (Throwable $e) {
            $this->fallo('El disco «nas» no es escribible: '.$e->getMessage());
        }
    }

    /**
     * Solo cuenta cuántos `employee_documents.path` siguen con la ruta
     * legacy (`expedientes/{id}/...`) vs. la estructura legible actual
     * (`expedientes/{empresa}/{sucursal}/{numero - nombre}/...`, ver
     * docs/ESTRUCTURA_EXPEDIENTES_NAS.md). Deliberadamente NO calcula hash
     * ni escanea el disco NAS completo aquí (costoso en cada diagnóstico);
     * para eso están `expedientes:organizar-nas` (dry run) y
     * `expedientes:verificar-storage`.
     */
    private function revisarEstructuraExpedientesNas(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Estructura de expedientes en el NAS</>');

        if (! Schema::hasTable('employee_documents')) {
            $this->fallo('Falta la tabla «employee_documents» — ¿faltó correr `php artisan migrate`?');

            return;
        }

        $rutas = EmployeeDocument::withTrashed()
            ->where('disk', config('expedientes.disk'))
            ->pluck('path');

        if ($rutas->isEmpty()) {
            $this->ok('No hay documentos de expediente cargados todavía.');

            return;
        }

        $legacy = $rutas->filter(fn (string $ruta) => (bool) preg_match('#^expedientes/\d+/#', $ruta))->count();

        if ($legacy > 0) {
            $this->fallo("{$legacy} de {$rutas->count()} documento(s) siguen en la ruta legacy (UUID sin empresa/sucursal/colaborador) — corre `php artisan expedientes:organizar-nas` para ver el plan de migración.");

            return;
        }

        $this->ok("Los {$rutas->count()} documento(s) de expediente ya están en la estructura legible (empresa/sucursal/colaborador).");
    }

    private function revisarHeadcount(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Headcount</>');

        if (! Schema::hasTable('headcount_targets')) {
            $this->fallo('Falta la tabla «headcount_targets» — ¿faltó correr `php artisan migrate`?');

            return;
        }

        $total = HeadcountTarget::query()->count();

        if ($total === 0) {
            $this->fallo('No hay headcount cargado (tabla «headcount_targets» vacía) — corre `php artisan headcount:importar`.');

            return;
        }

        $this->ok("Headcount cargado: {$total} renglón(es) de plantilla autorizada.");
    }

    private function revisarFormatosOficiales(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Formatos oficiales</>');

        if (! Schema::hasTable('official_formats')) {
            $this->fallo('Falta la tabla «official_formats» — ¿faltó correr `php artisan migrate`?');

            return;
        }

        $total = OfficialFormat::query()->count();

        if ($total === 0) {
            $this->fallo('No hay formatos oficiales importados — coloca los PDFs en claude/formatos/originales/ y corre `php artisan formatos:importar-originales`.');

            return;
        }

        $sinConfigurar = OfficialFormat::query()->whereNull('archivado_en')->with('versionVigente')->get()->reject(fn (OfficialFormat $f) => $f->tieneConfiguracion())->count();
        $this->ok("Plantillas oficiales: {$total} (sin versión publicada con campos: {$sinConfigurar}).");
    }

    private function revisarColaboradoresIncompletos(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Colaboradores activos con datos incompletos</>');

        $campos = [
            'puesto_id' => 'puesto',
            'sucursal_principal_id' => 'sucursal',
            'departamento_id' => 'departamento',
            'genero' => 'género',
            'fecha_nacimiento' => 'fecha de nacimiento',
        ];

        foreach ($campos as $columna => $etiqueta) {
            $cantidad = User::query()->where('estatus', 'activo')->whereNull($columna)->count();

            if ($cantidad > 0) {
                $this->fallo("{$cantidad} colaborador(es) activo(s) sin {$etiqueta}.");

                continue;
            }

            $this->ok("Todos los colaboradores activos tienen {$etiqueta}.");
        }
    }

    private function revisarRutasMatriz(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Matriz comercial</>');

        if (! Schema::hasTable('nodos_comerciales')) {
            $this->fallo('Falta la tabla «nodos_comerciales» — ¿faltó correr `php artisan migrate`?');

            return;
        }

        $rutas = DB::table('nodos_comerciales')->where('tipo', TipoNodoComercial::Ruta->value)->count();

        if ($rutas === 0) {
            $this->fallo('No hay rutas cargadas en la matriz comercial (nodos_comerciales.tipo = ruta).');

            return;
        }

        $this->ok("Matriz comercial: {$rutas} ruta(s) cargada(s).");
    }

    private function revisarPermisos(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Permisos</>');

        $tablaPermisos = config('permission.table_names.permissions', 'permissions');

        if (! is_string($tablaPermisos) || ! Schema::hasTable($tablaPermisos)) {
            $this->fallo("Falta la tabla «{$tablaPermisos}» — ¿faltó correr `php artisan migrate`?");

            return;
        }

        $esperados = RolesYPermisosSeeder::catalogoPermisos();

        try {
            $existentes = Permission::query()->pluck('name')->all();
        } catch (Throwable $e) {
            $this->fallo('No se pudo leer el catálogo de permisos: '.$e->getMessage());

            return;
        }

        $faltantes = array_diff($esperados, $existentes);

        if ($faltantes !== []) {
            $this->fallo(count($faltantes).' permiso(s) del catálogo no están sembrados: '.implode(', ', array_slice($faltantes, 0, 10)).(count($faltantes) > 10 ? '…' : '').' — corre `php artisan people:sincronizar-permisos`.');

            return;
        }

        $this->ok(count($esperados).' permisos del catálogo están sembrados.');

        // Roles base a los que les falta algún permiso del diseño (p. ej.
        // celebraciones.* tras un git pull): simulación, no escribe nada.
        try {
            $pendientes = app(SincronizadorPermisosService::class)->sincronizar(simular: true)['permisos_otorgados'];
        } catch (Throwable $e) {
            return;
        }

        if ($pendientes !== []) {
            $this->fallo(sprintf(
                'Roles base sin permisos del catálogo: %s — corre `php artisan people:sincronizar-permisos` (no quita personalizaciones).',
                implode(', ', array_map(fn (string $rol, array $permisos) => sprintf('%s (%d)', $rol, count($permisos)), array_keys($pendientes), $pendientes)),
            ));

            return;
        }

        $this->ok('Los roles base tienen todos sus permisos del catálogo.');
    }

    private function revisarRolesDemo(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Roles demo</>');

        $tablaRoles = config('permission.table_names.roles', 'roles');

        if (! is_string($tablaRoles) || ! Schema::hasTable($tablaRoles)) {
            $this->fallo("Falta la tabla «{$tablaRoles}» — ¿faltó correr `php artisan migrate`?");

            return;
        }

        $esperados = array_keys(RolesYPermisosSeeder::permisosBasePorRol());

        try {
            $existentes = Role::query()->pluck('name')->all();
        } catch (Throwable $e) {
            $this->fallo('No se pudo leer el catálogo de roles: '.$e->getMessage());

            return;
        }

        $faltantes = array_diff($esperados, $existentes);

        if ($faltantes !== []) {
            $this->fallo('Faltan roles: '.implode(', ', $faltantes).' — corre `php artisan people:sincronizar-permisos`.');

            return;
        }

        $this->ok(count($esperados).' roles existen ('.implode(', ', $esperados).').');
    }

    private function ok(string $mensaje): void
    {
        $this->line("  <fg=green>✓</> {$mensaje}");
    }

    private function fallo(string $mensaje): void
    {
        $this->huboProblemas = true;
        $this->line("  <fg=red>✗</> {$mensaje}");
    }
}
