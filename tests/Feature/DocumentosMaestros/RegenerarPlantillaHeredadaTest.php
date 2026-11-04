<?php

use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Services\Contratos\RegularizacionContratosService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Flujos\ConstructorEnlaces;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;

/*
| Con plantilla heredada la huella no incluía los datos del colaborador: corregir
| un apellido y regenerar devolvía el PDF viejo mientras la UI anunciaba una
| «nueva versión». Y quien sigue en contratación recibía un botón que siempre
| terminaba en «Omitido: el colaborador no está activo».
*/

beforeEach(function () {
    // Estas pruebas cubren el contrato, no el requisito previo de Solicitud de
    // Empleo y Estudio Socioeconómico (ver Incorporacion/SolicitudEmpleoTest).
    config(['incorporacion.documentos_ingreso.exigir_antes_de_contrato' => false]);
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $estructura = clEstructura();
    $puesto = Puesto::factory()->create(['grupo_documental' => 'gestor', 'meses_periodo_prueba' => 3]);
    clPlantilla('contrato_capacitacion');
    $this->colaborador = clExpedienteCompleto(Colaborador::factory()->create([
        'name' => 'Valeria',
        'apellidos' => 'Quintero',
        'estatus' => 'en_incorporacion',
        'estado_alta' => 'pendiente_contrato',
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_id' => $puesto->id,
        'fecha_ingreso' => now()->toDateString(),
        'sueldo_mensual' => 12000,
    ]));
    $contrato = ContratoLaboral::query()->create([
        'colaborador_id' => $this->colaborador->id,
        'tipo' => TipoContratacion::CapacitacionInicial,
        'fecha_inicio' => now()->toDateString(),
        'fecha_fin' => now()->addMonths(2)->toDateString(),
        'estado' => 'vigente',
        'sueldo_mensual' => 12000,
        'puesto_id' => $puesto->id,
        'sucursal_id' => $estructura['sucursal']->id,
    ]);
    $this->generar = fn (): GeneratedDocument => app(DocumentoProcesoService::class)->generar(
        'alta', $contrato->refresh(), 'contrato_capacitacion', $this->rh, [], [], true, false, 'Se corrigió el apellido',
    );
});

test('corregir un dato que la plantilla imprime genera una versión nueva; sin cambios, no', function () {
    $v1 = ($this->generar)();
    expect($v1->payload['fecha_inicio_contrato'] ?? '')->toBe(now()->format('d/m/Y'));

    $sinCambios = ($this->generar)();
    expect($sinCambios->id)->toBe($v1->id)->and($sinCambios->wasRecentlyCreated)->toBeFalse();

    $this->colaborador->update(['apellidos' => 'Quintana']);
    $v2 = ($this->generar)();

    expect($v2->id)->not->toBe($v1->id)
        ->and($v2->wasRecentlyCreated)->toBeTrue()
        ->and($v1->refresh()->motivo_cancelacion)->not->toBeNull();
});

test('quien sigue en contratación no recibe el botón de regularizar y su enlace lleva al ciclo', function () {
    $plan = app(RegularizacionContratosService::class)->plan($this->colaborador);
    expect($plan['estado'])->toBe('por_generar')->and($plan['generable'])->toBeFalse();

    $enlace = app(ConstructorEnlaces::class)->generarContratos($this->rh, $this->colaborador);
    expect($enlace)->toContain("/rh/colaboradores/{$this->colaborador->id}/ciclo");

    $this->colaborador->update(['estatus' => 'activo', 'estado_alta' => null]);
    expect(app(ConstructorEnlaces::class)->generarContratos($this->rh, $this->colaborador->refresh()))
        ->toContain('tab=contratacion');
});
