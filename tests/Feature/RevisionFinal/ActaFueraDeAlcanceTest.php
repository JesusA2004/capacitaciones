<?php

use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Services\Actas\ActaPdfService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
| Hallazgo 6: FuenteActa solo exigía documentos_laborales.generar, sin alcance, y la
| vista previa entregaba el PDF de actas de otra sucursal recorriendo IDs.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->centro = Sucursal::factory()->create();
    $this->otra = Sucursal::factory()->create();
    $this->rh = clUsuario('rh_admin');
    $persona = Colaborador::factory()->create(['sucursal_principal_id' => $this->centro->id, 'estatus' => 'activo']);
    $this->actingAs($this->rh)->post(route('rh.actas.store', $persona), [
        'tipo' => 'administrativa',
        'fecha' => now()->toDateString(),
        'lugar' => 'Toluca, Estado de México',
        'hechos' => 'El arqueo de caja arrojó un faltante.',
        'circunstanciada' => [
            'incidencia' => ['tipo' => 'irregularidad_bienes', 'fecha_desde' => now()->subDay()->toDateString()],
            'jefe' => ['participa' => false],
            'testigos_hechos' => [],
            'testigos_asistencia' => [],
            'trabajador' => ['manifesto' => false],
        ],
    ])->assertSessionHasNoErrors();
    $this->acta = ActaAdministrativa::query()->latest('id')->firstOrFail();
    $this->datos = ['clave' => $this->acta->tipo->clavePlantilla(), 'campos' => []];
    $this->ajeno = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->otra->id]);
    $this->ajeno->givePermissionTo(['documentos_laborales.generar', 'actas.ver', 'actas.descargar', 'actas.gestionar']);
    app()->instance(ActaPdfService::class, Mockery::mock(ActaPdfService::class, ['vistaPrevia' => '%PDF-acta', 'valoresDocumento' => []])->shouldIgnoreMissing());
});

test('la vista previa de un acta de otra sucursal se niega en web', function () {
    $this->actingAs($this->ajeno)->postJson("/rh/documentos-edicion/acta/{$this->acta->id}/vista-previa", $this->datos)->assertForbidden();
});

test('la vista previa de un acta de otra sucursal se niega en la API', function () {
    Sanctum::actingAs($this->ajeno);
    $this->postJson("/api/v1/rh/documentos-edicion/acta/{$this->acta->id}/vista-previa", $this->datos)->assertForbidden();
});

test('tampoco se emite ni se consultan sus campos fuera de alcance', function () {
    $this->actingAs($this->ajeno);
    $this->getJson("/rh/documentos-edicion/acta/{$this->acta->id}/campos?clave={$this->datos['clave']}")->assertForbidden();
    $this->postJson("/rh/documentos-edicion/acta/{$this->acta->id}/emitir", [...$this->datos, 'motivo' => 'Intento fuera de alcance'])->assertForbidden();
});

test('RH dentro de su alcance sigue viendo la vista previa', function () {
    $this->actingAs($this->rh)->postJson("/rh/documentos-edicion/acta/{$this->acta->id}/vista-previa", $this->datos)->assertOk();
});
