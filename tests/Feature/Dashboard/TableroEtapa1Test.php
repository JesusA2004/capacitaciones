<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\OrigenBaja;
use App\Models\Candidato;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Bajas\BajaRegistroService;
use App\Services\Reportes\TableroRhService;
use Carbon\CarbonImmutable;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;

/*
| Etapa 1 · Tablero de RH (PDF págs. 3-4, encargo 3.2-3.4): cuatro bloques con
| fuentes únicas, conciliación de plantilla, cobertura sin sobrecontar, mes sin
| datos y mes en curso, y filtros coherentes.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    CarbonImmutable::setTestNow('2026-10-15 12:00:00');
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    config(['bajas.tablero.mes_inicio_sistema' => '2026-09']);

    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');
    // La cuenta de prueba no debe sumar a la plantilla que se concilia.
    $this->rh->colaborador?->update(['estatus' => EstadoUsuario::Inactivo]);
    $this->corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'es_corporativo' => true]);
    $this->sucursal = Sucursal::factory()->create(['nombre' => 'Tula']);
    $this->puesto = Puesto::factory()->create();

    $this->plazas = function (Sucursal $sucursal, int $autorizadas): void {
        HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $this->puesto->id, 'plantilla_autorizada' => $autorizadas]);
    };
    $this->activos = function (Sucursal $sucursal, int $cuantos, string $ingreso = '2025-01-01'): void {
        Colaborador::factory()->count($cuantos)->create([
            'sucursal_principal_id' => $sucursal->id,
            'puesto_id' => $this->puesto->id,
            'estatus' => EstadoUsuario::Activo,
            'fecha_ingreso' => $ingreso,
        ]);
    };
    $this->baja = function (Sucursal $sucursal, string $fecha, EstatusBaja $estatus): void {
        $persona = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'fecha_ingreso' => '2025-01-01', 'estatus' => EstadoUsuario::Inactivo, 'fecha_baja' => $fecha]);
        $causal = CausalRotacion::query()->where('clave', 'RV-01')->firstOrFail();
        app(BajaRegistroService::class)->nueva($persona, [
            'forma_salida' => $causal->forma(),
            'causal_rotacion_id' => $causal->id,
            'fecha_baja' => $fecha,
            'estatus' => $estatus,
            'es_rotacion' => true,
            'origen' => OrigenBaja::Sistema,
        ], null)->save();
    };
    $this->dto = fn (array $filtros = []): array => app(TableroRhService::class)->construir($this->rh, $filtros);
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

test('autorizadas, activas y vacantes concilian por Corporativo y Sucursales', function () {
    ($this->plazas)($this->corporativo, 4);
    ($this->plazas)($this->sucursal, 10);
    ($this->activos)($this->corporativo, 3);
    ($this->activos)($this->sucursal, 8);

    $poblacion = ($this->dto)()['summary']['plantilla_activa']['poblacion'];

    expect($poblacion['total']['autorizadas'])->toBe(14)
        ->and($poblacion['corporativo']['activos'] + $poblacion['sucursales']['activos'])->toBe(11)
        ->and($poblacion['total']['vacantes'])->toBe(3)
        ->and($poblacion['corporativo']['vacantes'])->toBe(1)
        ->and($poblacion['sucursales']['vacantes'])->toBe(2)
        ->and($poblacion['total']['cobertura'])->toBe(78.6);
});

test('los excedentes no sobrecuentan la cobertura ni restan vacantes', function () {
    ($this->plazas)($this->sucursal, 2);
    ($this->activos)($this->sucursal, 5);

    $poblacion = ($this->dto)()['summary']['plantilla_activa']['poblacion'];

    expect($poblacion['total']['cobertura'])->toBe(100.0)
        ->and($poblacion['total']['vacantes'])->toBe(0)
        ->and($poblacion['sucursales']['activos'])->toBe(5);
});

test('una sucursal sin plazas autorizadas no divide entre cero y se marca al final Corporativo', function () {
    ($this->activos)($this->sucursal, 2);
    ($this->plazas)($this->corporativo, 1);

    $dto = ($this->dto)();
    $filas = collect($dto['headcount_by_branch']);

    expect($filas->firstWhere('sucursal_id', $this->sucursal->id)['cumplimiento'])->toEqual(0)
        ->and($filas->firstWhere('sucursal_id', $this->corporativo->id)['es_corporativo'])->toBeTrue()
        ->and($filas->firstWhere('sucursal_id', $this->sucursal->id)['es_corporativo'])->toBeFalse();
});

test('un mes sin datos responde null y cero, sin errores', function () {
    $dto = ($this->dto)(['mes' => '2020-02']);

    expect($dto['summary']['rotacion_mes']['porcentaje'])->toBeNull()
        ->and($dto['summary']['rotacion_acumulada']['porcentaje'])->toBeNull()
        ->and($dto['summary']['altas_mes']['valor'])->toBe(0)
        ->and($dto['summary']['costo_por_contratacion']['valor'])->toBeNull();
});

test('altas por fecha de ingreso, bajas por último día aunque sigan en trámite, y acumulada del año', function () {
    ($this->plazas)($this->sucursal, 10);
    ($this->activos)($this->sucursal, 9);
    ($this->activos)($this->sucursal, 2, '2026-10-05');
    ($this->activos)($this->sucursal, 1, '2026-09-05');
    ($this->baja)($this->sucursal, '2026-10-08', EstatusBaja::EnRevisionRh);
    ($this->baja)($this->sucursal, '2026-09-20', EstatusBaja::Cerrada);

    $dto = ($this->dto)(['mes' => '2026-10']);

    expect($dto['summary']['altas_mes']['valor'])->toBe(2)
        ->and($dto['summary']['rotacion_mes']['bajas'])->toBe(1)
        ->and($dto['summary']['rotacion_acumulada']['anio'])->toBe(2026)
        ->and($dto['summary']['rotacion_acumulada']['bajas'])->toBe(2)
        ->and(collect($dto['turnover_monthly'])->last()['en_curso'])->toBeTrue();
});

test('la tendencia respeta 6, 12 y 24 meses', function () {
    foreach ([6, 12, 24] as $meses) {
        expect(($this->dto)(['meses' => $meses])['turnover_monthly'])->toHaveCount($meses);
    }
});

test('el periodo actualiza el embudo y el tiempo por nivel', function () {
    $reciente = Candidato::factory()->create(['sucursal_id' => $this->sucursal->id]);
    $antiguo = Candidato::factory()->create(['sucursal_id' => $this->sucursal->id]);
    $antiguo->forceFill(['created_at' => '2026-01-10 10:00:00'])->save();
    $reciente->forceFill(['created_at' => '2026-10-02 10:00:00'])->save();

    expect(($this->dto)(['meses' => 6])['recruitment_funnel'][0]['total'])->toBe(1)
        ->and(($this->dto)(['meses' => 12])['recruitment_funnel'][0]['total'])->toBe(2);
});

test('el embudo cuenta a quienes alcanzaron cada fase, no a quienes están en ella', function () {
    Candidato::factory()->create(['sucursal_id' => $this->sucursal->id, 'estado' => EstadoCandidato::Recibidos, 'etapa_maxima' => EstadoCandidato::Recibidos->orden()]);
    Candidato::factory()->create(['sucursal_id' => $this->sucursal->id, 'estado' => EstadoCandidato::Contratado, 'etapa_maxima' => EstadoCandidato::Contratado->orden(), 'contratado_en' => now()]);

    $embudo = collect(($this->dto)()['recruitment_funnel'])->keyBy('clave');

    expect($embudo['interesados']['total'])->toBe(2)
        ->and($embudo['referencias']['total'])->toBe(1)
        ->and($embudo['contratados']['total'])->toBe(1);
});

test('sin inversión capturada no se presenta costo por contratación', function () {
    Candidato::factory()->create(['sucursal_id' => $this->sucursal->id, 'estado' => EstadoCandidato::Contratado, 'contratado_en' => now()]);

    expect(($this->dto)()['summary']['costo_por_contratacion']['valor'])->toBeNull();
});

test('el filtro de sucursal acota todos los bloques', function () {
    $otra = Sucursal::factory()->create();
    ($this->plazas)($this->sucursal, 5);
    ($this->plazas)($otra, 7);
    ($this->activos)($this->sucursal, 5, '2026-10-02');
    ($this->activos)($otra, 7, '2026-10-02');
    ($this->baja)($otra, '2026-10-03', EstatusBaja::Cerrada);
    Candidato::factory()->create(['sucursal_id' => $otra->id]);

    $dto = ($this->dto)(['sucursal_id' => $this->sucursal->id]);

    expect($dto['summary']['plantilla_activa']['poblacion']['total']['autorizadas'])->toBe(5)
        ->and($dto['summary']['altas_mes']['valor'])->toBe(5)
        ->and($dto['summary']['rotacion_mes']['bajas'])->toBe(0)
        ->and($dto['summary']['reclutamiento']['en_proceso'])->toBe(0)
        ->and($dto['headcount_by_branch'])->toHaveCount(1);
});

test('un cambio de puesto no duplica a la persona en la plantilla', function () {
    ($this->plazas)($this->sucursal, 3);
    ($this->activos)($this->sucursal, 2);

    Colaborador::query()->first()?->update(['puesto_id' => Puesto::factory()->create()->id]);

    expect(($this->dto)()['summary']['plantilla_activa']['poblacion']['sucursales']['activos'])->toBe(2);
});

test('un gerente no puede filtrar fuera de su alcance', function () {
    $gerente = User::factory()->create(['sucursal_principal_id' => $this->sucursal->id]);
    $gerente->assignRole('gerente_sucursal');
    $ajena = Sucursal::factory()->create();
    ($this->plazas)($ajena, 9);

    $dto = app(TableroRhService::class)->construir($gerente, ['sucursal_id' => $ajena->id]);

    expect($dto['filters']['sucursal_id'])->toBeNull()
        ->and($dto['summary']['plantilla_activa']['poblacion']['total']['autorizadas'])->toBe(0);
});
