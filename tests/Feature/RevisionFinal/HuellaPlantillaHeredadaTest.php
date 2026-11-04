<?php

use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\Puesto;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;

/*
| Hallazgo 9 de la revisión final: la huella de una plantilla heredada omitía el
| expediente, así que cambiar el domicilio respondía «Sin cambios». Ya lo corrigió
| 599e9043; esta prueba lo fija con el dato que citaba el hallazgo.
*/

test('cambiar el domicilio cambia la huella de una plantilla heredada y emite otra versión', function () {
    // Cubre el contrato, no el requisito previo de Solicitud de Empleo y Estudio
    // Socioeconómico (ver Incorporacion/SolicitudEmpleoTest).
    config(['incorporacion.documentos_ingreso.exigir_antes_de_contrato' => false]);
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $rh = clUsuario('rh_admin');
    $estructura = clEstructura();
    $puesto = Puesto::factory()->create(['grupo_documental' => 'gestor', 'meses_periodo_prueba' => 3]);
    clPlantilla('contrato_capacitacion');
    $colaborador = clExpedienteCompleto(Colaborador::factory()->create([
        'estatus' => 'en_incorporacion',
        'estado_alta' => 'pendiente_contrato',
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_id' => $puesto->id,
        'fecha_ingreso' => now()->toDateString(),
        'sueldo_mensual' => 12000,
        'domicilio' => 'Calle Uno 10',
    ]));
    $contrato = ContratoLaboral::query()->create([
        'colaborador_id' => $colaborador->id,
        'tipo' => TipoContratacion::CapacitacionInicial,
        'fecha_inicio' => now()->toDateString(),
        'fecha_fin' => now()->addMonths(2)->toDateString(),
        'estado' => 'vigente',
        'sueldo_mensual' => 12000,
        'puesto_id' => $puesto->id,
        'sucursal_id' => $estructura['sucursal']->id,
    ]);
    $generar = fn () => app(DocumentoProcesoService::class)->generar('alta', $contrato->refresh(), 'contrato_capacitacion', $rh, [], [], true, false, 'Se corrigió el domicilio');

    $v1 = $generar();
    expect($generar()->id)->toBe($v1->id);

    $colaborador->update(['domicilio' => 'Calle Dos 20']);

    expect($generar()->id)->not->toBe($v1->id);
});
