<?php

use App\Enums\TipoMovimientoLaboral;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Permisos\AlcanceModuloService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

/*
| Alcance (2026-11-06): la gerente de sucursal A ve SOLO la sucursal A (más
| sus sucursales adicionales); Gerente/Subgerente Regional (rol
| gerente_regional) y Dirección Comercial ven todas las sucursales.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();

    $estructura = clEstructura();
    $this->a = $estructura['sucursal'];
    $this->a->update(['es_corporativo' => false]);
    $this->b = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id, 'es_corporativo' => false]);
    $this->puesto = Puesto::factory()->create(['nombre' => 'Gestor']);
    $this->gerente = clUsuario('gerente_sucursal', ['estatus' => 'activo', 'sucursal_principal_id' => $this->a->id]);
    $this->enA = Colaborador::factory()->create(['estatus' => 'activo', 'sucursal_principal_id' => $this->a->id, 'puesto_id' => $this->puesto->id]);
    $this->enB = Colaborador::factory()->create(['estatus' => 'activo', 'sucursal_principal_id' => $this->b->id, 'puesto_id' => $this->puesto->id]);
});

test('la gerente de sucursal A no abre el expediente ni el paquete de contratación de alguien de B (web y API)', function () {
    $contratoB = ContratoLaboral::factory()->create(['colaborador_id' => $this->enB->id]);

    foreach (['gerente_sucursal', 'gerente'] as $rol) {
        $gerente = $rol === 'gerente_sucursal' ? $this->gerente : clUsuario('gerente', ['estatus' => 'activo', 'sucursal_principal_id' => $this->a->id]);

        $this->actingAs($gerente)->get(route('rh.expedientes.show', $this->enB))->assertForbidden();
        $this->actingAs($gerente)->getJson("/rh/documentos-proceso/contrato/{$contratoB->id}")->assertForbidden();

        Sanctum::actingAs($gerente);
        $this->getJson("/api/v1/rh/documentos-proceso/contrato/{$contratoB->id}")->assertForbidden();
    }

    $this->actingAs($this->gerente)->get(route('rh.expedientes.show', $this->enA))->assertOk();
});

test('el organigrama de puestos solo lista candidatos y movimientos de su sucursal', function () {
    Candidato::factory()->create(['sucursal_id' => $this->a->id, 'puesto_objetivo_id' => $this->puesto->id, 'nombre' => 'Candidata A']);
    Candidato::factory()->create(['sucursal_id' => $this->b->id, 'puesto_objetivo_id' => $this->puesto->id, 'nombre' => 'Candidata B']);
    MovimientoLaboral::factory()->create(['colaborador_id' => $this->enA->id, 'puesto_nuevo_id' => $this->puesto->id, 'tipo_movimiento' => TipoMovimientoLaboral::AjusteManual->value]);
    MovimientoLaboral::factory()->create(['colaborador_id' => $this->enB->id, 'puesto_nuevo_id' => $this->puesto->id, 'tipo_movimiento' => TipoMovimientoLaboral::AjusteManual->value]);

    $this->actingAs($this->gerente)->get(route('administracion.jerarquia-puestos.index'))
        ->assertOk()
        ->assertInertia(function (Assert $pagina): void {
            $puestos = collect($pagina->toArray()['props']['puestos']);
            $nombres = $puestos->flatMap(fn (array $p) => collect($p['candidatos'] ?? [])->pluck('nombre'))->all();
            expect($nombres)->toContain('Candidata A')->not->toContain('Candidata B');
        });

    $movimientos = collect($this->actingAs($this->gerente)->getJson(route('administracion.jerarquia-puestos.historial', $this->puesto))->assertOk()->json('movimientos'));
    expect($movimientos->pluck('colaborador_id')->all())->toBe([$this->enA->id]);
});

test('la matriz comercial no le manda a la gerente la plantilla de toda la empresa', function () {
    $this->actingAs($this->gerente)->get(route('administracion.matriz-comercial.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina->where('puedeEditar', false)->where('gestoresDisponibles', []));
});

test('la gerente no ve el lote de «Generar todos» ni la bandeja de CVs sin sucursal', function () {
    $this->actingAs($this->gerente)->get(route('rh.contratos.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina->where('lote', null)->where('puedeGenerar', false));
    $this->actingAs($this->gerente)->getJson(route('rh.candidatos.bandeja-cv.index'))->assertForbidden();
});

test('una baja registrada por alguien de A de una persona de B no la ve la gerente de A; la de B sí', function () {
    $jefe = clUsuario('colaborador', ['estatus' => 'activo', 'sucursal_principal_id' => $this->a->id]);
    $baja = SolicitudInterna::factory()->create([
        'user_id' => $jefe->id,
        'colaborador_id' => $jefe->colaborador_id,
        'sucursal_id' => $this->a->id,
        'objetivo_colaborador_id' => $this->enB->id,
    ]);
    $gerenteB = clUsuario('gerente_sucursal', ['estatus' => 'activo', 'sucursal_principal_id' => $this->b->id]);

    expect($this->gerente->can('view', $baja))->toBeFalse()
        ->and($gerenteB->can('view', $baja))->toBeTrue();
});

test('Gerente Regional y Dirección Comercial ven todas las sucursales operativas; la gerente solo la suya', function () {
    $regional = clUsuario('gerente_regional', ['estatus' => 'activo', 'sucursal_principal_id' => $this->a->id]);
    $comercial = clUsuario('director_comercial', ['estatus' => 'activo', 'sucursal_principal_id' => $this->a->id]);
    $organizacional = app(AlcanceOrganizacionalService::class);
    $modulo = app(AlcanceModuloService::class);

    foreach ([$regional, $comercial] as $usuario) {
        expect($organizacional->sucursalesVisiblesIds($usuario)->all())->toContain($this->a->id, $this->b->id)
            ->and($organizacional->alcanzaColaborador($usuario, $this->enB))->toBeTrue();
    }

    expect($organizacional->sucursalesVisiblesIds($this->gerente)->all())->toBe([$this->a->id])
        ->and($modulo->alcanzaColaborador($this->gerente, $this->enB, 'expedientes'))->toBeFalse();

    // Sucursal adicional asignada: entonces sí la ve.
    $conAdicional = clUsuario('gerente_sucursal', ['estatus' => 'activo', 'sucursal_principal_id' => $this->a->id]);
    $conAdicional->colaborador->sucursalesAdicionales()->attach($this->b->id);
    expect(app(AlcanceModuloService::class)->alcanzaColaborador($conAdicional, $this->enB, 'expedientes'))->toBeTrue();
});
