<?php

use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Models\User;
use App\Services\Asignaciones\AsignacionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Fase 8 (rendimiento): el detalle de un curso en "Mi capacitación" calcula
 * el bloqueo de cada leccion (ProgresoService::estadoBloqueoLeccion), que
 * necesita subir de leccion a modulo y de modulo a curso. Sin hidratar esa
 * relacion inversa a mano, Eloquent dispara una consulta perdida por cada
 * leccion (N+1). Esta prueba fija el numero de lecciones y confirma que la
 * cantidad de consultas no crece proporcionalmente con esa cantidad.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('ver el detalle de un curso con muchas lecciones no dispara una consulta n+1 por leccion', function () {
    $colaborador = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $curso = Curso::factory()->create(['requiere_orden' => true]);
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);

    foreach (range(1, 15) as $orden) {
        Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'orden' => $orden, 'obligatoria' => true]);
    }

    app(AsignacionService::class)->crear($curso, [
        'nombre' => 'Curso con muchas lecciones',
        'responsable_id' => $admin->id,
    ], [['tipo' => 'usuario', 'id' => $colaborador->id]]);

    DB::enableQueryLog();

    $this->actingAs($colaborador)
        ->get(route('mi-capacitacion.show', $curso))
        ->assertOk();

    $totalConsultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Antes de memoizar ProgresoService::leccionCompletada() y de hidratar a
    // mano la relacion inversa leccion->modulo->curso, esta misma pagina con
    // 15 lecciones disparaba 37 consultas (una por leccion, y otra vez por
    // cada verificacion de "leccion obligatoria anterior"). El limite aqui
    // confirma que el conteo ya no escala con el numero de lecciones.
    expect($totalConsultas)->toBeLessThan(20);
});
