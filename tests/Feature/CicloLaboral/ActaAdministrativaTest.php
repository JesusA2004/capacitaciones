<?php

use App\Enums\EstadoActa;
use App\Enums\TipoContratacion;
use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
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

/**
 * Los testigos ahora se eligen de un buscador (CLAUDE.md §76-79: nunca
 * texto libre), así que el payload de prueba exige un colaborador_id real
 * — se crean aquí mismo con el nombre que el resto de la prueba espera ver
 * en el formato oficial. El backend resuelve nombre/puesto del propio
 * Colaborador (nunca del texto que mande el request, encargo §24-26): el
 * puesto real se asigna aquí para que el formato lo encuentre lleno.
 */
function aaDatos(array $extra = []): array
{
    $testigo1 = Colaborador::factory()->create(['name' => 'María', 'apellidos' => 'López Ruiz', 'puesto_id' => Puesto::factory()->create(['nombre' => 'Subgerente'])->id]);
    $testigo2 = Colaborador::factory()->create(['name' => 'Juan', 'apellidos' => 'Pérez Gómez', 'puesto_id' => Puesto::factory()->create(['nombre' => 'Gestor'])->id]);

    return [
        'tipo' => 'administrativa',
        'motivo' => 'Faltas injustificadas',
        'fecha' => now()->toDateString(),
        'hora' => '10:30',
        'hechos' => "PRIMERO. No se presentó a laborar el 6 de octubre.\nSEGUNDO. No avisó.",
        'testigos' => [
            ['colaborador_id' => $testigo1->id, 'nombre' => $testigo1->nombreCompleto(), 'puesto' => 'Subgerente'],
            ['colaborador_id' => $testigo2->id, 'nombre' => $testigo2->nombreCompleto(), 'puesto' => 'Gestor'],
        ],
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
        // El backend siempre pone el nombre real del sujeto del acta en la
        // primera manifestación (encargo §28), nunca lo que mande el
        // request — "Adriana Cortés" en aaDatos() se ignora.
        ->and($documento->payload['manifestaciones_acta'])->toBe('Adriana Cortés Beltrán: Tuvo un problema familiar.')
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

test('con un solo testigo tampoco se emite el formato oficial', function () {
    dmMotorFielDePrueba();

    if (! aaImportarFormato()) {
        $this->markTestSkipped('Sin el formato del acta en docs/formatos_fuente.');
    }

    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $testigo = Colaborador::factory()->create(['name' => 'María', 'apellidos' => 'López Ruiz', 'puesto_id' => Puesto::factory()->create(['nombre' => 'Subgerente'])->id]);

    $this->actingAs($this->rh)->post(route('rh.actas.store', $colaborador), aaDatos([
        'testigos' => [['colaborador_id' => $testigo->id, 'nombre' => $testigo->nombreCompleto(), 'puesto' => 'Subgerente']],
    ]));
    $acta = ActaAdministrativa::query()->sole();

    $this->actingAs($this->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), ['clave' => 'acta_administrativa', 'proceso' => 'acta'])
        ->assertUnprocessable();

    expect(GeneratedDocument::query()->count())->toBe(0)
        ->and($acta->refresh()->estado)->toBe(EstadoActa::Borrador)
        ->and($acta->testigos)->toHaveCount(1);
});

test('los dos testigos deben ser colaboradores distintos entre si', function () {
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $testigo = Colaborador::factory()->create();

    $this->actingAs($this->rh)->post(route('rh.actas.store', $colaborador), aaDatos([
        'testigos' => [
            ['colaborador_id' => $testigo->id, 'nombre' => $testigo->nombreCompleto(), 'puesto' => 'Gestor'],
            ['colaborador_id' => $testigo->id, 'nombre' => $testigo->nombreCompleto(), 'puesto' => 'Gestor'],
        ],
    ]))->assertSessionHasErrors('testigos.0.colaborador_id');
});

test('un testigo no puede ser el colaborador del acta', function () {
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $otro = Colaborador::factory()->create();

    $this->actingAs($this->rh)->post(route('rh.actas.store', $colaborador), aaDatos([
        'testigos' => [
            ['colaborador_id' => $colaborador->id, 'nombre' => $colaborador->nombreCompleto(), 'puesto' => 'Gestor'],
            ['colaborador_id' => $otro->id, 'nombre' => $otro->nombreCompleto(), 'puesto' => 'Gestor'],
        ],
    ]))->assertSessionHasErrors('testigos.0.colaborador_id');
});

test('un testigo sin colaborador_id (texto libre) se rechaza', function () {
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    $this->actingAs($this->rh)->post(route('rh.actas.store', $colaborador), aaDatos([
        'testigos' => [
            ['nombre' => 'Alguien sin elegir del buscador', 'puesto' => 'Gestor'],
            ['colaborador_id' => Colaborador::factory()->create()->id, 'nombre' => 'Otro Testigo', 'puesto' => 'Gestor'],
        ],
    ]))->assertSessionHasErrors('testigos.0.colaborador_id');
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
