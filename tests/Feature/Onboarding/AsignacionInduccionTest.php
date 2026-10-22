<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoOnboarding;
use App\Enums\EstadoUsuario;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoProcesoOnboarding;
use App\Models\AcuseDescriptivoPuesto;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DescriptivoPuesto;
use App\Models\EvaluacionInduccion;
use App\Models\InduccionAsignacion;
use App\Models\NotificacionEntrega;
use App\Models\OnboardingModulo;
use App\Models\OnboardingProceso;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Colaboradores\AltaColaboradorService;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\OnboardingService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| «Asignar inducción» y «Asignar descriptivo de puesto» (todos / personas /
| sucursal), «Ver como colaborador» y el acuse con «Enterado(a)…» +
| «Estoy de acuerdo con…».
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin');

    $this->modulo = OnboardingModulo::query()->create([
        'clave' => 'induccion_mr_lana_2026',
        'titulo' => 'Inducción Mr. Lana',
        'tipo' => TipoModuloOnboarding::Institucional,
        'orden' => 1,
        'evaluacion_formal_clave' => 'induccion_mr_lana_2026',
        'obligatorio' => true,
        'activo' => true,
    ]);
});

function aiPersona(object $t, array $extra = []): User
{
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $t->estructura['sucursal']->id,
        'puesto_id' => $t->estructura['puesto']->id,
        'estatus' => EstadoUsuario::Activo,
        ...$extra,
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');

    return $usuario;
}

function aiAsignar(object $t, array $datos, ?User $actor = null)
{
    return $t->actingAs($actor ?? $t->rh)->from('/induccion/presentacion')->post(route('rh.onboarding.induccion.asignaciones.store'), [
        'modo' => 'personas',
        'incluye_presentacion' => true,
        'incluye_material' => false,
        ...$datos,
    ]);
}

test('a personal ACTIVO sin onboarding le abre una reinducción, la ve en Mi espacio y recibe el aviso', function () {
    $ana = aiPersona($this);

    aiAsignar($this, ['colaborador_ids' => [$ana->colaborador_id], 'fecha_limite' => now()->addDays(5)->toDateString(), 'mensaje' => 'Revísala esta semana.'])
        ->assertRedirect('/induccion/presentacion')
        ->assertSessionHasNoErrors();

    $proceso = OnboardingProceso::query()->where('colaborador_id', $ana->colaborador_id)->sole();
    $asignacion = InduccionAsignacion::query()->sole();

    expect($proceso->tipo)->toBe(TipoProcesoOnboarding::Reinduccion)
        ->and($proceso->colaborador_abierto_id)->toBeNull()
        ->and($proceso->contrato_laboral_id)->toBeNull()
        ->and($asignacion->vigente)->toBeTrue()
        ->and($asignacion->incluye_material)->toBeFalse()
        ->and($asignacion->mensaje)->toBe('Revísala esta semana.')
        ->and(NotificacionEntrega::query()->where('user_id', $ana->id)->where('evento', 'induccion_asignada')->count())->toBe(1)
        // No cuenta como su onboarding de ingreso (ciclo laboral intacto).
        ->and(app(OnboardingService::class)->procesoActual($ana->colaborador))->toBeNull();

    $this->actingAs($ana)->get(route('portal.mi-proceso'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Portal/MiProceso')
            ->where('accesoInduccion.estado', 'disponible')
            ->where('miInduccion.es_reinduccion', true)
            ->where('miInduccion.asignacion.mensaje', 'Revísala esta semana.')
            ->where('miInduccion.pasos.1.estado', 'omitido'));

    $this->actingAs($ana)->get(route('portal.index'))
        ->assertInertia(fn (Assert $p) => $p->where('miInduccion.estado', 'disponible'));

    // La app recibe el campo nuevo sin perder los de siempre.
    $this->actingAs($ana, 'sanctum')->getJson('/api/v1/colaborador/mi-proceso')
        ->assertOk()
        ->assertJsonPath('data.accesoInduccion.estado', 'disponible')
        ->assertJsonPath('data.miInduccion.estado_rh', 'asignada');
});

test('al aprobar la reinducción se cierra SIN volver a activar al colaborador', function () {
    $ana = aiPersona($this, ['estado_alta' => EstadoAltaColaborador::Activo, 'activado_en' => now()->subYear()]);
    aiAsignar($this, ['colaborador_ids' => [$ana->colaborador_id]])->assertSessionHasNoErrors();
    $proceso = OnboardingProceso::query()->sole();

    $altas = Mockery::mock(AltaColaboradorService::class);
    $altas->shouldNotReceive('activar');
    $altas->shouldNotReceive('recalcularEstado');
    app()->instance(AltaColaboradorService::class, $altas);

    $proceso->avances()->update(['contenido_visto_en' => now()]);
    $evaluacion = app(EvaluacionInduccionService::class)->habilitarPara($proceso->refresh());
    $evaluacion->update(['estado' => EstadoEvaluacionInduccion::Aprobada]);
    app(OnboardingService::class)->aprobarAvancesFormal($proceso, 'induccion_mr_lana_2026');

    expect(app(OnboardingService::class)->cerrarSiListo($proceso, $this->rh))->toBeTrue()
        ->and($proceso->refresh()->estado)->toBe(EstadoOnboarding::Completado)
        ->and($ana->colaborador->refresh()->estado_alta)->toBe(EstadoAltaColaborador::Activo);
});

test('a quien está en onboarding de ingreso se le reasigna sobre el MISMO proceso', function () {
    $ana = aiPersona($this, ['estatus' => EstadoUsuario::EnIncorporacion]);
    $ingreso = app(OnboardingService::class)->iniciar($ana->colaborador, ContratoLaboral::factory()->create(['colaborador_id' => $ana->colaborador_id]), $this->rh);

    aiAsignar($this, ['colaborador_ids' => [$ana->colaborador_id]])->assertSessionHasNoErrors();

    expect(OnboardingProceso::query()->where('colaborador_id', $ana->colaborador_id)->count())->toBe(1)
        ->and(InduccionAsignacion::query()->sole()->onboarding_proceso_id)->toBe($ingreso->id);

    // Asignada igual: se omite y se reporta.
    aiAsignar($this, ['colaborador_ids' => [$ana->colaborador_id]])->assertSessionHas('toast.type', 'warning');
    expect(InduccionAsignacion::query()->count())->toBe(1);
});

test('retirar deja «Sin asignar» y cancela la reinducción; no se puede tras contestar', function () {
    $ana = aiPersona($this);
    aiAsignar($this, ['colaborador_ids' => [$ana->colaborador_id]]);

    $this->actingAs($this->rh)->delete(route('rh.onboarding.induccion.asignaciones.destroy', $ana->colaborador_id), ['motivo' => 'Error'])
        ->assertSessionHasNoErrors();

    expect(OnboardingProceso::query()->sole()->estado)->toBe(EstadoOnboarding::Cancelado)
        ->and(InduccionAsignacion::query()->sole()->vigente)->toBeNull();

    $this->actingAs($ana)->get(route('portal.induccion.ver'))
        ->assertInertia(fn (Assert $p) => $p->where('acceso.estado', 'sin_asignar')->where('pdf', null));

    // Reasignar y contestar: ya no se puede retirar.
    aiAsignar($this, ['colaborador_ids' => [$ana->colaborador_id]]);
    $nuevo = OnboardingProceso::query()->where('estado', '!=', EstadoOnboarding::Cancelado->value)->sole();
    EvaluacionInduccion::query()->create([
        'onboarding_proceso_id' => $nuevo->id, 'colaborador_id' => $ana->colaborador_id, 'clave' => 'induccion_mr_lana_2026',
        'version' => '2026', 'estado' => EstadoEvaluacionInduccion::PendienteCalificacionRh,
    ]);

    $this->actingAs($this->rh)->delete(route('rh.onboarding.induccion.asignaciones.destroy', $ana->colaborador_id))
        ->assertSessionHasErrors('colaborador');
});

test('por sucursal solo asigna dentro del alcance y omite a quien ya la aprobó', function () {
    $otra = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id, 'nombre' => 'Cuautla']);
    $ana = aiPersona($this);
    $beto = aiPersona($this);
    $lejos = aiPersona($this, ['sucursal_principal_id' => $otra->id]);

    // Beto ya aprobó (onboarding de ingreso cerrado).
    $cerrado = OnboardingProceso::query()->create(['colaborador_id' => $beto->colaborador_id, 'estado' => EstadoOnboarding::Completado, 'iniciado_en' => now(), 'completado_en' => now()]);
    $cerrado->avances()->create(['onboarding_modulo_id' => $this->modulo->id, 'tipo' => 'institucional', 'orden' => 1, 'estado' => 'aprobado']);
    EvaluacionInduccion::query()->create(['onboarding_proceso_id' => $cerrado->id, 'colaborador_id' => $beto->colaborador_id, 'clave' => 'induccion_mr_lana_2026', 'version' => '2026', 'estado' => EstadoEvaluacionInduccion::Aprobada]);

    $resumen = $this->actingAs($this->rh)->postJson(route('rh.onboarding.induccion.asignaciones.previsualizar'), [
        'modo' => 'sucursales', 'sucursal_ids' => [$this->estructura['sucursal']->id], 'incluye_presentacion' => true, 'incluye_material' => false,
    ])->assertOk()->json('data');

    expect($resumen['asignar'])->toBe(1)
        ->and($resumen['descripcion'])->toContain('Cuernavaca')
        ->and(collect($resumen['omitidos'])->pluck('id')->all())->toContain($beto->colaborador_id);

    aiAsignar($this, ['modo' => 'sucursales', 'sucursal_ids' => [$this->estructura['sucursal']->id]]);

    expect(InduccionAsignacion::query()->pluck('colaborador_id')->all())->toBe([$ana->colaborador_id]);

    // Un gerente con alcance de sucursal no alcanza a otra sucursal.
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $gerente->givePermissionTo('onboarding.gestionar');
    aiAsignar($this, ['colaborador_ids' => [$lejos->colaborador_id]], $gerente)->assertSessionHasErrors('colaborador_ids');
    expect(InduccionAsignacion::query()->where('colaborador_id', $lejos->colaborador_id)->exists())->toBeFalse();
});

test('sin onboarding.gestionar no se asigna ni se ve como colaborador (403)', function () {
    $ana = aiPersona($this);
    $companero = aiPersona($this);

    aiAsignar($this, ['colaborador_ids' => [$ana->colaborador_id]], $companero)->assertForbidden();
    $this->actingAs($companero)->getJson(route('rh.onboarding.asignar.colaboradores'))->assertForbidden();
    $this->actingAs($companero)->get(route('rh.onboarding.induccion.vista-colaborador', $ana->colaborador_id))->assertForbidden();

    expect(InduccionAsignacion::query()->count())->toBe(0);
});

test('el buscador devuelve la lista completa sin escribir nada', function () {
    aiPersona($this);
    aiPersona($this);

    $datos = $this->actingAs($this->rh)->getJson(route('rh.onboarding.asignar.colaboradores'))->assertOk()->json('data');

    expect(count($datos))->toBeGreaterThanOrEqual(2);
});

test('«Ver como colaborador» muestra lo mismo que Mi espacio y NO registra avance', function () {
    $ana = aiPersona($this);
    aiAsignar($this, ['colaborador_ids' => [$ana->colaborador_id]]);
    $avance = OnboardingProceso::query()->sole()->avances()->sole();

    $this->actingAs($this->rh)->get(route('rh.onboarding.induccion.vista-colaborador', $ana->colaborador_id))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Rh/Onboarding/VistaColaboradorInduccion')
            ->where('colaborador.id', $ana->colaborador_id)
            ->where('miInduccion.estado', 'disponible')
            ->where('miInduccion.presentacion.paginas_vistas', 0));

    $this->actingAs($this->rh)->get(route('rh.onboarding.induccion.vista-colaborador'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('colaborador', null)->where('miInduccion.estado', 'disponible'));

    expect($avance->refresh()->progreso_contenido)->toBeNull()
        ->and($avance->contenido_visto_en)->toBeNull()
        ->and(EvaluacionInduccion::query()->count())->toBe(0);
});

test('descriptivo: asignar por personas a personal activo, vista previa sin registrar y acuse con «Enterado» + «Estoy de acuerdo con»', function () {
    $ana = aiPersona($this);
    $descriptivo = DescriptivoPuesto::query()->create([
        'clave' => 'asesor', 'nombre_puesto' => 'Asesor comercial', 'version' => 1, 'archivo_nombre' => 'a.pdf', 'disk' => 'nas',
        'path' => 'descriptivos/a.pdf', 'sha256' => str_repeat('a', 64), 'paginas' => 1,
        'datos' => ['objetivo' => 'Colocar créditos.', 'funciones' => ['Promover productos.']],
    ]);
    $descriptivo->puestos()->attach($this->estructura['puesto']->id);

    // Vista previa antes de enviarlo: nada se crea.
    $this->actingAs($this->rh)->get(route('rh.onboarding.descriptivo-puesto.vista-colaborador', $ana->colaborador_id))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Rh/Onboarding/VistaColaboradorDescriptivo')
            ->where('acuse.id', null)
            ->where('acuse.descriptivo.objetivo', 'Colocar créditos.'));
    expect(AcuseDescriptivoPuesto::query()->count())->toBe(0);

    $this->actingAs($this->rh)->from('/rh/induccion-puesto')->post(route('rh.onboarding.descriptivo-puesto.asignaciones.store'), [
        'modo' => 'personas', 'colaborador_ids' => [$ana->colaborador_id],
    ])->assertSessionHasNoErrors();

    $acuse = AcuseDescriptivoPuesto::query()->sole();

    // Web: las dos cosas son obligatorias.
    $this->actingAs($ana)->get(route('portal.descriptivo-puesto'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Portal/DescriptivoPuesto')->where('acuse.estado', 'leido'));
    $this->actingAs($ana)->post(route('portal.descriptivo-puesto.acusar', $acuse), ['enterado' => false, 'conformidad' => ' '])
        ->assertSessionHasErrors(['enterado', 'conformidad']);
    $this->actingAs($ana)->post(route('portal.descriptivo-puesto.acusar', $acuse), ['enterado' => true, 'conformidad' => 'Estoy de acuerdo con mis funciones y horario.'])
        ->assertSessionHasNoErrors();

    $acuse->refresh();
    expect($acuse->conformidad)->toBe('Estoy de acuerdo con mis funciones y horario.')
        ->and($acuse->enterado_texto)->toContain('Enterado(a) de las actividades')
        ->and($acuse->enterado_en)->not->toBeNull();

    // Ya enterado: un segundo «Asignar» lo omite.
    $this->actingAs($this->rh)->postJson(route('rh.onboarding.descriptivo-puesto.asignaciones.previsualizar'), ['modo' => 'personas', 'colaborador_ids' => [$ana->colaborador_id]])
        ->assertOk()
        ->assertJsonPath('data.asignar', 0);
});
