<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoDocumento;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Expedientes\ExpedienteService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
 * RH decide sobre cualquier documento «por revisar» (cargado, en revisión y
 * cambio solicitado), incluidos los opcionales; aprobar el último obligatorio
 * deja el expediente al 100 % y habilita «Documentos de contratación».
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
});

function rdDocumento(array $atributos): EmployeeDocument
{
    return EmployeeDocument::factory()->create([
        'colaborador_id' => Colaborador::factory()->create()->id,
        ...$atributos,
    ]);
}

test('rh aprueba tal cual un documento con cambio solicitado y eso cierra la solicitud', function () {
    $documento = rdDocumento(['status' => EstadoDocumento::CambioSolicitado->value, 'change_requested_at' => now()]);

    $this->actingAs($this->rh)
        ->post(route('rh.documentos.aprobar', $documento))
        ->assertSessionHasNoErrors();

    expect($documento->fresh()->status)->toBe(EstadoDocumento::Aprobado)
        ->and($documento->fresh()->reviewed_by)->toBe($this->rh->id);
});

test('rh también puede autorizar el cambio solicitado desde la web', function () {
    $documento = rdDocumento(['status' => EstadoDocumento::CambioSolicitado->value]);

    $this->actingAs($this->rh)
        ->post(route('rh.documentos.autorizar-cambio', $documento))
        ->assertSessionHasNoErrors();

    expect($documento->fresh()->status)->toBe(EstadoDocumento::CambioAutorizado);
});

test('rh rechaza o pide corrección de un documento con cambio solicitado', function () {
    $rechazar = rdDocumento(['status' => EstadoDocumento::CambioSolicitado->value]);
    $corregir = rdDocumento(['status' => EstadoDocumento::Cargado->value]);

    $this->actingAs($this->rh)->post(route('rh.documentos.rechazar', $rechazar), ['rejection_reason' => 'Ilegible.'])->assertSessionHasNoErrors();
    $this->actingAs($this->rh)->post(route('rh.documentos.solicitar-correccion', $corregir), ['comments' => 'Falta la reversa.'])->assertSessionHasNoErrors();

    expect($rechazar->fresh()->status)->toBe(EstadoDocumento::Rechazado)
        ->and($corregir->fresh()->status)->toBe(EstadoDocumento::RequiereCorreccion);
});

test('un documento ya aprobado o rechazado no admite otra decisión', function () {
    $aprobado = rdDocumento(['status' => EstadoDocumento::Aprobado->value]);
    $rechazado = rdDocumento(['status' => EstadoDocumento::Rechazado->value]);

    $this->actingAs($this->rh)->post(route('rh.documentos.rechazar', $aprobado), ['rejection_reason' => 'x'])->assertSessionHasErrors('documento');
    $this->actingAs($this->rh)->post(route('rh.documentos.aprobar', $rechazado))->assertSessionHasErrors('documento');

    expect($aprobado->fresh()->status)->toBe(EstadoDocumento::Aprobado)
        ->and($rechazado->fresh()->status)->toBe(EstadoDocumento::Rechazado);
});

test('sin permiso de revisión no se aprueba ni se autoriza un cambio', function () {
    $documento = rdDocumento(['status' => EstadoDocumento::CambioSolicitado->value]);
    $colaborador = clUsuario('colaborador');

    $this->actingAs($colaborador)->post(route('rh.documentos.aprobar', $documento))->assertForbidden();
    $this->actingAs($colaborador)->post(route('rh.documentos.autorizar-cambio', $documento))->assertForbidden();

    // Nadie revisa su propio documento, ni siendo RH.
    $propio = rdDocumento(['colaborador_id' => $this->rh->colaborador_id, 'status' => EstadoDocumento::Cargado->value]);
    $this->actingAs($this->rh)->post(route('rh.documentos.aprobar', $propio))->assertForbidden();

    expect($documento->fresh()->status)->toBe(EstadoDocumento::CambioSolicitado)
        ->and($propio->fresh()->status)->toBe(EstadoDocumento::Cargado);
});

test('la api móvil de rh aprueba un documento con cambio solicitado con la misma regla', function () {
    $documento = rdDocumento(['status' => EstadoDocumento::CambioSolicitado->value]);
    Sanctum::actingAs($this->rh);

    $this->postJson("/api/v1/rh/expedientes/{$documento->colaborador_id}/documentos/{$documento->id}/aprobar")->assertOk();
    expect($documento->fresh()->status)->toBe(EstadoDocumento::Aprobado);

    $this->postJson("/api/v1/rh/expedientes/{$documento->colaborador_id}/documentos/{$documento->id}/aprobar")->assertUnprocessable();
});

test('aprobar el último obligatorio deja el expediente al 100 % y habilita contratación; el CV opcional se aprueba sin contar', function () {
    Sanctum::actingAs($this->rh);
    $estructura = clEstructura();
    $solicitud = DocumentType::factory()->create(['nombre' => 'Solicitud de empleo', 'requerido' => true, 'activo' => true]);
    $ine = DocumentType::factory()->create(['nombre' => 'INE', 'requerido' => true, 'activo' => true]);
    $cv = DocumentType::factory()->create(['nombre' => 'Currículum (CV)', 'requerido' => false, 'activo' => true]);

    $id = $this->postJson('/api/v1/rh/colaboradores', [
        'name' => 'Luis Ángel',
        'apellidos' => 'Méndez Díaz',
        'email' => 'luis.mendez@mrlana.test',
        'curp' => 'MEDL900101HMSNZS09',
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_id' => $estructura['puesto']->id,
        'sueldo_mensual' => 12000,
        'fecha_ingreso' => now()->toDateString(),
        'tipo_contratacion' => 'periodo_prueba',
        'fecha_fin_contrato' => now()->addDays(30)->toDateString(),
    ])->assertCreated()->json('colaborador_id');
    $colaborador = Colaborador::query()->findOrFail($id);

    EmployeeDocument::factory()->create(['colaborador_id' => $id, 'document_type_id' => $ine->id, 'status' => EstadoDocumento::Aprobado->value]);
    $documentoSolicitud = EmployeeDocument::factory()->create(['colaborador_id' => $id, 'document_type_id' => $solicitud->id, 'status' => EstadoDocumento::CambioSolicitado->value]);
    $documentoCv = EmployeeDocument::factory()->create(['colaborador_id' => $id, 'document_type_id' => $cv->id, 'status' => EstadoDocumento::Cargado->value]);

    $contrato = ContratoLaboral::query()->where('colaborador_id', $id)->firstOrFail();
    $expediente = app(ExpedienteService::class);
    $procesos = app(DocumentoProcesoService::class);

    expect($expediente->estadoDocumental($colaborador)['porcentaje'])->toBe(50.0)
        ->and($procesos->seccionAlta($contrato, $this->rh)['bloqueo'])->toContain('1 de 2');

    // La ficha web ofrece «Aprobar» en ambos (y «Autorizar cambio» en la solicitud).
    $this->actingAs($this->rh)->get(route('rh.expedientes.show', $id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('documentosRequeridos', fn ($items) => collect($items)->firstWhere('tipo.id', $solicitud->id)['documento']['acciones'] === ['aprobar' => true, 'rechazar' => true, 'corregir' => true, 'autorizar_cambio' => true, 'descargar' => true]
                && collect($items)->firstWhere('tipo.id', $cv->id)['documento']['acciones']['aprobar'] === true
                && collect($items)->firstWhere('tipo.id', $ine->id)['documento']['acciones']['aprobar'] === false));

    // El CV opcional se aprueba, pero no mueve el avance.
    $this->actingAs($this->rh)->post(route('rh.documentos.aprobar', $documentoCv))->assertSessionHasNoErrors();
    expect($documentoCv->fresh()->status)->toBe(EstadoDocumento::Aprobado)
        ->and($expediente->estadoDocumental($colaborador->refresh())['porcentaje'])->toBe(50.0);

    // Último obligatorio (venía con cambio solicitado): 100 % y paquete habilitado al instante.
    $this->actingAs($this->rh)->post(route('rh.documentos.aprobar', $documentoSolicitud))->assertSessionHasNoErrors();

    $colaborador->refresh();
    expect($expediente->estadoDocumental($colaborador)['porcentaje'])->toBe(100.0)
        ->and($expediente->estadoDocumental($colaborador)['completo'])->toBeTrue()
        ->and($colaborador->estado_alta)->toBe(EstadoAltaColaborador::PendienteContrato)
        ->and($procesos->seccionAlta($contrato->refresh(), $this->rh)['bloqueo'])->toBeNull();
});
