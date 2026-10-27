<?php

use App\Enums\EstadoDocumento;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Expedientes\DocumentosPendientesService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Incorporacion\IncorporacionService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/*
| Documentos OPCIONALES del checklist (pagaré, carta de antecedentes no
| penales, licencia de conducir): se ven en el checklist del expediente con la
| etiqueta «Opcional», pero nunca cuentan para el avance, los faltantes, el
| bloqueo de la app ni la carta compromiso (todo eso lee solo `requerido`).
*/

const CLAVES_OPCIONALES = ['pagare_expediente', 'carta_antecedentes_no_penales', 'licencia_conducir'];

beforeEach(function () {
    $this->seed([RolesYPermisosSeeder::class, DocumentTypeSeeder::class]);
    Storage::fake(config('expedientes.disk'));
});

test('el catálogo trae los 3 documentos como opcionales del checklist y el seeder es idempotente', function () {
    $this->seed(DocumentTypeSeeder::class);

    $opcionales = DocumentType::query()->whereIn('clave', CLAVES_OPCIONALES)->get()->keyBy('clave');

    expect($opcionales)->toHaveCount(3)
        ->and($opcionales['pagare_expediente']->nombre)->toBe('Pagaré')
        ->and($opcionales['carta_antecedentes_no_penales']->nombre)->toBe('Carta de antecedentes no penales')
        ->and($opcionales['licencia_conducir']->nombre)->toBe('Licencia de conducir');

    foreach ($opcionales as $tipo) {
        expect($tipo->requerido)->toBeFalse()
            ->and($tipo->checklist_opcional)->toBeTrue()
            ->and($tipo->aplica_alta)->toBeFalse()
            ->and($tipo->activo)->toBeTrue()
            ->and($tipo->estaEnChecklist())->toBeTrue();
    }

    expect(DocumentType::query()->delChecklist()->pluck('clave'))->toContain(...CLAVES_OPCIONALES)
        ->and(DocumentType::query()->where('requerido', true)->count())->toBe(15)
        // El pagaré de préstamos es otra cosa (plantilla «pagare»): no se mezcla.
        ->and(DocumentType::query()->where('clave', 'pagare')->exists())->toBeFalse();
});

test('sin asignar, los opcionales no cuentan para el avance, los faltantes, la app ni la carta', function () {
    $colaborador = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value]);

    $estado = app(ExpedienteService::class)->estadoDocumental($colaborador);
    $claves = collect($estado['documentos'])->pluck('clave');

    expect($claves)->not->toContain(...CLAVES_OPCIONALES)
        ->and($estado['requeridos'])->toBe(DocumentType::query()->where('requerido', true)->where('activo', true)->count());

    // Exigibles del registro/bloqueo de la app y de la carta compromiso.
    $pendientes = app(DocumentosPendientesService::class);
    expect($pendientes->tiposExigibles()->pluck('clave'))->not->toContain(...CLAVES_OPCIONALES)
        ->and(collect($pendientes->pendientes($colaborador))->pluck('clave'))->not->toContain(...CLAVES_OPCIONALES);

    // API móvil (checklist de incorporación): misma forma, sin los opcionales.
    $incorporacion = app(IncorporacionService::class);
    expect($incorporacion->tiposDocumento($colaborador)->pluck('clave'))->not->toContain(...CLAVES_OPCIONALES);
});

test('el checklist web los muestra como «Opcional» y subir uno no cambia el avance', function () {
    $rh = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $rh->assignRole('rh_admin');
    $persona = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value]);
    $licencia = DocumentType::query()->where('clave', 'licencia_conducir')->firstOrFail();
    $antes = app(ExpedienteService::class)->resumenCompletitud($persona);

    $this->actingAs($rh)->get(route('rh.expedientes.show', $persona))
        ->assertInertia(fn ($page) => $page->where('documentosRequeridos', function ($items) use ($licencia) {
            $items = collect($items);
            $item = $items->firstWhere('tipo.id', $licencia->id);

            return $item !== null
                && $item['tipo']['requerido'] === false
                && $item['tipo']['opcional'] === true
                && $item['documento'] === null
                && $items->filter(fn ($i) => $i['tipo']['opcional'] === true)->count() === 3
                && $items->where('tipo.requerido', true)->every(fn ($i) => $i['tipo']['opcional'] === false);
        }));

    EmployeeDocument::factory()->create([
        'colaborador_id' => $persona->id,
        'document_type_id' => $licencia->id,
        'status' => EstadoDocumento::Aprobado->value,
        'version' => 1,
    ]);

    // Solo esa persona lo tiene; su avance (solo requeridos) no cambia.
    expect(app(ExpedienteService::class)->resumenCompletitud($persona->refresh()))->toBe($antes)
        ->and(EmployeeDocument::query()->where('document_type_id', $licencia->id)->pluck('colaborador_id')->all())->toBe([$persona->id]);
});
