<?php

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoAsistencia;
use App\Models\Asignacion;
use App\Models\AsignacionUsuario;
use App\Models\Asistencia;
use App\Models\Cuestionario;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\IntentoCuestionario;
use App\Models\Leccion;
use App\Models\SesionEnVivo;
use App\Models\User;
use App\Notifications\CalificacionesPendientesNotification;
use App\Notifications\FechaLimiteProximaNotification;
use App\Notifications\SesionEnVivoProximaNotification;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('recordar fechas limite notifica solo asignaciones pendientes que vencen pronto y no vuelve a notificar', function () {
    Notification::fake();

    $colaborador = User::factory()->create();
    $admin = User::factory()->create();
    $curso = Curso::factory()->create();
    $asignacion = Asignacion::create(['nombre' => 'Curso urgente', 'asignable_type' => Curso::class, 'asignable_id' => $curso->id, 'responsable_id' => $admin->id]);

    $porVencer = AsignacionUsuario::create([
        'asignacion_id' => $asignacion->id,
        'user_id' => $colaborador->id,
        'estado' => EstadoAsignacion::Pendiente->value,
        'fecha_limite' => now()->addDay(),
    ]);

    $lejana = AsignacionUsuario::create([
        'asignacion_id' => $asignacion->id,
        'user_id' => User::factory()->create()->id,
        'estado' => EstadoAsignacion::Pendiente->value,
        'fecha_limite' => now()->addDays(30),
    ]);

    $this->artisan('capacitacion:recordar-fechas-limite')->assertSuccessful();

    Notification::assertSentTo($colaborador, FechaLimiteProximaNotification::class);
    Notification::assertNotSentTo($lejana->usuario, FechaLimiteProximaNotification::class);
    expect($porVencer->fresh()->recordatorio_enviado_en)->not->toBeNull();

    Notification::fake();
    $this->artisan('capacitacion:recordar-fechas-limite')->assertSuccessful();
    Notification::assertNothingSent();
});

test('recordar sesiones proximas notifica a los inscritos con sesion en la proxima hora', function () {
    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'sesion_en_vivo']);
    $colaborador = User::factory()->create();

    $sesion = SesionEnVivo::factory()->create([
        'leccion_id' => $leccion->id,
        'fecha_inicio' => now()->addMinutes(30),
        'creado_por' => User::factory()->create()->id,
    ]);
    Asistencia::create(['sesion_en_vivo_id' => $sesion->id, 'user_id' => $colaborador->id, 'estado' => EstadoAsistencia::Pendiente->value]);

    Notification::fake();
    $this->artisan('capacitacion:recordar-sesiones-proximas')->assertSuccessful();

    Notification::assertSentTo($colaborador, SesionEnVivoProximaNotification::class);
});

test('recordar calificaciones pendientes notifica solo a quien tiene el permiso y algo pendiente', function () {
    $instructor = User::factory()->create();
    $instructor->assignRole('instructor');

    $colaborador = User::factory()->create();
    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'cuestionario']);
    $cuestionario = Cuestionario::factory()->create(['leccion_id' => $leccion->id]);

    IntentoCuestionario::create([
        'cuestionario_id' => $cuestionario->id,
        'user_id' => $colaborador->id,
        'numero_intento' => 1,
        'estado' => 'enviado',
        'iniciado_en' => now(),
        'enviado_en' => now(),
    ]);

    Notification::fake();
    $this->artisan('capacitacion:recordar-calificaciones-pendientes')->assertSuccessful();

    Notification::assertSentTo($instructor, CalificacionesPendientesNotification::class);
});

test('recordar calificaciones pendientes no notifica cuando no hay nada pendiente', function () {
    $instructor = User::factory()->create();
    $instructor->assignRole('instructor');

    Notification::fake();
    $this->artisan('capacitacion:recordar-calificaciones-pendientes')->assertSuccessful();

    Notification::assertNothingSent();
});
