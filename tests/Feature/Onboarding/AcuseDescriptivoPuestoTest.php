<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoUsuario;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoTarea;
use App\Models\AcuseDescriptivoPuesto;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DescriptivoPuesto;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingModulo;
use App\Models\OnboardingProceso;
use App\Models\Puesto;
use App\Models\TareaRh;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\SesionInduccionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| «Enviar descriptivo de puesto»: tras aprobar la evaluación de inducción,
| RH envía el descriptivo OFICIAL vigente del puesto → el colaborador lo lee
| en la app, marca «OK, enterado» y deja su comentario → RH lo ve.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'America/Mexico_City'));
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();

    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin');

    OnboardingModulo::query()->create([
        'clave' => 'induccion_mr_lana_2026',
        'titulo' => 'Inducción Mr. Lana',
        'tipo' => TipoModuloOnboarding::Institucional,
        'orden' => 1,
        'evaluacion_formal_clave' => 'induccion_mr_lana_2026',
        'obligatorio' => true,
        'activo' => true,
    ]);

    $this->descriptivo = adpDescriptivo($this->estructura['puesto']);
    $this->ana = adpNuevoIngreso($this, $this->estructura['puesto']);
});

afterEach(fn () => Carbon::setTestNow());

function adpDescriptivo(Puesto $puesto, int $version = 1): DescriptivoPuesto
{
    $descriptivo = DescriptivoPuesto::query()->create([
        'clave' => 'asesor_comercial',
        'nombre_puesto' => 'Asesor comercial',
        'version' => $version,
        'archivo_nombre' => 'Asesor comercial.pdf',
        'disk' => 'nas',
        'path' => "descriptivos/asesor-v{$version}.pdf",
        'sha256' => str_repeat((string) $version, 64),
        'paginas' => 3,
        'datos' => [
            'nombre_puesto' => 'Asesor comercial',
            'area' => 'Comercial',
            'edad' => '25 a 40',
            'sexo' => 'Indistinto',
            'objetivo' => 'Colocar créditos con calidad de cartera.',
            'funciones' => ['Promover los productos de la sucursal.', 'Integrar expedientes de crédito.', ''],
            'conocimientos' => [['conocimiento' => 'Ventas', 'funcion' => 'Prospectar clientes', 'anios' => '1']],
            'competencias' => [],
            'actividades_criticas' => ['Cobranza preventiva.'],
            'facultades' => [],
            'metadatos' => ['fecha_revision' => '01/09/2026'],
        ],
    ]);
    $descriptivo->puestos()->attach($puesto->id);

    return $descriptivo;
}

/**
 * @return array{colaborador: Colaborador, usuario: User, proceso: OnboardingProceso}
 */
function adpNuevoIngreso(object $t, Puesto $puesto): array
{
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $t->estructura['sucursal']->id,
        'puesto_id' => $puesto->id,
        'estatus' => EstadoUsuario::EnIncorporacion,
        'estado_alta' => EstadoAltaColaborador::EnOnboarding,
        'fecha_ingreso' => now()->toDateString(),
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id]);
    $proceso = app(OnboardingService::class)->iniciar($colaborador, $contrato, $t->rh);

    return ['colaborador' => $colaborador, 'usuario' => $usuario, 'proceso' => $proceso];
}

function adpEvaluacion(array $persona, EstadoEvaluacionInduccion $estado): EvaluacionInduccion
{
    return EvaluacionInduccion::query()->create([
        'onboarding_proceso_id' => $persona['proceso']->id,
        'colaborador_id' => $persona['colaborador']->id,
        'clave' => 'induccion_mr_lana_2026',
        'version' => '2026',
        'estado' => $estado,
        'aprobada_en' => $estado === EstadoEvaluacionInduccion::Aprobada ? now() : null,
    ]);
}

function adpAvisos(User $usuario, string $tipo): int
{
    return Notification::sent($usuario, PendienteRhNotification::class)
        ->filter(fn (PendienteRhNotification $n): bool => $n->toDatabase($usuario)['tipo'] === $tipo)
        ->count();
}

test('no se puede enviar el descriptivo antes de que el colaborador apruebe su evaluación de inducción', function () {
    adpEvaluacion($this->ana, EstadoEvaluacionInduccion::PendienteCalificacionRh);

    $this->actingAs($this->rh)
        ->post("/rh/colaboradores/{$this->ana['colaborador']->id}/descriptivo-puesto/enviar")
        ->assertSessionHasErrors(['descriptivo' => 'Se habilita cuando el colaborador termina y aprueba su evaluación de inducción.']);

    expect(AcuseDescriptivoPuesto::query()->count())->toBe(0)
        ->and(adpAvisos($this->ana['usuario'], 'descriptivo_puesto_enviado'))->toBe(0);
});

test('RH envía el descriptivo vigente: queda pendiente para el colaborador y le llega el aviso en la app; reenviar no duplica', function () {
    $evaluacion = adpEvaluacion($this->ana, EstadoEvaluacionInduccion::Aprobada);
    // Versión nueva del mismo descriptivo: se envía la vigente.
    $v2 = adpDescriptivo($this->estructura['puesto'], 2);

    $this->actingAs($this->rh)
        ->from('/induccion/presentacion')
        ->post("/rh/colaboradores/{$this->ana['colaborador']->id}/descriptivo-puesto/enviar")
        ->assertRedirect('/induccion/presentacion')
        ->assertSessionHasNoErrors();

    $acuse = AcuseDescriptivoPuesto::query()->sole();

    expect($acuse->descriptivo_puesto_id)->toBe($v2->id)
        ->and($acuse->descriptivo_version)->toBe(2)
        ->and($acuse->evaluacion_induccion_id)->toBe($evaluacion->id)
        ->and($acuse->enviado_por)->toBe($this->rh->id)
        ->and($acuse->acusado_en)->toBeNull()
        ->and(TareaRh::query()->where('tipo', TipoTarea::DescriptivoPuestoPorAcusar->value)->where('asignado_user_id', $this->ana['usuario']->id)->whereNull('resuelta_en')->count())->toBe(1)
        ->and(adpAvisos($this->ana['usuario'], 'descriptivo_puesto_enviado'))->toBe(1);

    // Reenviar mientras no lo acusa: mismo acuse, solo recordatorio.
    $this->actingAs($this->rh)->post("/rh/colaboradores/{$this->ana['colaborador']->id}/descriptivo-puesto/enviar")->assertSessionHasNoErrors();

    expect(AcuseDescriptivoPuesto::query()->count())->toBe(1)
        ->and(TareaRh::query()->where('tipo', TipoTarea::DescriptivoPuestoPorAcusar->value)->count())->toBe(1);
});

test('sin descriptivo oficial importado para su puesto no se envía nada', function () {
    $otroPuesto = Puesto::factory()->create(['nombre' => 'Gestor de cobranza']);
    $beto = adpNuevoIngreso($this, $otroPuesto);
    adpEvaluacion($beto, EstadoEvaluacionInduccion::Aprobada);

    $this->actingAs($this->rh)
        ->post("/rh/colaboradores/{$beto['colaborador']->id}/descriptivo-puesto/enviar")
        ->assertSessionHasErrors(['descriptivo' => 'El puesto «Gestor de cobranza» todavía no tiene descriptivo oficial importado.']);

    expect(AcuseDescriptivoPuesto::query()->count())->toBe(0);
});

test('solo RH con permiso de onboarding puede enviarlo (web y API)', function () {
    adpEvaluacion($this->ana, EstadoEvaluacionInduccion::Aprobada);
    $companero = clUsuario('colaborador', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    $this->actingAs($companero)->post("/rh/colaboradores/{$this->ana['colaborador']->id}/descriptivo-puesto/enviar")->assertForbidden();
    $this->actingAs($companero, 'sanctum')->postJson("/api/v1/rh/colaboradores/{$this->ana['colaborador']->id}/descriptivo-puesto/enviar")->assertForbidden();
    $this->actingAs($companero, 'sanctum')->getJson("/api/v1/rh/colaboradores/{$this->ana['colaborador']->id}/descriptivo-puesto")->assertForbidden();

    expect(AcuseDescriptivoPuesto::query()->count())->toBe(0);
});

test('app: el colaborador lee su descriptivo (contenido del PDF, sin edad ni sexo), marca «OK, enterado» con comentario y RH lo ve', function () {
    adpEvaluacion($this->ana, EstadoEvaluacionInduccion::Aprobada);

    $this->actingAs($this->rh, 'sanctum')
        ->postJson("/api/v1/rh/colaboradores/{$this->ana['colaborador']->id}/descriptivo-puesto/enviar")
        ->assertOk()
        ->assertJsonPath('data.acuse.estado', 'enviado')
        ->assertJsonPath('data.puede_enviar', true);

    $acuse = AcuseDescriptivoPuesto::query()->sole();

    $respuesta = $this->actingAs($this->ana['usuario'], 'sanctum')
        ->getJson('/api/v1/colaborador/descriptivo-puesto')
        ->assertOk()
        ->assertJsonPath('data.id', $acuse->id)
        ->assertJsonPath('data.estado', 'enviado')
        ->assertJsonPath('data.descriptivo.nombre_puesto', 'Asesor comercial')
        ->assertJsonPath('data.descriptivo.objetivo', 'Colocar créditos con calidad de cartera.')
        ->assertJsonPath('data.descriptivo.secciones.0.clave', 'funciones')
        ->assertJsonPath('data.descriptivo.secciones.0.elementos', ['Promover los productos de la sucursal.', 'Integrar expedientes de crédito.'])
        ->json('data');

    expect(collect($respuesta['descriptivo']['secciones'])->pluck('clave')->all())->toBe(['funciones', 'actividades_criticas', 'conocimientos'])
        ->and(json_encode($respuesta))->not->toContain('Indistinto')
        ->and(json_encode($respuesta))->not->toContain('25 a 40');

    $this->actingAs($this->ana['usuario'], 'sanctum')
        ->postJson("/api/v1/colaborador/descriptivo-puesto/{$acuse->id}/leido")
        ->assertOk()
        ->assertJsonPath('data.estado', 'leido');

    // Las dos cosas son obligatorias.
    $this->actingAs($this->ana['usuario'], 'sanctum')
        ->postJson("/api/v1/colaborador/descriptivo-puesto/{$acuse->id}/acusar", ['enterado' => false, 'comentario' => '  '])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['enterado', 'comentario']);

    $this->actingAs($this->ana['usuario'], 'sanctum')
        ->postJson("/api/v1/colaborador/descriptivo-puesto/{$acuse->id}/acusar", ['enterado' => true, 'comentario' => 'Doy por enterado de mis actividades y responsabilidades.'])
        ->assertOk()
        ->assertJsonPath('data.estado', 'enterado')
        ->assertJsonPath('data.enterado', true)
        ->assertJsonPath('data.comentario', 'Doy por enterado de mis actividades y responsabilidades.');

    expect(TareaRh::query()->where('tipo', TipoTarea::DescriptivoPuestoPorAcusar->value)->whereNull('resuelta_en')->count())->toBe(0)
        ->and(adpAvisos($this->rh, 'descriptivo_puesto_acusado'))->toBe(1);

    // Ya acusado: no se modifica ni se reenvía.
    $this->actingAs($this->ana['usuario'], 'sanctum')
        ->postJson("/api/v1/colaborador/descriptivo-puesto/{$acuse->id}/acusar", ['enterado' => true, 'comentario' => 'Otro'])
        ->assertStatus(422);

    $this->actingAs($this->rh, 'sanctum')
        ->getJson("/api/v1/rh/colaboradores/{$this->ana['colaborador']->id}/descriptivo-puesto")
        ->assertOk()
        ->assertJsonPath('data.acuse.estado', 'enterado')
        ->assertJsonPath('data.acuse.comentario', 'Doy por enterado de mis actividades y responsabilidades.')
        ->assertJsonPath('data.puede_enviar', false);

    // Seguimiento de «Presentar inducción» (web): RH ve el estado y el comentario.
    $fila = collect(app(SesionInduccionService::class)->seguimiento($this->rh))
        ->firstWhere('colaborador.id', $this->ana['colaborador']->id);

    expect($fila['descriptivo_puesto']['acuse']['comentario'])->toBe('Doy por enterado de mis actividades y responsabilidades.')
        ->and($fila['descriptivo_puesto']['acuse']['estado_etiqueta'])->toBe('Enterado');
});

test('nadie más puede leer ni acusar el descriptivo de otro colaborador', function () {
    adpEvaluacion($this->ana, EstadoEvaluacionInduccion::Aprobada);
    $this->actingAs($this->rh)->post("/rh/colaboradores/{$this->ana['colaborador']->id}/descriptivo-puesto/enviar");
    $acuse = AcuseDescriptivoPuesto::query()->sole();

    $otro = clUsuario('colaborador', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    $this->actingAs($otro, 'sanctum')->getJson('/api/v1/colaborador/descriptivo-puesto')->assertOk()->assertJsonPath('data', null);
    $this->actingAs($otro, 'sanctum')->postJson("/api/v1/colaborador/descriptivo-puesto/{$acuse->id}/leido")->assertForbidden();
    $this->actingAs($otro, 'sanctum')
        ->postJson("/api/v1/colaborador/descriptivo-puesto/{$acuse->id}/acusar", ['enterado' => true, 'comentario' => 'Enterado'])
        ->assertForbidden();

    expect($acuse->refresh()->acusado_en)->toBeNull()
        ->and($acuse->leido_en)->toBeNull();
});

test('revisión de la evaluación (web): RH ve el estado del descriptivo y el botón disponible tras aprobar', function () {
    $evaluacion = adpEvaluacion($this->ana, EstadoEvaluacionInduccion::Aprobada);
    $intento = $evaluacion->intentos()->create([
        'numero' => 1,
        'presentado_por' => $this->ana['usuario']->id,
        'respuestas' => ['a' => [], 'b' => [], 'c' => [], 'd' => []],
        'presentado_en' => now(),
        'calificacion_a' => 10, 'calificacion_b' => 5, 'calificacion_c' => 5, 'calificacion_d' => 10,
        'calificacion_total' => 30,
        'aprobado' => true,
        'calificado_por' => $this->rh->id,
        'calificado_en' => now(),
    ]);

    $this->actingAs($this->rh)
        ->get("/rh/onboarding/induccion/intentos/{$intento->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rh/Onboarding/CalificarInduccion')
            ->where('descriptivoPuesto.puede_enviar', true)
            ->where('descriptivoPuesto.acuse', null));
});
