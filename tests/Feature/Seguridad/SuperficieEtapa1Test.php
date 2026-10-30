<?php

use App\Enums\EstadoUsuario;
use App\Enums\FormaSalida;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\Sucursal;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/*
| Encargo Etapa 1 §1.3 y §7: recorre con URL directa la superficie nueva. Un
| gerente, de su sucursal o de otra, no llega a importes ni a documentos de
| salida; Sistemas tampoco, porque traen sueldos.
*/

beforeEach(function () {
    clRelojMediodiaMexico();
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $estructura = clEstructura();
    $this->sucursal = $estructura['sucursal'];
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->gerenteAjeno = clUsuario('gerente_sucursal', ['sucursal_principal_id' => Sucursal::factory()->create()->id]);
    $this->sistemas = clUsuario('sistemas', ['sucursal_principal_id' => $this->sucursal->id]);

    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursal->id,
        'puesto_id' => $estructura['puesto']->id,
        'jefe_id' => $this->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => now()->subYears(3)->toDateString(),
        'sueldo_mensual' => 17345.67,
    ]);

    $causal = CausalRotacion::query()->where('clave', 'RV-01')->firstOrFail();
    $this->actingAs($this->rh)->post(route('rh.bajas.store'), [
        'colaborador_id' => $this->persona->id,
        'forma_salida' => FormaSalida::RenunciaVoluntaria->value,
        'causal_rotacion_id' => $causal->id,
        'fecha_baja' => now()->subDay()->toDateString(),
        'recontratable' => 'si',
        'comentarios' => 'Se documentó el caso con el reporte de la sucursal.',
    ])->assertRedirect();

    $this->baja = Baja::query()->where('colaborador_id', $this->persona->id)->latest('id')->firstOrFail();
    $this->finiquito = FiniquitoCalculo::query()->where('solicitud_interna_id', $this->baja->cierre->solicitud_interna_id)->firstOrFail();
});

test('el gerente no abre finiquitos, documentos de salida ni el contrato por URL directa', function () {
    $this->actingAs($this->gerente);

    foreach ([
        route('rh.bajas.finiquitos.index'),
        route('rh.bajas.finiquitos.recibo', $this->finiquito),
        route('rh.bajas.salida.vista-previa', $this->baja),
        route('rh.bajas.salida.campos', $this->baja),
        route('rh.bajas.salida.descargar', $this->baja),
        route('rh.bajas.previa.finiquito', ['colaborador_id' => $this->persona->id]),
        route('rh.contratos.vista-previa', [$this->persona, 'contrato_capacitacion']),
        route('rh.documentos-proceso.vista-previa', ['colaborador', $this->persona->id, 'clave' => 'contrato_capacitacion']),
    ] as $url) {
        expect($this->get($url)->getStatusCode())->toBeIn([403, 422], $url);
    }

    $this->post(route('rh.bajas.finiquitos.vista-previa', $this->finiquito), ['conceptos' => []])->assertForbidden();
});

test('el expediente de la baja no le muestra importes al gerente', function () {
    $respuesta = $this->actingAs($this->gerente)->get(route('rh.bajas.show', $this->baja))->assertOk();

    expect($respuesta->getContent())->not->toContain('17345');
    $respuesta->assertInertia(fn ($page) => $page->where('baja.finiquito', null));
});

test('la API de bajas tampoco le entrega importes al gerente', function () {
    Sanctum::actingAs($this->gerente);

    $respuesta = $this->getJson('/api/v1/rh/bajas/'.$this->baja->id)->assertOk();

    expect($respuesta->getContent())->not->toContain('17345');
});

test('un gerente de otra sucursal no alcanza la baja, su documento ni la incapacidad por URL', function () {
    $this->actingAs($this->gerenteAjeno);

    $this->get(route('rh.bajas.show', $this->baja))->assertForbidden();
    $this->get(route('rh.incapacidades.historial', $this->persona))->assertForbidden();
    $this->get(route('rh.expedientes.show', $this->persona))->assertForbidden();
});

test('el filtro de rotación no le abre a un gerente otra sucursal', function () {
    $ajena = Sucursal::factory()->create(['nombre' => 'Sucursal ajena de prueba']);

    $this->actingAs($this->gerente)
        ->get(route('rh.bajas.rotacion', ['sucursal_id' => $ajena->id, 'vista' => 'por_sucursal']))
        ->assertOk()
        ->assertDontSee('Sucursal ajena de prueba');
});

test('Sistemas ya no consulta contratos, recibos ni finiquitos', function () {
    expect($this->sistemas->can('finiquitos.ver'))->toBeFalse()
        ->and($this->sistemas->can('contratos.ver_documento'))->toBeFalse()
        ->and($this->sistemas->can('nomina.recibos.ver'))->toBeFalse()
        ->and($this->sistemas->can('expedientes.ver'))->toBeTrue();

    $this->actingAs($this->sistemas)->get(route('rh.bajas.finiquitos.index'))->assertForbidden();
    $this->get(route('rh.bajas.finiquitos.recibo', $this->finiquito))->assertForbidden();
});

test('RH conserva el flujo: finiquito, salida y recibo', function () {
    $this->actingAs($this->rh);

    $this->get(route('rh.bajas.finiquitos.index'))->assertOk();
    // Sin maestro cargado en el NAS de prueba puede responder validación, nunca 403.
    expect($this->get(route('rh.bajas.finiquitos.recibo', $this->finiquito))->getStatusCode())->not->toBe(403)
        ->and($this->get(route('rh.bajas.salida.campos', $this->baja))->getStatusCode())->not->toBe(403);
});

test('la migración retira los importes a Sistemas y no toca a RH', function () {
    $sistemas = Role::findByName('sistemas');
    $sistemas->givePermissionTo('finiquitos.ver');

    $migracion = require database_path('migrations/2026_11_03_100000_sistemas_sin_documentos_con_importes.php');
    $migracion->up();
    $migracion->up();

    expect($sistemas->fresh()->hasPermissionTo('finiquitos.ver'))->toBeFalse()
        ->and(Role::findByName('rh_admin')->hasPermissionTo('finiquitos.ver'))->toBeTrue();
});
