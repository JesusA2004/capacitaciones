<?php

use App\Enums\EstadoEntregaActividad;
use App\Models\Actividad;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\EntregaActividad;
use App\Models\InscripcionCurso;
use App\Models\Leccion;
use App\Models\User;
use App\Services\Capacitacion\ProgresoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');

    $this->colaborador = User::factory()->create();
    $this->colaborador->assignRole('colaborador');

    $this->instructor = User::factory()->create();
    $this->instructor->assignRole('instructor');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $this->leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'actividad']);

    InscripcionCurso::create(['user_id' => $this->colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);
});

test('se puede configurar una actividad para una leccion', function () {
    $this->actingAs($this->instructor)
        ->post(route('cursos.lecciones.actividad.store', [$this->leccion->modulo->curso, $this->leccion->modulo, $this->leccion]), [
            'titulo' => 'Sube tu reporte',
            'tipo_entrega' => 'archivo',
            'calificacion_minima' => 70,
        ])
        ->assertRedirect();

    expect(Actividad::where('leccion_id', $this->leccion->id)->exists())->toBeTrue();
});

test('un colaborador puede entregar un archivo y no puede volver a entregar mientras esta pendiente', function () {
    $actividad = Actividad::factory()->create(['leccion_id' => $this->leccion->id, 'tipo_entrega' => 'archivo']);

    $this->actingAs($this->colaborador)
        ->post(route('mi-capacitacion.lecciones.actividad.store', $this->leccion), [
            'archivo' => UploadedFile::fake()->create('reporte.pdf', 200, 'application/pdf'),
        ])
        ->assertRedirect();

    $entrega = EntregaActividad::where('actividad_id', $actividad->id)->firstOrFail();
    expect($entrega->estado)->toBe(EstadoEntregaActividad::Entregada);
    expect($entrega->version)->toBe(1);
    expect($entrega->recurso_multimedia_id)->not->toBeNull();

    $this->actingAs($this->colaborador)
        ->post(route('mi-capacitacion.lecciones.actividad.store', $this->leccion), [
            'archivo' => UploadedFile::fake()->create('reporte2.pdf', 200, 'application/pdf'),
        ])
        ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'error');

    expect(EntregaActividad::where('actividad_id', $actividad->id)->count())->toBe(1);
});

test('aprobar una entrega completa la leccion y rechazarla permite reenviar', function () {
    $actividad = Actividad::factory()->create(['leccion_id' => $this->leccion->id, 'tipo_entrega' => 'texto']);

    $this->actingAs($this->colaborador)->post(route('mi-capacitacion.lecciones.actividad.store', $this->leccion), [
        'contenido_texto' => 'Mi entrega',
    ]);

    $entrega = EntregaActividad::where('actividad_id', $actividad->id)->firstOrFail();

    $this->actingAs($this->instructor)
        ->post(route('calificaciones.actividades.calificar', $entrega), ['aprobada' => false, 'retroalimentacion' => 'Falta detalle'])
        ->assertRedirect();

    $entrega->refresh();
    expect($entrega->estado)->toBe(EstadoEntregaActividad::Rechazada);
    expect(app(ProgresoService::class)->leccionCompletada($this->colaborador, $this->leccion))->toBeFalse();

    $this->actingAs($this->colaborador)
        ->post(route('mi-capacitacion.lecciones.actividad.store', $this->leccion), ['contenido_texto' => 'Entrega corregida'])
        ->assertRedirect();

    $segundaEntrega = EntregaActividad::where('actividad_id', $actividad->id)->where('version', 2)->firstOrFail();

    $this->actingAs($this->instructor)
        ->post(route('calificaciones.actividades.calificar', $segundaEntrega), ['aprobada' => true, 'calificacion' => 90])
        ->assertRedirect();

    expect(app(ProgresoService::class)->leccionCompletada($this->colaborador, $this->leccion))->toBeTrue();
});
