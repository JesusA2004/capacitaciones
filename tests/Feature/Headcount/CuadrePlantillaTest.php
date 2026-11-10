<?php

use App\Enums\EstadoVacante;
use App\Enums\EstatusBaja;
use App\Enums\MotivoDiferenciaPlantilla;
use App\Models\Baja;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Headcount\CuadrePlantillaService;
use App\Services\Headcount\HeadcountService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Cuadre de plantilla (docs/HEADCOUNT_Y_VACANTES.md → «Cuadre de plantilla»):
|  - en cada sucursal y en el total: autorizadas = ocupadas + vacantes;
|  - vigentes + segundas plazas = ocupadas + excedentes + fuera + sin ubicación;
|  - Σ filas (Corporativo + sucursales + «Sin sucursal asignada») = total;
|  - en incorporación SÍ ocupa plaza; una baja registrada deja de contar
|    desde su último día aunque falte el finiquito; cancelada, vuelve a contar.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $empresa = Empresa::factory()->create();
    $this->corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'empresa_id' => $empresa->id, 'es_corporativo' => true, 'activo' => true]);
    $this->cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'empresa_id' => $empresa->id, 'es_corporativo' => false, 'activo' => true]);
    $this->tula = Sucursal::factory()->create(['nombre' => 'Tula', 'empresa_id' => $empresa->id, 'es_corporativo' => false, 'activo' => true]);

    $this->gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'activo' => true]);
    $this->subgerente = Puesto::factory()->create(['nombre' => 'Subgerente', 'puesto_superior_id' => $this->gerente->id, 'activo' => true]);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'puesto_superior_id' => $this->subgerente->id, 'activo' => true]);
    $this->contralor = Puesto::factory()->create(['nombre' => 'Gerente de Contraloría', 'activo' => true]);
    $this->auditora = Puesto::factory()->create(['nombre' => 'Auditora', 'puesto_superior_id' => $this->contralor->id, 'activo' => true]);

    HeadcountTarget::factory()->create(['sucursal_id' => $this->cuernavaca->id, 'puesto_id' => $this->gestor->id, 'plantilla_autorizada' => 5]);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->tula->id, 'puesto_id' => $this->gestor->id, 'plantilla_autorizada' => 2]);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->corporativo->id, 'puesto_id' => $this->contralor->id, 'plantilla_autorizada' => 1]);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->corporativo->id, 'puesto_id' => $this->auditora->id, 'plantilla_autorizada' => 2]);

    $this->persona = fn (Sucursal $s, Puesto $p, string $estatus = 'activo'): Colaborador => Colaborador::factory()->create(['sucursal_principal_id' => $s->id, 'puesto_id' => $p->id, 'estatus' => $estatus]);
    $this->rh = clUsuario('rh_admin');
    $this->cuadre = fn (): array => app(CuadrePlantillaService::class)->cuadre();
    $this->fila = fn (array $cuadre, Sucursal $s): array => collect($cuadre['filas'])->firstWhere('sucursal_id', $s->id);
    $this->baja = fn (Colaborador $c, string $fecha, EstatusBaja $estatus = EstatusBaja::EnRevisionRh): Baja => Baja::query()->create([
        'folio' => sprintf('BJ-T-%d', $c->id), 'colaborador_id' => $c->id, 'nombre_colaborador' => $c->nombreCompleto(),
        'sucursal_id' => $c->sucursal_principal_id, 'puesto_id' => $c->puesto_id, 'estatus' => $estatus, 'fecha_baja' => $fecha, 'creado_por' => $this->rh->id,
    ]);
});

function cuadreSinProblemas(array $cuadre): void
{
    expect($cuadre['problemas'])->toBe([]);

    foreach ([...$cuadre['filas'], $cuadre['total']] as $f) {
        expect($f['autorizadas'])->toBe($f['ocupadas'] + $f['vacantes'])
            ->and($f['vigentes'] + $f['segundas_plazas'])->toBe($f['ocupadas'] + $f['excedentes'] + $f['fuera_de_plantilla'] + $f['sin_ubicacion']);
    }
}

test('en incorporación ocupa plaza: Activa = laborando + en incorporación y autorizadas = ocupadas + vacantes', function () {
    ($this->persona)($this->cuernavaca, $this->gestor);
    ($this->persona)($this->cuernavaca, $this->gestor);
    ($this->persona)($this->cuernavaca, $this->gestor, 'en_incorporacion');

    $cuadre = ($this->cuadre)();
    $fila = ($this->fila)($cuadre, $this->cuernavaca);

    expect($fila['activos'])->toBe(2)
        ->and($fila['en_incorporacion'])->toBe(1)
        ->and($fila['vigentes'])->toBe(3)
        ->and($fila['ocupadas'])->toBe(3)
        ->and($fila['vacantes'])->toBe(2)
        ->and(collect($cuadre['diferencias'])->where('motivo', MotivoDiferenciaPlantilla::EnIncorporacion->value)->count())->toBe(1);

    cuadreSinProblemas($cuadre);
});

test('una baja con último día pasado y finiquito pendiente ya no cuenta, y su vacante se abre al registrarla', function () {
    $gestores = collect(range(1, 5))->map(fn () => ($this->persona)($this->cuernavaca, $this->gestor));
    app(VacanteAutoGenerationService::class)->sincronizarTodo();
    expect(Vacante::query()->where('sucursal_id', $this->cuernavaca->id)->whereIn('estado', EstadoVacante::valoresAbiertos())->exists())->toBeFalse();

    // Baja registrada (En trámite): el estatus laboral sigue «activo» hasta el finiquito.
    ($this->baja)($gestores[0], now()->subDays(3)->toDateString());

    $cuadre = ($this->cuadre)();
    $fila = ($this->fila)($cuadre, $this->cuernavaca);

    expect($gestores[0]->refresh()->estatus->value)->toBe('activo')
        ->and($fila['vigentes'])->toBe(4)
        ->and($fila['ocupadas'])->toBe(4)
        ->and($fila['vacantes'])->toBe(1)
        ->and($fila['bajas_en_tramite'])->toBe(1)
        ->and(app(HeadcountService::class)->vacantesDerivadas($this->cuernavaca->id, $this->gestor->id))->toBe(1)
        // BajaPlantillaObserver: la vacante automática se abrió al momento.
        ->and((int) Vacante::query()->where('sucursal_id', $this->cuernavaca->id)->where('generada_automaticamente', true)->whereIn('estado', EstadoVacante::valoresAbiertos())->sum('plazas_disponibles'))->toBe(1);

    $diferencia = collect($cuadre['diferencias'])->firstWhere('motivo', MotivoDiferenciaPlantilla::BajaEnTramite->value);
    expect($diferencia['personas'][0]['id'])->toBe($gestores[0]->id);
    cuadreSinProblemas($cuadre);
});

test('una baja con último día futuro sigue contando hasta ese día (baja programada)', function () {
    $gestor = ($this->persona)($this->tula, $this->gestor);
    ($this->baja)($gestor, now()->addDays(10)->toDateString());

    $fila = ($this->fila)(($this->cuadre)(), $this->tula);

    expect($fila['vigentes'])->toBe(1)
        ->and($fila['ocupadas'])->toBe(1)
        ->and($fila['bajas_programadas'])->toBe(1)
        ->and($fila['bajas_en_tramite'])->toBe(0);
});

test('una baja cancelada («No procede») vuelve a contar en activos y ocupadas', function () {
    $gestor = ($this->persona)($this->tula, $this->gestor);
    $baja = ($this->baja)($gestor, now()->subDay()->toDateString());
    expect(($this->fila)(($this->cuadre)(), $this->tula)['ocupadas'])->toBe(0);

    $baja->update(['estatus' => EstatusBaja::Cancelada, 'cancelado_en' => now(), 'motivo_cancelacion' => 'No procede']);

    $fila = ($this->fila)(($this->cuadre)(), $this->tula);
    expect($fila['activos'])->toBe(1)
        ->and($fila['ocupadas'])->toBe(1)
        ->and($fila['vacantes'])->toBe(1)
        ->and($fila['bajas_en_tramite'])->toBe(0)
        ->and(app(HeadcountService::class)->vacantesDerivadas($this->tula->id, $this->gestor->id))->toBe(1);
});

test('fuera de plantilla, excedente y sin sucursal se muestran aparte con nombre, y la fila «Sin sucursal asignada» cierra la suma', function () {
    // La cuenta de RH del beforeEach (clUsuario) es una persona sin sucursal.
    $base = ($this->cuadre)()['total'];
    $auxiliar = Puesto::factory()->create(['nombre' => 'Auxiliar sin plantilla', 'puesto_superior_id' => $this->subgerente->id, 'activo' => true]);
    $fuera = ($this->persona)($this->cuernavaca, $auxiliar);
    ($this->persona)($this->tula, $this->gestor);
    ($this->persona)($this->tula, $this->gestor);
    ($this->persona)($this->tula, $this->gestor); // 3 para 2 plazas: excedente
    $sinSucursal = Colaborador::factory()->create(['sucursal_principal_id' => null, 'puesto_id' => $this->gestor->id, 'estatus' => 'activo']);

    $cuadre = ($this->cuadre)();
    $sin = collect($cuadre['filas'])->firstWhere('sucursal_id', null);

    expect(($this->fila)($cuadre, $this->cuernavaca)['fuera_de_plantilla'])->toBe(1)
        ->and(($this->fila)($cuadre, $this->tula)['excedentes'])->toBe(1)
        ->and($sin)->not->toBeNull()
        ->and($sin['sucursal'])->toBe('Sin sucursal asignada')
        ->and($sin['sin_ubicacion'])->toBe($base['sin_ubicacion'] + 1)
        ->and($cuadre['total']['fuera_de_plantilla'] + $cuadre['total']['excedentes'] + $cuadre['total']['sin_ubicacion'])->toBe($base['fuera_de_plantilla'] + $base['excedentes'] + $base['sin_ubicacion'] + 3);

    $porMotivo = collect($cuadre['diferencias'])->groupBy('motivo');
    expect($porMotivo[MotivoDiferenciaPlantilla::FueraDePlantilla->value][0]['personas'][0]['id'])->toBe($fuera->id)
        ->and($porMotivo[MotivoDiferenciaPlantilla::Excedente->value][0]['cantidad'])->toBe(1)
        ->and(collect($porMotivo[MotivoDiferenciaPlantilla::Excedente->value][0]['personas'])->count())->toBe(3)
        ->and(collect($porMotivo[MotivoDiferenciaPlantilla::SinUbicacion->value])->pluck('personas.0.id')->all())->toContain($sinSucursal->id);

    cuadreSinProblemas($cuadre);
});

test('doble puesto («Ocupa ambos»): una persona, dos plazas, y el cuadre lo explica como segunda plaza', function () {
    $contralor = ($this->persona)($this->corporativo, $this->contralor);
    CoberturaPuesto::factory()->create(['colaborador_id' => $contralor->id, 'puesto_id' => $this->auditora->id, 'sucursal_id' => $this->corporativo->id, 'activa' => true, 'ocupa_plaza' => true]);

    $cuadre = ($this->cuadre)();
    $corp = ($this->fila)($cuadre, $this->corporativo);

    expect($corp['vigentes'])->toBe(1)
        ->and($corp['segundas_plazas'])->toBe(1)
        ->and($corp['ocupadas'])->toBe(2)
        ->and($corp['vacantes'])->toBe(1)
        ->and(collect($cuadre['diferencias'])->firstWhere('motivo', MotivoDiferenciaPlantilla::SegundaPlaza->value)['personas'][0]['id'])->toBe($contralor->id);

    cuadreSinProblemas($cuadre);
});

test('dashboard: la suma de las filas de «Plantilla por sucursal» es exactamente cada cifra de las tarjetas, y el detalle de sucursal da lo mismo', function () {
    ($this->persona)($this->cuernavaca, $this->gestor);
    ($this->persona)($this->cuernavaca, $this->gestor, 'en_incorporacion');
    ($this->persona)($this->tula, $this->gestor);
    ($this->persona)($this->tula, $this->gestor);
    ($this->persona)($this->tula, $this->gestor);
    ($this->persona)($this->corporativo, $this->contralor);
    $baja = ($this->persona)($this->cuernavaca, $this->gestor);
    ($this->baja)($baja, now()->subDay()->toDateString());
    Colaborador::factory()->create(['sucursal_principal_id' => null, 'puesto_id' => $this->gestor->id, 'estatus' => 'activo']);

    $tablero = $this->actingAs($this->rh)->get(route('dashboard'))->assertOk()->viewData('page')['props']['tablero'];
    $total = $tablero['summary']['plantilla_activa']['poblacion']['total'];
    $filas = collect($tablero['headcount_by_branch']);

    foreach (['autorizadas', 'ocupadas', 'vacantes', 'activos', 'en_incorporacion', 'vigentes', 'fuera_de_plantilla', 'excedentes', 'sin_ubicacion', 'segundas_plazas', 'bajas_en_tramite'] as $campo) {
        expect((int) $filas->sum($campo))->toBe($total[$campo], sprintf('Σ filas de «%s»', $campo));
    }

    expect($tablero['cuadre']['problemas'])->toBe([])
        ->and($total['autorizadas'])->toBe($total['ocupadas'] + $total['vacantes'])
        ->and($tablero['summary']['plantilla_activa']['valor'])->toBe($total['ocupadas'])
        ->and($tablero['en_incorporacion'])->toBe($total['en_incorporacion'])
        ->and($total['bajas_en_tramite'])->toBe(1)
        ->and($filas->firstWhere('sucursal_id', null)['sucursal'])->toBe('Sin sucursal asignada');

    // La pantalla de la sucursal da exactamente la misma fila.
    $fila = $filas->firstWhere('sucursal_id', $this->cuernavaca->id);
    $show = $this->actingAs($this->rh)->get(route('administracion.sucursales.show', $this->cuernavaca))->assertOk()->viewData('page')['props']['totales'];

    expect($show['plantilla_autorizada'])->toBe($fila['autorizadas'])
        ->and($show['plantilla_actual'])->toBe($fila['ocupadas'])
        ->and($show['vacantes'])->toBe($fila['vacantes'])
        ->and($show['activos'])->toBe($fila['activos'])
        ->and($show['en_incorporacion'])->toBe($fila['en_incorporacion'])
        ->and($show['bajas_en_tramite'])->toBe($fila['bajas_en_tramite']);
});

test('el comando de diagnóstico es de solo lectura e imprime totales y que todo cuadra', function () {
    ($this->persona)($this->cuernavaca, $this->gestor);
    $antes = [Vacante::query()->count(), Colaborador::query()->count(), Baja::query()->count()];

    $this->artisan('headcount:diagnosticar-cuadre')
        ->expectsOutputToContain('TODO CUADRA')
        ->assertSuccessful();

    $this->artisan('headcount:diagnosticar-cuadre', ['--sucursal' => 'Cuernavaca'])->assertSuccessful();

    expect([Vacante::query()->count(), Colaborador::query()->count(), Baja::query()->count()])->toBe($antes);
});
