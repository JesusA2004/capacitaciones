<?php

use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Enums\OrigenBaja;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\PlantillaMensual;
use App\Models\RotacionCarga;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Services\Bajas\BajaRegistroService;
use App\Services\Bajas\PlantillaMensualService;
use App\Services\Bajas\TableroRotacionService;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Rotacion\RotacionAnalisisService;
use App\Services\Rotacion\RotacionMotorService;
use Carbon\CarbonImmutable;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Tablero de rotación (criterio de aceptación 6) y plantilla mensual: el
| índice de un mes = bajas (es_rotacion, por último día) ÷ plantilla promedio
| × 100; antes del mes de inicio del sistema se usan los agregados del Excel
| de rotación.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    config(['bajas.tablero.mes_inicio_sistema' => '2026-04']);

    $this->estructura = clEstructura();
    $this->sucursal = $this->estructura['sucursal'];
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->sucursal->id]);
});

function trBajaCerrada(Sucursal $sucursal, string $clave, string $fecha, bool $rotacion = true, string $ingreso = '2025-01-01'): Baja
{
    $persona = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'fecha_ingreso' => $ingreso, 'estatus' => EstadoUsuario::Inactivo, 'fecha_baja' => $fecha]);
    $causal = CausalRotacion::query()->where('clave', $clave)->firstOrFail();
    $baja = app(BajaRegistroService::class)->nueva($persona, [
        'forma_salida' => $causal->forma(),
        'causal_rotacion_id' => $causal->id,
        'fecha_baja' => $fecha,
        'estatus' => EstatusBaja::Cerrada,
        'es_rotacion' => $rotacion,
        'origen' => OrigenBaja::Sistema,
        'cerrado_en' => now(),
    ], null);
    $baja->save();

    return $baja;
}

test('criterio 6: el índice del mes es igual al cálculo a mano con plantilla_mensual', function () {
    PlantillaMensual::query()->create(['periodo' => '2026-05', 'anio' => 2026, 'mes' => 5, 'sucursal_id' => $this->sucursal->id, 'activos_inicio' => 40, 'activos_cierre' => 38, 'plantilla_promedio' => '39.00', 'fuente' => 'foto']);

    trBajaCerrada($this->sucursal, 'RV-01', '2026-05-10');
    trBajaCerrada($this->sucursal, 'RE-01', '2026-05-20');
    // No cuenta: movimiento interno (RN-14).
    trBajaCerrada($this->sucursal, 'RV-02', '2026-05-21', rotacion: false);

    $mes = app(TableroRotacionService::class)->mes(CarbonImmutable::create(2026, 5, 1), collect([$this->sucursal->id]));

    expect($mes['bajas'])->toBe(2)
        ->and($mes['plantilla_promedio'])->toBe(39.0)
        ->and($mes['indice'])->toBe(round(2 * 100 / 39, 2))
        ->and($mes['fuente'])->toBe('sistema');

    $f = app(RotacionMotorService::class)->filtros($this->rh, ['periodo' => 'mes', 'anio' => 2026, 'mes' => 5]);
    $causales = collect(app(RotacionAnalisisService::class)->causales($f)['causales'])->pluck('clave')->all();
    expect($causales)->toContain('oferta_laboral', 'abandono_faltas');
});

test('los meses anteriores al módulo usan el Excel de rotación; el desglose por causal solo existe para bajas individuales', function () {
    $carga = RotacionCarga::query()->create(['nombre_archivo' => 'rotacion.xlsx', 'archivo_ruta' => 'rotacion/x.xlsx', 'archivo_hash' => str_repeat('a', 64), 'estado' => 'aplicada', 'subido_por' => $this->rh->id]);
    RotacionPeriodo::query()->create(['sucursal_id' => $this->sucursal->id, 'anio' => 2026, 'mes' => 3, 'sucursal_excel' => 'CUERNAVACA', 'plantilla_inicial' => 50, 'altas' => 2, 'bajas' => 3, 'plantilla_final' => 46, 'carga_id' => $carga->id]);

    $marzo = app(TableroRotacionService::class)->mes(CarbonImmutable::create(2026, 3, 1), collect([$this->sucursal->id]));

    expect($marzo['fuente'])->toBe('excel')
        ->and($marzo['bajas'])->toBe(3)
        ->and($marzo['indice'])->toBe(round(3 * 100 / 48, 2));

    $motor = app(RotacionMotorService::class);
    $analisis = app(RotacionAnalisisService::class);
    $conDetalle = $analisis->resumen($motor->filtros($this->rh, ['periodo' => 'mes', 'anio' => 2026, 'mes' => 3, 'clasificacion' => 'voluntaria']));
    expect($conDetalle['total'])->toBe(0)->and($conDetalle['no_desglosables'])->toBe(3);

    $tendencia = $analisis->tendencia($motor->filtros($this->rh, ['periodo' => 'rango', 'desde' => '2026-01-01', 'hasta' => '2026-05-31']));
    expect(collect($tendencia['meses'])->firstWhere('periodo', '2026-03')['total'])->toBe(3)
        ->and(collect($tendencia['meses'])->firstWhere('periodo', '2026-03')['sin_desglose'])->toBe(3);
});

test('por sucursal: bajas, índice con su plantilla y serie mensual', function () {
    foreach (['2026-04' => 40, '2026-05' => 40] as $periodo => $total) {
        PlantillaMensual::query()->create(['periodo' => $periodo, 'anio' => 2026, 'mes' => (int) substr($periodo, 5), 'sucursal_id' => $this->sucursal->id, 'activos_inicio' => $total, 'activos_cierre' => $total, 'plantilla_promedio' => $total, 'fuente' => 'foto']);
    }

    trBajaCerrada($this->sucursal, 'RV-01', '2026-04-10');
    trBajaCerrada($this->sucursal, 'RV-01', '2026-05-10');
    trBajaCerrada($this->sucursal, 'NR-01', '2026-05-12');

    $f = app(RotacionMotorService::class)->filtros($this->rh, ['periodo' => 'rango', 'desde' => '2026-04-01', 'hasta' => '2026-05-31']);
    $fila = app(RotacionAnalisisService::class)->sucursales($f)[0];

    expect($fila['bajas'])->toBe(3)
        ->and($fila['serie'])->toBe([1, 2])
        ->and($fila['indice'])->toBe(round(3 * 100 / 40, 2));
});

test('exportar a Excel y PDF respeta los filtros', function () {
    $this->app->instance(BrowsershotRenderer::class, new class extends BrowsershotRenderer
    {
        public function renderizar(string $html, OpcionesPdf $opciones): string
        {
            return '%PDF-1.4 prueba';
        }
    });

    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.excel', ['periodo' => 'rango', 'desde' => '2026-04-01', 'hasta' => '2026-05-31']))->assertOk()->assertDownload();
    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.pdf', ['periodo' => 'rango', 'desde' => '2026-04-01', 'hasta' => '2026-05-31', 'clasificacion' => 'voluntaria']))->assertOk();
});

test('plantilla mensual: la foto cuenta activos por sucursal y el backfill reconstruye sin pisar la foto', function () {
    $otra = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id]);
    Colaborador::factory()->count(3)->create(['sucursal_principal_id' => $otra->id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => '2025-06-01']);
    // Dado de baja en abril: activo el 1 de abril, ya no el 30.
    Colaborador::factory()->create(['sucursal_principal_id' => $otra->id, 'estatus' => EstadoUsuario::Inactivo, 'fecha_ingreso' => '2025-01-01', 'fecha_baja' => '2026-04-15']);

    $servicio = app(PlantillaMensualService::class);
    $servicio->foto(CarbonImmutable::create(2026, 10, 1), 'inicio');
    $foto = PlantillaMensual::query()->where('periodo', '2026-10')->where('sucursal_id', $otra->id)->firstOrFail();
    expect($foto->activos_inicio)->toBe(3)->and($foto->fuente)->toBe('foto')->and((float) $foto->plantilla_promedio)->toBe(3.0);

    $foto->update(['activos_inicio' => 99]);
    $servicio->backfill(CarbonImmutable::create(2026, 4, 1), CarbonImmutable::create(2026, 10, 1));

    $abril = PlantillaMensual::query()->where('periodo', '2026-04')->where('sucursal_id', $otra->id)->firstOrFail();
    expect($abril->activos_inicio)->toBe(4)
        ->and($abril->activos_cierre)->toBe(3)
        ->and((float) $abril->plantilla_promedio)->toBe(3.5)
        ->and($abril->fuente)->toBe('reconstruida')
        // La foto tomada no se pisa.
        ->and($foto->refresh()->activos_inicio)->toBe(99);

    $this->artisan('bajas:plantilla-backfill', ['--desde' => '2026-01', '--hasta' => '2026-02'])->assertSuccessful();
    $this->artisan('bajas:foto-plantilla', ['--momento' => 'cierre'])->assertSuccessful();
});
