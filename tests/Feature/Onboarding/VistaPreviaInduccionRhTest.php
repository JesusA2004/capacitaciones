<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoMultimedia;
use App\Enums\EstadoUsuario;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoRecursoMultimedia;
use App\Models\AcuseDescriptivoPuesto;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DescriptivoPuesto;
use App\Models\EvaluacionInduccion;
use App\Models\EvaluacionInduccionIntento;
use App\Models\IntervaloVideoInduccion;
use App\Models\OnboardingModulo;
use App\Models\RecursoMultimedia;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\Onboarding\MaterialInduccionService;
use App\Services\Onboarding\OnboardingService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Regla: todo lo que se le presenta al colaborador, RH lo ve TAL CUAL antes
| de habilitarlo — evaluación FO-RH-EI, material de explicación y
| descriptivo de puesto. Las vistas previas no crean ni registran nada.
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

    $this->colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'estatus' => EstadoUsuario::EnIncorporacion,
        'estado_alta' => EstadoAltaColaborador::EnOnboarding,
        'fecha_ingreso' => now()->toDateString(),
    ]);
    $this->usuario = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->usuario->assignRole('colaborador');
    $this->proceso = app(OnboardingService::class)->iniciar(
        $this->colaborador,
        ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id]),
        $this->rh,
    );
});

test('RH ve la evaluación de inducción como colaborador: misma página, sin evaluación y sin crear nada', function () {
    $evaluaciones = EvaluacionInduccion::query()->count();
    $intentos = EvaluacionInduccionIntento::query()->count();

    $this->actingAs($this->rh)
        ->get('/rh/onboarding/induccion/vista-previa')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/EvaluacionInduccion')
            ->where('vistaPrevia', true)
            ->where('evaluacionId', null)
            ->has('contenido.seccion_a')
            ->has('contenido.seccion_d')
            // Nunca la clave de respuestas ni la rúbrica.
            ->missing('contenido.seccion_a.0.correcta')
            ->missing('contenido.seccion_d.0.rubrica'));

    expect(EvaluacionInduccion::query()->count())->toBe($evaluaciones)
        ->and(EvaluacionInduccionIntento::query()->count())->toBe($intentos);
});

test('un colaborador sin permiso de RH no puede ver las vistas previas (403)', function () {
    $this->actingAs($this->usuario)->get('/rh/onboarding/induccion/vista-previa')->assertForbidden();
    $this->actingAs($this->usuario)->getJson('/rh/onboarding/induccion/material/vista-previa')->assertForbidden();
    $this->actingAs($this->usuario)
        ->get("/rh/colaboradores/{$this->colaborador->id}/descriptivo-puesto/vista-previa")
        ->assertForbidden();
});

test('RH escucha/ve el material completo sin anti-adelanto y sin registrar avance del colaborador', function () {
    Storage::fake(config('media.disk'));
    Storage::disk(config('media.disk'))->put('hls/demo/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000\n360p.m3u8\n");
    Storage::disk(config('media.disk'))->put('hls/demo/360p.m3u8', "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6.0,\n360p_0.ts\n#EXTINF:6.0,\n360p_1.ts\n#EXTINF:6.0,\n360p_2.ts\n#EXT-X-ENDLIST\n");
    Storage::disk(config('media.disk'))->put('hls/demo/360p_2.ts', 'segmento');

    $video = RecursoMultimedia::factory()->create([
        'tipo' => TipoRecursoMultimedia::Video,
        'estado' => EstadoMultimedia::Disponible,
        'duracion_segundos' => 18,
        'ruta_hls_manifiesto' => 'hls/demo/master.m3u8',
        'subido_por' => $this->rh->id,
    ]);
    app(MaterialInduccionService::class)->activar($this->modulo, $video, $this->rh);
    $avance = $this->proceso->avances()->firstOrFail();
    $progresoAntes = $avance->progreso_contenido;

    $datos = $this->actingAs($this->rh)
        ->getJson('/rh/onboarding/induccion/material/vista-previa')
        ->assertOk()
        ->assertJsonPath('data.tipo', 'video')
        ->assertJsonPath('data.disponible', true)
        ->json('data');

    $maestro = $this->actingAs($this->rh)->get($datos['video_url'])->assertOk()->getContent();
    expect($maestro)->toContain('vista-previa/video/360.m3u8');

    // Variante COMPLETA (VOD con los 3 segmentos), no truncada al avance.
    $variante = $this->actingAs($this->rh)
        ->get(URL::temporarySignedRoute('rh.onboarding.induccion.material.vista-previa.variante', now()->addMinutes(5), ['altura' => 360]))
        ->assertOk()
        ->getContent();
    expect($variante)->toContain('#EXT-X-ENDLIST')->toContain('360p_2.ts');

    // El último segmento se sirve aunque RH «nunca lo haya visto».
    $this->actingAs($this->rh)
        ->get(URL::temporarySignedRoute('rh.onboarding.induccion.material.vista-previa.segmento', now()->addMinutes(5), ['altura' => 360, 'archivo' => '360p_2.ts']))
        ->assertOk();

    // Sin firma: rechazado.
    $this->actingAs($this->rh)->get('/rh/onboarding/induccion/material/vista-previa/video/360.m3u8')->assertForbidden();

    expect(IntervaloVideoInduccion::query()->count())->toBe(0)
        ->and($avance->refresh()->progreso_contenido)->toBe($progresoAntes);
});

test('«Ver lo que recibirá»: RH ve el PDF del descriptivo vigente sin crear acuse, aviso ni auditoría', function () {
    Storage::fake('nas');
    Storage::disk('nas')->put('descriptivos/asesor-v1.pdf', '%PDF-1.4 vista previa');

    $descriptivo = DescriptivoPuesto::query()->create([
        'clave' => 'asesor_comercial',
        'nombre_puesto' => 'Asesor comercial',
        'version' => 1,
        'archivo_nombre' => 'Asesor comercial.pdf',
        'disk' => 'nas',
        'path' => 'descriptivos/asesor-v1.pdf',
        'sha256' => str_repeat('1', 64),
        'paginas' => 1,
        'datos' => ['nombre_puesto' => 'Asesor comercial', 'funciones' => ['Promover productos.']],
    ]);
    $descriptivo->puestos()->attach($this->estructura['puesto']->id);

    EvaluacionInduccion::query()->create([
        'onboarding_proceso_id' => $this->proceso->id,
        'colaborador_id' => $this->colaborador->id,
        'clave' => 'induccion_mr_lana_2026',
        'version' => '2026',
        'estado' => EstadoEvaluacionInduccion::Aprobada,
        'aprobada_en' => now(),
    ]);
    $auditorias = Activity::query()->count();

    $respuesta = $this->actingAs($this->rh)
        ->get("/rh/colaboradores/{$this->colaborador->id}/descriptivo-puesto/vista-previa")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect($respuesta->headers->get('Content-Disposition'))->toContain('inline');

    expect(AcuseDescriptivoPuesto::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe($auditorias);
    $avisosDescriptivo = Notification::sent($this->usuario, PendienteRhNotification::class)
        ->filter(fn (PendienteRhNotification $n): bool => $n->toDatabase($this->usuario)['tipo'] === 'descriptivo_puesto_enviado');
    expect($avisosDescriptivo)->toHaveCount(0);
});

test('«Calificaciones y refuerzo» lista la inducción de cada colaborador (no el periodo de prueba) y es solo para RH', function () {
    EvaluacionInduccion::query()->create([
        'onboarding_proceso_id' => $this->proceso->id,
        'colaborador_id' => $this->colaborador->id,
        'clave' => 'induccion_mr_lana_2026',
        'version' => '2026',
        'estado' => EstadoEvaluacionInduccion::RequiereReforzamiento,
        'ultima_calificacion' => 20,
        'mejor_calificacion' => 20,
        'intentos_count' => 1,
    ]);

    $this->actingAs($this->rh)
        ->get('/rh/onboarding/calificaciones')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Rh/Onboarding/CalificacionesInduccion')
            ->has('filas', 1)
            ->where('filas.0.colaborador.id', $this->colaborador->id)
            ->where('filas.0.etapa', 'reforzamiento')
            ->where('filas.0.calificacion', 20)
            ->has('filas.0.modulos'));

    $this->actingAs($this->usuario)->get('/rh/onboarding/calificaciones')->assertForbidden();
});
