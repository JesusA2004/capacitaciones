<?php

use App\Models\Asignacion;
use App\Models\AsignacionUsuario;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Cuestionario;
use App\Models\Departamento;
use App\Models\InscripcionCurso;
use App\Models\IntentoCuestionario;
use App\Models\Leccion;
use App\Models\ProgresoLeccion;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

/**
 * Cubre las gráficas agregadas nuevas de MetricasDashboardService (fase de
 * modernización visual). No repite lo que ya cubre DashboardScopingTest
 * (qué dashboard ve cada rol) ni ReporteCumplimientoTest (el reporte
 * detallado); se enfoca en que la clave `graficas` tenga la forma esperada
 * por rol y en que los desgloses nuevos respeten el aislamiento por
 * sucursal, igual que el resto del sistema.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('el dashboard global incluye el desglose completo de graficas', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $respuesta = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        $graficas = $page->toArray()['props']['graficas'];

        expect($graficas)->toHaveKeys([
            'cursosPorEstado', 'cumplimientoPorDepartamento', 'colaboradoresActivos',
            'calificacionPromedio', 'asistenciaSesiones', 'videosCompletados',
            'cuestionarios', 'actividadesPendientes', 'evolucionMensual',
            'topCursosAvance', 'cursosMayorAbandono', 'usuariosPendientesCriticos',
        ]);
    });
});

test('el dashboard de colaborador solo incluye su subconjunto personal de graficas', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $respuesta = $this->actingAs($colaborador)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        $graficas = $page->toArray()['props']['graficas'];

        expect($graficas)->toHaveKeys(['cursosPorEstado', 'calificacionPromedio', 'asistenciaSesiones', 'videosCompletados']);
        expect($graficas)->not->toHaveKey('usuariosPendientesCriticos');
        expect($graficas)->not->toHaveKey('cumplimientoPorDepartamento');
    });
});

test('el cumplimiento por departamento respeta el aislamiento por sucursal de un gerente', function () {
    $sucursalPropia = Sucursal::factory()->create();
    $sucursalAjena = Sucursal::factory()->create();
    $departamentoPropio = Departamento::factory()->create();
    $departamentoAjeno = Departamento::factory()->create();

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $gerente->assignRole('gerente_sucursal');

    $colaboradorPropio = User::factory()->create([
        'sucursal_principal_id' => $sucursalPropia->id,
        'departamento_id' => $departamentoPropio->id,
    ]);
    $colaboradorAjeno = User::factory()->create([
        'sucursal_principal_id' => $sucursalAjena->id,
        'departamento_id' => $departamentoAjeno->id,
    ]);

    $responsable = User::factory()->create();
    $curso = Curso::factory()->create();
    $asignacion = Asignacion::create(['nombre' => 'Curso de prueba', 'asignable_type' => Curso::class, 'asignable_id' => $curso->id, 'responsable_id' => $responsable->id]);
    AsignacionUsuario::create(['asignacion_id' => $asignacion->id, 'user_id' => $colaboradorPropio->id, 'estado' => 'completada']);
    AsignacionUsuario::create(['asignacion_id' => $asignacion->id, 'user_id' => $colaboradorAjeno->id, 'estado' => 'pendiente']);

    $respuesta = $this->actingAs($gerente)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) use ($departamentoPropio, $departamentoAjeno) {
        $filas = collect($page->toArray()['props']['graficas']['cumplimientoPorDepartamento']);

        expect($filas->firstWhere('departamento_id', $departamentoPropio->id)['porcentaje'])->toEqual(100);
        expect($filas->firstWhere('departamento_id', $departamentoAjeno->id))->toBeNull();
    });
});

test('el top de cursos con mas avance calcula el porcentaje segun lecciones completadas', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $curso = Curso::factory()->create(['titulo' => 'Curso con avance']);
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccionCompletada = Leccion::factory()->create(['curso_modulo_id' => $modulo->id]);
    $leccionPendiente = Leccion::factory()->create(['curso_modulo_id' => $modulo->id]);

    $colaborador = User::factory()->create();
    InscripcionCurso::factory()->create(['user_id' => $colaborador->id, 'curso_id' => $curso->id, 'estado' => 'en_progreso']);
    ProgresoLeccion::factory()->create(['user_id' => $colaborador->id, 'leccion_id' => $leccionCompletada->id, 'estado' => 'completada']);
    ProgresoLeccion::factory()->create(['user_id' => $colaborador->id, 'leccion_id' => $leccionPendiente->id, 'estado' => 'pendiente']);

    $respuesta = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) use ($curso) {
        $filas = collect($page->toArray()['props']['graficas']['topCursosAvance']);
        $fila = $filas->firstWhere('curso_id', $curso->id);

        expect($fila)->not->toBeNull();
        expect($fila['porcentaje'])->toEqual(50.0);
    });
});

test('el top de cursos con mas avance no incluye colaboradores fuera del alcance de un gerente', function () {
    $sucursalPropia = Sucursal::factory()->create();
    $sucursalAjena = Sucursal::factory()->create();

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $gerente->assignRole('gerente_sucursal');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    Leccion::factory()->create(['curso_modulo_id' => $modulo->id]);

    $colaboradorAjeno = User::factory()->create(['sucursal_principal_id' => $sucursalAjena->id]);
    InscripcionCurso::factory()->create(['user_id' => $colaboradorAjeno->id, 'curso_id' => $curso->id]);

    $respuesta = $this->actingAs($gerente)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) use ($curso) {
        $filas = collect($page->toArray()['props']['graficas']['topCursosAvance']);

        expect($filas->firstWhere('curso_id', $curso->id))->toBeNull();
    });
});

test('los usuarios con pendientes criticos respetan el aislamiento por sucursal', function () {
    $sucursalPropia = Sucursal::factory()->create();
    $sucursalAjena = Sucursal::factory()->create();

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $gerente->assignRole('gerente_sucursal');

    $colaboradorPropio = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $colaboradorAjeno = User::factory()->create(['sucursal_principal_id' => $sucursalAjena->id]);

    $responsable = User::factory()->create();
    $curso = Curso::factory()->create();
    $asignacion = Asignacion::create(['nombre' => 'Curso de prueba', 'asignable_type' => Curso::class, 'asignable_id' => $curso->id, 'responsable_id' => $responsable->id]);
    AsignacionUsuario::create(['asignacion_id' => $asignacion->id, 'user_id' => $colaboradorPropio->id, 'estado' => 'vencida']);
    AsignacionUsuario::create(['asignacion_id' => $asignacion->id, 'user_id' => $colaboradorAjeno->id, 'estado' => 'vencida']);

    $respuesta = $this->actingAs($gerente)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) use ($colaboradorPropio, $colaboradorAjeno) {
        $filas = collect($page->toArray()['props']['graficas']['usuariosPendientesCriticos']);

        expect($filas->firstWhere('id', $colaboradorPropio->id)['vencidas'])->toEqual(1);
        expect($filas->firstWhere('id', $colaboradorAjeno->id))->toBeNull();
    });
});

test('los cuestionarios aprobados vs reprobados solo cuentan el ultimo intento calificado por usuario', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $cuestionario = Cuestionario::factory()->create();
    $colaborador = User::factory()->create();

    IntentoCuestionario::factory()->create([
        'cuestionario_id' => $cuestionario->id,
        'user_id' => $colaborador->id,
        'numero_intento' => 1,
        'estado' => 'calificado',
        'aprobado' => false,
    ]);
    IntentoCuestionario::factory()->create([
        'cuestionario_id' => $cuestionario->id,
        'user_id' => $colaborador->id,
        'numero_intento' => 2,
        'estado' => 'calificado',
        'aprobado' => true,
    ]);

    $respuesta = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        $cuestionarios = $page->toArray()['props']['graficas']['cuestionarios'];

        expect($cuestionarios)->toEqual(['aprobados' => 1, 'reprobados' => 0]);
    });
});

test('cursos por estado cuenta las inscripciones agrupadas por estado', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $colaboradorA = User::factory()->create();
    $colaboradorB = User::factory()->create();
    $colaboradorC = User::factory()->create();
    $curso = Curso::factory()->create();

    InscripcionCurso::factory()->create(['user_id' => $colaboradorA->id, 'curso_id' => $curso->id, 'estado' => 'completada']);
    InscripcionCurso::factory()->create(['user_id' => $colaboradorB->id, 'curso_id' => $curso->id, 'estado' => 'en_progreso']);
    InscripcionCurso::factory()->create(['user_id' => $colaboradorC->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);

    $respuesta = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        expect($page->toArray()['props']['graficas']['cursosPorEstado'])->toEqual([
            'completados' => 1,
            'en_progreso' => 1,
            'pendientes' => 1,
        ]);
    });
});
