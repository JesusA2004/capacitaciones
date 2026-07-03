<?php

use App\Models\Actividad;
use App\Models\Asignacion;
use App\Models\AsignacionUsuario;
use App\Models\BancoPregunta;
use App\Models\Cuestionario;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\InscripcionCurso;
use App\Models\Leccion;
use App\Models\Pregunta;
use App\Models\SesionEnVivo;
use App\Models\User;
use App\Notifications\ActividadCalificadaNotification;
use App\Notifications\AsignacionCreadaNotification;
use App\Notifications\CuestionarioCalificadoNotification;
use App\Notifications\SesionEnVivoProgramadaNotification;
use App\Services\Asignaciones\AsignacionService;
use App\Services\Evaluacion\EntregaActividadService;
use App\Services\Evaluacion\IntentoCuestionarioService;
use App\Services\Reuniones\AsistenciaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('crear una asignacion notifica a los usuarios afectados', function () {
    Notification::fake();

    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $admin = User::factory()->create();
    $curso = Curso::factory()->create();

    app(AsignacionService::class)->crear($curso, [
        'nombre' => 'Curso de prueba',
        'responsable_id' => $admin->id,
    ], [['tipo' => 'usuario', 'id' => $colaborador->id]]);

    app(AsignacionService::class)->materializar(Asignacion::firstOrFail());

    Notification::assertSentTo($colaborador, AsignacionCreadaNotification::class);
});

test('re-materializar una asignacion no vuelve a notificar', function () {
    $colaborador = User::factory()->create();
    $admin = User::factory()->create();
    $curso = Curso::factory()->create();

    $asignacion = app(AsignacionService::class)->crear($curso, [
        'nombre' => 'Curso de prueba',
        'responsable_id' => $admin->id,
    ], [['tipo' => 'usuario', 'id' => $colaborador->id]]);

    app(AsignacionService::class)->materializar($asignacion);

    Notification::fake();
    app(AsignacionService::class)->materializar($asignacion);

    Notification::assertNothingSent();
});

test('aprobar un intento de cuestionario notifica al colaborador', function () {
    Notification::fake();

    $colaborador = User::factory()->create();
    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'cuestionario']);

    InscripcionCurso::create(['user_id' => $colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);

    $banco = BancoPregunta::factory()->create();
    $pregunta = Pregunta::factory()->create(['banco_pregunta_id' => $banco->id, 'tipo' => 'opcion_unica', 'puntos' => 1]);
    $opcionCorrecta = $pregunta->opciones()->create(['texto' => 'Correcta', 'es_correcta' => true, 'orden' => 0]);
    $pregunta->opciones()->create(['texto' => 'Incorrecta', 'es_correcta' => false, 'orden' => 1]);

    $cuestionario = Cuestionario::factory()->create(['leccion_id' => $leccion->id, 'calificacion_minima' => 50]);
    $cuestionario->preguntas()->attach($pregunta->id, ['orden' => 0]);

    $servicio = app(IntentoCuestionarioService::class);
    $intento = $servicio->iniciarIntento($colaborador, $cuestionario);
    $servicio->enviarIntento($intento, [
        ['pregunta_id' => $pregunta->id, 'opcion_pregunta_id' => $opcionCorrecta->id],
    ]);

    Notification::assertSentTo($colaborador, CuestionarioCalificadoNotification::class);
});

test('calificar una entrega de actividad notifica al colaborador', function () {
    Notification::fake();

    $colaborador = User::factory()->create();
    $instructor = User::factory()->create();
    $instructor->assignRole('instructor');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'actividad']);
    InscripcionCurso::create(['user_id' => $colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);

    $actividad = Actividad::factory()->create(['leccion_id' => $leccion->id, 'tipo_entrega' => 'texto']);
    $entrega = app(EntregaActividadService::class)->entregar($colaborador, $actividad, ['contenido_texto' => 'Mi respuesta']);

    app(EntregaActividadService::class)->calificar($entrega, true, 90, null, $instructor);

    Notification::assertSentTo($colaborador, ActividadCalificadaNotification::class);
});

test('programar una sesion en vivo notifica a los inscritos', function () {
    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'sesion_en_vivo']);
    $colaborador = User::factory()->create();
    InscripcionCurso::create(['user_id' => $colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);

    Notification::fake();

    $sesion = SesionEnVivo::factory()->create(['leccion_id' => $leccion->id, 'creado_por' => User::factory()->create()->id]);
    app(AsistenciaService::class)->materializarParaSesion($sesion);

    Notification::assertSentTo($colaborador, SesionEnVivoProgramadaNotification::class);
});

test('un usuario que desactiva las notificaciones por correo de asignaciones solo recibe la interna', function () {
    $colaborador = User::factory()->create(['preferencias_notificaciones' => ['asignaciones' => false]]);
    $admin = User::factory()->create();
    $curso = Curso::factory()->create();

    $asignacion = app(AsignacionService::class)->crear($curso, [
        'nombre' => 'Curso de prueba',
        'responsable_id' => $admin->id,
    ], [['tipo' => 'usuario', 'id' => $colaborador->id]]);

    app(AsignacionService::class)->materializar($asignacion);

    $notificacion = new AsignacionCreadaNotification(AsignacionUsuario::where('user_id', $colaborador->id)->firstOrFail());

    expect($notificacion->via($colaborador))->toBe(['database']);
});
