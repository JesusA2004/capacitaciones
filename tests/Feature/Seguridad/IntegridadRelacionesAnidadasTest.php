<?php

use App\Models\Actividad;
use App\Models\Asistencia;
use App\Models\Cuestionario;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Models\SesionEnVivo;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

/**
 * Auditoria de seguridad (Fase 8): rutas anidadas donde Laravel resuelve
 * cada segmento (curso/modulo/leccion, sesion/asistencia) de forma
 * independiente por su ID, sin verificar automaticamente que en efecto
 * pertenezcan entre si. Sin una comprobacion explicita en el controlador,
 * alguien con el permiso correcto podria mezclar IDs de recursos que no
 * tienen relacion real (p. ej. una asistencia de una sesion ajena, pasada
 * junto con una sesion propia en la URL) y afectar el recurso equivocado.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('marcar una asistencia que no pertenece a la sesion de la URL se rechaza', function () {
    $instructor = User::factory()->create();
    $instructor->assignRole('instructor');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccionA = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'sesion_en_vivo']);
    $leccionB = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'sesion_en_vivo']);

    $sesionPropia = SesionEnVivo::factory()->create(['leccion_id' => $leccionA->id, 'creado_por' => $instructor->id]);
    $sesionAjena = SesionEnVivo::factory()->create(['leccion_id' => $leccionB->id, 'creado_por' => User::factory()->create()->id]);

    $asistenciaAjena = Asistencia::factory()->create(['sesion_en_vivo_id' => $sesionAjena->id]);

    $this->actingAs($instructor)
        ->post(route('sesiones.asistencias.marcar', [$sesionPropia, $asistenciaAjena]), ['estado' => 'presente'])
        ->assertNotFound();
});

test('agregar una leccion a un modulo que no pertenece al curso de la URL se rechaza', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $cursoA = Curso::factory()->create();
    $cursoB = Curso::factory()->create();
    $moduloDeB = CursoModulo::factory()->create(['curso_id' => $cursoB->id]);

    $this->actingAs($admin)
        ->post(route('cursos.lecciones.store', [$cursoA, $moduloDeB]), [
            'titulo' => 'Lección colada',
            'tipo' => 'texto',
            'contenido' => 'x',
        ])
        ->assertNotFound();
});

test('editar una leccion que no pertenece al modulo/curso de la URL se rechaza', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $cursoA = Curso::factory()->create();
    $moduloDeA = CursoModulo::factory()->create(['curso_id' => $cursoA->id]);

    $cursoB = Curso::factory()->create();
    $moduloDeB = CursoModulo::factory()->create(['curso_id' => $cursoB->id]);
    $leccionDeB = Leccion::factory()->create(['curso_modulo_id' => $moduloDeB->id]);

    $this->actingAs($admin)
        ->put(route('cursos.lecciones.update', [$cursoA, $moduloDeA, $leccionDeB]), [
            'titulo' => 'Intento de edicion cruzada',
            'tipo' => 'texto',
            'contenido' => 'x',
        ])
        ->assertNotFound();
});

test('configurar un cuestionario para una leccion que no pertenece al modulo/curso de la URL se rechaza', function () {
    $instructor = User::factory()->create();
    $instructor->assignRole('instructor');

    $cursoA = Curso::factory()->create();
    $moduloDeA = CursoModulo::factory()->create(['curso_id' => $cursoA->id]);

    $cursoB = Curso::factory()->create();
    $moduloDeB = CursoModulo::factory()->create(['curso_id' => $cursoB->id]);
    $leccionDeB = Leccion::factory()->create(['curso_modulo_id' => $moduloDeB->id, 'tipo' => 'cuestionario']);

    $this->actingAs($instructor)
        ->get(route('cursos.lecciones.cuestionario.edit', [$cursoA, $moduloDeA, $leccionDeB]))
        ->assertNotFound();

    expect(Cuestionario::where('leccion_id', $leccionDeB->id)->exists())->toBeFalse();
});

test('configurar una actividad para una leccion que no pertenece al modulo/curso de la URL se rechaza', function () {
    $instructor = User::factory()->create();
    $instructor->assignRole('instructor');

    $cursoA = Curso::factory()->create();
    $moduloDeA = CursoModulo::factory()->create(['curso_id' => $cursoA->id]);

    $cursoB = Curso::factory()->create();
    $moduloDeB = CursoModulo::factory()->create(['curso_id' => $cursoB->id]);
    $leccionDeB = Leccion::factory()->create(['curso_modulo_id' => $moduloDeB->id, 'tipo' => 'actividad']);

    $this->actingAs($instructor)
        ->get(route('cursos.lecciones.actividad.edit', [$cursoA, $moduloDeA, $leccionDeB]))
        ->assertNotFound();

    expect(Actividad::where('leccion_id', $leccionDeB->id)->exists())->toBeFalse();
});

test('programar una sesion en vivo para una leccion que no pertenece al modulo/curso de la URL se rechaza', function () {
    $instructor = User::factory()->create();
    $instructor->assignRole('instructor');

    $cursoA = Curso::factory()->create();
    $moduloDeA = CursoModulo::factory()->create(['curso_id' => $cursoA->id]);

    $cursoB = Curso::factory()->create();
    $moduloDeB = CursoModulo::factory()->create(['curso_id' => $cursoB->id]);
    $leccionDeB = Leccion::factory()->create(['curso_modulo_id' => $moduloDeB->id, 'tipo' => 'sesion_en_vivo']);

    $this->actingAs($instructor)
        ->get(route('cursos.lecciones.sesion.edit', [$cursoA, $moduloDeA, $leccionDeB]))
        ->assertNotFound();
});
