<?php

use App\Models\CampanaReclutamiento;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\Empresa;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\RotacionCarga;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\Headcount\HeadcountService;
use App\Services\Reclutamiento\CostoReclutamientoService;
use App\Services\Reportes\TableroRhService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;

/**
 * El tablero de RH CONSUME la fuente canónica de cada módulo (MÓDULO →
 * SERVICE CANÓNICO → DASHBOARD) en vez de recalcular fórmulas propias:
 *  - rotación: TableroRotacionService, el mismo motor de la pestaña Rotación;
 *  - plantilla/cobertura: HeadcountService;
 *  - costo/inversión: CostoReclutamientoService;
 *  - contratos por vencer: ContratoLaboralService::porVencer.
 * Y respeta el alcance (AlcanceOrganizacional/AlcanceModulo).
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->rhGlobal = function (): User {
        $rh = User::factory()->create();
        $rh->assignRole('rh_admin');

        return $rh;
    };

    // Un mes de rotación cargado (como lo dejaría el Excel de RH). El índice
    // que usa el tablero es bajas ÷ plantilla promedio × 100, igual que Bajas.
    $this->rotacionPeriodo = function (Sucursal $sucursal, int $anio, int $mes, int $bajas, int $plantillaFinal) use (&$carga): RotacionPeriodo {
        $carga ??= RotacionCarga::query()->create([
            'nombre_archivo' => 'rotacion.xlsx',
            'archivo_ruta' => 'rotacion/rotacion.xlsx',
            'archivo_hash' => str_repeat('a', 64),
            'subido_por' => User::factory()->create()->id,
        ]);

        return RotacionPeriodo::query()->create([
            'sucursal_id' => $sucursal->id,
            'anio' => $anio,
            'mes' => $mes,
            'sucursal_excel' => $sucursal->nombre,
            // Inicio igual al cierre: la plantilla promedio es la del argumento.
            'plantilla_inicial' => $plantillaFinal,
            'altas' => 0,
            'bajas' => $bajas,
            'plantilla_final' => $plantillaFinal,
            'indice' => $plantillaFinal > 0 ? round($bajas * 100 / $plantillaFinal, 2) : null,
            'carga_id' => $carga->id,
        ]);
    };
});

test('rotación del mes y la tendencia salen del periodo canónico cargado', function () {
    $rh = ($this->rhGlobal)();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    // 10 bajas sobre plantilla promedio 80 → 12.50 %.
    ($this->rotacionPeriodo)($sucursal, 2026, 3, 10, 80);

    $dto = app(TableroRhService::class)->construir($rh, ['mes' => '2026-03', 'sucursal_id' => $sucursal->id, 'meses' => 12]);

    $punto = collect($dto['turnover_monthly'])->firstWhere('mes', '2026-03');

    expect($dto['summary']['rotacion_mes']['porcentaje'])->toBe(12.5)
        ->and($dto['summary']['rotacion_mes']['bajas'])->toBe(10)
        ->and($dto['summary']['rotacion_mes']['plantilla_promedio'])->toBe(80.0)
        ->and($punto['indice'])->toBe(12.5);
});

test('sin periodo cargado la rotación del mes es null (no 0 ni la fórmula vieja)', function () {
    $rh = ($this->rhGlobal)();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    // Hay periodo en marzo, pero el mes seleccionado (abril) no tiene datos.
    ($this->rotacionPeriodo)($sucursal, 2026, 3, 10, 80);

    // Una baja real en abril: si el tablero usara la fórmula vieja
    // (fecha_baja) devolvería un porcentaje; debe devolver null.
    Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'fecha_ingreso' => '2026-01-01',
        'fecha_baja' => '2026-04-10',
        'estatus' => 'inactivo',
    ]);

    $dto = app(TableroRhService::class)->construir($rh, ['mes' => '2026-04', 'sucursal_id' => $sucursal->id, 'meses' => 12]);

    $puntoAbril = collect($dto['turnover_monthly'])->firstWhere('mes', '2026-04');

    expect($dto['summary']['rotacion_mes']['porcentaje'])->toBeNull()
        ->and($puntoAbril['indice'])->toBeNull();
});

test('el filtro por sucursal acota la rotación y el filtro por empresa agrega sus sucursales', function () {
    $rh = ($this->rhGlobal)();
    $empresa = Empresa::factory()->create();
    $sucA = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $sucB = Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    ($this->rotacionPeriodo)($sucA, 2026, 3, 10, 80);   // 12.5 %
    ($this->rotacionPeriodo)($sucB, 2026, 3, 20, 100);  // 20.0 %

    $soloA = app(TableroRhService::class)->construir($rh, ['mes' => '2026-03', 'sucursal_id' => $sucA->id]);
    expect($soloA['summary']['rotacion_mes']['porcentaje'])->toBe(12.5);

    // Empresa = agrega ambas sucursales: (10 + 20) × 100 / (80 + 100) = 16.67 %.
    $empresaDto = app(TableroRhService::class)->construir($rh, ['mes' => '2026-03', 'empresa_id' => $empresa->id]);
    expect($empresaDto['summary']['rotacion_mes']['porcentaje'])->toBe(16.67);
});

test('plantilla activa y cobertura coinciden con HeadcountService', function () {
    $rh = ($this->rhGlobal)();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $puesto = Puesto::factory()->create();

    HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'plantilla_autorizada' => 5]);
    Colaborador::factory()->count(3)->create(['sucursal_principal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'estatus' => 'activo']);

    $dto = app(TableroRhService::class)->construir($rh, ['sucursal_id' => $sucursal->id]);
    $canon = app(HeadcountService::class)->totalesGenerales(collect([$sucursal->id]));

    expect($dto['summary']['plantilla_activa']['valor'])->toBe($canon['plantilla_actual'])
        ->and($dto['summary']['plantilla_activa']['autorizada'])->toBe($canon['plantilla_autorizada'])
        ->and($dto['summary']['plantilla_activa']['porcentaje'])->toBe($canon['cumplimiento'])
        // ocupada = min(3, 5) = 3 de 5 autorizadas → 60 %.
        ->and($dto['summary']['plantilla_activa']['valor'])->toBe(3)
        ->and($dto['summary']['plantilla_activa']['autorizada'])->toBe(5)
        ->and($dto['summary']['plantilla_activa']['porcentaje'])->toBe(60.0);
});

test('la inversión en campañas coincide con CostoReclutamientoService', function () {
    $rh = ($this->rhGlobal)();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    CampanaReclutamiento::query()->create([
        'mes' => 3, 'anio' => 2026, 'canal' => 'meta', 'tipo_costo' => 'publicidad',
        'empresa_id' => $empresa->id, 'sucursal_id' => $sucursal->id, 'monto' => 5000,
    ]);

    $dto = app(TableroRhService::class)->construir($rh, ['mes' => '2026-03', 'sucursal_id' => $sucursal->id]);

    $inicio = CarbonImmutable::parse('2026-03-01')->startOfMonth();
    $fin = $inicio->endOfMonth();
    $canon = app(CostoReclutamientoService::class)->resumen($inicio, $fin, ['sucursal_ids' => [$sucursal->id]]);

    expect($dto['summary']['inversion_campanas_mes']['valor'])->toBe($canon['gasto_total'])
        ->and($dto['summary']['inversion_campanas_mes']['valor'])->toBe(5000.0)
        ->and($dto['summary']['inversion_campanas_mes']['campanas'])->toBe(1)
        // Sin contratados atribuidos no se inventa un costo por contratación.
        ->and($dto['summary']['costo_por_contratacion']['valor'])->toBe($canon['costo_por_contratacion'])
        ->and($dto['summary']['costo_por_contratacion']['valor'])->toBeNull();
});

test('contratos por vencer coincide con el servicio canónico y su ventana configurable', function () {
    $rh = ($this->rhGlobal)();
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id]);

    ContratoLaboral::factory()->create([
        'colaborador_id' => $colaborador->id,
        'estado' => 'vigente',
        'fecha_fin' => now()->addDays(3)->toDateString(),
    ]);

    $dias = (int) config('contratos.dias_aviso_vencimiento', 15);
    $dto = app(TableroRhService::class)->construir($rh, ['sucursal_id' => $sucursal->id]);

    $canon = app(ContratoLaboralService::class)->porVencer($rh, $dias)
        ->filter(fn (ContratoLaboral $c): bool => $c->colaborador->sucursal_principal_id === $sucursal->id)
        ->count();

    expect($dto['summary']['contratos_por_vencer']['valor'])->toBe($canon)
        ->and($dto['summary']['contratos_por_vencer']['valor'])->toBe(1)
        ->and($dto['summary']['contratos_por_vencer']['dias'])->toBe($dias);
});

test('un gerente solo ve la rotación de su propia sucursal (respeta el alcance)', function () {
    $empresa = Empresa::factory()->create();
    $propia = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $ajena = Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    $gerente = User::factory()->create(['sucursal_principal_id' => $propia->id]);
    $gerente->assignRole('gerente_sucursal');

    ($this->rotacionPeriodo)($propia, 2026, 3, 10, 80);   // 12.5 %
    ($this->rotacionPeriodo)($ajena, 2026, 3, 50, 100);   // 50.0 %, fuera de alcance

    $dto = app(TableroRhService::class)->construir($gerente, ['mes' => '2026-03']);

    // Solo la rotación de su sucursal, nunca la de la ajena.
    expect($dto['summary']['rotacion_mes']['porcentaje'])->toBe(12.5)
        ->and(collect($dto['filters']['sucursales'])->pluck('id')->all())->not->toContain($ajena->id);
});
