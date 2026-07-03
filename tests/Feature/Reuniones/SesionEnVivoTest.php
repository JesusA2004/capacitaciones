<?php

use App\Enums\EstadoAsistencia;
use App\Models\Asistencia;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\InscripcionCurso;
use App\Models\Leccion;
use App\Models\SesionEnVivo;
use App\Models\User;
use App\Services\Capacitacion\ProgresoService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->instructor = User::factory()->create();
    $this->instructor->assignRole('instructor');

    $this->coordinador = User::factory()->create();
    $this->coordinador->assignRole('administrador_capacitacion');

    $this->colaborador = User::factory()->create();
    $this->colaborador->assignRole('colaborador');

    $curso = Curso::factory()->create();
    $this->modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $this->leccion = Leccion::factory()->create(['curso_modulo_id' => $this->modulo->id, 'tipo' => 'sesion_en_vivo']);
    $this->curso = $curso;

    InscripcionCurso::create(['user_id' => $this->colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);
});

test('se puede programar una sesion en vivo con el proveedor manual y se materializan las asistencias pendientes', function () {
    $this->actingAs($this->instructor)
        ->post(route('cursos.lecciones.sesion.store', [$this->curso, $this->modulo, $this->leccion]), [
            'titulo' => 'Inducción en vivo',
            'proveedor' => 'manual',
            'fecha_inicio' => now()->addDay()->format('Y-m-d H:i:s'),
            'duracion_minutos' => 45,
            'enlace_reunion' => 'https://meet.example.com/abc',
        ])
        ->assertRedirect();

    $sesion = SesionEnVivo::where('leccion_id', $this->leccion->id)->firstOrFail();
    expect($sesion->enlace_reunion)->toBe('https://meet.example.com/abc');

    $asistencia = Asistencia::where('sesion_en_vivo_id', $sesion->id)->where('user_id', $this->colaborador->id)->firstOrFail();
    expect($asistencia->estado)->toBe(EstadoAsistencia::Pendiente);
});

test('marcar presente por primera vez completa la leccion y no requiere permiso de correccion', function () {
    $sesion = SesionEnVivo::factory()->create(['leccion_id' => $this->leccion->id, 'creado_por' => $this->instructor->id]);
    $asistencia = Asistencia::factory()->create(['sesion_en_vivo_id' => $sesion->id, 'user_id' => $this->colaborador->id]);

    $this->actingAs($this->instructor)
        ->post(route('sesiones.asistencias.marcar', [$sesion, $asistencia]), ['estado' => 'presente'])
        ->assertRedirect();

    expect($asistencia->fresh()->estado)->toBe(EstadoAsistencia::Presente);
    expect(app(ProgresoService::class)->leccionCompletada($this->colaborador, $this->leccion))->toBeTrue();
});

test('cambiar una asistencia ya marcada requiere el permiso de correccion y un motivo', function () {
    $sesion = SesionEnVivo::factory()->create(['leccion_id' => $this->leccion->id, 'creado_por' => $this->instructor->id]);
    $asistencia = Asistencia::factory()->create([
        'sesion_en_vivo_id' => $sesion->id,
        'user_id' => $this->colaborador->id,
        'estado' => EstadoAsistencia::Ausente->value,
    ]);

    $this->actingAs($this->instructor)
        ->post(route('sesiones.asistencias.marcar', [$sesion, $asistencia]), ['estado' => 'presente'])
        ->assertForbidden();

    $this->actingAs($this->coordinador)
        ->post(route('sesiones.asistencias.marcar', [$sesion, $asistencia]), ['estado' => 'presente'])
        ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'error');

    expect($asistencia->fresh()->estado)->toBe(EstadoAsistencia::Ausente);

    $this->actingAs($this->coordinador)
        ->post(route('sesiones.asistencias.marcar', [$sesion, $asistencia]), [
            'estado' => 'presente',
            'motivo' => 'El colaborador si asistio, se registro mal por error de captura.',
        ])
        ->assertRedirect();

    $asistencia->refresh();
    expect($asistencia->estado)->toBe(EstadoAsistencia::Presente);
    expect($asistencia->corregido_por)->toBe($this->coordinador->id);
    expect($asistencia->motivo_correccion)->not->toBeNull();
});
