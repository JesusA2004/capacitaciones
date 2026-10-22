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
| Nuevo flujo: la inducción se ASIGNA al colaborador al iniciar su onboarding
| y la ve SOLO en el PDF OFICIAL (docs/onboarding), sin que RH la presente en
| vivo. El visor registra las páginas mostradas; al terminar (todas las hojas)
| se marca el contenido visto y se habilita la evaluación.
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

test('la inducción asignada deja ver el PDF oficial del repositorio, sin sesión', function () {
    $oficial = base_path('docs/onboarding/Induccion_Mr_Lana_Rediseno (5).pdf');

    $respuesta = $this->actingAs($this->usuario)->get(route('induccion.pdf'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect(hash_file('sha256', $respuesta->baseResponse->getFile()->getPathname()))->toBe(hash_file('sha256', $oficial))
        ->and($this->paginas)->toBe(30)
        ->and($respuesta->headers->get('Content-Disposition'))->toContain('Induccion Mr. Lana.pdf')
        ->toContain(rawurlencode('Inducción Mr. Lana.pdf'))
        ->not->toContain('Rediseno');

    Sanctum::actingAs($this->usuario);
    $this->get('/api/v1/induccion/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('sin inducción asignada no hay PDF', function () {
    $ajeno = Colaborador::factory()->create();
    $usuarioAjeno = User::factory()->create(['colaborador_id' => $ajeno->id]);
    $usuarioAjeno->assignRole('colaborador');

    $this->actingAs($usuarioAjeno)->get(route('induccion.pdf'))->assertForbidden();
});

test('un invitado sin sesión es redirigido al login', function () {
    $this->get(route('induccion.pdf'))->assertRedirect();
});

test('«Presentar inducción» muestra el visor del PDF (sin capítulos HTML) y RH no registra progreso', function () {
    $this->actingAs($this->rh)->get(route('induccion.presentacion'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Portal/Induccion')
            ->where('modoPresentacion', true)
            ->where('pdf.url', route('induccion.pdf'))
            ->where('pdf.total_paginas', 30)
            ->missing('capitulos'));

    // RH no es el titular: no puede registrar páginas de nadie.
    $this->actingAs($this->rh)->postJson(route('portal.induccion.pagina', $this->avance), ['pagina' => 1])->assertForbidden();
    expect($this->avance->refresh()->progreso_contenido)->toBeNull();
});

test('«Inducción institucional» solo ofrece subir material con onboarding.configurar', function () {
    $this->actingAs($this->rh)->get(route('induccion.presentacion'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Portal/Induccion')
            ->where('puedeConfigurarMaterial', true)
            ->where('rutasMaterial.store', route('rh.onboarding.induccion.material.store')));

    // Presenta y asigna, pero no configura: sin formulario ni rutas de carga.
    $presentador = clUsuario('colaborador');
    $presentador->givePermissionTo(['onboarding.gestionar', 'onboarding.presentar_induccion']);

    expect($presentador->can('onboarding.configurar'))->toBeFalse();

    $this->actingAs($presentador)->get(route('induccion.presentacion'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Portal/Induccion')
            ->where('puedeConfigurarMaterial', false)
            ->where('rutasMaterial', null));

    // Y el endpoint sigue rechazando la carga (la regla vive en el backend).
    $this->actingAs($presentador)->post(route('rh.onboarding.induccion.material.store'), ['tipo' => 'audio'])->assertForbidden();
});

test('el colaborador registra páginas, no termina antes y al terminar pasa a la evaluación', function () {
    $this->actingAs($this->usuario)->get(route('portal.induccion.capitulos', $this->avance))
        ->assertRedirect(route('portal.induccion.ver'));

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

test('API móvil: mismo avance por página (con asignación) y terminar habilita la evaluación', function () {
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
