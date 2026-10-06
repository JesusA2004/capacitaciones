<?php

use App\Enums\FamiliaAdministrativa;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\PlantillaAdministrativa;
use App\Models\ReciboNomina;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
 * Documentos administrativos HTML → PDF (recibo de nómina, finiquito…):
 * el DISEÑO se versiona en Documentos maestros y los PDF generados guardan
 * snapshot de versión/diseño/motor; el diseño nunca cambia los datos.
 * Motor en pruebas: DomPDF (phpunit.xml PDF_RENDERER=dompdf).
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'numero_empleado' => 'EMP-0300']);
});

function daRecibo(object $prueba, string $inicio, string $fin): ReciboNomina
{
    Sanctum::actingAs($prueba->rh);
    $id = $prueba->postJson("/api/v1/rh/colaboradores/{$prueba->colaborador->id}/recibos", [
        'periodo_inicio' => $inicio,
        'periodo_fin' => $fin,
        'fecha_pago' => $fin,
        'conceptos' => [
            ['tipo' => 'percepcion', 'concepto' => 'Sueldo semanal', 'importe' => 3500],
            ['tipo' => 'deduccion', 'concepto' => 'Anticipo', 'importe' => 400],
        ],
    ])->assertCreated()->json('data.id');

    return ReciboNomina::query()->findOrFail($id);
}

function daDocumentoDe(ReciboNomina $recibo): GeneratedDocument
{
    return GeneratedDocument::query()->where('documentable_type', $recibo->getMorphClass())->where('documentable_id', $recibo->id)->firstOrFail();
}

test('el recibo se genera con el diseño de fábrica y guarda snapshot de versión, motor y diseño', function () {
    $documento = daDocumentoDe(daRecibo($this, '2026-09-14', '2026-09-20'));

    expect($documento->master_familia)->toBe('administrativo.recibo_nomina')
        ->and($documento->master_version)->toBe(0)
        ->and($documento->conversion_engine)->toBe('dompdf')
        ->and($documento->layout_snapshot['diseno']['content']['titulo'])->toBe('RECIBO DE NÓMINA')
        ->and($documento->layout_snapshot['motor'])->toBe('dompdf')
        ->and($documento->layout_snapshot['generado_por'])->toBe($this->rh->id)
        ->and(strlen((string) $documento->master_hash))->toBe(64);
    Storage::disk('nas')->assertExists($documento->path);
});

test('RH edita un borrador y lo activa: los nuevos recibos usan la versión nueva y los anteriores no cambian', function () {
    $anterior = daDocumentoDe(daRecibo($this, '2026-09-14', '2026-09-20'));
    $hashAnterior = $anterior->checksum;

    $this->actingAs($this->rh)->post(route('rh.documentos-maestros.administrativos.borrador', 'recibo_nomina'))->assertRedirect();
    $borrador = PlantillaAdministrativa::query()->where('familia', 'recibo_nomina')->firstOrFail();
    expect($borrador->estado)->toBe(PlantillaAdministrativa::BORRADOR)->and($borrador->version)->toBe(1);

    $diseno = $borrador->diseno;
    $diseno['content']['titulo'] = 'RECIBO {{ folio }}';
    $diseno['page']['margins_mm']['top'] = 25;
    $diseno['colors']['primary'] = '#112233';
    $this->actingAs($this->rh)->put(route('rh.documentos-maestros.administrativos.guardar', $borrador), ['diseno' => $diseno, 'motor' => 'dompdf', 'notas' => 'Margen mayor'])->assertRedirect();
    $this->actingAs($this->rh)->post(route('rh.documentos-maestros.administrativos.activar', $borrador))->assertRedirect();

    $nuevo = daDocumentoDe(daRecibo($this, '2026-09-21', '2026-09-27'));

    expect($nuevo->master_version)->toBe(1)
        ->and($nuevo->layout_snapshot['diseno']['page']['margins_mm']['top'])->toEqual(25)
        ->and($nuevo->layout_snapshot['diseno']['colors']['primary'])->toBe('#112233')
        ->and($anterior->fresh()->master_version)->toBe(0)
        ->and($anterior->fresh()->checksum)->toBe($hashAnterior)
        ->and($anterior->fresh()->layout_snapshot['diseno']['content']['titulo'])->toBe('RECIBO DE NÓMINA');

    // Una versión activa no se edita.
    $this->actingAs($this->rh)->put(route('rh.documentos-maestros.administrativos.guardar', $borrador), ['diseno' => $diseno])->assertSessionHasErrors('plantilla');
});

test('el diseño no puede alterar montos: claves ajenas se descartan y los importes salen del recibo', function () {
    $recibo = daRecibo($this, '2026-09-14', '2026-09-20');
    $familia = FamiliaAdministrativa::ReciboNomina;
    $this->actingAs($this->rh)->post(route('rh.documentos-maestros.administrativos.borrador', $familia->value));
    $borrador = PlantillaAdministrativa::query()->firstOrFail();

    $this->actingAs($this->rh)->put(route('rh.documentos-maestros.administrativos.guardar', $borrador), [
        'diseno' => [...$borrador->diseno, 'neto' => '$999,999.00', 'percepciones' => [['concepto' => 'Inventado', 'importe' => '$1']], 'page' => ['margins_mm' => ['top' => 500]]],
    ])->assertRedirect();

    $guardado = $borrador->fresh()->diseno;
    expect($guardado)->not->toHaveKey('neto')
        ->and($guardado)->not->toHaveKey('percepciones')
        ->and($guardado['page']['margins_mm']['top'])->toEqual(60);

    $servicio = app(DocumentoAdministrativoService::class);
    $html = $servicio->html($familia, app(DatosDocumentoAdministrativo::class)->recibo($recibo), $guardado);
    expect($html)->toContain('$3,100.00')->not->toContain('999,999')->not->toContain('Inventado');
});

test('la vista previa es un PDF real con datos ficticios y solo la ve quien administra plantillas', function () {
    $this->actingAs($this->rh)->get(route('rh.documentos-maestros.administrativos.vista-previa', 'finiquito'))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs($this->rh)->get(route('rh.documentos-maestros.administrativos.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/DocumentosMaestros/Administrativos')->has('familias', count(FamiliaAdministrativa::cases())));
    $this->actingAs($this->rh)->get(route('rh.documentos-maestros.administrativos.editar', 'recibo_nomina'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/DocumentosMaestros/AdministrativoEditor')->where('vigente.version', 0));

    $colaborador = clUsuario('colaborador');
    $this->actingAs($colaborador)->get(route('rh.documentos-maestros.administrativos.vista-previa', 'finiquito'))->assertForbidden();
    $this->actingAs($colaborador)->post(route('rh.documentos-maestros.administrativos.borrador', 'finiquito'))->assertForbidden();
});

test('cada cambio de plantilla queda en la auditoría', function () {
    $this->actingAs($this->rh)->post(route('rh.documentos-maestros.administrativos.borrador', 'recibo_nomina'));
    $borrador = PlantillaAdministrativa::query()->firstOrFail();
    $this->actingAs($this->rh)->put(route('rh.documentos-maestros.administrativos.guardar', $borrador), ['diseno' => $borrador->diseno]);
    $this->actingAs($this->rh)->post(route('rh.documentos-maestros.administrativos.activar', $borrador));

    $eventos = Activity::query()->where('subject_type', $borrador->getMorphClass())->where('subject_id', $borrador->id)->pluck('event')->all();
    expect($eventos)->toContain('plantilla_administrativa_borrador')
        ->toContain('plantilla_administrativa_diseno')
        ->toContain('plantilla_administrativa_activada');
});
