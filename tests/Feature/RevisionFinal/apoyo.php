<?php

use App\Enums\EstadoFlujoDocumento;
use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Models\User;
use App\Services\DocumentosMaestros\DocumentoProcesoService;

/**
 * Contrato vigente con plantilla heredada (no depende de originales de Jurídico).
 *
 * @return array{colaborador: Colaborador, contrato: ContratoLaboral}
 */
function rfContrato(array $datosColaborador = []): array
{
    $estructura = clEstructura();
    $puesto = Puesto::factory()->create(['grupo_documental' => 'gestor', 'meses_periodo_prueba' => 3]);
    DocumentTemplate::query()->where('clave', 'contrato_capacitacion')->exists() || clPlantilla('contrato_capacitacion', ['requiere_impresion' => true, 'requiere_firma_fisica' => true]);
    $colaborador = clExpedienteCompleto(Colaborador::factory()->create([
        'estatus' => 'activo',
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_id' => $puesto->id,
        'fecha_ingreso' => now()->toDateString(),
        'sueldo_mensual' => 12000,
        'domicilio' => 'Calle Uno 10',
        ...$datosColaborador,
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

    return ['colaborador' => $colaborador, 'contrato' => $contrato];
}

function rfEmitir(ContratoLaboral $contrato, User $actor, ?string $motivo = null): GeneratedDocument
{
    return app(DocumentoProcesoService::class)->generar('alta', $contrato->refresh(), 'contrato_capacitacion', $actor, regenerar: $motivo !== null, motivoRevision: $motivo);
}

function rfFirmar(GeneratedDocument $documento): GeneratedDocument
{
    $documento->update(['estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente->value, 'requiere_firma_fisica' => true]);

    return $documento->refresh();
}
