<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoUsuario;
use App\Enums\ResultadoEvaluacion;
use App\Enums\TipoBaja;
use App\Models\Baja;
use App\Models\BajaBitacora;
use App\Models\BajaDocumentoEdicion;
use App\Models\CausalRotacion;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/apoyo.php';

beforeEach(function () {
    clRelojMediodiaMexico();
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
});

/*
| Hallazgo 15: la rectificación de un documento de baja no dejaba edición ni bitácora, y
| un generar posterior la cancelaba sin lo corregido.
*/
function esBajaConvenio(object $prueba): Baja
{
    $estructura = clEstructura();
    $persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => now()->subYears(3)->toDateString(),
        'sueldo_mensual' => 12000,
    ]);
    $causal = CausalRotacion::query()->where('clave', 'NR-02')->firstOrFail();
    $prueba->actingAs($prueba->rh)->post(route('rh.bajas.store'), [
        'colaborador_id' => $persona->id,
        'forma_salida' => $causal->forma()->value,
        'causal_rotacion_id' => $causal->id,
        'fecha_baja' => now()->subDay()->toDateString(),
        'recontratable' => 'si',
        'comentarios' => 'Se documentó el caso con el reporte de la sucursal.',
    ])->assertRedirect();

    return Baja::query()->where('colaborador_id', $persona->id)->latest('id')->firstOrFail();
}

test('rectificar un documento de baja deja edición y bitácora, y generar después conserva lo corregido', function () {
    $baja = esBajaConvenio($this);
    $url = route('rh.documentos-edicion.emitir', ['tipo' => 'salida', 'id' => $baja->id]);
    $this->actingAs($this->rh)->postJson($url, [])->assertCreated();
    $firmado = rfFirmar(GeneratedDocument::query()->where('clave_plantilla', 'convenio_terminacion')->sole());

    $this->actingAs($this->rh)->postJson($url, ['campos' => ['cuerpo' => 'Texto rectificado por RH.'], 'motivo' => 'La redacción estaba mal.', 'rectificar' => true])->assertCreated();
    $rectificacion = GeneratedDocument::query()->where('clave_plantilla', 'convenio_terminacion')->latest('id')->firstOrFail();

    expect($rectificacion->revision_de_id)->toBe($firmado->id)
        ->and(BajaDocumentoEdicion::query()->where('baja_id', $baja->id)->where('generated_document_id', $rectificacion->id)->exists())->toBeTrue()
        ->and(BajaBitacora::query()->where('baja_id', $baja->id)->where('accion', 'corrigio_documento')->exists())->toBeTrue();

    // Sin cambios: no se cancela la rectificación ni se pierde el texto.
    $this->actingAs($this->rh)->postJson($url, [])->assertOk();
    expect($rectificacion->refresh()->estado_flujo?->value)->not->toBe('cancelado')
        ->and($firmado->refresh()->estado_flujo?->value)->toBe('firmado_fisicamente');
});

/*
| Hallazgo 16: el editor buscaba la versión en la evaluación y el documento vive en el
| cierre: respondía «No se pudo emitir» y el reintento decía «ya generado».
*/
test('emitir la evaluación desde el editor responde con el documento y repetir no falla', function () {
    ['colaborador' => $colaborador, 'contrato' => $contrato] = rfContrato();
    clPlantilla('evaluacion_capacitacion');
    $evaluacion = EvaluacionPeriodoPrueba::factory()->create(['colaborador_id' => $colaborador->id, 'contrato_laboral_id' => $contrato->id, 'estado' => EstadoEvaluacionPrueba::Autorizada, 'resultado' => ResultadoEvaluacion::NoAprobado]);
    $cierre = CierreLaboral::query()->create([
        'colaborador_id' => $colaborador->id,
        'tipo_baja' => TipoBaja::FinContrato,
        'motivo' => 'No renovación',
        'fecha_efectiva' => now()->toDateString(),
        'estado' => EstadoCierreLaboral::Iniciado,
        'iniciado_por' => $this->rh->id,
        'autorizado_rh_en' => now(),
        'evaluacion_id' => $evaluacion->id,
    ]);
    $url = route('rh.documentos-edicion.emitir', ['tipo' => 'evaluacion', 'id' => $evaluacion->id]);
    $datos = ['clave' => 'evaluacion_capacitacion'];

    $id = $this->actingAs($this->rh)->postJson($url, $datos)->assertCreated()->json('documento_id');
    $this->actingAs($this->rh)->postJson($url, $datos)->assertOk()->assertJsonPath('creado', false)->assertJsonPath('documento_id', $id);

    expect(GeneratedDocument::query()->findOrFail($id)->documentable_id)->toBe($cierre->id);
});
