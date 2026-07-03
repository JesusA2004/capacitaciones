<?php

use App\Models\Asistencia;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Models\SesionEnVivo;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->colaborador = User::factory()->create();
    $this->colaborador->assignRole('colaborador');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $this->leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'sesion_en_vivo']);
    $this->curso = $curso;
    $this->modulo = $modulo;
});

test('un colaborador no puede programar una sesion en vivo', function () {
    $this->actingAs($this->colaborador)
        ->get(route('cursos.lecciones.sesion.edit', [$this->curso, $this->modulo, $this->leccion]))
        ->assertForbidden();
});

test('un colaborador no puede ver la lista de asistencias de una sesion', function () {
    $creador = User::factory()->create();
    $creador->assignRole('instructor');

    $sesion = SesionEnVivo::factory()->create(['leccion_id' => $this->leccion->id, 'creado_por' => $creador->id]);

    $this->actingAs($this->colaborador)
        ->get(route('sesiones.asistencias.index', $sesion))
        ->assertForbidden();
});

test('un colaborador no puede marcar la asistencia de otro usuario', function () {
    $creador = User::factory()->create();
    $creador->assignRole('instructor');

    $sesion = SesionEnVivo::factory()->create(['leccion_id' => $this->leccion->id, 'creado_por' => $creador->id]);
    $asistencia = Asistencia::factory()->create(['sesion_en_vivo_id' => $sesion->id, 'user_id' => $this->colaborador->id]);

    $this->actingAs($this->colaborador)
        ->post(route('sesiones.asistencias.marcar', [$sesion, $asistencia]), ['estado' => 'presente'])
        ->assertForbidden();
});

test('un colaborador sin el curso asignado no puede ver el detalle de la sesion en vivo', function () {
    $creador = User::factory()->create();
    $creador->assignRole('instructor');

    SesionEnVivo::factory()->create(['leccion_id' => $this->leccion->id, 'creado_por' => $creador->id]);

    $this->actingAs($this->colaborador)
        ->get(route('mi-capacitacion.lecciones.sesion.show', $this->leccion))
        ->assertForbidden();
});
