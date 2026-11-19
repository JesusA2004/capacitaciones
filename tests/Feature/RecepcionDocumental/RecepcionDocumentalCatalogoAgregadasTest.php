<?php

use App\Enums\EstadoRecepcionDocumento;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\RecepcionDocumentalCatalogo;
use App\Models\RecepcionDocumentalRenglon;
use App\Models\Sucursal;
use App\Services\RecepcionDocumental\CatalogoReglasRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use App\Services\RecepcionDocumental\RecepcionDocumentalService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;

/*
| Recepción documental — entradas AGREGADAS por RH en el catálogo
| (docs/RECEPCION_DOCUMENTAL_RH.md → «Agregar lo que debe llegar»):
| simular antes/después, aplicar en cortes abiertos, apagar conservando lo
| recibido, el corte cerrado no cambia y solo `configurar` puede hacerlo.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-11-20 18:00:00', 'UTC'));
    $this->toluca = Sucursal::factory()->create(['nombre' => 'Toluca', 'activo' => true]);
    $this->metepec = Sucursal::factory()->create(['nombre' => 'Metepec', 'activo' => true]);
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->toluca->id]);
    app(CatalogoReglasRecepcion::class)->sembrar();
    // Solo lo agregado: las reglas del sistema apagadas para contar limpio.
    RecepcionDocumentalCatalogo::query()->update(['activa' => false]);

    foreach ([$this->toluca, $this->toluca, $this->metepec] as $sucursal) {
        Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => '2025-01-10']);
    }
});

afterEach(fn () => Carbon::setTestNow());

test('agregar «por sucursal» y «por colaborador activo» simula, aplica solo en cortes abiertos y apagar conserva lo recibido', function () {
    $servicio = app(RecepcionDocumentalService::class);
    $octubre = $servicio->generar(PeriodoRecepcion::desdeClave('2026-10'), null)['corte'];
    $servicio->cerrar($octubre, $this->rh, 'Cierre de octubre.');
    $base = ['sucursal_ids' => [$this->toluca->id, $this->metepec->id]];

    // Simular no guarda nada y dice cuántos papeles habrá.
    $this->actingAs($this->rh)->postJson(route('rh.recepcion-documental.catalogo.simular'), [
        'accion' => 'crear', 'nombre' => 'Reporte de caja', 'modo_calculo' => 'por_sucursal', 'cantidad' => 2, ...$base,
    ])->assertOk()->assertJsonPath('data.total.despues', 4)->assertJsonPath('data.cerrados_intactos', 1);
    expect(RecepcionDocumentalCatalogo::query()->where('nombre', 'Reporte de caja')->exists())->toBeFalse();

    $this->actingAs($this->rh)->postJson(route('rh.recepcion-documental.catalogo.store'), [
        'nombre' => 'Reporte de caja', 'modo_calculo' => 'por_sucursal', 'cantidad' => 2, ...$base,
    ])->assertCreated()->assertJsonPath('recalculo.recalculado', true);
    $this->actingAs($this->rh)->postJson(route('rh.recepcion-documental.catalogo.store'), [
        'nombre' => 'Constancia firmada', 'modo_calculo' => 'por_colaborador_activo', 'cantidad' => 1, ...$base,
    ])->assertCreated();

    $noviembre = $servicio->corteDelPeriodo(PeriodoRecepcion::desdeClave('2026-11'));
    // 2 sucursales × 2 reportes + 4 activos × 1 constancia (3 + la persona de RH en Toluca) = 8 papeles.
    expect($servicio->totalEsperado($noviembre))->toBe(8)
        ->and(RecepcionDocumentalRenglon::query()->where('corte_id', $octubre->id)->count())->toBe(0);

    $caja = RecepcionDocumentalCatalogo::query()->where('nombre', 'Reporte de caja')->firstOrFail();
    $recibido = RecepcionDocumentalRenglon::query()->where('corte_id', $noviembre->id)->where('tipo_documento', $caja->clave)->where('sucursal_id', $this->toluca->id)->firstOrFail();
    $recibido->update(['estado' => EstadoRecepcionDocumento::Recibido, 'cantidad_recibida' => 2]);

    // Ya se usó: no se borra, solo se apaga (lo recibido se queda y se reporta).
    $this->actingAs($this->rh)->deleteJson(route('rh.recepcion-documental.catalogo.destroy', $caja))->assertUnprocessable();
    $this->actingAs($this->rh)->postJson(route('rh.recepcion-documental.catalogo.simular'), ['accion' => 'actualizar', 'entrada_id' => $caja->id, 'activa' => false])
        ->assertOk()->assertJsonPath('data.total.despues', 6)->assertJsonPath('data.conflictos', 1);
    $this->actingAs($this->rh)->patchJson(route('rh.recepcion-documental.catalogo.update', $caja), ['activa' => false])->assertOk();

    expect($recibido->refresh()->estado)->toBe(EstadoRecepcionDocumento::Recibido)
        ->and(RecepcionDocumentalRenglon::query()->where('corte_id', $noviembre->id)->where('tipo_documento', $caja->clave)->where('sucursal_id', $this->metepec->id)->value('estado'))->toBe(EstadoRecepcionDocumento::NoAplica)
        ->and($servicio->totalEsperado($noviembre))->toBe(6);
});

test('solo quien tiene recepcion_documental.configurar agrega o simula; una entrada sin uso se puede borrar', function () {
    $auxiliar = clUsuario('rh_auxiliar', ['sucursal_principal_id' => $this->toluca->id]);
    $datos = ['nombre' => 'Lista firmada', 'modo_calculo' => 'fijo_mensual', 'cantidad' => 1, 'activa' => false];

    $this->actingAs($auxiliar)->postJson(route('rh.recepcion-documental.catalogo.store'), $datos)->assertForbidden();
    $this->actingAs($auxiliar)->postJson(route('rh.recepcion-documental.catalogo.simular'), ['accion' => 'crear', ...$datos])->assertForbidden();

    $this->actingAs($this->rh)->postJson(route('rh.recepcion-documental.catalogo.store'), ['nombre' => 'Sin cálculo'])->assertUnprocessable()->assertJsonValidationErrors('modo_calculo');
    $id = $this->actingAs($this->rh)->postJson(route('rh.recepcion-documental.catalogo.store'), $datos)->assertCreated()->json('entrada_id');
    $entrada = RecepcionDocumentalCatalogo::query()->findOrFail($id);

    $this->actingAs($auxiliar)->deleteJson(route('rh.recepcion-documental.catalogo.destroy', $entrada))->assertForbidden();
    $this->actingAs($this->rh)->deleteJson(route('rh.recepcion-documental.catalogo.destroy', $entrada))->assertOk();

    expect(RecepcionDocumentalCatalogo::query()->whereKey($id)->exists())->toBeFalse();
});
