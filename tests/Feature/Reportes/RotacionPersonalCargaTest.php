<?php

use App\Enums\EstadoCargaRotacion;
use App\Models\Colaborador;
use App\Models\RotacionCarga;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Rotacion\RotacionExcelLector;
use App\Services\Rotacion\RotacionImportService;
use Database\Seeders\RolesYPermisosSeeder;
use Database\Seeders\SucursalSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
 * Carga del Excel de rotación de personal (Reportes → Rotación de
 * personal). El fixture reproduce la estructura del Excel real
 * (docs/rotacion/): bloque por sucursal con el nombre arriba de la fila de
 * meses, ALTAS / BAJAS / PLANTILLA / plantilla inicial en A / ROTACIÓN.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(SucursalSeeder::class);
    Storage::fake('nas');
});

function rotxUsuario(string $rol, ?int $sucursalId = null): User
{
    $colaborador = Colaborador::factory()->create($sucursalId !== null ? ['sucursal_principal_id' => $sucursalId] : []);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole($rol);

    return $usuario;
}

/**
 * Escribe un bloque con el layout del Excel real a partir de la fila $r
 * (nombre en F$r, meses en la fila $r+1).
 *
 * @param  array<int, array{0: int, 1: int}>  $meses  mes => [altas, bajas]
 * @param  array<int, float>  $rotacionExcel  mes => valor escrito en ROTACIÓN
 */
function rotxBloque($hoja, int $r, ?string $nombre, int $inicial, array $meses, array $rotacionExcel = []): void
{
    $encabezado = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPT', 'OCT', 'NOV', 'DIC'];
    if ($nombre !== null) {
        $hoja->setCellValue('F'.$r, $nombre);
    }
    foreach ($encabezado as $i => $mes) {
        $hoja->setCellValue(chr(66 + $i).($r + 1), $mes);
    }
    $hoja->setCellValue('N'.($r + 1), 'TOTALES');
    $hoja->setCellValue('A'.($r + 2), 'ALTAS');
    $hoja->setCellValue('A'.($r + 3), 'BAJAS');
    $hoja->setCellValue('A'.($r + 4), 'PLANTILLA');
    $hoja->setCellValue('A'.($r + 5), $inicial);
    $hoja->setCellValue('A'.($r + 6), 'ROTACIÓN');

    $plantilla = $inicial;
    foreach ($meses as $mes => [$altas, $bajas]) {
        $col = chr(65 + $mes);
        $plantilla += $altas - $bajas;
        $hoja->setCellValue($col.($r + 2), $altas);
        $hoja->setCellValue($col.($r + 3), $bajas);
        $hoja->setCellValue($col.($r + 4), $plantilla);
        $hoja->setCellValue($col.($r + 6), $rotacionExcel[$mes] ?? round($bajas * 100 / $plantilla, 4));
    }
}

/**
 * @param  callable(Worksheet): void  $llenar
 */
function rotxExcel(callable $llenar, string $hoja = 'SUCURSALES 2026'): UploadedFile
{
    $libro = new Spreadsheet;
    $libro->getActiveSheet()->setTitle($hoja);
    $libro->getActiveSheet()->setCellValue('C2', 'ROTACIÓN POR SUCURSALES 2026');
    $llenar($libro->getActiveSheet());

    $ruta = tempnam(sys_get_temp_dir(), 'rotx').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return new UploadedFile($ruta, 'INDICE DE ROTACION 2026.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function rotxArchivoBase(): UploadedFile
{
    return rotxExcel(function ($hoja) {
        // Atlacomulco: coincide exacto; enero trae la ROTACIÓN mal (como el Excel real, ÷3).
        rotxBloque($hoja, 9, 'ATLACOMULCO', 9, [1 => [0, 3], 2 => [2, 0], 3 => [1, 1]], [1 => 16.67]);
        // «SAN LUIS» → «San Luis Potosí» por coincidencia aproximada.
        rotxBloque($hoja, 18, 'SAN LUIS', 8, [1 => [1, 0], 2 => [0, 0]]);
        // No existe en el catálogo.
        rotxBloque($hoja, 27, 'PACHUCA', 9, [1 => [2, 2]]);
        // Bloque sin nombre (como el consolidado del Excel real).
        rotxBloque($hoja, 36, null, 50, [1 => [3, 3]]);
        // Resumen al pie: no se importa, se recalcula.
        $hoja->setCellValue('B60', 'Total de bajas');
        $hoja->setCellValue('D60', 99);
    });
}

test('analizar guarda el original en el NAS y reporta lo que no cuadró sin escribir periodos', function () {
    $rh = rotxUsuario('super_admin');

    $this->actingAs($rh)
        ->post(route('reportes.rotacion.analizar'), ['archivo' => rotxArchivoBase()])
        ->assertRedirect();

    $carga = RotacionCarga::query()->sole();
    expect($carga->estado)->toBe(EstadoCargaRotacion::Analizada)
        ->and($carga->periodos_detectados)->toBe(5) // 3 Atlacomulco + 2 San Luis
        ->and($carga->periodos_nuevos)->toBe(5)
        ->and(RotacionPeriodo::query()->count())->toBe(0)
        ->and($carga->archivo_ruta)->toStartWith('rotacion/');
    Storage::disk('nas')->assertExists($carga->archivo_ruta);

    $mensajes = collect($carga->detalle['hallazgos'])->pluck('mensaje')->implode("\n");
    expect($mensajes)
        ->toContain('«PACHUCA» no existe en el catálogo')
        ->toContain('sin nombre de sucursal')
        ->toContain('«SAN LUIS» se asignó a la sucursal «San Luis Potosí» por coincidencia aproximada')
        ->toContain('Enero: la ROTACIÓN del Excel (16.67 %) no es bajas × 100 / plantilla (50.00 %)')
        ->toContain('fuera de los bloques por sucursal');
    expect($carga->resumen['sucursales_sin_catalogo'])->toBe(['PACHUCA']);

    $this->actingAs($rh)->get(route('reportes.rotacion.index', ['carga' => $carga->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reportes/RotacionPersonal')
            ->where('cargaSeleccionada.id', $carga->id)
            ->where('cargaSeleccionada.puede_aplicar', true)
            ->has('cargaSeleccionada.periodos', 5));
});

test('aplicar carga los meses con el índice recalculado y lo audita', function () {
    $rh = rotxUsuario('super_admin');
    $this->actingAs($rh)->post(route('reportes.rotacion.analizar'), ['archivo' => rotxArchivoBase()]);
    $carga = RotacionCarga::query()->sole();

    $this->actingAs($rh)->post(route('reportes.rotacion.aplicar', $carga))->assertRedirect();

    $atlacomulco = Sucursal::query()->where('nombre', 'Atlacomulco')->value('id');
    $enero = RotacionPeriodo::query()->where(['sucursal_id' => $atlacomulco, 'anio' => 2026, 'mes' => 1])->sole();
    expect($enero->plantilla_inicial)->toBe(9)
        ->and($enero->altas)->toBe(0)
        ->and($enero->bajas)->toBe(3)
        ->and($enero->plantilla_final)->toBe(6)
        ->and((float) $enero->indice)->toBe(50.0)
        ->and((float) $enero->indice_excel)->toBe(16.67);

    expect(RotacionPeriodo::query()->count())->toBe(5)
        ->and($carga->refresh()->estado)->toBe(EstadoCargaRotacion::Aplicada)
        ->and($carga->aplicado_por)->toBe($rh->id)
        ->and(Activity::query()->where('event', 'rotacion.carga_aplicada')->exists())->toBeTrue();

    // Ya aplicada: no se puede volver a aplicar ni descartar.
    $this->actingAs($rh)->post(route('reportes.rotacion.aplicar', $carga))->assertForbidden();
});

test('volver a subir un mes lo reemplaza y guarda los valores anteriores', function () {
    $rh = rotxUsuario('super_admin');
    $this->actingAs($rh)->post(route('reportes.rotacion.analizar'), ['archivo' => rotxArchivoBase()]);
    $this->actingAs($rh)->post(route('reportes.rotacion.aplicar', RotacionCarga::query()->sole()));

    // Atlacomulco: marzo corregido (2 bajas) y abril nuevo.
    $nuevo = rotxExcel(fn ($hoja) => rotxBloque($hoja, 9, 'Atlacomulco', 9, [1 => [0, 3], 2 => [2, 0], 3 => [1, 2], 4 => [1, 0]], [1 => 16.67]));
    $this->actingAs($rh)->post(route('reportes.rotacion.analizar'), ['archivo' => $nuevo]);
    $segunda = RotacionCarga::query()->latest('id')->first();

    expect($segunda->periodos_nuevos)->toBe(1)
        ->and($segunda->periodos_reemplazados)->toBe(1)
        ->and($segunda->periodos_sin_cambios)->toBe(2);

    $this->actingAs($rh)->post(route('reportes.rotacion.aplicar', $segunda))->assertRedirect();

    $atlacomulco = Sucursal::query()->where('nombre', 'Atlacomulco')->value('id');
    $marzo = RotacionPeriodo::query()->where(['sucursal_id' => $atlacomulco, 'anio' => 2026, 'mes' => 3])->sole();
    expect($marzo->bajas)->toBe(2)
        ->and($marzo->plantilla_final)->toBe(7)
        ->and($marzo->carga_id)->toBe($segunda->id);

    $reemplazo = collect($segunda->refresh()->detalle['periodos'])->firstWhere('accion', 'reemplaza');
    expect($reemplazo['mes'])->toBe(3)
        ->and($reemplazo['anterior']['bajas'])->toBe(1);

    $auditoria = Activity::query()->where('event', 'rotacion.carga_aplicada')->latest('id')->first();
    expect($auditoria->properties['cambios'][0]['antes']['bajas'])->toBe(1)
        ->and($auditoria->properties['cambios'][0]['despues']['bajas'])->toBe(2);
});

test('aplicar dos veces la misma carga no duplica periodos ni audita dos veces (idempotencia/concurrencia)', function () {
    $rh = rotxUsuario('super_admin');
    $this->actingAs($rh)->post(route('reportes.rotacion.analizar'), ['archivo' => rotxArchivoBase()]);
    $carga = RotacionCarga::query()->sole();

    $this->actingAs($rh)->post(route('reportes.rotacion.aplicar', $carga))->assertRedirect();
    expect($carga->refresh()->estado)->toBe(EstadoCargaRotacion::Aplicada);

    $periodosTras = RotacionPeriodo::query()->count();
    $auditoriasTras = Activity::query()->where('event', 'rotacion.carga_aplicada')->count();

    // Segunda confirmación por HTTP: la carga ya no está por confirmar → 403.
    $this->actingAs($rh)->post(route('reportes.rotacion.aplicar', $carga))->assertForbidden();

    // Defensa ante concurrencia a nivel de servicio: aplicar() recarga con
    // lockForUpdate() y REVALIDA el estado dentro de la transacción, de modo
    // que un segundo aplicar() sobre la carga ya Aplicada lanza la validación
    // y no vuelve a escribir periodos ni a auditar.
    expect(fn () => app(RotacionImportService::class)->aplicar($carga->refresh(), $rh))
        ->toThrow(ValidationException::class, 'Esta carga ya no está por confirmar.');

    expect(RotacionPeriodo::query()->count())->toBe($periodosTras)
        ->and(Activity::query()->where('event', 'rotacion.carga_aplicada')->count())->toBe($auditoriasTras);
});

test('descartar no carga nada y conserva el registro', function () {
    $rh = rotxUsuario('super_admin');
    $this->actingAs($rh)->post(route('reportes.rotacion.analizar'), ['archivo' => rotxArchivoBase()]);
    $carga = RotacionCarga::query()->sole();

    $this->actingAs($rh)->post(route('reportes.rotacion.descartar', $carga))->assertRedirect();

    expect($carga->refresh()->estado)->toBe(EstadoCargaRotacion::Descartada)
        ->and(RotacionPeriodo::query()->count())->toBe(0);
    Storage::disk('nas')->assertExists($carga->archivo_ruta);
});

test('el tablero calcula el índice mensual consolidado de los últimos 12 meses', function () {
    $rh = rotxUsuario('super_admin');
    $this->actingAs($rh)->post(route('reportes.rotacion.analizar'), ['archivo' => rotxArchivoBase()]);
    $this->actingAs($rh)->post(route('reportes.rotacion.aplicar', RotacionCarga::query()->sole()));

    $this->actingAs($rh)->get(route('reportes.rotacion.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reportes/RotacionPersonal')
            ->where('tablero.ventana.hasta', '2026-03')
            ->has('tablero.meses', 12)
            // Enero 2026: 3 bajas sobre plantilla promedio (17 + 15) ÷ 2 = 16 → 18.75 %.
            ->where('tablero.meses.10.clave', '2026-02')
            ->where('tablero.meses.9.clave', '2026-01')
            ->where('tablero.meses.9.bajas', 3)
            ->where('tablero.meses.9.plantilla_final', 15)
            ->where('tablero.meses.9.plantilla_promedio', fn ($v) => (float) $v === 16.0)
            ->where('tablero.meses.9.indice', fn ($v) => (float) $v === 18.75)
            ->where('tablero.totales.bajas', 4));
});

test('sin rotacion.cargar se consulta pero no se sube; sin rotacion.ver no se entra', function () {
    // Cargar el Excel es solo de super_admin (2026-10-20): RH (admin y
    // auxiliar) consulta el histórico, pero no sube, aplica ni descarta.
    $admin = rotxUsuario('super_admin');
    $this->actingAs($admin)->post(route('reportes.rotacion.analizar'), ['archivo' => rotxArchivoBase()]);
    $carga = RotacionCarga::query()->sole();

    foreach (['rh_admin', 'rh_auxiliar'] as $rol) {
        $rh = rotxUsuario($rol);
        $this->actingAs($rh)->get(route('reportes.rotacion.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('puedeCargar', false)->where('cargas', []));
        $this->actingAs($rh)->post(route('reportes.rotacion.analizar'), ['archivo' => rotxArchivoBase()])->assertForbidden();
        $this->actingAs($rh)->post(route('reportes.rotacion.aplicar', $carga))->assertForbidden();
        $this->actingAs($rh)->post(route('reportes.rotacion.descartar', $carga))->assertForbidden();
        $this->actingAs($rh)->get(route('reportes.rotacion.archivo', $carga))->assertForbidden();
    }

    $colaborador = rotxUsuario('colaborador');
    $this->actingAs($colaborador)->get(route('reportes.rotacion.index'))->assertForbidden();
    $this->actingAs($colaborador)->get(route('reportes.rotacion.formato'))->assertForbidden();
});

test('con alcance de sucursal solo ve la rotación de sus sucursales', function () {
    $rh = rotxUsuario('super_admin');
    $this->actingAs($rh)->post(route('reportes.rotacion.analizar'), ['archivo' => rotxArchivoBase()]);
    $this->actingAs($rh)->post(route('reportes.rotacion.aplicar', RotacionCarga::query()->sole()));

    Role::query()->create(['name' => 'consulta_rotacion_sucursal', 'guard_name' => 'web'])
        ->givePermissionTo(['rotacion.ver', 'rotacion.ver_sucursal']);
    $sanLuis = Sucursal::query()->where('nombre', 'San Luis Potosí')->value('id');
    $gerente = rotxUsuario('consulta_rotacion_sucursal', $sanLuis);

    $this->actingAs($gerente)->get(route('reportes.rotacion.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sucursales', 1)
            ->where('sucursales.0.id', $sanLuis)
            ->where('tablero.totales.bajas', 0)
            ->where('tablero.totales.altas', 1));
});

test('el formato actualizado se descarga prellenado y el lector lo vuelve a leer igual', function () {
    $rh = rotxUsuario('super_admin');
    $this->actingAs($rh)->post(route('reportes.rotacion.analizar'), ['archivo' => rotxArchivoBase()]);
    $this->actingAs($rh)->post(route('reportes.rotacion.aplicar', RotacionCarga::query()->sole()));

    $respuesta = $this->actingAs($rh)->get(route('reportes.rotacion.formato', ['anio' => 2026]));
    $respuesta->assertOk()->assertDownload('INDICE DE ROTACION DE PERSONAL 2026 - formato actualizado.xlsx');

    $lectura = app(RotacionExcelLector::class)->leer($respuesta->baseResponse->getFile()->getPathname());
    $bloques = collect($lectura['bloques'])->keyBy('sucursal');

    // Solo los bloques con meses capturados se leen; el resto de las
    // sucursales activas del catálogo viene vacío (informativo).
    expect($bloques->keys()->sort()->values()->all())->toBe(['ATLACOMULCO', 'SAN LUIS POTOSÍ']);
    expect(collect($lectura['hallazgos'])->where('sucursal', 'TULA')->pluck('mensaje')->all())
        ->toBe(['El bloque no tiene ningún mes capturado.']);
    $atlacomulco = $bloques->get('ATLACOMULCO');
    expect($atlacomulco['anio'])->toBe(2026)
        ->and(array_column($atlacomulco['meses'], 'mes'))->toBe([1, 2, 3])
        ->and($atlacomulco['meses'][0]['plantilla_inicial'])->toBe(9)
        ->and($atlacomulco['meses'][2]['plantilla_final'])->toBe(8)
        ->and($atlacomulco['meses'][0]['indice'])->toBe(50.0);

    // El formato propio no genera advertencias de estructura.
    $niveles = collect($lectura['hallazgos'])->whereIn('nivel', ['error', 'advertencia'])->pluck('mensaje')->all();
    expect($niveles)->toBe([]);
});

test('un archivo que no es Excel se rechaza con un mensaje claro', function () {
    $rh = rotxUsuario('super_admin');

    $this->actingAs($rh)
        ->post(route('reportes.rotacion.analizar'), ['archivo' => UploadedFile::fake()->create('rotacion.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('archivo');

    expect(RotacionCarga::query()->count())->toBe(0);
});

test('«TULA DE ALLENDE» del Excel real entra a «Tula» por el alias confirmado, sin advertencia de coincidencia aproximada', function () {
    $rh = rotxUsuario('super_admin');

    $this->actingAs($rh)
        ->post(route('reportes.rotacion.analizar'), ['archivo' => rotxExcel(function ($hoja) {
            rotxBloque($hoja, 9, 'TULA DE ALLENDE', 10, [1 => [0, 0], 2 => [1, 0]]);
        })])
        ->assertRedirect();

    $carga = RotacionCarga::query()->sole();
    $mensajes = collect($carga->detalle['hallazgos'])->pluck('mensaje')->implode("\n");

    expect($carga->periodos_detectados)->toBe(2)
        ->and($carga->resumen['sucursales'])->toBe(['Tula'])
        ->and($carga->resumen['sucursales_sin_catalogo'])->toBe([])
        ->and($mensajes)->not->toContain('coincidencia aproximada');
});
