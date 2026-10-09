<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Models\CampanaReclutamiento;
use App\Models\Candidato;
use App\Models\CandidatoEntrevista;
use App\Models\CandidatoPsicometrica;
use App\Models\HeadcountTarget;
use App\Models\Vacante;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Campaña completa: parte de una vacante REAL (de la que se derivan empresa,
 * sucursal, departamento y puesto), guarda lo publicado, sus adjuntos en el
 * NAS privado y mide su embudo (candidatos → entrevistas → psicométricos →
 * contratados) con costos, conversión y días de cobertura.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    $this->rh = clUsuario('rh_admin');
    $this->estructura = clEstructura();
    HeadcountTarget::factory()->create(['sucursal_id' => $this->estructura['sucursal']->id, 'puesto_id' => $this->estructura['puesto']->id, 'plantilla_autorizada' => 10]);
    $this->vacante = Vacante::factory()->create([
        'empresa_id' => $this->estructura['empresa']->id, 'sucursal_id' => $this->estructura['sucursal']->id, 'puesto_id' => $this->estructura['puesto']->id,
        'estado' => EstadoVacante::Abierta->value, 'plazas_requeridas' => 2, 'plazas_disponibles' => 2, 'plazas_cubiertas' => 0,
    ]);
});

test('la campaña se liga a una vacante real, deriva su ubicación y guarda publicación y adjuntos en el NAS', function () {
    $this->actingAs($this->rh)->post(route('rh.campanas.store'), [
        'vacante_id' => $this->vacante->id,
        'canal' => 'meta',
        'fecha_inicio' => '2026-10-01',
        'fecha_fin' => '2026-10-20',
        'presupuesto' => 5000,
        'monto' => 3000,
        'sueldo_publicado' => 9500,
        'copy' => 'Únete como Gestor en Córdoba.',
        'url' => 'https://facebook.com/anuncio/123',
        'responsable_id' => $this->rh->id,
        // Aunque la petición mande otra sucursal, sale de la vacante.
        'sucursal_id' => 999999,
        'adjuntos' => [UploadedFile::fake()->image('arte.png'), UploadedFile::fake()->create('copy.pdf', 50, 'application/pdf')],
    ])->assertSessionHasNoErrors();

    $campana = CampanaReclutamiento::query()->with('adjuntos')->firstOrFail();
    expect($campana->sucursal_id)->toBe($this->estructura['sucursal']->id)
        ->and($campana->puesto_id)->toBe($this->estructura['puesto']->id)
        ->and($campana->empresa_id)->toBe($this->estructura['empresa']->id)
        ->and($campana->mes)->toBe(10)
        ->and($campana->anio)->toBe(2026)
        ->and($campana->presupuesto)->toBe('5000.00')
        ->and($campana->sueldo_publicado)->toBe('9500.00')
        ->and($campana->responsable_id)->toBe($this->rh->id)
        ->and($campana->adjuntos)->toHaveCount(2);

    $adjunto = $campana->adjuntos->first();
    Storage::disk('nas')->assertExists($adjunto->path);
    $this->actingAs($this->rh)->get(route('rh.campanas.adjuntos.show', [$campana, $adjunto]))->assertOk();

    // Un colaborador sin permiso no ve el arte.
    $this->actingAs(clUsuario('colaborador'))->get(route('rh.campanas.adjuntos.show', [$campana, $adjunto]))->assertForbidden();
});

test('sin vacante no se crea una campaña', function () {
    $this->actingAs($this->rh)->post(route('rh.campanas.store'), [
        'canal' => 'meta', 'fecha_inicio' => '2026-10-01', 'monto' => 100,
    ])->assertSessionHasErrors('vacante_id');
});

test('las métricas del embudo salen de los candidatos de la campaña', function () {
    $campana = CampanaReclutamiento::query()->create([
        'vacante_id' => $this->vacante->id, 'canal' => 'meta', 'mes' => 10, 'anio' => 2026, 'fecha_inicio' => '2026-10-01', 'monto' => 4000,
        'sucursal_id' => $this->estructura['sucursal']->id, 'puesto_id' => $this->estructura['puesto']->id,
    ]);
    $candidatos = Candidato::factory()->count(4)->create(['campana_reclutamiento_id' => $campana->id, 'vacante_id' => $this->vacante->id]);
    foreach ($candidatos->take(2) as $c) {
        CandidatoEntrevista::query()->create(['candidato_id' => $c->id, 'realizada_en' => now(), 'resultado' => 'viable']);
    }
    CandidatoPsicometrica::query()->create(['candidato_id' => $candidatos[0]->id, 'link' => 'https://pruebas.test', 'enviada_en' => now()]);
    $candidatos[0]->forceFill(['estado' => EstadoCandidato::Contratado->value, 'contratado_en' => '2026-10-11 10:00:00'])->save();

    $this->actingAs($this->rh)->get(route('rh.campanas.index', ['mes' => 10, 'anio' => 2026]))
        ->assertInertia(fn ($page) => $page
            ->where('campanas.data.0.resultado.candidatos', 4)
            ->where('campanas.data.0.resultado.entrevistas', 2)
            ->where('campanas.data.0.resultado.psicometricos', 1)
            ->where('campanas.data.0.resultado.contratados', 1)
            ->where('campanas.data.0.resultado.costo_por_candidato', 1000)
            ->where('campanas.data.0.resultado.costo_por_colaborador', 4000)
            ->where('campanas.data.0.resultado.conversion', 25)
            ->where('campanas.data.0.resultado.dias_cobertura', 10));
});

test('impresiones y clics solo cuentan si RH los capturó; contactados y costo por clic salen de datos reales', function () {
    $campana = CampanaReclutamiento::query()->create([
        'vacante_id' => $this->vacante->id, 'canal' => 'meta', 'mes' => 10, 'anio' => 2026, 'fecha_inicio' => '2026-10-01', 'monto' => 3000,
        'sucursal_id' => $this->estructura['sucursal']->id, 'puesto_id' => $this->estructura['puesto']->id,
    ]);
    $candidatos = Candidato::factory()->count(3)->create(['campana_reclutamiento_id' => $campana->id, 'vacante_id' => $this->vacante->id, 'etapa_maxima' => 1]);
    $candidatos[0]->forceFill(['etapa_maxima' => EstadoCandidato::EntrevistaPendiente->orden()])->save();

    // Sin capturar: null (nunca cero inventado).
    $this->actingAs($this->rh)->get(route('rh.campanas.index', ['mes' => 10, 'anio' => 2026]))
        ->assertInertia(fn ($page) => $page
            ->where('campanas.data.0.resultado.contactados', 1)
            ->where('campanas.data.0.resultado.impresiones', null)
            ->where('campanas.data.0.resultado.clics', null)
            ->where('campanas.data.0.resultado.costo_por_clic', null));

    $campana->update(['impresiones' => 12000, 'clics' => 300]);

    $this->actingAs($this->rh)->get(route('rh.campanas.index', ['mes' => 10, 'anio' => 2026]))
        ->assertInertia(fn ($page) => $page
            ->where('campanas.data.0.resultado.impresiones', 12000)
            ->where('campanas.data.0.resultado.clics', 300)
            ->where('campanas.data.0.resultado.costo_por_clic', 10));
});
