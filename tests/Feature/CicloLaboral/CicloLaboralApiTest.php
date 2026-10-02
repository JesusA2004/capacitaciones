<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoReingreso;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Reingreso;
use App\Models\Sucursal;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| API v1 del ciclo laboral para la app: mismas reglas que la web porque
| ambos canales llaman a los mismos services.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'America/Mexico_City'));
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->estructura = clEstructura();
    $this->sucursal = $this->estructura['sucursal'];
    $this->rh = clUsuario('rh_admin');
    $this->reclutador = clUsuario('rh_auxiliar');
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->gerenteOtra = clUsuario('gerente_sucursal', ['sucursal_principal_id' => Sucursal::factory()->create()->id]);

    $this->candidato = app(CandidatoWorkflowService::class)->registrar([
        'empresa_id' => $this->estructura['empresa']->id,
        'sucursal_id' => $this->sucursal->id,
        'departamento_id' => $this->estructura['departamento']->id,
        'puesto_objetivo_id' => $this->estructura['puesto']->id,
        'nombre' => 'Luis',
        'apellidos' => 'Pérez Api',
        'telefono' => '5511122233',
        'correo' => 'luis.api@example.test',
        'fuente' => 'facebook_grupos',
        'gerente_involucrado_id' => $this->gerente->id,
    ], $this->reclutador);
});

afterEach(fn () => Carbon::setTestNow());

test('el reclutamiento avanza por la API con acciones del workflow; el gerente preautoriza y solo RH autoriza', function () {
    Sanctum::actingAs($this->reclutador);
    $this->getJson('/api/v1/rh/candidatos')->assertOk()->assertJsonPath('data.0.id', $this->candidato->id)->assertJsonStructure(['meta' => ['total', 'estados']]);
    $this->postJson("/api/v1/rh/candidatos/{$this->candidato->id}/perfil", ['viable' => true, 'observaciones' => 'Cubre perfil.'])
        ->assertOk()
        ->assertJsonPath('data.candidato.estado', EstadoCandidato::EntrevistaPendiente->value);

    Sanctum::actingAs($this->gerente);
    $this->postJson("/api/v1/rh/candidatos/{$this->candidato->id}/entrevista", ['realizada_en' => now()->subDay()->toDateTimeString(), 'resultado' => 'viable', 'observaciones' => 'Bien.'])
        ->assertOk()
        ->assertJsonPath('data.candidato.estado', EstadoCandidato::PsicometricasPendientes->value);

    Sanctum::actingAs($this->reclutador);
    $this->postJson("/api/v1/rh/candidatos/{$this->candidato->id}/psicometricas/enviar", ['link' => 'https://pruebas.example.test/1'])->assertOk();
    $this->postJson("/api/v1/rh/candidatos/{$this->candidato->id}/psicometricas/resultados", ['resumen' => 'Resultados en perfil.'])->assertOk();

    Sanctum::actingAs($this->gerente);
    $this->postJson("/api/v1/rh/candidatos/{$this->candidato->id}/psicometricas/revision", ['viable' => true])->assertOk();
    $this->postJson("/api/v1/rh/candidatos/{$this->candidato->id}/socioeconomico", ['fecha_visita' => now()->toDateString(), 'direccion' => 'Calle 1', 'resultado' => 'viable', 'checklist' => ['vivienda_en_orden' => true]])->assertOk();

    Sanctum::actingAs($this->reclutador);
    $this->postJson("/api/v1/rh/candidatos/{$this->candidato->id}/referencias", ['empresa' => 'Empresa X', 'contacto' => 'Jefe X', 'resultado' => 'positiva'])->assertOk();
    $this->postJson("/api/v1/rh/candidatos/{$this->candidato->id}/referencias/concluir", ['viable' => true])
        ->assertOk()
        ->assertJsonPath('data.candidato.estado', EstadoCandidato::PreseleccionGerente->value);

    // Un gerente de otra sucursal no ve ni decide.
    Sanctum::actingAs($this->gerenteOtra);
    $this->getJson("/api/v1/rh/candidatos/{$this->candidato->id}")->assertForbidden();
    expect($this->postJson("/api/v1/rh/candidatos/{$this->candidato->id}/preautorizar", ['comentario' => 'x'])->status())->toBeIn([403, 422]);
    expect($this->candidato->refresh()->estado)->toBe(EstadoCandidato::PreseleccionGerente);

    Sanctum::actingAs($this->gerente);
    $acciones = collect($this->getJson("/api/v1/rh/candidatos/{$this->candidato->id}")->assertOk()->json('data.ciclo.acciones_permitidas'))->pluck('clave');
    expect($acciones)->toContain('preautorizar');

    $this->postJson("/api/v1/rh/candidatos/{$this->candidato->id}/preautorizar", ['comentario' => 'Lo recomiendo.'])
        ->assertOk()
        ->assertJsonPath('data.candidato.estado', EstadoCandidato::AutorizacionRhPendiente->value);

    // El gerente no puede dar la autorización final.
    $this->postJson("/api/v1/rh/candidatos/{$this->candidato->id}/autorizar", ['comentario' => 'yo mismo'])->assertUnprocessable()->assertJsonValidationErrors('aprobacion');
    expect($this->candidato->refresh()->estado)->toBe(EstadoCandidato::AutorizacionRhPendiente);

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/candidatos/{$this->candidato->id}/autorizar", ['comentario' => 'Autorizado.'])
        ->assertOk()
        ->assertJsonPath('data.candidato.estado', EstadoCandidato::AutorizadoRh->value);

    // La bandeja del ciclo, el tablero y la ficha responden por la API.
    $this->getJson('/api/v1/rh/ciclo/pendientes')->assertOk()->assertJsonStructure(['data', 'meta' => ['conteos']]);
    $this->getJson('/api/v1/rh/tablero?mes=2026-10')->assertOk()->assertJsonPath('data.filters.mes', '2026-10')->assertJsonCount(7, 'data.recruitment_funnel');
});

test('mi proceso y la ficha del ciclo de una persona por la API', function () {
    $persona = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id, 'jefe_id' => $this->gerente->colaborador_id]);

    Sanctum::actingAs($persona);
    $respuesta = $this->getJson('/api/v1/colaborador/mi-proceso')->assertOk()
        ->assertJsonStructure(['data' => ['pendientes', 'lecciones', 'documentos', 'todo_listo']])
        ->assertJsonMissingPath('data.ciclo');

    // La persona nunca recibe los nombres internos de sus etapas.
    $texto = mb_strtolower((string) json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE));
    expect($texto)->not->toContain('onboarding')->not->toContain('periodo de prueba')->not->toContain('contratación')->not->toContain('etapa');

    // La persona no consulta fichas ajenas.
    $this->getJson("/api/v1/rh/colaboradores/{$this->gerente->colaborador_id}/ciclo")->assertForbidden();

    // Su jefe sí (cadena de mando), uno de otra sucursal no.
    Sanctum::actingAs($this->gerente);
    $this->getJson("/api/v1/rh/colaboradores/{$persona->colaborador_id}/ciclo")->assertOk()->assertJsonPath('data.colaborador.id', $persona->colaborador_id);
    Sanctum::actingAs($this->gerenteOtra);
    $this->getJson("/api/v1/rh/colaboradores/{$persona->colaborador_id}/ciclo")->assertForbidden();
});

test('reingreso por la API: misma persona, gerente solicita y solo RH decide', function () {
    $ex = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => EstadoUsuario::Inactivo,
        'estado_alta' => EstadoAltaColaborador::Baja,
        'curp' => 'APIR900101HDFPRN01',
    ]);
    $ex->delete();

    Sanctum::actingAs($this->gerente);
    $this->getJson('/api/v1/rh/reingresos/buscar?q=APIR900101HDFPRN01')->assertOk()->assertJsonPath('data.0.id', $ex->id);
    $this->getJson("/api/v1/rh/reingresos/historial/{$ex->id}")->assertOk()->assertJsonPath('data.colaborador.id', $ex->id);
    $id = $this->postJson('/api/v1/rh/reingresos', ['colaborador_id' => $ex->id, 'motivo' => 'Buen desempeño previo.'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/rh/reingresos/{$id}/decidir", ['viable' => true])->assertForbidden();

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/reingresos/{$id}/decidir", ['viable' => true, 'comentario' => 'Viable.'])->assertOk();

    expect(Reingreso::query()->findOrFail($id)->estado)->toBe(EstadoReingreso::EnContratacion)
        ->and(Colaborador::withTrashed()->where('curp', 'APIR900101HDFPRN01')->count())->toBe(1);
});

test('el bootstrap móvil trae tema, ciclo propio, capacidades del ciclo y pendientes', function () {
    Sanctum::actingAs($this->gerente);

    $this->getJson('/api/v1/mobile/bootstrap')->assertOk()
        ->assertJsonPath('theme.primary', '#164E50')
        ->assertJsonPath('ciclo_laboral.acciones.preautorizar', true)
        ->assertJsonPath('ciclo_laboral.acciones.autorizar_rh', false)
        ->assertJsonStructure(['ciclo_laboral' => ['propio' => ['por_hacer', 'en_espera', 'pendientes', 'todo_listo']], 'pendientes' => ['abiertas']])
        ->assertJsonMissingPath('ciclo_laboral.propio.etapa');

    Sanctum::actingAs($this->rh);
    $this->getJson('/api/v1/mobile/bootstrap')->assertOk()->assertJsonPath('ciclo_laboral.acciones.autorizar_rh', true);

    $this->getJson('/api/v1/app/theme')->assertOk()->assertJsonStructure(['data' => ['colors' => ['primary', 'accent', 'danger', 'background', 'surface'], 'version']]);
});
