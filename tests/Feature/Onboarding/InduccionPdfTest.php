<?php

use App\Enums\EstadoUsuario;
use App\Enums\TipoModuloOnboarding;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingModulo;
use App\Models\User;
use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\OnboardingService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
| La inducción se ve en el PDF OFICIAL (docs/onboarding), nunca en una
| reconstrucción HTML. RH la presenta sin registrar progreso; el
| colaborador avanza por páginas MOSTRADAS y al terminarlas se habilita su
| evaluación formal (cuya calificación no cambia).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $estructura = clEstructura();

    OnboardingModulo::query()->create([
        'clave' => 'induccion_mr_lana_2026',
        'titulo' => 'Inducción Mr. Lana',
        'tipo' => TipoModuloOnboarding::Institucional,
        'orden' => 1,
        'evaluacion_formal_clave' => 'induccion_mr_lana_2026',
        'obligatorio' => true,
        'activo' => true,
    ]);

    $this->colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'estatus' => EstadoUsuario::EnIncorporacion,
        'fecha_ingreso' => now()->toDateString(),
    ]);
    $this->usuario = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->usuario->assignRole('colaborador');
    $proceso = app(OnboardingService::class)->iniciar($this->colaborador, ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id]), $this->rh);
    $this->avance = $proceso->avances()->firstOrFail();
    $this->paginas = app(InduccionPdfService::class)->totalPaginas();
});

test('el endpoint sirve exactamente el PDF oficial del repositorio, solo con sesión', function () {
    $oficial = base_path('docs/onboarding/Induccion_Mr_Lana_Rediseno (5).pdf');

    $respuesta = $this->actingAs($this->usuario)->get(route('induccion.pdf'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect(hash_file('sha256', $respuesta->baseResponse->getFile()->getPathname()))->toBe(hash_file('sha256', $oficial))
        ->and($this->paginas)->toBe(30)
        // Nombre amigable; el del archivo fuente nunca se ve.
        ->and($respuesta->headers->get('Content-Disposition'))->toContain('Induccion Mr. Lana.pdf')
        ->toContain(rawurlencode('Inducción Mr. Lana.pdf'))
        ->not->toContain('Rediseno');

    Sanctum::actingAs($this->usuario);
    $this->get('/api/v1/induccion/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');

});

test('sin sesión no hay PDF de inducción', function () {
    $this->get(route('induccion.pdf'))->assertRedirect();
    $this->getJson('/api/v1/induccion/pdf')->assertUnauthorized();
});

test('Presentar inducción muestra el visor del PDF (sin capítulos HTML) y no registra progreso', function () {
    $this->actingAs($this->rh)->get(route('induccion.presentacion'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Portal/Induccion')
            ->where('modoPresentacion', true)
            ->where('avanceId', null)
            ->where('pdf.url', route('induccion.pdf'))
            ->where('pdf.total_paginas', 30)
            ->missing('capitulos'));

    // RH no es el titular: no puede registrar páginas de nadie.
    $this->actingAs($this->rh)->postJson(route('portal.induccion.pagina', $this->avance), ['pagina' => 1])->assertForbidden();
    expect($this->avance->refresh()->progreso_contenido)->toBeNull();
});

test('el colaborador avanza por páginas mostradas, no puede terminar antes y al terminar pasa a su evaluación', function () {
    $this->actingAs($this->usuario)->get(route('portal.induccion.capitulos', $this->avance))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Portal/Induccion')->where('avanceId', $this->avance->id)->where('resumen.paginas_vistas', []));

    $this->actingAs($this->usuario)->postJson(route('portal.induccion.pagina', $this->avance), ['pagina' => 1])
        ->assertOk()
        ->assertJsonPath('data.paginas_vistas', [1]);
    $this->actingAs($this->usuario)->postJson(route('portal.induccion.pagina', $this->avance), ['pagina' => 31])->assertUnprocessable();

    // Saltar a la última no cuenta las intermedias como vistas.
    $this->actingAs($this->usuario)->postJson(route('portal.induccion.pagina', $this->avance), ['pagina' => 30])->assertOk();
    $this->actingAs($this->usuario)->post(route('portal.induccion.terminar', $this->avance))->assertSessionHasErrors('induccion');
    expect($this->avance->refresh()->contenido_visto_en)->toBeNull()
        ->and(EvaluacionInduccion::query()->count())->toBe(0);

    foreach (range(2, 29) as $pagina) {
        $this->actingAs($this->usuario)->postJson(route('portal.induccion.pagina', $this->avance), ['pagina' => $pagina])->assertOk();
    }

    $this->actingAs($this->usuario)->post(route('portal.induccion.terminar', $this->avance))
        ->assertRedirect(route('portal.induccion.contenido'));

    expect($this->avance->refresh()->contenido_visto_en)->not->toBeNull()
        ->and(EvaluacionInduccion::query()->where('colaborador_id', $this->colaborador->id)->exists())->toBeTrue();
});

test('API móvil: mismo avance por página y terminar habilita la evaluación', function () {
    Sanctum::actingAs($this->usuario);

    $this->getJson("/api/v1/colaborador/induccion/capitulos/{$this->avance->id}")
        ->assertOk()
        ->assertJsonPath('data.pdf.url', route('api.v1.induccion.pdf'))
        ->assertJsonPath('data.pdf.total_paginas', 30);

    foreach (range(1, 30) as $pagina) {
        $this->postJson("/api/v1/colaborador/induccion/capitulos/{$this->avance->id}/pagina", ['pagina' => $pagina])->assertOk();
    }

    $this->postJson("/api/v1/colaborador/induccion/capitulos/{$this->avance->id}/terminar")
        ->assertOk()
        ->assertJsonPath('data.resumen.completado', true)
        ->assertJsonPath('data.evaluacionInduccion.puede_presentar', true);
});
