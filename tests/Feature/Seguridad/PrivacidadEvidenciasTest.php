<?php

use App\Enums\EstadoEntregaActividad;
use App\Models\Actividad;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\EntregaActividad;
use App\Models\InscripcionCurso;
use App\Models\Leccion;
use App\Models\RecursoMultimedia;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Auditoría de cumplimiento sección 12/13 (docs/AUDITORIA_CUMPLIMIENTO.md):
 * las evidencias de entregas de actividad no deben aparecer en la biblioteca
 * multimedia general ni ser accesibles por un usuario que solo tenga el
 * permiso plano de biblioteca (`multimedia.administrar`).
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

test('una evidencia de entrega de actividad no aparece en el listado de la biblioteca multimedia', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $instructor = User::factory()->create();
    $instructor->assignRole('instructor');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'actividad']);
    InscripcionCurso::create(['user_id' => $colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);
    $actividad = Actividad::factory()->create(['leccion_id' => $leccion->id, 'tipo_entrega' => 'archivo']);

    $this->actingAs($colaborador)
        ->post(route('mi-capacitacion.lecciones.actividad.store', $leccion), [
            'archivo' => UploadedFile::fake()->create('evidencia.pdf', 200, 'application/pdf'),
        ])
        ->assertRedirect();

    $recursoBiblioteca = RecursoMultimedia::factory()->create(['tipo' => 'documento']);

    $respuesta = $this->actingAs($admin)->get(route('multimedia.index'));
    $respuesta->assertOk();

    $idsListados = collect($respuesta->viewData('page')['props']['recursos']['data'])->pluck('id');

    expect($idsListados)->toContain($recursoBiblioteca->id);

    $entrega = EntregaActividad::where('actividad_id', $actividad->id)->firstOrFail();
    expect($idsListados)->not->toContain($entrega->recurso_multimedia_id);
});

test('un usuario con solo multimedia.administrar no puede ver ni descargar una evidencia restringida', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    // Simula un rol futuro con solo el permiso de biblioteca, sin
    // "respuestas.ver": no se le asigna ningun rol con ambos permisos, solo
    // el permiso directo de biblioteca, para que la prueba no dependa de la
    // combinacion de permisos que trae el rol administrador_capacitacion.
    $bibliotecario = User::factory()->create();
    $bibliotecario->givePermissionTo('multimedia.administrar');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'actividad']);
    InscripcionCurso::create(['user_id' => $colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);
    Actividad::factory()->create(['leccion_id' => $leccion->id, 'tipo_entrega' => 'archivo']);

    $this->actingAs($colaborador)
        ->post(route('mi-capacitacion.lecciones.actividad.store', $leccion), [
            'archivo' => UploadedFile::fake()->create('evidencia.pdf', 200, 'application/pdf'),
        ]);

    $entrega = EntregaActividad::firstOrFail();
    $recurso = RecursoMultimedia::findOrFail($entrega->recurso_multimedia_id);

    expect($bibliotecario->can('view', $recurso))->toBeFalse();
    expect($bibliotecario->can('delete', $recurso))->toBeFalse();

    $this->actingAs($bibliotecario)
        ->delete(route('multimedia.destroy', $recurso))
        ->assertForbidden();
});

test('un gerente_sucursal no puede calificar la entrega de un colaborador de otra sucursal', function () {
    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    $gerente->assignRole('gerente_sucursal');
    $gerente->givePermissionTo('respuestas.ver', 'respuestas.calificar');

    $colaborador = User::factory()->create(['sucursal_principal_id' => $sucursalB->id]);
    $colaborador->assignRole('colaborador');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'actividad']);
    InscripcionCurso::create(['user_id' => $colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);
    Actividad::factory()->create(['leccion_id' => $leccion->id, 'tipo_entrega' => 'texto']);

    $this->actingAs($colaborador)
        ->post(route('mi-capacitacion.lecciones.actividad.store', $leccion), ['contenido_texto' => 'Mi entrega']);

    $entrega = EntregaActividad::firstOrFail();

    $this->actingAs($gerente)
        ->post(route('calificaciones.actividades.calificar', $entrega), ['aprobada' => true, 'calificacion' => 90])
        ->assertForbidden();

    expect($entrega->fresh()->estado)->toBe(EstadoEntregaActividad::Entregada);
});

test('un gerente_sucursal si puede calificar la entrega de un colaborador de su propia sucursal', function () {
    $sucursal = Sucursal::factory()->create();

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursal->id]);
    $gerente->assignRole('gerente_sucursal');
    $gerente->givePermissionTo('respuestas.ver', 'respuestas.calificar');

    $colaborador = User::factory()->create(['sucursal_principal_id' => $sucursal->id]);
    $colaborador->assignRole('colaborador');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'actividad']);
    InscripcionCurso::create(['user_id' => $colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);
    Actividad::factory()->create(['leccion_id' => $leccion->id, 'tipo_entrega' => 'texto']);

    $this->actingAs($colaborador)
        ->post(route('mi-capacitacion.lecciones.actividad.store', $leccion), ['contenido_texto' => 'Mi entrega']);

    $entrega = EntregaActividad::firstOrFail();

    $this->actingAs($gerente)
        ->post(route('calificaciones.actividades.calificar', $entrega), ['aprobada' => true, 'calificacion' => 90])
        ->assertRedirect();

    expect($entrega->fresh()->estado)->toBe(EstadoEntregaActividad::Aprobada);
});
