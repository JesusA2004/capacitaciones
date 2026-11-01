<?php

use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Services\Auditoria\AuditoriaService;
use App\Services\CicloLaboral\TimelineService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
| Hallazgos 10 y 11: motivos de corrección e importes de finiquito se mostraban en el
| timeline y en el historial a quien no tiene permiso de sueldo ni de finiquito.
*/

const RF_SECRETO = '25,000';

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->sucursal = Sucursal::factory()->create();
    $this->persona = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo']);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->rh = clUsuario('rh_admin');
    expect($this->gerente->can('salarios.ver'))->toBeFalse()->and($this->gerente->can('finiquitos.ver'))->toBeFalse();

    $auditoria = app(AuditoriaService::class);

    foreach (['correccion_aplicada', 'documento_regenerado', 'documento_revision_firmado', 'finiquito_entregado', 'expediente_datos_laborales_editados'] as $evento) {
        $auditoria->registrar($evento, $this->persona, $this->rh, ['colaborador_id' => $this->persona->id, 'motivo' => 'Era 20,000, debe ser '.RF_SECRETO, 'referencia' => 'Pago de $'.RF_SECRETO]);
    }

    // Otro motivo, sin relación con sueldo ni finiquito: sigue siendo visible.
    $auditoria->registrar('cierre_laboral_rechazado', $this->persona, $this->rh, ['colaborador_id' => $this->persona->id, 'motivo' => 'Faltó el formato de renuncia']);
});

test('el timeline oculta motivos e importes sin permiso, sin perder el evento', function () {
    $items = collect(app(TimelineService::class)->de($this->persona, conSueldos: false));

    expect(json_encode($items, JSON_UNESCAPED_UNICODE))->not->toContain(RF_SECRETO)
        ->and($items->firstWhere('evento', 'correccion_aplicada'))->not->toBeNull()
        ->and($items->firstWhere('evento', 'cierre_laboral_rechazado')['descripcion'])->toBe('Faltó el formato de renuncia');
});

test('quien tiene permiso sí lee el motivo', function () {
    $items = collect(app(TimelineService::class)->de($this->persona, conSueldos: true));

    expect($items->firstWhere('evento', 'correccion_aplicada')['descripcion'])->toContain(RF_SECRETO);
});

test('por omisión el timeline no entrega sueldos', function () {
    expect(json_encode(app(TimelineService::class)->de($this->persona), JSON_UNESCAPED_UNICODE))->not->toContain(RF_SECRETO);
});

test('la ficha del ciclo en web y API no filtra el motivo a un gerente', function () {
    $this->actingAs($this->gerente)->get("/rh/colaboradores/{$this->persona->id}/ciclo")->assertOk()->assertDontSee(RF_SECRETO, false);

    Sanctum::actingAs($this->gerente);
    expect($this->getJson("/api/v1/rh/colaboradores/{$this->persona->id}/ciclo")->assertOk()->getContent())->not->toContain(RF_SECRETO);
});

test('la ficha de RH conserva el motivo', function () {
    Sanctum::actingAs($this->rh);
    expect($this->getJson("/api/v1/rh/colaboradores/{$this->persona->id}/ciclo")->assertOk()->getContent())->toContain(RF_SECRETO);
});

describe('historial de la solicitud', function () {
    beforeEach(function () {
        $autor = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);
        $this->solicitud = SolicitudInterna::factory()->create(['user_id' => $autor->id, 'colaborador_id' => $autor->colaborador_id, 'tipo' => TipoSolicitudInterna::BajaColaborador]);
        $this->solicitud->historial()->create(['user_id' => $this->rh->id, 'accion' => 'finiquito_ajuste', 'comentario' => 'Aguinaldo: $20,000 → $'.RF_SECRETO.'. Revisión']);
        $this->solicitud->historial()->create(['user_id' => $this->rh->id, 'accion' => 'en_revision', 'comentario' => 'Se revisa el expediente']);
        $this->gerente->givePermissionTo(['solicitudes.ver', 'rh.solicitudes.ver', 'rh.solicitudes.detalle']);
    });

    test('web: sin permiso de finiquito no llega el importe, sí lo demás', function () {
        $this->actingAs($this->gerente)->get(route('rh.solicitudes.show', $this->solicitud))->assertOk()
            ->assertDontSee(RF_SECRETO, false)->assertSee('Se revisa el expediente', false);
    });

    test('API: sin permiso de finiquito no llega el importe, sí lo demás', function () {
        Sanctum::actingAs($this->gerente);
        $json = $this->getJson("/api/v1/rh/solicitudes/{$this->solicitud->id}")->assertOk()->getContent();

        expect($json)->not->toContain(RF_SECRETO)->toContain('Se revisa el expediente');
    });

    test('quien registró la baja tampoco lo lee por su propia vista', function () {
        Sanctum::actingAs($this->solicitud->usuario);
        expect($this->getJson("/api/v1/solicitudes/{$this->solicitud->id}")->assertOk()->getContent())->not->toContain(RF_SECRETO);
    });

    test('RH con permiso de finiquito sí lo lee', function () {
        FiniquitoCalculo::factory()->create(['solicitud_interna_id' => $this->solicitud->id, 'colaborador_id' => $this->persona->id]);
        Sanctum::actingAs($this->rh);

        expect($this->getJson("/api/v1/rh/solicitudes/{$this->solicitud->id}")->assertOk()->getContent())->toContain(RF_SECRETO);
    });
});
