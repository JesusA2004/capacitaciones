<?php

use App\Enums\EstadoVacante;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\HeadcountTarget;
use App\Models\HeadcountTargetHistorial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Headcount\HeadcountService;
use App\Services\Headcount\PlazasEmpresaService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use App\Services\Vacantes\VacantesListadoService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| docs/HEADCOUNT_Y_VACANTES.md:
|  A. RH autoriza N plazas de CUALQUIER puesto, también corporativo.
|  B. Gestor volante con plazas propias (vacantes «Gestor volante»), sin
|     romper lo ocupado: un volante sin plaza de volante ocupa una de Gestor.
|  C. Una persona en dos puestos: «Ocupa ambos» cierra la vacante; «Cubre
|     mientras se contrata» la deja abierta.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $empresa = Empresa::factory()->create();
    $this->corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'empresa_id' => $empresa->id, 'es_corporativo' => true, 'activo' => true]);
    $this->orizaba = Sucursal::factory()->create(['nombre' => 'Orizaba', 'empresa_id' => $empresa->id, 'es_corporativo' => false, 'activo' => true]);

    $this->contraloria = Departamento::factory()->create(['nombre' => 'Contraloría']);
    $this->gerenteContraloria = Puesto::factory()->create(['nombre' => 'Gerente de Contraloría', 'activo' => true]);
    $this->auditora = Puesto::factory()->create(['nombre' => 'Auditora', 'puesto_superior_id' => $this->gerenteContraloria->id, 'departamento_id' => $this->contraloria->id, 'activo' => true]);

    $this->gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'activo' => true]);
    $this->subgerente = Puesto::factory()->create(['nombre' => 'Subgerente', 'puesto_superior_id' => $this->gerente->id, 'activo' => true]);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'puesto_superior_id' => $this->subgerente->id, 'activo' => true]);
    $this->volante = Puesto::factory()->create(['nombre' => 'Gestor Volante', 'puesto_superior_id' => $this->subgerente->id, 'activo' => true]);

    $this->rh = clUsuario('rh_admin');

    $this->automatica = fn (Sucursal $sucursal, Puesto $puesto): ?Vacante => Vacante::query()
        ->where('sucursal_id', $sucursal->id)
        ->where('puesto_id', $puesto->id)
        ->where('generada_automaticamente', true)
        ->whereIn('estado', EstadoVacante::valoresAbiertos())
        ->first();
});

test('A: RH autoriza 4 plazas de Auditora en Corporativo desde el puesto y se abren 3 vacantes que la plaza automática no pisa', function () {
    // Una auditora registrada en una sucursal: su plaza vive en Corporativo.
    Colaborador::factory()->create(['puesto_id' => $this->auditora->id, 'sucursal_principal_id' => $this->orizaba->id, 'estatus' => 'activo']);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->corporativo->id, 'puesto_id' => $this->auditora->id, 'plantilla_autorizada' => 1]);

    $this->actingAs($this->rh)
        ->put(route('administracion.puestos.plantilla.update', $this->auditora), [
            'sucursal_id' => $this->corporativo->id,
            'plantilla_autorizada' => 4,
            'motivo' => 'Dirección autorizó 4 auditoras',
        ])
        ->assertSessionHasNoErrors();

    $vacante = ($this->automatica)($this->corporativo, $this->auditora);
    expect($vacante)->not->toBeNull()
        ->and($vacante->plazas_disponibles)->toBe(3)
        ->and(HeadcountTargetHistorial::query()->where('puesto_id', $this->auditora->id)->latest('id')->value('valor_nuevo'))->toBe(4);

    // La plaza automática de empresa nunca sobrescribe lo autorizado a mano.
    app(PlazasEmpresaService::class)->asegurar();
    expect(HeadcountTarget::query()->where('puesto_id', $this->auditora->id)->sum('plantilla_autorizada'))->toEqual(4);

    // El listado de Vacantes la muestra: Corporativo + área del puesto.
    $listado = app(VacantesListadoService::class);
    $filas = collect($listado->filas($listado->consulta($this->rh)->get()));
    $fila = $filas->firstWhere('puesto_id', $this->auditora->id);
    expect($fila)->not->toBeNull()
        ->and($fila['plazas_disponibles'])->toBe(3)
        ->and($fila['es_corporativo'])->toBeTrue()
        ->and($fila['departamento'])->toBe('Contraloría');

    // Filtro por área: encuentra la vacante aunque la vacante no traiga departamento.
    $porArea = $listado->consulta($this->rh, ['departamento_id' => $this->contraloria->id])->pluck('id');
    expect($porArea->all())->toBe([$vacante->id]);

    // La ficha del puesto lista la ubicación con sus cifras.
    $this->actingAs($this->rh)
        ->get(route('administracion.puestos.show', $this->auditora))
        ->assertInertia(fn ($page) => $page
            ->where('plantilla.totales.autorizadas', 4)
            ->where('plantilla.totales.vacantes', 3)
            ->where('plantilla.ubicaciones.0.es_corporativo', true)
            ->where('puede.editar_plantilla', true));
});

test('A: sin headcount.editar no se autorizan plazas desde el puesto', function () {
    $this->actingAs(clUsuario('administrador_capacitacion'))
        ->put(route('administracion.puestos.plantilla.update', $this->auditora), [
            'sucursal_id' => $this->corporativo->id,
            'plantilla_autorizada' => 4,
            'motivo' => 'Intento sin permiso',
        ])
        ->assertForbidden();

    expect(HeadcountTarget::query()->where('puesto_id', $this->auditora->id)->exists())->toBeFalse();
});

test('B: «¿Esta sucursal tendrá volantes?» abre vacantes «Gestor volante» separadas de las de «Gestor fijo»', function () {
    HeadcountTarget::factory()->create(['sucursal_id' => $this->orizaba->id, 'puesto_id' => $this->gestor->id, 'plantilla_autorizada' => 3]);
    Colaborador::factory()->count(2)->create(['puesto_id' => $this->gestor->id, 'sucursal_principal_id' => $this->orizaba->id, 'estatus' => 'activo']);

    $this->actingAs($this->rh)
        ->put(route('administracion.sucursales.volantes.update', $this->orizaba), [
            'tendra_volantes' => true,
            'cantidad' => 1,
            'motivo' => 'Dirección autorizó 1 volante',
        ])
        ->assertSessionHasNoErrors();

    expect(HeadcountTarget::query()->where('sucursal_id', $this->orizaba->id)->where('puesto_id', $this->volante->id)->value('plantilla_autorizada'))->toBe(1)
        ->and(($this->automatica)($this->orizaba, $this->volante)?->plazas_disponibles)->toBe(1)
        ->and(($this->automatica)($this->orizaba, $this->gestor)?->plazas_disponibles)->toBe(1);

    $listado = app(VacantesListadoService::class);
    $filas = collect($listado->filas($listado->consulta($this->rh)->get()));
    $resumen = collect($listado->resumen($filas->all())['por_puesto'])->pluck('puesto')->all();

    expect($filas->pluck('puesto')->sort()->values()->all())->toBe(['Gestor fijo', 'Gestor volante'])
        ->and($filas->firstWhere('puesto', 'Gestor fijo')['puesto_nota'])->toContain('Matriz comercial')
        ->and($resumen)->toContain('Gestor fijo')->toContain('Gestor volante');

    // «No»: las plazas de volante quedan en 0 y su vacante se cierra.
    $this->actingAs($this->rh)
        ->put(route('administracion.sucursales.volantes.update', $this->orizaba), [
            'tendra_volantes' => false,
            'motivo' => 'Ya no tendrá volantes',
        ])
        ->assertSessionHasNoErrors();

    expect(HeadcountTarget::query()->where('sucursal_id', $this->orizaba->id)->where('puesto_id', $this->volante->id)->value('plantilla_autorizada'))->toBe(0)
        ->and(($this->automatica)($this->orizaba, $this->volante))->toBeNull();
});

test('B: compatibilidad — un volante sin plaza de volante ocupa una de Gestor y nada ocupado pasa a vacante', function () {
    // Como antes de separar: 3 de Gestor + 0 de volante, 2 gestores + 1 volante.
    HeadcountTarget::factory()->create(['sucursal_id' => $this->orizaba->id, 'puesto_id' => $this->gestor->id, 'plantilla_autorizada' => 3]);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->orizaba->id, 'puesto_id' => $this->volante->id, 'plantilla_autorizada' => 0]);
    Colaborador::factory()->count(2)->create(['puesto_id' => $this->gestor->id, 'sucursal_principal_id' => $this->orizaba->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['puesto_id' => $this->volante->id, 'sucursal_principal_id' => $this->orizaba->id, 'estatus' => 'activo']);

    $headcount = app(HeadcountService::class);
    app(VacanteAutoGenerationService::class)->sincronizarTodo();

    expect($headcount->plantillaDePar($this->orizaba->id, $this->gestor->id))->toBe(['autorizada' => 3, 'ocupada' => 3, 'faltantes' => 0])
        ->and($headcount->poblacion(collect([$this->orizaba->id]))['total'])->toMatchArray(['autorizadas' => 3, 'ocupadas' => 3, 'vacantes' => 0, 'fuera_de_plantilla' => 0])
        ->and(Vacante::query()->where('sucursal_id', $this->orizaba->id)->whereIn('estado', EstadoVacante::valoresAbiertos())->count())->toBe(0);

    // Y al revés: más gestores fijos que plazas ocupan la plaza libre de volante.
    HeadcountTarget::query()->where('puesto_id', $this->volante->id)->update(['plantilla_autorizada' => 2]);
    Colaborador::factory()->count(2)->create(['puesto_id' => $this->gestor->id, 'sucursal_principal_id' => $this->orizaba->id, 'estatus' => 'activo']);
    $headcount = app(HeadcountService::class);

    // 4 fijos + 1 volante en 3 + 2 plazas: todo ocupado, como si fuera una sola plaza.
    expect($headcount->plantillaDePar($this->orizaba->id, $this->volante->id))->toBe(['autorizada' => 2, 'ocupada' => 2, 'faltantes' => 0])
        ->and($headcount->plantillaDePar($this->orizaba->id, $this->gestor->id))->toBe(['autorizada' => 3, 'ocupada' => 3, 'faltantes' => 0]);
});

test('C: «Ocupa ambos puestos» cuenta la segunda plaza como ocupada y cierra la vacante; al quitarla se reabre', function () {
    $cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'es_corporativo' => false, 'activo' => true]);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->orizaba->id, 'puesto_id' => $this->gerente->id, 'plantilla_autorizada' => 1]);
    HeadcountTarget::factory()->create(['sucursal_id' => $cuernavaca->id, 'puesto_id' => $this->gerente->id, 'plantilla_autorizada' => 1]);
    $gerenteOrizaba = Colaborador::factory()->create(['puesto_id' => $this->gerente->id, 'sucursal_principal_id' => $this->orizaba->id, 'estatus' => 'activo']);
    app(VacanteAutoGenerationService::class)->sincronizarTodo();
    expect(($this->automatica)($cuernavaca, $this->gerente))->not->toBeNull();

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $gerenteOrizaba->id,
            'puesto_id' => $this->gerente->id,
            'sucursal_id' => $cuernavaca->id,
            'ocupa_plaza' => true,
        ])
        ->assertSessionHasNoErrors();

    $cobertura = CoberturaPuesto::query()->sole();
    $headcount = app(HeadcountService::class);

    expect($cobertura->ocupa_plaza)->toBeTrue()
        ->and($headcount->plantillaDePar($cuernavaca->id, $this->gerente->id))->toBe(['autorizada' => 1, 'ocupada' => 1, 'faltantes' => 0])
        ->and(($this->automatica)($cuernavaca, $this->gerente))->toBeNull()
        // Sigue siendo UNA persona vigente: la segunda plaza se reporta aparte.
        ->and($headcount->poblacion(collect([$this->orizaba->id, $cuernavaca->id]))['total'])->toMatchArray(['vigentes' => 1, 'segundas_plazas' => 1, 'ocupadas' => 2, 'vacantes' => 0]);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.finalizar', $cobertura))
        ->assertRedirect();

    expect(app(HeadcountService::class)->plantillaDePar($cuernavaca->id, $this->gerente->id)['faltantes'])->toBe(1)
        ->and(($this->automatica)($cuernavaca, $this->gerente)?->plazas_disponibles)->toBe(1);
});

test('C: «Cubre mientras se contrata» deja la vacante abierta', function () {
    $cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'es_corporativo' => false, 'activo' => true]);
    HeadcountTarget::factory()->create(['sucursal_id' => $cuernavaca->id, 'puesto_id' => $this->gerente->id, 'plantilla_autorizada' => 1]);
    $gerenteOrizaba = Colaborador::factory()->create(['puesto_id' => $this->gerente->id, 'sucursal_principal_id' => $this->orizaba->id, 'estatus' => 'activo']);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $gerenteOrizaba->id,
            'puesto_id' => $this->gerente->id,
            'sucursal_id' => $cuernavaca->id,
            'ocupa_plaza' => false,
        ])
        ->assertSessionHasNoErrors();

    expect(CoberturaPuesto::query()->sole()->ocupa_plaza)->toBeFalse()
        ->and(app(HeadcountService::class)->plantillaDePar($cuernavaca->id, $this->gerente->id)['faltantes'])->toBe(1)
        ->and(($this->automatica)($cuernavaca, $this->gerente)?->plazas_disponibles)->toBe(1);
});

test('C: la baja de quien ocupa ambos puestos reabre la vacante de la segunda plaza', function () {
    HeadcountTarget::factory()->create(['sucursal_id' => $this->corporativo->id, 'puesto_id' => $this->auditora->id, 'plantilla_autorizada' => 1]);
    $gerenteOrizaba = Colaborador::factory()->create(['puesto_id' => $this->gerente->id, 'sucursal_principal_id' => $this->orizaba->id, 'estatus' => 'activo']);
    CoberturaPuesto::query()->create([
        'colaborador_id' => $gerenteOrizaba->id, 'puesto_id' => $this->auditora->id, 'sucursal_id' => null,
        'motivo' => 'vacante', 'fecha_inicio' => now()->toDateString(), 'activa' => true, 'ocupa_plaza' => true,
    ]);
    app(VacanteAutoGenerationService::class)->sincronizarTodo();
    expect(($this->automatica)($this->corporativo, $this->auditora))->toBeNull();

    $gerenteOrizaba->update(['estatus' => 'inactivo']);

    expect(($this->automatica)($this->corporativo, $this->auditora)?->plazas_disponibles)->toBe(1);
});
