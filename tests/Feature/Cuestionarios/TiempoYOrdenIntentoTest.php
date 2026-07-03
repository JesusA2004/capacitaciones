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
use App\Models\RecursoMultimedia;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Auditoría de cumplimiento sección 10 (docs/AUDITORIA_CUMPLIMIENTO.md): el
 * tiempo límite debe validarse en el backend (nunca confiar en el
 * temporizador del navegador) y el orden de preguntas/opciones debe fijarse
 * una sola vez por intento, no recalcularse en cada carga de página.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');

    $this->colaborador = User::factory()->create();
    $this->colaborador->assignRole('colaborador');
    $this->instructor = User::factory()->create();
    $this->instructor->assignRole('instructor');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $this->leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'cuestionario']);
    InscripcionCurso::create(['user_id' => $this->colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);

    $banco = BancoPregunta::factory()->create();
    $this->pregunta = Pregunta::factory()->create(['banco_pregunta_id' => $banco->id, 'tipo' => 'opcion_unica', 'puntos' => 1]);
    $this->correcta = $this->pregunta->opciones()->create(['texto' => 'Correcta', 'es_correcta' => true, 'orden' => 0]);
    $this->incorrecta = $this->pregunta->opciones()->create(['texto' => 'Incorrecta', 'es_correcta' => false, 'orden' => 1]);
});

test('un intento con tiempo limite vencido se rechaza y queda marcado como expirado', function () {
    $cuestionario = Cuestionario::factory()->create([
        'leccion_id' => $this->leccion->id,
        'tiempo_limite_minutos' => 10,
        'tolerancia_segundos' => 5,
    ]);
    $cuestionario->preguntas()->attach($this->pregunta->id, ['orden' => 0]);

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.lecciones.cuestionario.iniciar', $this->leccion));
    $intento = IntentoCuestionario::where('user_id', $this->colaborador->id)->firstOrFail();

    expect($intento->fecha_limite)->not->toBeNull();

    $this->travel(11)->minutes();

    $this->actingAs($this->colaborador)
        ->post(route('mi-capacitacion.intentos.enviar', $intento), [
            'respuestas' => [
                ['pregunta_id' => $this->pregunta->id, 'opcion_pregunta_id' => $this->correcta->id],
            ],
        ])
        ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'error');

    $intento->refresh();
    expect($intento->estado)->toBe(EstadoIntentoCuestionario::Expirado);
    expect($intento->respuestas()->count())->toBe(0);
});

test('un envio dentro de la tolerancia configurada si se acepta aunque el tiempo base ya paso', function () {
    $cuestionario = Cuestionario::factory()->create([
        'leccion_id' => $this->leccion->id,
        'tiempo_limite_minutos' => 10,
        'tolerancia_segundos' => 120,
    ]);
    $cuestionario->preguntas()->attach($this->pregunta->id, ['orden' => 0]);

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.lecciones.cuestionario.iniciar', $this->leccion));
    $intento = IntentoCuestionario::where('user_id', $this->colaborador->id)->firstOrFail();

    $this->travel(630)->seconds();

    $this->actingAs($this->colaborador)
        ->post(route('mi-capacitacion.intentos.enviar', $intento), [
            'respuestas' => [
                ['pregunta_id' => $this->pregunta->id, 'opcion_pregunta_id' => $this->correcta->id],
            ],
        ])
        ->assertRedirect();

    $intento->refresh();
    expect($intento->estado)->toBe(EstadoIntentoCuestionario::Calificado);
});

test('visitar dos veces la pantalla del intento activo devuelve siempre el mismo orden de preguntas', function () {
    $preguntaDos = Pregunta::factory()->create(['banco_pregunta_id' => $this->pregunta->banco_pregunta_id, 'tipo' => 'verdadero_falso', 'puntos' => 1]);
    $preguntaDos->opciones()->create(['texto' => 'Verdadero', 'es_correcta' => true, 'orden' => 0]);
    $preguntaDos->opciones()->create(['texto' => 'Falso', 'es_correcta' => false, 'orden' => 1]);

    $cuestionario = Cuestionario::factory()->create(['leccion_id' => $this->leccion->id, 'aleatorizar_preguntas' => true]);
    $cuestionario->preguntas()->attach([$this->pregunta->id => ['orden' => 0], $preguntaDos->id => ['orden' => 1]]);

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.lecciones.cuestionario.iniciar', $this->leccion));

    $primeraCarga = $this->actingAs($this->colaborador)->get(route('mi-capacitacion.lecciones.cuestionario.show', $this->leccion));
    $segundaCarga = $this->actingAs($this->colaborador)->get(route('mi-capacitacion.lecciones.cuestionario.show', $this->leccion));

    $ordenPrimero = collect($primeraCarga->viewData('page')['props']['preguntas'])->pluck('id')->all();
    $ordenSegundo = collect($segundaCarga->viewData('page')['props']['preguntas'])->pluck('id')->all();

    expect($ordenPrimero)->toBe($ordenSegundo);

    $intento = IntentoCuestionario::where('user_id', $this->colaborador->id)->firstOrFail();
    expect($intento->orden_preguntas)->toBe($ordenPrimero);
});

test('una pregunta de escala guarda el valor numerico y queda pendiente de revision manual', function () {
    $preguntaEscala = Pregunta::factory()->create([
        'banco_pregunta_id' => $this->pregunta->banco_pregunta_id,
        'tipo' => 'escala',
        'puntos' => 1,
        'escala_min' => 1,
        'escala_max' => 5,
    ]);

    $cuestionario = Cuestionario::factory()->create(['leccion_id' => $this->leccion->id, 'calificacion_minima' => 1]);
    $cuestionario->preguntas()->attach($preguntaEscala->id, ['orden' => 0]);

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.lecciones.cuestionario.iniciar', $this->leccion));
    $intento = IntentoCuestionario::where('user_id', $this->colaborador->id)->firstOrFail();

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.intentos.enviar', $intento), [
        'respuestas' => [
            ['pregunta_id' => $preguntaEscala->id, 'valor_numerico' => 4],
        ],
    ])->assertRedirect();

    $intento->refresh();
    expect($intento->estado)->toBe(EstadoIntentoCuestionario::Enviado);

    $respuesta = $intento->respuestas()->firstOrFail();
    expect($respuesta->valor_numerico)->toBe(4);
    expect($respuesta->es_correcta)->toBeNull();
});

test('una pregunta de carga de archivo guarda el recurso como evidencia restringida y queda pendiente de revision', function () {
    $preguntaArchivo = Pregunta::factory()->create([
        'banco_pregunta_id' => $this->pregunta->banco_pregunta_id,
        'tipo' => 'carga_archivo',
        'puntos' => 1,
        'extensiones_permitidas' => ['pdf'],
        'tamano_maximo_mb' => 5,
    ]);

    $cuestionario = Cuestionario::factory()->create(['leccion_id' => $this->leccion->id, 'calificacion_minima' => 1]);
    $cuestionario->preguntas()->attach($preguntaArchivo->id, ['orden' => 0]);

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.lecciones.cuestionario.iniciar', $this->leccion));
    $intento = IntentoCuestionario::where('user_id', $this->colaborador->id)->firstOrFail();

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.intentos.enviar', $intento), [
        'respuestas' => [
            ['pregunta_id' => $preguntaArchivo->id, 'archivo' => UploadedFile::fake()->create('evidencia.pdf', 200, 'application/pdf')],
        ],
    ])->assertRedirect();

    $respuesta = $intento->respuestas()->firstOrFail();
    expect($respuesta->recurso_multimedia_id)->not->toBeNull();

    $recurso = RecursoMultimedia::findOrFail($respuesta->recurso_multimedia_id);
    expect($recurso->acceso_restringido)->toBeTrue();
    expect($recurso->origen->value)->toBe('cuestionario');
    expect($recurso->propietario_id)->toBe($this->colaborador->id);
});

test('un archivo con extension no permitida para la pregunta se rechaza', function () {
    $preguntaArchivo = Pregunta::factory()->create([
        'banco_pregunta_id' => $this->pregunta->banco_pregunta_id,
        'tipo' => 'carga_archivo',
        'puntos' => 1,
        'extensiones_permitidas' => ['pdf'],
    ]);

    $cuestionario = Cuestionario::factory()->create(['leccion_id' => $this->leccion->id, 'calificacion_minima' => 1]);
    $cuestionario->preguntas()->attach($preguntaArchivo->id, ['orden' => 0]);

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.lecciones.cuestionario.iniciar', $this->leccion));
    $intento = IntentoCuestionario::where('user_id', $this->colaborador->id)->firstOrFail();

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.intentos.enviar', $intento), [
        'respuestas' => [
            ['pregunta_id' => $preguntaArchivo->id, 'archivo' => UploadedFile::fake()->create('evidencia.exe', 200, 'application/octet-stream')],
        ],
    ])->assertSessionHasErrors('respuestas');

    expect($intento->fresh()->estado)->toBe(EstadoIntentoCuestionario::EnProgreso);
});
