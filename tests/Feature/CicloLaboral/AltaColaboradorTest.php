<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoUsuario;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\MovimientoLaboral;
use App\Models\TareaRh;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->estructura = clEstructura();
    $this->jefe = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id]);
});

function clDatosAlta(array $estructura, array $extra = []): array
{
    return [
        'name' => 'Ana',
        'apellidos' => 'Martínez Ruiz',
        'email' => 'ana.martinez@mrlana.test',
        'curp' => 'MARA900101MDFRRN09',
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_id' => $estructura['puesto']->id,
        'sueldo_mensual' => 15000,
        'fecha_ingreso' => now()->toDateString(),
        'tipo_contratacion' => 'periodo_prueba',
        'fecha_fin_contrato' => now()->addDays(30)->toDateString(),
        ...$extra,
    ];
}

test('rh da de alta un colaborador con estructura, contrato, expediente, cuenta y estado de alta', function () {
    Sanctum::actingAs($this->rh);
    DocumentType::factory()->create(['requerido' => true, 'activo' => true]);

    $respuesta = $this->postJson('/api/v1/rh/colaboradores', clDatosAlta($this->estructura, [
        'jefe_id' => $this->jefe->id,
        'gerente_id' => $this->jefe->id,
    ]))->assertCreated();

    $colaborador = Colaborador::query()->findOrFail($respuesta->json('colaborador_id'));

    expect($colaborador->estatus)->toBe(EstadoUsuario::EnIncorporacion)
        ->and($colaborador->estado_alta)->toBe(EstadoAltaColaborador::PendienteDocumentos)
        ->and($colaborador->jefe_id)->toBe($this->jefe->id)
        ->and($colaborador->gerente_id)->toBe($this->jefe->id)
        ->and((float) $colaborador->sueldo_mensual)->toBe(15000.0)
        ->and($colaborador->periodo_prueba_fin?->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and($colaborador->numero_empleado)->not->toBeNull()
        ->and($colaborador->expediente_storage_path)->toContain('expedientes/MR LANA/Cuernavaca/');

    $contrato = ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->firstOrFail();
    expect($contrato->tipo->value)->toBe('periodo_prueba')
        ->and((float) $contrato->sueldo_mensual)->toBe(15000.0);

    $cuenta = User::query()->where('colaborador_id', $colaborador->id)->firstOrFail();
    expect($cuenta->hasRole('colaborador'))->toBeTrue();

    expect(MovimientoLaboral::query()->where('colaborador_id', $colaborador->id)->where('tipo_movimiento', 'alta')->exists())->toBeTrue();

    // Sin plantillas cargadas: los documentos contractuales quedan como pendiente explícito (no se inventa un texto).
    expect(TareaRh::query()->where('tipo', TipoTarea::ContratoPendiente->value)->count())->toBeGreaterThan(0)
        ->and($respuesta->json('data.documentos_contractuales_sin_plantilla'))->toContain('contrato_periodo_prueba');

    expect(TareaRh::query()->where('tipo', TipoTarea::ExpedienteIncompleto->value)->where('asignado_user_id', $cuenta->id)->exists())->toBeTrue();
});

test('el alta recorre pendiente_documentos → revisión → firma → activación hasta quedar activa', function () {
    Sanctum::actingAs($this->rh);
    $tipo = DocumentType::factory()->create(['requerido' => true, 'activo' => true]);
    clPlantilla('contrato_periodo_prueba', ['requiere_firma_digital' => true]);

    $id = $this->postJson('/api/v1/rh/colaboradores', clDatosAlta($this->estructura))->assertCreated()->json('colaborador_id');
    $colaborador = Colaborador::query()->findOrFail($id);
    $cuenta = User::query()->where('colaborador_id', $id)->firstOrFail();

    // El colaborador carga su documento obligatorio: pasa a revisión.
    $documento = EmployeeDocument::factory()->create(['colaborador_id' => $id, 'document_type_id' => $tipo->id, 'status' => EstadoDocumento::EnRevision->value]);
    expect($colaborador->refresh()->estado_alta)->toBe(EstadoAltaColaborador::DocumentacionEnRevision);

    // No se puede activar con documentos sin aprobar.
    $this->postJson("/api/v1/rh/colaboradores/{$id}/activar")->assertUnprocessable();

    // RH aprueba: el contrato ya estaba generado y pendiente de firma.
    $documento->update(['status' => EstadoDocumento::Aprobado->value]);
    expect($colaborador->refresh()->estado_alta)->toBe(EstadoAltaColaborador::PendienteFirma);

    $contrato = GeneratedDocument::query()->where('colaborador_id', $id)->where('clave_plantilla', 'contrato_periodo_prueba')->firstOrFail();
    expect($contrato->estado_flujo)->toBe(EstadoFlujoDocumento::PendienteFirmaColaborador);

    // El colaborador firma digitalmente desde la app.
    Sanctum::actingAs($cuenta);
    $this->postJson("/api/v1/colaborador/documentos-laborales/{$contrato->id}/firmar", ['acepto' => true])->assertOk();
    expect($colaborador->refresh()->estado_alta)->toBe(EstadoAltaColaborador::PendienteActivacion);

    // Un colaborador no puede activarse a sí mismo.
    $this->postJson("/api/v1/rh/colaboradores/{$id}/activar")->assertForbidden();

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/colaboradores/{$id}/activar")->assertOk()->assertJsonPath('data.estado_alta', 'activo');

    expect($colaborador->refresh()->estatus)->toBe(EstadoUsuario::Activo)
        ->and($colaborador->activado_en)->not->toBeNull();
});

test('no se duplica una persona: la misma CURP no puede darse de alta dos veces', function () {
    Sanctum::actingAs($this->rh);
    Colaborador::factory()->create(['curp' => 'MARA900101MDFRRN09']);

    $this->postJson('/api/v1/rh/colaboradores', clDatosAlta($this->estructura))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('curp');
});

test('el alta exige fecha de vencimiento cuando el contrato no es indeterminado', function () {
    Sanctum::actingAs($this->rh);

    $this->postJson('/api/v1/rh/colaboradores', clDatosAlta($this->estructura, ['fecha_fin_contrato' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fecha_fin_contrato');
});

test('un colaborador sin permiso no puede dar de alta personal', function () {
    Sanctum::actingAs(clUsuario('colaborador'));

    $this->postJson('/api/v1/rh/colaboradores', clDatosAlta($this->estructura))->assertForbidden();
});
