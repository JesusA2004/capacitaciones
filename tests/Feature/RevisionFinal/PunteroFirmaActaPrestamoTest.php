<?php

use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\Prestamo;
use App\Models\Sucursal;
use App\Services\Actas\ActaService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Nomina\PrestamoAutorizacionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/apoyo.php';

/*
| Hallazgo 13: como en el contrato (#2), rectificar mueve el puntero a una versión sin
| firmar. El acta firmada no debe reabrir edición ni el préstamo perder su firma.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
});

function pfActaRectificada(object $prueba): ActaAdministrativa
{
    $persona = Colaborador::factory()->create(['sucursal_principal_id' => Sucursal::factory()->create()->id, 'estatus' => 'activo']);
    $prueba->actingAs($prueba->rh)->post(route('rh.actas.store', $persona), [
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
    $acta = ActaAdministrativa::query()->latest('id')->firstOrFail();
    $servicio = app(ActaService::class);
    $servicio->generarDocumento($acta, $prueba->rh);
    rfFirmar($acta->refresh()->documento);
    $acta->update(['hechos' => 'Hechos corregidos después de firmar.']);

    return $acta->refresh();
}

test('un acta firmada no reabre su edición tras rectificar el formato', function () {
    $acta = pfActaRectificada($this);
    $firmado = $acta->generated_document_id;
    app(DocumentoProcesoService::class)->generar('acta', $acta, $acta->tipo->clavePlantilla(), $this->rh, revision: true, motivoRevision: 'Se corrigió el nombre');
    $acta->refresh();

    expect($acta->generated_document_id)->not->toBe($firmado)
        ->and(app(ActaService::class)->permisosDe($this->rh, $acta)['corregir_generada'] ?? false)->toBeFalse();

    expect(fn () => app(ActaService::class)->actualizar($acta, ['hechos' => 'Otros hechos capturados'], $this->rh, 'Motivo suficiente'))
        ->toThrow(ValidationException::class, 'firmada');
});

test('un préstamo firmado se puede resguardar aunque su contrato tenga una rectificación sin firmar', function () {
    ['colaborador' => $colaborador] = rfContrato();
    $prestamo = Prestamo::factory()->create(['colaborador_id' => $colaborador->id]);
    $crear = fn (string $clave, array $datos) => GeneratedDocument::factory()->create(['colaborador_id' => $colaborador->id, 'documentable_type' => $prestamo->getMorphClass(), 'documentable_id' => $prestamo->id, 'clave_plantilla' => $clave, 'requiere_firma_fisica' => true, ...$datos]);
    $firmado = $crear('prestamo_contrato', ['estado_flujo' => 'firmado_fisicamente']);
    $rectificado = $crear('prestamo_contrato', ['estado_flujo' => 'pendiente_firma_fisica', 'revision_de_id' => $firmado->id]);
    $pagare = $crear('prestamo_pagare', ['estado_flujo' => 'firmado_fisicamente']);
    $prestamo->update(['contrato_documento_id' => $rectificado->id, 'pagare_documento_id' => $pagare->id]);

    expect(app(PrestamoAutorizacionService::class)->resguardar($prestamo->refresh(), $this->rh)->resguardado_en)->not->toBeNull();
});

test('sin ninguna versión firmada el préstamo no se resguarda', function () {
    ['colaborador' => $colaborador] = rfContrato();
    $prestamo = Prestamo::factory()->create(['colaborador_id' => $colaborador->id]);
    $crear = fn (string $clave, string $estado) => GeneratedDocument::factory()->create(['colaborador_id' => $colaborador->id, 'documentable_type' => $prestamo->getMorphClass(), 'documentable_id' => $prestamo->id, 'clave_plantilla' => $clave, 'requiere_firma_fisica' => true, 'estado_flujo' => $estado]);
    $prestamo->update(['contrato_documento_id' => $crear('prestamo_contrato', 'pendiente_firma_fisica')->id, 'pagare_documento_id' => $crear('prestamo_pagare', 'firmado_fisicamente')->id]);

    expect(fn () => app(PrestamoAutorizacionService::class)->resguardar($prestamo->refresh(), $this->rh))->toThrow(ValidationException::class);
});
