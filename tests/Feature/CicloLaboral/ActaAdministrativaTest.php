<?php

use App\Enums\EstadoActa;
use App\Enums\TipoContratacion;
use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\DocumentosMaestros\CatalogoMaestrosService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| PRO-ADP-08 · Actas administrativas en la web: se levanta el acta (motivo
| escrito a mano, hechos, testigos, manifestaciones), se genera el formato
| oficial con los datos del colaborador por «Documentos del proceso»
| (tipo `acta`), sigue su flujo físico y queda en el expediente.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->estructura = clEstructura();
});

function aaDatos(array $extra = []): array
{
    return [
        'tipo' => 'administrativa',
        'motivo' => 'Faltas injustificadas',
        'fecha' => now()->toDateString(),
        'hora' => '10:30',
        'hechos' => "PRIMERO. No se presentó a laborar el 6 de octubre.\nSEGUNDO. No avisó.",
        'testigos' => [['nombre' => 'María López Ruiz', 'puesto' => 'Subgerente'], ['nombre' => 'Juan Pérez Gómez', 'puesto' => 'Gestor']],
        'declaraciones' => [['persona' => 'Adriana Cortés', 'declaracion' => 'Tuvo un problema familiar.']],
        ...$extra,
    ];
}

/** Registra el formato oficial del acta (derivado del de Jurídico) con el conversor de prueba. */
function aaImportarFormato(): bool
{
    $catalogo = app(CatalogoMaestrosService::class);
    $registrado = false;

    foreach (glob(base_path('docs/formatos_fuente').'/*.docx') ?: [] as $ruta) {
        foreach ($catalogo->todasPorHash((string) hash_file('sha256', $ruta)) as $fuente) {
            if ($fuente['familia'] === 'acta_administrativa.general') {
                app(ImportadorFormatosJuridicosService::class)->registrarVersion($fuente['familia'], $fuente['version'], (string) file_get_contents($ruta), [basename($ruta)], $fuente, null);
                $registrado = true;
            }
        }
    }

    return $registrado;
}

test('el acta administrativa exige el motivo; RH la levanta desde la web y abre su detalle', function () {
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    $this->actingAs($this->rh)->post(route('rh.actas.store', $colaborador), aaDatos(['motivo' => '']))
        ->assertSessionHasErrors('motivo');

    $this->actingAs($this->rh)->post(route('rh.actas.store', $colaborador), aaDatos())->assertRedirect();
    $acta = ActaAdministrativa::query()->sole();

    expect($acta->motivo)->toBe('Faltas injustificadas')
        ->and($acta->estado)->toBe(EstadoActa::Borrador)
        ->and($acta->folio)->toStartWith('ACT-');

    $this->actingAs($this->rh)->get(route('rh.actas.show', $acta))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/Actas/Show')
            ->where('acta.motivo', 'Faltas injustificadas')
            ->where('acta.testigos.1.nombre', 'Juan Pérez Gómez')
            ->where('puedeGestionar', true));

    $this->actingAs($this->rh)->get(route('rh.actas.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/Actas/Index')->where('actas.data.0.motivo', 'Faltas injustificadas'));
});

test('el formato oficial se llena con los datos del colaborador y lo escrito en el acta, y queda en el expediente', function () {
    dmMotorFielDePrueba();

    if (! aaImportarFormato()) {
        $this->markTestSkipped('Sin el formato del acta en docs/formatos_fuente.');
    }

    $colaborador = Colaborador::factory()->create(['name' => 'Adriana', 'apellidos' => 'Cortés Beltrán', 'sucursal_principal_id' => $this->estructura['sucursal']->id, 'puesto_id' => $this->estructura['puesto']->id]);
    ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id, 'tipo' => TipoContratacion::Indeterminado->value, 'fecha_inicio' => '2025-03-01', 'fecha_fin' => null]);
    $this->estructura['empresa']->update(['representante_legal_nombre' => 'Lesli Maribel Rodríguez Herrera', 'domicilio_fiscal' => 'Subida del Club 114, Cuernavaca, Morelos']);

    $this->actingAs($this->rh)->post(route('rh.actas.store', $colaborador), aaDatos());
    $acta = ActaAdministrativa::query()->sole();

    $seccion = $this->actingAs($this->rh)->getJson(route('rh.documentos-proceso.show', ['tipo' => 'acta', 'id' => $acta->id]))
        ->assertOk()->json('data');
    expect($seccion['documentos'][0]['clave'])->toBe('acta_administrativa');

    // Sin jefe en el organigrama ni puesto de quien genera: el formato los
    // pide por su nombre (modal «Completar datos») en vez de dejar la línea.
    $faltantes = $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), ['clave' => 'acta_administrativa', 'proceso' => 'acta'])
        ->assertUnprocessable()->json('faltantes');
    expect(array_column($faltantes, 'etiqueta'))->toEqualCanonicalizing(['Representante de RH: cargo', 'Jefe inmediato: nombre', 'Jefe inmediato: cargo']);

    $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), [
        'clave' => 'acta_administrativa',
        'proceso' => 'acta',
        'manuales' => ['rh_cargo' => 'Analista de RH', 'jefe_nombre' => 'Pedro Soto Ruiz', 'jefe_cargo' => 'Gerente de Sucursal'],
    ])->assertCreated();

    $documento = GeneratedDocument::query()->where('clave_plantilla', 'acta_administrativa')->sole();
    $acta->refresh();

    expect($acta->estado)->toBe(EstadoActa::Generada)
        ->and($acta->generated_document_id)->toBe($documento->id)
        ->and($documento->categoria->value)->toBe('actas')
        ->and($documento->colaborador_id)->toBe($colaborador->id)
        ->and($documento->payload['motivo_acta_mayusculas'])->toBe('FALTAS INJUSTIFICADAS')
        ->and($documento->payload['hechos_acta'])->toContain('SEGUNDO. No avisó.')
        ->and($documento->payload['manifestaciones_acta'])->toBe('Adriana Cortés: Tuvo un problema familiar.')
        ->and($documento->payload['testigo_2_nombre'])->toBe('JUAN PÉREZ GÓMEZ')
        ->and($documento->payload['nombre_completo_mayusculas'])->toBe('ADRIANA CORTÉS BELTRÁN')
        ->and($documento->payload['tipo_contrato_acta'])->toBe('Contrato Individual de Trabajo por Tiempo Indeterminado')
        ->and($documento->payload['fecha_conclusion_contrato_acta'])->toBe('Tiempo indeterminado')
        ->and($documento->payload['hora_acta'])->toBe('10:30')
        ->and($documento->payload['jefe_nombre'])->toBe('PEDRO SOTO RUIZ')
        ->and(Storage::disk($documento->disk)->exists($documento->path))->toBeTrue();
});

test('sin testigos el formato no se emite: pide los datos que faltan por su nombre', function () {
    dmMotorFielDePrueba();

    if (! aaImportarFormato()) {
        $this->markTestSkipped('Sin el formato del acta en docs/formatos_fuente.');
    }

    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $this->actingAs($this->rh)->post(route('rh.actas.store', $colaborador), aaDatos(['testigos' => []]));
    $acta = ActaAdministrativa::query()->sole();

    $respuesta = $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), ['clave' => 'acta_administrativa', 'proceso' => 'acta'])
        ->assertUnprocessable();

    expect(json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE))->toContain('Testigo 1: nombre')
        ->and(GeneratedDocument::query()->count())->toBe(0)
        ->and($acta->refresh()->estado)->toBe(EstadoActa::Borrador);
});

test('acta cerrada: ya no se genera el formato; el colaborador implicado no la ve', function () {
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $this->actingAs($this->rh)->post(route('rh.actas.store', $colaborador), aaDatos());
    $acta = ActaAdministrativa::query()->sole();
    $acta->update(['estado' => EstadoActa::Cerrada]);

    $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), ['clave' => 'acta_administrativa', 'proceso' => 'acta'])
        ->assertUnprocessable();

    $implicado = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $implicado->assignRole('colaborador');

    $this->actingAs($implicado)->get(route('rh.actas.show', $acta))->assertForbidden();
    $this->actingAs($implicado)->get(route('rh.actas.index'))->assertForbidden();
});
