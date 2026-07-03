<?php

use App\Models\Actividad;
use App\Models\Cuestionario;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\EntregaActividad;
use App\Models\IntentoCuestionario;
use App\Models\Leccion;
use App\Models\Pregunta;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->colaborador = User::factory()->create();
    $this->colaborador->assignRole('colaborador');

    $curso = Curso::factory()->create();
    $this->modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $this->curso = $curso;
});

test('un colaborador no puede configurar una actividad', function () {
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $this->modulo->id, 'tipo' => 'actividad']);

    $this->actingAs($this->colaborador)
        ->get(route('cursos.lecciones.actividad.edit', [$this->curso, $this->modulo, $leccion]))
        ->assertForbidden();
});

test('un colaborador no puede ver la lista de cuestionarios pendientes de calificar', function () {
    $this->actingAs($this->colaborador)
        ->get(route('calificaciones.cuestionarios.index'))
        ->assertForbidden();
});

test('un colaborador no puede calificar una respuesta de cuestionario', function () {
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $this->modulo->id, 'tipo' => 'cuestionario']);
    $cuestionario = Cuestionario::factory()->create(['leccion_id' => $leccion->id]);
    $intento = IntentoCuestionario::factory()->create(['cuestionario_id' => $cuestionario->id, 'user_id' => $this->colaborador->id]);
    $respuesta = $intento->respuestas()->create(['pregunta_id' => Pregunta::factory()->create()->id]);

    $this->actingAs($this->colaborador)
        ->post(route('calificaciones.cuestionarios.calificar', $respuesta), ['es_correcta' => true])
        ->assertForbidden();
});

test('un colaborador no puede ver la lista de actividades pendientes de calificar', function () {
    $this->actingAs($this->colaborador)
        ->get(route('calificaciones.actividades.index'))
        ->assertForbidden();
});

test('un colaborador no puede calificar una entrega de actividad', function () {
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $this->modulo->id, 'tipo' => 'actividad']);
    $actividad = Actividad::factory()->create(['leccion_id' => $leccion->id]);
    $entrega = EntregaActividad::factory()->create(['actividad_id' => $actividad->id, 'user_id' => $this->colaborador->id]);

    $this->actingAs($this->colaborador)
        ->post(route('calificaciones.actividades.calificar', $entrega), ['aprobada' => true])
        ->assertForbidden();
});

test('un colaborador no puede enviar el intento de otro usuario', function () {
    $otro = User::factory()->create();
    $otro->assignRole('colaborador');

    $leccion = Leccion::factory()->create(['curso_modulo_id' => $this->modulo->id, 'tipo' => 'cuestionario']);
    $cuestionario = Cuestionario::factory()->create(['leccion_id' => $leccion->id]);
    $intento = IntentoCuestionario::factory()->create(['cuestionario_id' => $cuestionario->id, 'user_id' => $otro->id]);

    $this->actingAs($this->colaborador)
        ->post(route('mi-capacitacion.intentos.enviar', $intento), ['respuestas' => []])
        ->assertForbidden();
});
