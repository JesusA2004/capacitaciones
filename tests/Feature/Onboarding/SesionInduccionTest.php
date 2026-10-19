<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoUsuario;
use App\Enums\TipoModuloOnboarding;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingModulo;
use App\Models\OnboardingProceso;
use App\Models\SesionInduccion;
use App\Models\User;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\SesionInduccionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Nuevo flujo (2026): la inducción se ASIGNA y el colaborador la ve solo; ya
| no hay «sesión en vivo» en el camino principal. El flujo legacy de sesiones
| se conserva (modelos/tablas/Service) pero sus rutas quedan tras el flag
| features.induccion_sesiones_legacy (apagado por defecto). Aquí se cubre el
| nuevo gate y que el legacy «no rompe» y queda apagado.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'America/Mexico_City'));
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    Storage::fake('nas');

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

    $this->sesiones = app(SesionInduccionService::class);
    $this->evaluaciones = app(EvaluacionInduccionService::class);
    $this->ana = siNuevoIngreso($this, $this->estructura['sucursal']->id);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @return array{colaborador: Colaborador, usuario: User, proceso: OnboardingProceso}
 */
function siNuevoIngreso(object $t, int $sucursalId): array
{
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursalId,
        'puesto_id' => $t->estructura['puesto']->id,
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

test('nuevo gate: la inducción asignada es visible sin ninguna sesión', function () {
    $this->actingAs($this->ana['usuario'])->get(route('portal.induccion.ver'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Portal/Induccion')
            ->where('acceso.estado', 'disponible')
            ->where('acceso.puede_ver_contenido', true)
            ->where('pdf.url', route('induccion.pdf')));
    $this->actingAs($this->ana['usuario'])->get(route('induccion.pdf'))->assertOk();

    $this->actingAs($this->ana['usuario'])->get(route('portal.mi-proceso'))
        ->assertInertia(fn ($page) => $page->where('accesoInduccion.estado', 'disponible'));
});

test('rutas legacy de sesiones apagadas por defecto; el Service no se rompe', function () {
    // Con la bandera apagada, las rutas de escritura responden 403.
    $this->actingAs($this->rh)
        ->post('/rh/induccion/sesiones', ['colaborador_ids' => [$this->ana['colaborador']->id]])
        ->assertForbidden();

    Sanctum::actingAs($this->rh);
    $this->postJson('/api/v1/rh/induccion/sesiones', ['colaborador_ids' => [$this->ana['colaborador']->id]])
        ->assertForbidden();

    // El Service sigue construyéndose y operando (historial auditable intacto).
    $sesion = $this->sesiones->iniciar($this->rh, [$this->ana['colaborador']->id]);
    expect($sesion)->toBeInstanceOf(SesionInduccion::class)
        ->and(SesionInduccion::query()->count())->toBe(1);
});

test('legacy (flag encendido): enviar evaluación marca contenido visto por RH, habilita y avisa', function () {
    config(['features.induccion_sesiones_legacy' => true]);

    $sesion = $this->sesiones->iniciar($this->rh, [$this->ana['colaborador']->id]);
    $this->sesiones->enviarEvaluacion($sesion, $this->rh);

    $evaluacion = EvaluacionInduccion::query()->where('colaborador_id', $this->ana['colaborador']->id)->sole();
    $avance = $this->ana['proceso']->avances()->firstOrFail();

    expect($evaluacion->estado)->toBe(EstadoEvaluacionInduccion::Disponible)
        ->and($avance->refresh()->contenido_visto_en)->not->toBeNull()
        ->and($avance->progreso_contenido['presentada_por_rh']['sesion_id'])->toBe($sesion->id);
});

test('aprobar la evaluación deja la inducción «completada» y sin PDF', function () {
    $avance = $this->ana['proceso']->avances()->firstOrFail();
    app(OnboardingService::class)->marcarContenidoVisto($avance, $this->ana['usuario']);
    $evaluacion = $this->evaluaciones->habilitarPara($this->ana['proceso']->refresh());
    $intento = $this->evaluaciones->presentar($evaluacion, $this->ana['usuario'], siRespuestas(), null);
    $this->evaluaciones->calificarCasosPracticos($intento, $this->rh, siPuntosD());

    expect($evaluacion->refresh()->estado)->toBe(EstadoEvaluacionInduccion::Aprobada);

    $this->actingAs($this->ana['usuario'])->get(route('portal.induccion.ver'))
        ->assertInertia(fn ($page) => $page->where('pdf', null)->where('acceso.estado', 'completada'));
    $this->actingAs($this->ana['usuario'])->get(route('induccion.pdf'))->assertForbidden();
});

function siRespuestas(): array
{
    $a = [];
    foreach (config('induccion_evaluacion.seccion_a') as $i => $p) {
        $a[$i] = $p['correcta'];
    }
    $b = [];
    foreach (config('induccion_evaluacion.seccion_b') as $i => $p) {
        $b[$i] = $p['correcto'];
    }
    $c = [];
    foreach (config('induccion_evaluacion.seccion_c.conceptos') as $i => $concepto) {
        $c[$i] = $concepto['letra_correcta'];
    }
    $d = [];
    foreach (config('induccion_evaluacion.seccion_d') as $caso) {
        $d[$caso['clave']] = 'Respuesta para '.$caso['clave'].'.';
    }

    return ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d];
}

function siPuntosD(): array
{
    $puntos = [];
    foreach (config('induccion_evaluacion.seccion_d') as $caso) {
        foreach ($caso['rubrica'] as $criterio) {
            $puntos[$caso['clave']][$criterio['clave']] = true;
        }
    }

    return $puntos;
}
