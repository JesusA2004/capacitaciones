<?php

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\LoteGeneracionContratos;
use App\Models\Puesto;
use App\Models\User;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Contratos: solo RH los genera (2026-11-06). Generar (individual, «Generar
| todos», paquete de contratación, regenerar) exige SOLO contratos.generar:
| quitarlo en «Roles y permisos» quita el botón y el backend lo rechaza,
| aunque el rol conserve el general documentos_laborales.generar.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();

    $estructura = clEstructura();
    $this->sucursal = $estructura['sucursal'];
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'meses_periodo_prueba' => 2, 'grupo_documental' => 'gestor']);
    $base = ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id];
    $this->rh = clUsuario('rh_admin', $base);
    $this->empleado = Colaborador::factory()->create([...$base, 'puesto_id' => $this->gestor->id, 'fecha_ingreso' => now()->subDays(10)->toDateString()]);
});

function csrContratoGenerado(Colaborador $colaborador, ContratoLaboral $contrato): GeneratedDocument
{
    return GeneratedDocument::factory()->create([
        'colaborador_id' => $colaborador->id,
        'documentable_type' => $contrato->getMorphClass(),
        'documentable_id' => $contrato->id,
        'categoria' => CategoriaDocumento::Contratos->value,
        'estado_flujo' => EstadoFlujoDocumento::Generado->value,
        'clave_plantilla' => 'contrato_capacitacion',
    ]);
}

function csrQuitarARh(array $permisos): void
{
    Role::findByName('rh_admin')->revokePermissionTo($permisos);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

test('por defecto solo RH genera contratos: ninguna gerencia, la regional ni Dirección Comercial', function () {
    $base = RolesYPermisosSeeder::permisosBasePorRol();

    foreach (['gerente', 'gerente_sucursal', 'gerente_regional', 'director_comercial'] as $rol) {
        expect($base[$rol])->not->toContain('contratos.generar')
            ->not->toContain('documentos_laborales.generar')
            ->not->toContain('contratos.ver_documento')
            ->not->toContain('contratos.descargar');
    }

    foreach (['rh_admin', 'rh_auxiliar'] as $rol) {
        expect($base[$rol])->toContain('contratos.generar');
    }
});

test('Gerente Regional y Dirección Comercial no generan contratos (individual, todos, paquete ni API)', function () {
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $this->empleado->id]);

    foreach (['gerente_regional', 'director_comercial'] as $rol) {
        $usuario = clUsuario($rol, ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id]);

        $this->actingAs($usuario)->post(route('rh.contratos.generar', $this->empleado))->assertForbidden();
        $this->actingAs($usuario)->post(route('rh.contratos.generar-todos'))->assertForbidden();

        expect(app(DocumentoProcesoService::class)->puedeGenerar($usuario, 'contrato_capacitacion', $contrato))->toBeFalse()
            ->and($usuario->can('generarDocumento', $contrato))->toBeFalse();

        Sanctum::actingAs($usuario);
        $this->postJson("/api/v1/rh/colaboradores/{$this->empleado->id}/documentos-laborales", ['clave' => 'contrato_capacitacion', 'contrato_id' => $contrato->id])
            ->assertForbidden();
    }

    expect(LoteGeneracionContratos::query()->count())->toBe(0);
});

test('RH genera contratos por defecto', function () {
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $this->empleado->id]);

    $this->actingAs($this->rh)->get(route('rh.contratos.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina->where('puedeGenerar', true));

    expect(app(DocumentoProcesoService::class)->puedeGenerar($this->rh, 'contrato_capacitacion', $contrato))->toBeTrue()
        ->and($this->rh->can('generarDocumento', $contrato))->toBeTrue()
        ->and($this->rh->can('regenerar', csrContratoGenerado($this->empleado, $contrato)))->toBeTrue();
});

test('quitar «Generar contratos» a RH en Roles y permisos quita el botón y el backend lo rechaza, aunque conserve el general', function () {
    csrQuitarARh(['contratos.generar']);
    $this->rh->refresh();
    expect($this->rh->can('documentos_laborales.generar'))->toBeTrue();

    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $this->empleado->id]);
    $documento = csrContratoGenerado($this->empleado, $contrato);
    $otroDocumento = GeneratedDocument::factory()->create([
        'colaborador_id' => $this->empleado->id,
        'categoria' => CategoriaDocumento::Otros->value,
        'estado_flujo' => EstadoFlujoDocumento::Generado->value,
    ]);

    // Web: sin botón «Generar» / «Generar todos» y 403 al llamarlo directo.
    $this->actingAs($this->rh)->get(route('rh.contratos.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina->where('puedeGenerar', false));
    $this->actingAs($this->rh)->post(route('rh.contratos.generar', $this->empleado))->assertForbidden();
    $this->actingAs($this->rh)->post(route('rh.contratos.generar-todos'))->assertForbidden();
    $this->actingAs($this->rh)->get(route('rh.contratos.vista-previa', ['colaborador' => $this->empleado->id, 'clave' => 'contrato_capacitacion']))->assertForbidden();

    // Paquete de contratación / regenerar: el servicio y las policies lo niegan.
    $servicio = app(DocumentoProcesoService::class);
    expect($servicio->puedeGenerar($this->rh, 'contrato_capacitacion', $contrato))->toBeFalse()
        ->and($this->rh->can('generarDocumento', $contrato))->toBeFalse()
        ->and($this->rh->can('regenerar', $documento))->toBeFalse()
        // Los demás documentos laborales los sigue generando.
        ->and($servicio->puedeGenerar($this->rh, 'carta_renuncia'))->toBeTrue()
        ->and($this->rh->can('regenerar', $otroDocumento))->toBeTrue();

    // API: generar el documento del contrato responde 403 y la app oculta el botón.
    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/colaboradores/{$this->empleado->id}/documentos-laborales", ['clave' => 'contrato_capacitacion', 'contrato_id' => $contrato->id])
        ->assertForbidden();

    $bootstrap = $this->getJson('/api/v1/mobile/bootstrap')->assertOk()->json();
    $acciones = data_get($bootstrap, 'data.ciclo_laboral.acciones') ?? data_get($bootstrap, 'ciclo_laboral.acciones');
    expect($acciones['generar_contratos'])->toBeFalse();

    expect(LoteGeneracionContratos::query()->count())->toBe(0);
});

test('quitar «Ver contrato» y «Descargar contrato» a RH también se respeta', function () {
    csrQuitarARh(['contratos.ver_documento', 'contratos.descargar']);
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $this->empleado->id]);
    $documento = csrContratoGenerado($this->empleado, $contrato);

    $usuario = $this->rh->refresh();
    expect($usuario->can('ver', $documento))->toBeFalse()
        ->and($usuario->can('descargar', $documento))->toBeFalse();
});

test('la migración quita la generación de contratos a las gerencias y a Dirección Comercial y conserva la de RH', function () {
    foreach (['gerente', 'gerente_sucursal', 'gerente_regional', 'director_comercial'] as $rol) {
        // Estado de producción: un administrador les dio generar de más.
        Role::findByName($rol)->givePermissionTo(['contratos.generar', 'documentos_laborales.generar', 'reportes_rh.datos_sensibles']);
    }
    // Rol personalizado que generaba contratos solo con el general.
    $personal = Role::query()->create(['name' => 'rh_personalizado', 'guard_name' => 'web']);
    $personal->givePermissionTo(['contratos.ver', 'documentos_laborales.ver', 'documentos_laborales.generar']);
    $directo = User::factory()->create();
    $directo->givePermissionTo('documentos_laborales.generar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    argMigracionContratos();
    argMigracionContratos();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['gerente', 'gerente_sucursal', 'gerente_regional', 'director_comercial'] as $rol) {
        $permisos = Role::findByName($rol)->permissions()->pluck('name')->all();
        expect($permisos)->not->toContain('contratos.generar')
            ->not->toContain('documentos_laborales.generar')
            ->toContain('reportes_rh.datos_sensibles');
    }

    expect(Role::findByName('rh_admin')->hasPermissionTo('contratos.generar'))->toBeTrue()
        ->and($personal->refresh()->hasPermissionTo('contratos.generar'))->toBeTrue()
        ->and($personal->hasPermissionTo('contratos.ver_documento'))->toBeTrue()
        ->and($directo->refresh()->hasDirectPermission('contratos.generar'))->toBeTrue()
        ->and(Activity::query()->where('event', 'permisos_rol_ajustados')->count())->toBeGreaterThanOrEqual(4);
});

function argMigracionContratos(): void
{
    $migracion = require database_path('migrations/2026_11_13_500400_contratos_solo_los_genera_rh.php');
    $migracion->up();
}
