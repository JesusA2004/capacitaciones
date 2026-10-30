<?php

use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\OrigenBaja;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Bajas\MigracionCatalogoBajasService;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Conversión del subcatálogo anterior (causales del 20-oct por la mañana) a
| las 17 claves RV/NR/RE y expediente de baja de los cierres previos.
| Idempotente y sin perder el valor original.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $estructura = clEstructura();
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->persona = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => '2024-01-01']);
    User::factory()->create(['colaborador_id' => $this->persona->id])->assignRole('colaborador');
});

test('una baja con causal anterior sin equivalencia limpia se convierte, conserva el original y queda marcada; correrlo dos veces no cambia nada', function () {
    // Simula una causal del catálogo anterior todavía vigente («Mutuo acuerdo»).
    $anterior = CausalRotacion::query()->where('clave', 'mutuo_acuerdo')->firstOrFail();
    $anterior->update(['legado' => false, 'activo' => true, 'reemplazada_por_id' => null]);

    $this->actingAs($this->rh)->post(route('rh.colaboradores.cierres.store', $this->persona), [
        'tipo_baja' => 'renuncia',
        'causal_rotacion_id' => $anterior->id,
        'fecha_efectiva' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    // Y un cierre anterior al módulo (sin expediente de baja).
    $cierre = CierreLaboral::query()->firstOrFail();
    $previa = DB::table('bajas')->where('cierre_laboral_id', $cierre->id)->value('id');
    DB::table('baja_bitacora')->where('baja_id', $previa)->delete();
    DB::table('bajas')->where('id', $previa)->delete();

    $servicio = app(MigracionCatalogoBajasService::class);
    $reporte = $servicio->aplicar();
    $rv05 = CausalRotacion::query()->where('clave', 'RV-05')->firstOrFail();
    $baja = Baja::query()->where('cierre_laboral_id', $cierre->id)->firstOrFail();

    expect($reporte['no_limpias'])->toContain('mutuo_acuerdo → RV-05')
        ->and($reporte['bajas_creadas'])->toBe(1)
        ->and($cierre->refresh()->causal_rotacion_id)->toBe($rv05->id)
        ->and($cierre->causal_rotacion_original)->toBe('Renuncia · Mutuo acuerdo')
        ->and($baja->origen)->toBe(OrigenBaja::CierrePrevio)
        ->and($baja->estatus)->toBe(EstatusBaja::EnRevisionRh)
        ->and($baja->causal_rotacion_id)->toBe($rv05->id)
        ->and($baja->causal_migrada_de)->toBe('Renuncia · Mutuo acuerdo')
        ->and($anterior->refresh()->legado)->toBeTrue()
        ->and($anterior->activo)->toBeFalse()
        ->and($anterior->reemplazada_por_id)->toBe($rv05->id);

    $conteos = [Baja::query()->count(), CausalRotacion::query()->count(), DB::table('baja_bitacora')->count()];
    $segundo = $servicio->aplicar();

    expect($segundo['bajas_creadas'])->toBe(0)
        ->and($segundo['legado'])->toBe([])
        ->and([Baja::query()->count(), CausalRotacion::query()->count(), DB::table('baja_bitacora')->count()])->toBe($conteos)
        ->and(CausalRotacion::query()->delCatalogo()->count())->toBe(20);
});

test('un cierre ya ejecutado crea su baja cerrada (cuenta en el tablero)', function () {
    $this->actingAs($this->rh)->post(route('rh.colaboradores.cierres.store', $this->persona), [
        'tipo_baja' => 'renuncia',
        'causal_rotacion_id' => CausalRotacion::query()->where('clave', 'RV-01')->value('id'),
        'fecha_efectiva' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    $cierre = CierreLaboral::query()->firstOrFail();
    $previa = DB::table('bajas')->where('cierre_laboral_id', $cierre->id)->value('id');
    DB::table('baja_bitacora')->where('baja_id', $previa)->delete();
    DB::table('bajas')->where('id', $previa)->delete();
    $cierre->update(['estado' => 'expediente_cerrado', 'baja_ejecutada_en' => now()]);

    app(MigracionCatalogoBajasService::class)->aplicar();

    $baja = Baja::query()->where('cierre_laboral_id', $cierre->id)->firstOrFail();
    expect($baja->estatus)->toBe(EstatusBaja::Cerrada)
        ->and($baja->causal_migrada_de)->toBeNull()
        ->and($baja->es_rotacion)->toBeTrue();
});
