<?php

use App\Models\ImportacionExcel;
use App\Models\MigracionExpedientes;
use App\Models\User;
use App\Services\ImportadorContactosEmergencia\ImportadorContactosEmergenciaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
 * Decisión del negocio (2026-10-20): las importaciones y cargas masivas son
 * SOLO de super_admin — carga inicial maestra, migración de expedientes,
 * salarios y vacaciones, contactos de emergencia, Excel de rotación, recibos
 * de nómina por archivo y descriptivos de puesto. rh_admin (que ve todo) y
 * rh_auxiliar reciben 403 en web y API y no tienen esos permisos (el menú
 * los oculta); las pantallas de CONSULTA siguen abiertas para RH.
 */

/** @var list<string> */
const IMP_PERMISOS = [
    'expedientes.migrar',
    'headcount.importar',
    'colaboradores.importar_salarios_vacaciones',
    'rotacion.cargar',
    'nomina.recibos.importar',
    'contactos_emergencia.importar',
    'perfiles_puesto.importar',
];

/** Pantallas GET de importación (sin parámetros). */
const IMP_PANTALLAS = [
    'administracion.importar-datos.index',
    'administracion.importar-datos.carga-inicial.index',
    'rh.importaciones.index',
    'rh.contactos-emergencia.index',
    'rh.contactos-emergencia.plantilla',
    'rh.expedientes.migracion.index',
    'rh.perfiles-puesto.importacion',
];

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Storage::fake('nas_legacy');
    Storage::fake('local');
    // Carpeta de descriptivos vacía: la pantalla no lee PDFs reales.
    $vacia = storage_path('framework/testing/descriptivos-vacios');
    is_dir($vacia) || mkdir($vacia, 0777, true);
    config(['descriptivos.carpeta' => $vacia]);
});

test('ningún rol salvo super_admin tiene permisos de importación en su base; super_admin los tiene todos', function () {
    foreach (RolesYPermisosSeeder::permisosBasePorRol() as $rol => $permisos) {
        $tiene = array_values(array_intersect(IMP_PERMISOS, $permisos));

        if ($rol === 'super_admin') {
            expect($tiene)->toEqualCanonicalizing(IMP_PERMISOS);
        } else {
            expect($tiene)->toBe([], "el rol {$rol} conserva permisos de importación");
        }
    }
});

test('rh_admin y rh_auxiliar reciben 403 en cada pantalla de importación; super_admin entra a todas', function () {
    $admin = clUsuario('super_admin');

    foreach (IMP_PANTALLAS as $ruta) {
        $this->actingAs($admin)->get(route($ruta))->assertOk();
    }

    foreach (['rh_admin', 'rh_auxiliar'] as $rol) {
        $rh = clUsuario($rol);

        foreach (IMP_PANTALLAS as $ruta) {
            $this->actingAs($rh)->get(route($ruta))->assertForbidden();
        }
    }
});

test('rh_admin y rh_auxiliar reciben 403 al subir, aplicar o quitar cualquier importación (web)', function () {
    $migracion = MigracionExpedientes::query()->create(['archivo_nombre' => 'x.xlsx', 'archivo_hash' => 'x', 'estado' => 'analizado', 'plan' => '{}']);
    $contactos = ImportacionExcel::query()->create([
        'tipo' => ImportadorContactosEmergenciaService::TIPO, 'nombre_archivo' => 'c.xlsx', 'archivo_hash' => str_repeat('a', 64),
        'estado' => 'previsualizado', 'detalle' => [], 'subido_por' => clUsuario('super_admin')->id,
    ]);
    $xlsx = fn () => UploadedFile::fake()->create('archivo.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    foreach (['rh_admin', 'rh_auxiliar'] as $rol) {
        $rh = clUsuario($rol);
        $this->actingAs($rh);

        $this->post(route('administracion.importar-datos.carga-inicial.analizar'), ['archivo' => $xlsx()])->assertForbidden();
        $this->post(route('administracion.importar-datos.carga-inicial.aplicar', $migracion), ['confirmacion' => true])->assertForbidden();
        $this->get(route('administracion.importar-datos.carga-inicial.estado', $migracion))->assertForbidden();
        $this->post(route('rh.expedientes.migracion.analizar'), ['archivo' => $xlsx()])->assertForbidden();
        $this->post(route('rh.expedientes.migracion.aplicar', $migracion), ['modo' => 'copiar', 'confirmacion' => true])->assertForbidden();
        $this->post(route('rh.importaciones.analizar'), ['archivo' => $xlsx()])->assertForbidden();
        $this->post(route('rh.contactos-emergencia.analizar'), ['archivo' => $xlsx()])->assertForbidden();
        $this->post(route('rh.contactos-emergencia.aplicar', $contactos))->assertForbidden();
        $this->delete(route('rh.contactos-emergencia.destroy', $contactos))->assertForbidden();
        $this->post(route('reportes.rotacion.analizar'), ['archivo' => $xlsx()])->assertForbidden();
        $this->post(route('rh.perfiles-puesto.importar'), ['sha256' => str_repeat('a', 64), 'puestos' => [1]])->assertForbidden();
        $this->post(route('rh.nomina.lotes.store'), [
            'periodicidad' => 'semanal', 'fecha' => '2026-10-08', 'origen' => 'importacion',
            'archivo' => UploadedFile::fake()->createWithContent('lote.csv', "numero_empleado,tipo,concepto,importe\n"),
        ])->assertForbidden();
    }

    expect(ImportacionExcel::query()->count())->toBe(1);
});

test('rh_admin y rh_auxiliar reciben 403 al importar recibos por la API; super_admin no', function () {
    $csv = fn () => UploadedFile::fake()->createWithContent('semana.csv', "numero_empleado,tipo,concepto,importe,cantidad\n");
    $payload = fn () => ['archivo' => $csv(), 'periodo_inicio' => '2026-09-14', 'periodo_fin' => '2026-09-20', 'simular' => true];

    foreach (['rh_admin', 'rh_auxiliar'] as $rol) {
        Sanctum::actingAs(clUsuario($rol));
        $this->post('/api/v1/rh/recibos/importar', $payload(), ['Accept' => 'application/json'])->assertForbidden();
    }

    Sanctum::actingAs(clUsuario('super_admin'));
    expect($this->post('/api/v1/rh/recibos/importar', $payload(), ['Accept' => 'application/json'])->getStatusCode())->not->toBe(403);
});

test('las pantallas de CONSULTA siguen abiertas para rh_admin, sin botones de importar ni permisos de importación en el menú', function () {
    $rh = clUsuario('rh_admin');

    foreach (['reportes.rotacion.index', 'reportes.rotacion.tablero.index', 'rh.expedientes.index', 'rh.nomina.lotes.index', 'rh.perfiles-puesto.index', 'administracion.sucursales.index'] as $ruta) {
        $this->actingAs($rh)->get(route($ruta))->assertOk();
    }

    $this->actingAs($rh)->get(route('reportes.rotacion.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('puedeCargar', false));
    $this->actingAs($rh)->get(route('reportes.rotacion.tablero.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('puedeCargarExcel', false));
    $this->actingAs($rh)->get(route('rh.nomina.lotes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('puedeImportar', false)->where('puedeCrear', true));
    $this->actingAs($rh)->get(route('rh.perfiles-puesto.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('puedeImportar', false)
            // Lo que el menú usa para mostrar «Importar datos», «Importar
            // salarios y vacaciones» y «Contactos de emergencia».
            ->where('auth.user.permissions', fn ($permisos) => collect($permisos)->intersect(IMP_PERMISOS)->isEmpty()));
});

test('super_admin ve los botones de importar y todos los permisos de importación en el menú', function () {
    $admin = User::factory()->create(['colaborador_id' => null]);
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->get(route('rh.perfiles-puesto.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('puedeImportar', true)
            ->where('auth.user.permissions', fn ($permisos) => collect(IMP_PERMISOS)->diff($permisos)->isEmpty()));
    $this->actingAs($admin)->get(route('rh.nomina.lotes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('puedeImportar', true));
    $this->actingAs($admin)->get(route('reportes.rotacion.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('puedeCargar', true));
});
