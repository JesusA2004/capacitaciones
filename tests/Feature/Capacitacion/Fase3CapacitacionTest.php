<?php

use App\Enums\NivelCertificacion;
use App\Models\Certificado;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Models\User;
use App\Services\Asignaciones\AsignacionService;
use App\Services\Capacitacion\ProgresoService;
use App\Services\Certificados\CertificacionMrLanaService;
use Database\Seeders\RolesYPermisosSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Fase 3 de PEOPLE (docs/CAPACITACION.md): portada operativa, aula tipo
 * Classroom, Mi capacitación (aulas/pendientes/certificados) y la base de la
 * Certificación Mr. Lana, con la bandera `capacitacion` encendida.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

/**
 * Curso publicado con dos lecciones obligatorias, asignado a $alumno.
 *
 * @return array{0: Curso, 1: Leccion, 2: Leccion}
 */
function fase3CursoAsignado(User $alumno, User $admin, bool $constancia = true): array
{
    $curso = Curso::factory()->create(['genera_constancia' => $constancia, 'requiere_orden' => true]);
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $primera = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'orden' => 1, 'obligatoria' => true]);
    $segunda = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'orden' => 2, 'obligatoria' => true]);

    app(AsignacionService::class)->crear($curso, [
        'nombre' => 'Asignación de prueba',
        'responsable_id' => $admin->id,
    ], [['tipo' => 'usuario', 'id' => $alumno->id]]);

    return [$curso, $primera, $segunda];
}

test('la bandera capacitacion está encendida por defecto en la configuración', function () {
    $config = require base_path('config/features.php');

    expect($config['capacitacion'])->toBeTrue()
        ->and($config['certificacion'])->toBeTrue();
});

test('la portada de capacitación carga para quien opera el módulo y su resumen llega diferido', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');
    $alumno = User::factory()->create();
    fase3CursoAsignado($alumno, $admin);

    $this->actingAs($admin)
        ->get(route('capacitacion.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Capacitacion/Index')
            ->where('puede.ver_cursos', true)
            ->missing('portada')
            ->loadDeferredProps(fn (Assert $recarga) => $recarga
                ->where('portada.indicadores.participantes', 1)
                ->has('portada.cursos', 1)
                ->has('portada.serie', 6)
                ->has('portada.estados', 3)));
});

test('la portada y el tablero de certificación responden 403 a un colaborador sin permisos de capacitación', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)->get(route('capacitacion.index'))->assertForbidden();
    $this->actingAs($colaborador)->get(route('capacitacion.certificacion'))->assertForbidden();
});

test('el aula muestra tablón, trabajo, calificaciones y participantes con su progreso', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');
    $alumno = User::factory()->create(['name' => 'Ana']);
    [$curso, $primera] = fase3CursoAsignado($alumno, $admin);

    app(ProgresoService::class)->completarLeccion($alumno, $primera);

    $this->actingAs($admin)
        ->get(route('cursos.aula', $curso))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Cursos/Aula')
            ->where('aula.curso.id', $curso->id)
            ->where('aula.resumen.participantes', 1)
            ->where('aula.participantes.0.progreso', 50)
            ->has('aula.tablon')
            ->has('aula.trabajo')
            ->has('aula.calificaciones', 1));
});

test('mi capacitación muestra mis aulas, mis pendientes desbloqueados y mis certificados', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');
    $alumno = User::factory()->create();
    [$curso, $primera, $segunda] = fase3CursoAsignado($alumno, $admin);

    foreach (['mi-capacitacion.index' => 'aulas', 'mi-capacitacion.pendientes' => 'pendientes', 'mi-capacitacion.certificados' => 'certificados'] as $ruta => $seccion) {
        $this->actingAs($alumno)
            ->get(route($ruta))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MiCapacitacion/Index')
                ->where('seccion', $seccion)
                ->loadDeferredProps(fn (Assert $recarga) => $recarga
                    ->has('panel.aulas', 1)
                    ->where('panel.aulas.0.progreso', 0)
                    // requiere_orden: solo la primera lección está desbloqueada.
                    ->has('panel.pendientes', 1)
                    ->where('panel.pendientes.0.leccion_id', $primera->id)
                    ->has('panel.certificados', 0)
                    ->where('panel.certificacion.nivel', null)));
    }

    $progreso = app(ProgresoService::class);
    $progreso->completarLeccion($alumno, $primera);
    $progreso->completarLeccion($alumno, $segunda->fresh());

    $this->actingAs($alumno)
        ->get(route('mi-capacitacion.certificados'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $recarga) => $recarga
                ->where('panel.aulas.0.progreso', 100)
                ->has('panel.pendientes', 0)
                ->has('panel.certificados', 1)
                ->where('panel.certificados.0.curso_id', $curso->id)
                ->where('panel.certificacion.nivel.clave', NivelCertificacion::Bronce->value)));
});

test('los niveles de la Certificación Mr. Lana salen de los umbrales configurados', function () {
    config(['capacitacion.certificacion.umbrales' => ['bronce' => 1, 'plata' => 2, 'oro' => 4]]);
    $servicio = app(CertificacionMrLanaService::class);

    expect($servicio->nivelPara(0))->toBeNull()
        ->and($servicio->nivelPara(1))->toBe(NivelCertificacion::Bronce)
        ->and($servicio->nivelPara(3))->toBe(NivelCertificacion::Plata)
        ->and($servicio->nivelPara(9))->toBe(NivelCertificacion::Oro);

    $resumen = $servicio->resumenPorConteo(3);
    expect($resumen['siguiente']['clave'] ?? null)->toBe('oro')
        ->and($resumen['siguiente']['faltan'] ?? null)->toBe(1)
        ->and($resumen['progreso'])->toBe(50);
});

test('el tablero de certificación cuenta personas por nivel', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');
    $alumno = User::factory()->create();
    Certificado::factory()->create(['user_id' => $alumno->id]);

    $this->actingAs($admin)
        ->get(route('capacitacion.certificacion'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Capacitacion/Certificacion')
            ->has('niveles', 3)
            ->loadDeferredProps(fn (Assert $recarga) => $recarga
                ->where('tablero.total_certificados', 1)
                ->where('tablero.personas.0.id', $alumno->id)));
});
