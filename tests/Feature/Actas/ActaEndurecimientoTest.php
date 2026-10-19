<?php

use App\Enums\EstadoActa;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoUsuario;
use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Actas\ActaPdfService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Acta administrativa circunstanciada — endurecimiento (auditoría
| 2026-10-15): máquina de estados real para cerrar, negativa/entrega solo
| sobre el definitivo, testigos requeridos solo si los hay, participantes
| dentro del alcance del actor, quien levanta = quien registra, jefe =
| responsable por colaborador_id y cierre coherente con el levantamiento.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->centro = Sucursal::factory()->create(['nombre' => 'Toluca Centro']);
    $this->orizaba = Sucursal::factory()->create(['nombre' => 'Orizaba']);
    $this->rh = clUsuario('rh_admin', ['name' => 'Rocío', 'apellidos' => 'Hernández Paz', 'sucursal_principal_id' => $this->centro->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->centro->id]);

    $this->jefeOrizaba = Colaborador::factory()->create(['name' => 'Jefe', 'apellidos' => 'De Orizaba', 'sucursal_principal_id' => $this->orizaba->id, 'estatus' => EstadoUsuario::Activo]);
    $this->trabajador = Colaborador::factory()->create([
        'name' => 'Adriana', 'apellidos' => 'Cortés Lima', 'sucursal_principal_id' => $this->centro->id,
        'puesto_id' => Puesto::factory()->create(['nombre' => 'Cajera'])->id, 'estatus' => EstadoUsuario::Activo,
    ]);
    $this->testigo = Colaborador::factory()->create(['name' => 'María', 'apellidos' => 'López Ruiz', 'sucursal_principal_id' => $this->centro->id, 'estatus' => EstadoUsuario::Activo]);
    $this->ajeno = Colaborador::factory()->create(['name' => 'Diego', 'apellidos' => 'Veracruz', 'sucursal_principal_id' => $this->orizaba->id, 'estatus' => EstadoUsuario::Activo]);
});

/** Captura mínima completa del acta circunstanciada. */
function aeDatos(array $circunstanciada = [], array $extra = []): array
{
    return [
        'tipo' => 'administrativa',
        'fecha' => now()->toDateString(),
        'lugar' => 'Toluca, Estado de México',
        'hechos' => 'El arqueo de caja arrojó un faltante.',
        'circunstanciada' => array_replace_recursive([
            'incidencia' => ['tipo' => 'irregularidad_bienes', 'fecha_desde' => now()->subDay()->toDateString()],
            'jefe' => ['participa' => false],
            'testigos_hechos' => [],
            'testigos_asistencia' => [],
            'trabajador' => ['manifesto' => false],
        ], $circunstanciada),
        ...$extra,
    ];
}

function aeLevantar(object $prueba, array $datos, ?User $actor = null): ActaAdministrativa
{
    $prueba->actingAs($actor ?? $prueba->rh)->post(route('rh.actas.store', $prueba->trabajador), $datos)->assertSessionHasNoErrors()->assertRedirect();

    return ActaAdministrativa::query()->latest('id')->firstOrFail();
}

function aeGenerar(object $prueba, ActaAdministrativa $acta): GeneratedDocument
{
    $prueba->actingAs($prueba->rh)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), ['clave' => 'acta_administrativa', 'proceso' => 'acta'])
        ->assertSuccessful();

    return GeneratedDocument::query()->findOrFail($acta->refresh()->generated_document_id);
}

function aeOperar(object $prueba, GeneratedDocument $documento, string $accion, array $datos = []): void
{
    $prueba->actingAs($prueba->rh)->post(route('rh.documentos-proceso.operar', ['documento' => $documento->id, 'accion' => $accion]), $datos, ['Accept' => 'application/json'])
        ->assertOk();
}

function aeErrorCerrar(object $prueba, ActaAdministrativa $acta): string
{
    $prueba->actingAs($prueba->rh)->post(route('rh.actas.cerrar', $acta))->assertSessionHasErrors('acta');

    return (string) session('errors')->first('acta');
}

test('cerrar exige la máquina de estados: definitivo → firma física o negativa → entrega de copia (ya NO exige escaneo)', function () {
    $acta = aeLevantar($this, aeDatos());
    expect(aeErrorCerrar($this, $acta))->toContain('Genera el acta definitiva');

    $documento = aeGenerar($this, $acta);
    expect(aeErrorCerrar($this, $acta))->toContain('firma física')->toContain('negativa');

    // Impreso y marcado como firmado físicamente: ese es el estado FINAL del
    // original en papel. El acta firmada se resguarda físicamente, ya no se
    // escanea; lo único que falta para cerrar es la constancia de entrega.
    aeOperar($this, $documento, 'imprimir');
    aeOperar($this, $documento, 'firma-fisica');
    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);
    expect(aeErrorCerrar($this, $acta->refresh()))->toContain('copia');

    $this->actingAs($this->rh)->post(route('rh.actas.entrega', $acta), ['estado' => 'entregada'])->assertSessionHasNoErrors();
    $this->actingAs($this->rh)->post(route('rh.actas.cerrar', $acta))->assertSessionHasNoErrors();

    expect($acta->refresh()->estado)->toBe(EstadoActa::Cerrada)
        ->and($acta->cerrada_en)->not->toBeNull()
        ->and(aeErrorCerrar($this, $acta))->toContain('ya está cerrada');
});

test('con negativa a firmar asentada tampoco se cierra sin constancia de entrega (ya NO exige escaneo)', function () {
    $acta = aeLevantar($this, aeDatos());
    aeGenerar($this, $acta);

    $this->actingAs($this->rh)->post(route('rh.actas.negativa-firma', $acta), ['motivo' => 'Se le leyó el acta y no quiso firmar.'])->assertSessionHasNoErrors();
    // Con negativa asentada, lo único que falta es la constancia de entrega
    // (no el escaneo: el acta en papel se resguarda físicamente).
    expect(aeErrorCerrar($this, $acta->refresh()))->toContain('copia');

    // El API usa el mismo servicio: mismo bloqueo.
    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/actas/{$acta->id}/cerrar")->assertUnprocessable()->assertJsonValidationErrors('acta');
    expect($acta->refresh()->estado)->toBe(EstadoActa::Generada);

    // Registrada la entrega de copia, cierra sin necesidad de escaneo.
    $this->actingAs($this->rh)->post(route('rh.actas.entrega', $acta), ['estado' => 'entregada'])->assertSessionHasNoErrors();
    $this->actingAs($this->rh)->post(route('rh.actas.cerrar', $acta))->assertSessionHasNoErrors();
    expect($acta->refresh()->estado)->toBe(EstadoActa::Cerrada);
});

test('acta histórica (formato null): se cierra solo firmada o con negativa', function () {
    $historica = ActaAdministrativa::query()->create([
        'colaborador_id' => $this->trabajador->id, 'tipo' => 'administrativa', 'estado' => EstadoActa::Generada,
        'fecha' => now()->subYear()->toDateString(), 'hechos' => 'Histórica', 'creado_por' => $this->rh->id,
    ]);

    expect(aeErrorCerrar($this, $historica))->toContain('firma');

    $historica->update(['negativa_firma' => true, 'motivo_negativa' => 'No firmó.']);
    $this->actingAs($this->rh)->post(route('rh.actas.cerrar', $historica))->assertSessionHasNoErrors();
    expect($historica->refresh()->estado)->toBe(EstadoActa::Cerrada);
});

test('acta NO circunstanciada con documento solo «Impreso» no se cierra: exige firma física real o negativa (P1-6)', function () {
    // Documento físico apenas impreso: estaFirmado() devolvía true aquí, así
    // que un acta disciplinaria se cerraba sin firma física real. Ahora se
    // exige la firma física de verdad (firmaFisicaRegistrada) o la negativa.
    $documento = GeneratedDocument::factory()->create([
        'colaborador_id' => $this->trabajador->id,
        'estado_flujo' => EstadoFlujoDocumento::Impreso,
        'requiere_impresion' => true,
        'requiere_firma_fisica' => true,
    ]);

    $acta = ActaAdministrativa::query()->create([
        'colaborador_id' => $this->trabajador->id, 'tipo' => 'administrativa', 'estado' => EstadoActa::Generada,
        'formato' => null, 'generated_document_id' => $documento->id,
        'fecha' => now()->subMonth()->toDateString(), 'hechos' => 'Histórica impresa', 'creado_por' => $this->rh->id,
    ]);

    expect(aeErrorCerrar($this, $acta))->toContain('firma física');
    $this->actingAs($this->rh)->post(route('rh.actas.cerrar', $acta))->assertSessionHasErrors('acta');
    expect($acta->refresh()->estado)->toBe(EstadoActa::Generada);

    // Firmado físicamente → ya se puede cerrar (histórica: sin constancia de entrega).
    $documento->update(['estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente]);
    $this->actingAs($this->rh)->post(route('rh.actas.cerrar', $acta))->assertSessionHasNoErrors();
    expect($acta->refresh()->estado)->toBe(EstadoActa::Cerrada);
});

test('negativa a firmar y entrega de copia: solo sobre el acta definitiva y abierta (web y API)', function () {
    $acta = aeLevantar($this, aeDatos());

    $this->actingAs($this->rh)->post(route('rh.actas.negativa-firma', $acta), ['motivo' => 'No quiso firmar.'])->assertSessionHasErrors('acta');
    $this->actingAs($this->rh)->post(route('rh.actas.entrega', $acta), ['estado' => 'entregada'])->assertSessionHasErrors('acta');

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/actas/{$acta->id}/negativa-firma", ['motivo' => 'No quiso firmar.'])->assertUnprocessable()->assertJsonValidationErrors('acta');
    $this->postJson("/api/v1/rh/actas/{$acta->id}/entrega", ['estado' => 'entregada'])->assertUnprocessable()->assertJsonValidationErrors('acta');

    $acta->refresh();
    expect($acta->negativa_firma)->toBeFalse()
        ->and($acta->circunstanciada['entrega']['estado'])->toBe('pendiente');

    // Cerrada: tampoco.
    aeGenerar($this, $acta);
    $acta->refresh()->update(['estado' => EstadoActa::Cerrada]);
    $this->actingAs($this->rh)->post(route('rh.actas.negativa-firma', $acta), ['motivo' => 'Tarde.'])->assertSessionHasErrors('acta');
    $this->actingAs($this->rh)->post(route('rh.actas.entrega', $acta), ['estado' => 'entregada'])->assertSessionHasErrors('acta');
    expect($acta->refresh()->negativa_firma)->toBeFalse();
});

test('requiere_testigos solo si el acta tiene testigos; con 0 la firma física no se bloquea', function () {
    $sinTestigos = aeLevantar($this, aeDatos());
    $documento = aeGenerar($this, $sinTestigos);

    expect($documento->requiere_testigos)->toBeFalse()
        ->and(ActaPdfService::tieneTestigos($sinTestigos->refresh()))->toBeFalse();

    aeOperar($this, $documento, 'imprimir');
    aeOperar($this, $documento, 'firma-fisica');
    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);

    $conTestigo = aeLevantar($this, aeDatos(['testigos_asistencia' => [['colaborador_id' => $this->testigo->id]]]));
    expect(aeGenerar($this, $conTestigo)->requiere_testigos)->toBeTrue();
});

test('participante fuera del alcance del actor: 422 por web y por API; el jefe real del organigrama sí se acepta', function () {
    $fuera = aeDatos(['testigos_hechos' => [['colaborador_id' => $this->ajeno->id, 'relacion' => 'presencio_directamente']]]);

    $this->actingAs($this->gerente)->post(route('rh.actas.store', $this->trabajador), $fuera)
        ->assertSessionHasErrors('circunstanciada.testigos_hechos.0.colaborador_id');
    $this->actingAs($this->gerente)->post(route('rh.actas.store', $this->trabajador), aeDatos(['testigos_asistencia' => [['colaborador_id' => $this->ajeno->id]]]))
        ->assertSessionHasErrors('circunstanciada.testigos_asistencia.0.colaborador_id');
    $this->actingAs($this->gerente)->post(route('rh.actas.store', $this->trabajador), [
        ...aeDatos(), 'testigos' => [['colaborador_id' => $this->ajeno->id], ['colaborador_id' => $this->testigo->id]],
    ])->assertSessionHasErrors('testigos.0.colaborador_id');

    // Jefe que NO es el del organigrama y está fuera de alcance: rechazado.
    $otroJefe = Colaborador::factory()->create(['sucursal_principal_id' => $this->orizaba->id, 'estatus' => EstadoUsuario::Activo]);
    $this->actingAs($this->gerente)->post(route('rh.actas.store', $this->trabajador), aeDatos(['jefe' => ['participa' => true, 'colaborador_id' => $otroJefe->id]]))
        ->assertSessionHasErrors('circunstanciada.jefe.colaborador_id');

    Sanctum::actingAs($this->gerente);
    $this->postJson("/api/v1/rh/colaboradores/{$this->trabajador->id}/actas", $fuera)
        ->assertUnprocessable()->assertJsonValidationErrors('circunstanciada.testigos_hechos.0.colaborador_id');

    expect(ActaAdministrativa::query()->count())->toBe(0);

    // El jefe inmediato REAL del trabajador sí se acepta aunque esté en otra
    // sucursal (p. ej. un regional). Se fija directo en la tabla: el
    // organigrama lo recalcula al crear colaboradores.
    Colaborador::query()->whereKey($this->trabajador->id)->update(['jefe_id' => $this->jefeOrizaba->id]);
    $acta = aeLevantar($this, aeDatos(['jefe' => ['participa' => true, 'colaborador_id' => $this->jefeOrizaba->id]]), $this->gerente);
    expect($acta->circunstanciada['jefe']['colaborador_id'])->toBe($this->jefeOrizaba->id);

    // Al editar tampoco se cuela alguien fuera de alcance.
    $this->actingAs($this->gerente)->put(route('rh.actas.update', $acta), array_diff_key(aeDatos(['testigos_asistencia' => [['colaborador_id' => $this->ajeno->id]]]), ['tipo' => true]))
        ->assertSessionHasErrors('circunstanciada.testigos_asistencia.0.colaborador_id');

    // RH (alcance global) sí puede elegirlo.
    aeLevantar($this, $fuera);
});

test('quien levanta es siempre quien registra: responsable_id ajeno se rechaza al crear y al editar', function () {
    $otro = clUsuario('rh_admin');

    $this->actingAs($this->rh)->post(route('rh.actas.store', $this->trabajador), aeDatos([], ['responsable_id' => $otro->id]))
        ->assertSessionHasErrors('responsable_id');

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/colaboradores/{$this->trabajador->id}/actas", aeDatos([], ['responsable_id' => $otro->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('responsable_id');

    $acta = aeLevantar($this, aeDatos());
    expect($acta->responsable_id)->toBe($this->rh->id)
        ->and($acta->circunstanciada['responsable']['colaborador_id'])->toBe($this->rh->colaborador_id);

    $this->actingAs($this->rh)->put(route('rh.actas.update', $acta), ['responsable_id' => $otro->id])->assertSessionHasErrors('responsable_id');
    expect($acta->refresh()->responsable_id)->toBe($this->rh->id);
});

test('jefe = responsable se decide por colaborador_id, no por nombre (homónimos son personas distintas)', function () {
    // Homónimo: otra persona con el mismo nombre que quien levanta.
    $homonimo = Colaborador::factory()->create(['name' => 'Rocío', 'apellidos' => 'Hernández Paz', 'sucursal_principal_id' => $this->centro->id, 'estatus' => EstadoUsuario::Activo]);
    $acta = aeLevantar($this, aeDatos(['jefe' => ['participa' => true, 'colaborador_id' => $homonimo->id]]));
    $datos = app(ActaPdfService::class)->datosCircunstanciada($acta->refresh());

    expect($datos['jefe']['nombre'])->toBe($datos['responsable']['nombre'])
        ->and($datos['jefe']['es_responsable'])->toBeFalse();

    // Quien levanta ES el jefe que participa: una sola persona.
    $mismo = aeLevantar($this, aeDatos(['jefe' => ['participa' => true, 'colaborador_id' => $this->rh->colaborador_id]]));
    expect(app(ActaPdfService::class)->datosCircunstanciada($mismo->refresh())['jefe']['es_responsable'])->toBeTrue();
});

test('el cierre no puede ser anterior al levantamiento ni futuro (hora de México)', function () {
    $mx = Carbon::now('America/Mexico_City');
    $ayer = $mx->copy()->subDay()->toDateString();
    $base = fn (array $cierre): array => aeDatos(['cierre' => $cierre], ['fecha' => $ayer, 'hora' => '10:00']);

    $this->actingAs($this->rh)->post(route('rh.actas.store', $this->trabajador), $base(['fecha' => $mx->copy()->subDays(2)->toDateString(), 'hora' => '12:00']))
        ->assertSessionHasErrors('circunstanciada.cierre.fecha');
    $this->actingAs($this->rh)->post(route('rh.actas.store', $this->trabajador), $base(['fecha' => $ayer, 'hora' => '09:30']))
        ->assertSessionHasErrors('circunstanciada.cierre.hora');
    $this->actingAs($this->rh)->post(route('rh.actas.store', $this->trabajador), $base(['fecha' => $mx->copy()->addDay()->toDateString(), 'hora' => '09:00']))
        ->assertSessionHasErrors('circunstanciada.cierre.fecha');
    expect(ActaAdministrativa::query()->count())->toBe(0);

    $acta = aeLevantar($this, $base(['fecha' => $ayer, 'hora' => '11:15']));
    expect($acta->circunstanciada['cierre'])->toBe(['fecha' => $ayer, 'hora' => '11:15']);

    // Mover el levantamiento después del cierre ya guardado también se rechaza.
    $this->actingAs($this->rh)->put(route('rh.actas.update', $acta), ['hora' => '12:00'])->assertSessionHasErrors('circunstanciada.cierre.hora');

    // Al generar el definitivo el cierre queda congelado: ya no se edita.
    aeGenerar($this, $acta);
    $this->actingAs($this->rh)->put(route('rh.actas.update', $acta), array_diff_key(aeDatos(['cierre' => ['fecha' => $ayer, 'hora' => '13:00']]), ['tipo' => true]))
        ->assertSessionHasErrors('acta');
    expect($acta->refresh()->circunstanciada['cierre'])->toBe(['fecha' => $ayer, 'hora' => '11:15']);
});
