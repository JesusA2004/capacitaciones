<?php

use App\Enums\EstadoIntentoCuestionario;
use App\Models\BancoPregunta;
use App\Models\Cuestionario;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\InscripcionCurso;
use App\Models\IntentoCuestionario;
use App\Models\Leccion;
use App\Models\Pregunta;
use App\Models\User;
use App\Services\Capacitacion\ProgresoService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->colaborador = User::factory()->create();
    $this->colaborador->assignRole('colaborador');

    $this->instructor = User::factory()->create();
    $this->instructor->assignRole('instructor');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $this->leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'cuestionario']);

    InscripcionCurso::create(['user_id' => $this->colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);

    $banco = BancoPregunta::factory()->create();

    $this->preguntaOpcionUnica = Pregunta::factory()->create(['banco_pregunta_id' => $banco->id, 'tipo' => 'opcion_unica', 'puntos' => 1]);
    $this->opcionCorrecta = $this->preguntaOpcionUnica->opciones()->create(['texto' => 'Correcta', 'es_correcta' => true, 'orden' => 0]);
    $this->opcionIncorrecta = $this->preguntaOpcionUnica->opciones()->create(['texto' => 'Incorrecta', 'es_correcta' => false, 'orden' => 1]);

    $this->cuestionario = Cuestionario::factory()->create([
        'leccion_id' => $this->leccion->id,
        'calificacion_minima' => 80,
        'intentos_maximos' => 1,
    ]);
    $this->cuestionario->preguntas()->attach($this->preguntaOpcionUnica->id, ['orden' => 0, 'puntos' => null]);
});

test('un colaborador puede iniciar y aprobar un cuestionario con respuestas correctas', function () {
    $this->actingAs($this->colaborador)
        ->post(route('mi-capacitacion.lecciones.cuestionario.iniciar', $this->leccion))
        ->assertRedirect();

    $intento = IntentoCuestionario::where('cuestionario_id', $this->cuestionario->id)->where('user_id', $this->colaborador->id)->firstOrFail();
    expect($intento->estado)->toBe(EstadoIntentoCuestionario::EnProgreso);

    $this->actingAs($this->colaborador)
        ->post(route('mi-capacitacion.intentos.enviar', $intento), [
            'respuestas' => [
                ['pregunta_id' => $this->preguntaOpcionUnica->id, 'opcion_pregunta_id' => $this->opcionCorrecta->id],
            ],
        ])
        ->assertRedirect();

    $intento->refresh();
    expect($intento->estado)->toBe(EstadoIntentoCuestionario::Calificado);
    expect($intento->calificacion)->toBe(100);
    expect($intento->aprobado)->toBeTrue();
    expect(app(ProgresoService::class)->leccionCompletada($this->colaborador, $this->leccion))->toBeTrue();
});

test('responder incorrectamente reprueba el intento y no completa la leccion', function () {
    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.lecciones.cuestionario.iniciar', $this->leccion));
    $intento = IntentoCuestionario::where('user_id', $this->colaborador->id)->firstOrFail();

    $this->actingAs($this->colaborador)
        ->post(route('mi-capacitacion.intentos.enviar', $intento), [
            'respuestas' => [
                ['pregunta_id' => $this->preguntaOpcionUnica->id, 'opcion_pregunta_id' => $this->opcionIncorrecta->id],
            ],
        ]);

    $intento->refresh();
    expect($intento->aprobado)->toBeFalse();
    expect(app(ProgresoService::class)->leccionCompletada($this->colaborador, $this->leccion))->toBeFalse();
});

test('no se pueden iniciar mas intentos que los permitidos', function () {
    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.lecciones.cuestionario.iniciar', $this->leccion));
    $intento = IntentoCuestionario::where('user_id', $this->colaborador->id)->firstOrFail();

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.intentos.enviar', $intento), [
        'respuestas' => [
            ['pregunta_id' => $this->preguntaOpcionUnica->id, 'opcion_pregunta_id' => $this->opcionIncorrecta->id],
        ],
    ]);

    $this->actingAs($this->colaborador)
        ->post(route('mi-capacitacion.lecciones.cuestionario.iniciar', $this->leccion))
        ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'error');

    expect(IntentoCuestionario::where('user_id', $this->colaborador->id)->count())->toBe(1);
});

test('una pregunta de respuesta corta queda pendiente hasta que un instructor la califica manualmente', function () {
    $banco = BancoPregunta::factory()->create();
    $preguntaAbierta = Pregunta::factory()->create(['banco_pregunta_id' => $banco->id, 'tipo' => 'respuesta_corta', 'puntos' => 1]);

    $cuestionario = Cuestionario::factory()->create(['leccion_id' => Leccion::factory()->create(['tipo' => 'cuestionario'])->id, 'calificacion_minima' => 50]);
    $cuestionario->preguntas()->attach($preguntaAbierta->id, ['orden' => 0]);

    $curso = $cuestionario->leccion->modulo->curso;
    InscripcionCurso::create(['user_id' => $this->colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.lecciones.cuestionario.iniciar', $cuestionario->leccion));
    $intento = IntentoCuestionario::where('cuestionario_id', $cuestionario->id)->firstOrFail();

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.intentos.enviar', $intento), [
        'respuestas' => [
            ['pregunta_id' => $preguntaAbierta->id, 'respuesta_texto' => 'Mi respuesta'],
        ],
    ]);

    $intento->refresh();
    expect($intento->estado)->toBe(EstadoIntentoCuestionario::Enviado);

    $respuesta = $intento->respuestas()->firstOrFail();

    $this->actingAs($this->instructor)
        ->post(route('calificaciones.cuestionarios.calificar', $respuesta), ['es_correcta' => true])
        ->assertRedirect();

    $intento->refresh();
    expect($intento->estado)->toBe(EstadoIntentoCuestionario::Calificado);
    expect($intento->aprobado)->toBeTrue();
});
