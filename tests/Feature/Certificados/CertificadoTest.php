<?php

use App\Models\Certificado;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Models\User;
use App\Services\Capacitacion\ProgresoService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function completarCursoConConstancia(bool $generaConstancia = true): array
{
    $colaborador = User::factory()->create();
    $curso = Curso::factory()->create(['genera_constancia' => $generaConstancia]);
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'obligatoria' => true]);

    app(ProgresoService::class)->completarLeccion($colaborador, $leccion);

    return [$colaborador, $curso];
}

test('completar un curso con genera_constancia emite una constancia con folio unico', function () {
    [$colaborador, $curso] = completarCursoConConstancia();

    $certificado = Certificado::where('user_id', $colaborador->id)->where('curso_id', $curso->id)->first();

    expect($certificado)->not->toBeNull();
    expect($certificado->folio)->toStartWith('MRL-');
});

test('completar un curso sin genera_constancia no emite ninguna constancia', function () {
    [$colaborador, $curso] = completarCursoConConstancia(generaConstancia: false);

    expect(Certificado::where('user_id', $colaborador->id)->where('curso_id', $curso->id)->exists())->toBeFalse();
});

test('completar el mismo curso dos veces no duplica la constancia', function () {
    [$colaborador, $curso] = completarCursoConConstancia();

    $servicio = app(ProgresoService::class);
    $servicio->recalcularInscripcion($colaborador, $curso);
    $servicio->recalcularInscripcion($colaborador, $curso);

    expect(Certificado::where('user_id', $colaborador->id)->where('curso_id', $curso->id)->count())->toBe(1);
});

test('el propio colaborador puede descargar su constancia en pdf', function () {
    [$colaborador] = completarCursoConConstancia();
    $certificado = Certificado::where('user_id', $colaborador->id)->firstOrFail();

    $respuesta = $this->actingAs($colaborador)
        ->get(route('mi-capacitacion.constancias.descargar', $certificado));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf');
});

test('otro colaborador no puede descargar una constancia ajena', function () {
    [$colaborador] = completarCursoConConstancia();
    $certificado = Certificado::where('user_id', $colaborador->id)->firstOrFail();

    $otro = User::factory()->create();

    $this->actingAs($otro)
        ->get(route('mi-capacitacion.constancias.descargar', $certificado))
        ->assertForbidden();
});

test('la verificacion publica confirma un folio valido sin necesidad de sesion', function () {
    [$colaborador] = completarCursoConConstancia();
    $certificado = Certificado::where('user_id', $colaborador->id)->firstOrFail();

    $respuesta = $this->get(route('constancias.verificar', $certificado->folio))->assertOk();

    $respuesta->assertInertia(fn ($page) => $page->component('Constancias/Verificar')->where('valido', true));
});

test('la verificacion publica indica que un folio inexistente no es valido', function () {
    $respuesta = $this->get(route('constancias.verificar', 'MRL-NOEXISTE'))->assertOk();

    $respuesta->assertInertia(fn ($page) => $page->component('Constancias/Verificar')->where('valido', false));
});
