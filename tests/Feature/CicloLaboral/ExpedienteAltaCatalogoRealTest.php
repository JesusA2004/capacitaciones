<?php

use App\Enums\EstadoAltaColaborador;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Incorporacion\IncorporacionService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Regresión: con el catálogo REAL (DocumentTypeSeeder), "Contrato laboral"
| es obligatorio pero no aplica al alta (se genera después de aprobar el
| expediente). Exigirlo en Etapa 2 bloqueaba el ciclo para siempre.
*/

beforeEach(function () {
    $this->seed([RolesYPermisosSeeder::class, DocumentTypeSeeder::class]);
    Storage::fake('nas');
    Notification::fake();
});

test('en contratación el expediente se completa solo con los obligatorios del alta y RH lo puede aprobar', function () {
    $rh = clUsuario('rh_admin');
    $colaborador = clColaboradorEnContratacion();
    $incorporacion = app(IncorporacionService::class);
    $delAlta = DocumentType::query()->where('activo', true)->where('requerido', true)->where('aplica_alta', true)->get();

    expect(DocumentType::query()->where('clave', 'contrato')->value('aplica_alta'))->toBeFalse()
        ->and($incorporacion->tiposDocumento($colaborador)->pluck('clave'))->not->toContain('contrato');

    foreach ($delAlta as $tipo) {
        $documento = $incorporacion->subirDocumento($colaborador, $tipo, clArchivoPdf("{$tipo->clave}.pdf"), $rh->id);
        $this->actingAs($rh);
        $incorporacion->aprobarDocumento($documento, $rh, null);
    }

    $estado = app(ExpedienteService::class)->estadoDocumental($colaborador->refresh());
    expect($estado['completo'])->toBeTrue()
        ->and($estado['requeridos'])->toBe($delAlta->count());

    $incorporacion->aprobarIncorporacion($colaborador, $rh);
    expect($colaborador->refresh()->incorporacion_decision)->toBe('aprobado');
});

test('una persona activa sí debe tener su contrato firmado en el expediente', function () {
    $colaborador = Colaborador::factory()->create(['estado_alta' => EstadoAltaColaborador::Activo]);

    $estado = app(ExpedienteService::class)->estadoDocumental($colaborador);

    expect(collect($estado['documentos'])->pluck('clave'))->toContain('contrato')
        ->and(EmployeeDocument::query()->where('colaborador_id', $colaborador->id)->count())->toBe(0)
        ->and($estado['completo'])->toBeFalse();
});
