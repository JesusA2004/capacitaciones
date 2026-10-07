<?php

use App\Enums\ClaseEntradaMatriz;
use App\Enums\MotivoCobertura;
use App\Enums\TipoNodoComercial;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Headcount\HeadcountService;
use App\Services\MatrizComercial\ClasificadorNodoComercial;
use App\Services\MatrizComercial\MatrizComercialService;
use App\Services\Organigrama\SincronizadorOrganigramaService;
use Database\Seeders\DepartamentoSeeder;
use Database\Seeders\MatrizComercialSeeder;
use Database\Seeders\PuestoJerarquiaSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Database\Seeders\SucursalSeeder;
use Illuminate\Validation\ValidationException;

/*
 * Estructura organizacional confirmada por dirección (2026-09-29) — ver
 * docs/ORGANIGRAMA.md y SincronizadorOrganigramaService.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(DepartamentoSeeder::class);
    $this->seed(SucursalSeeder::class);
    $this->seed(PuestoJerarquiaSeeder::class);
    $this->seed(MatrizComercialSeeder::class);

    $this->puesto = fn (string $nombre) => Puesto::query()->where('nombre', $nombre)->firstOrFail();
    $this->region = fn (string $codigo) => NodoComercial::query()->where('tipo', TipoNodoComercial::Region->value)->where('nombre', "Región {$codigo}")->first();
});

/**
 * @return array<string, array<string, mixed>>
 */
function nodosPersonasEstructura(User $usuario): array
{
    $nodos = [];

    test()->actingAs($usuario)
        ->get(route('administracion.jerarquia-puestos.index'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$nodos) {
            $nodos = collect($page->toArray()['props']['personas'])->keyBy('clave')->all();
        });

    return $nodos;
}

function rhAdminEstructura(): User
{
    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    return $usuario;
}

test('Q1 y Q3 existen, cada una ligada a su puesto regional; Q2 no existe', function () {
    expect(($this->region)('Q1')?->puesto_id)->toBe(($this->puesto)('Gerente Regional Q1')->id)
        ->and(($this->region)('Q3')?->puesto_id)->toBe(($this->puesto)('Gerente Regional Q3')->id)
        ->and(($this->region)('Q2'))->toBeNull()
        ->and(NodoComercial::query()->where('nombre', 'like', '%Q2%')->orWhere('region', 'Q2')->exists())->toBeFalse()
        ->and(Puesto::query()->where('nombre', 'like', '%Q2%')->exists())->toBeFalse();
});

test('GERENCIA / SUBGERENCIA / VOLANTE de la matriz no son rutas de cobro', function () {
    $clasificador = app(ClasificadorNodoComercial::class);

    expect($clasificador->clasificar('CUERNAVACA GTE'))->toBe(ClaseEntradaMatriz::Gerencia)
        ->and($clasificador->clasificar('SJR - GTE'))->toBe(ClaseEntradaMatriz::Gerencia)
        ->and($clasificador->clasificar('MIACATLAN GERENCIA'))->toBe(ClaseEntradaMatriz::Gerencia)
        ->and($clasificador->clasificar('CUERNAVACA SUBGTE'))->toBe(ClaseEntradaMatriz::Subgerencia)
        ->and($clasificador->clasificar('ATLIX-SUBGTE'))->toBe(ClaseEntradaMatriz::Subgerencia)
        ->and($clasificador->clasificar('VOLANTE CUERNAVACA'))->toBe(ClaseEntradaMatriz::Volante)
        ->and($clasificador->clasificar('HUAMANTLA (CASTIGO)'))->toBe(ClaseEntradaMatriz::CarteraEspecial)
        ->and($clasificador->clasificar('GRUPALES SUR'))->toBe(ClaseEntradaMatriz::OperacionGrupal)
        ->and($clasificador->clasificar('CUERNAVACA CENTRO'))->toBe(ClaseEntradaMatriz::RutaCobro);

    $cuernavacaGte = NodoComercial::query()->where('nombre', 'CUERNAVACA GTE')->firstOrFail();
    $volante = NodoComercial::query()->where('nombre', 'VOLANTE CUERNAVACA')->firstOrFail();

    expect($cuernavacaGte->tipo)->toBe(TipoNodoComercial::Gerencia)
        ->and($volante->tipo)->toBe(TipoNodoComercial::Volante)
        ->and(NodoComercial::query()->where('tipo', TipoNodoComercial::Ruta->value)->where(fn ($q) => $q->where('nombre', 'like', '%GERENCIA%')->orWhere('nombre', 'like', '%GTE%')->orWhere('nombre', 'like', 'VOLANTE%'))->exists())->toBeFalse();
});

test('una posición de gerencia no se puede asignar a un Gestor como si fuera ruta', function () {
    $gestor = Colaborador::factory()->create(['puesto_id' => ($this->puesto)('Gestor')->id]);
    $gerencia = NodoComercial::query()->where('nombre', 'CUERNAVACA GTE')->firstOrFail();

    expect(fn () => app(MatrizComercialService::class)->asignarResponsable($gerencia, $gestor))->toThrow(ValidationException::class);
});

test('Gestor: la ruta es una asignación aparte, no parte del nombre del puesto', function () {
    $gestor = ($this->puesto)('Gestor');

    expect($gestor->requiere_ruta)->toBeTrue()
        ->and(Puesto::query()->where('nombre', 'like', 'Gestor Ruta%')->exists())->toBeFalse()
        ->and(($this->puesto)('Gestor Volante')->requiere_ruta)->toBeFalse();

    $colaborador = Colaborador::factory()->create(['puesto_id' => $gestor->id]);
    $ruta = NodoComercial::query()->where('nombre', 'CUERNAVACA CENTRO')->firstOrFail();
    app(MatrizComercialService::class)->asignarResponsable($ruta, $colaborador);

    expect($ruta->refresh()->responsable_colaborador_id)->toBe($colaborador->id)
        ->and($colaborador->refresh()->puesto_id)->toBe($gestor->id);
});

test('Gestor Volante cuenta en la plantilla autorizada sin tener ruta', function () {
    $sucursal = Sucursal::query()->where('clave', 'CUE01')->firstOrFail();
    $gestor = ($this->puesto)('Gestor');
    $volante = ($this->puesto)('Gestor Volante');
    HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $gestor->id, 'plantilla_autorizada' => 3]);
    HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $volante->id, 'plantilla_autorizada' => 1]);
    Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'puesto_id' => $volante->id]);

    $fila = collect(app(HeadcountService::class)->resumenPorPuesto($sucursal->id))->firstWhere('puesto', 'Gestor');

    expect($fila['plantilla_autorizada'])->toBe(4)
        ->and($fila['plantilla_actual'])->toBe(1);
});

test('la cobertura de un gerente de sucursal no cambia su puesto titular ni duplica headcount', function () {
    $gerente = ($this->puesto)('Gerente de Sucursal');
    $cordoba = Sucursal::query()->where('clave', 'COR01')->firstOrFail();
    $cuernavaca = Sucursal::query()->where('clave', 'CUE01')->firstOrFail();
    HeadcountTarget::factory()->create(['sucursal_id' => $cordoba->id, 'puesto_id' => $gerente->id, 'plantilla_autorizada' => 1]);
    HeadcountTarget::factory()->create(['sucursal_id' => $cuernavaca->id, 'puesto_id' => $gerente->id, 'plantilla_autorizada' => 1]);
    $gerenteCordoba = Colaborador::factory()->create(['sucursal_principal_id' => $cordoba->id, 'puesto_id' => $gerente->id]);

    CoberturaPuesto::query()->create([
        'colaborador_id' => $gerenteCordoba->id, 'puesto_id' => $gerente->id, 'sucursal_id' => $cuernavaca->id,
        'motivo' => MotivoCobertura::Vacante, 'fecha_inicio' => now()->toDateString(), 'activa' => true,
    ]);

    $headcount = app(HeadcountService::class);
    $enCuernavaca = collect($headcount->resumenPorPuesto($cuernavaca->id))->firstWhere('puesto', 'Gerente de Sucursal');
    $enCordoba = collect($headcount->resumenPorPuesto($cordoba->id))->firstWhere('puesto', 'Gerente de Sucursal');

    expect($gerenteCordoba->refresh()->sucursal_principal_id)->toBe($cordoba->id)
        ->and($enCuernavaca['plantilla_actual'])->toBe(0)
        ->and($enCuernavaca['faltante'])->toBe(1)
        ->and($enCordoba['plantilla_actual'])->toBe(1);
});

test('cobertura regional: la titular de Q1 cubre Q3 sin cambiar su puesto y el organigrama lo muestra', function () {
    $q1 = ($this->puesto)('Gerente Regional Q1');
    $q3 = ($this->puesto)('Gerente Regional Q3');
    $corporativo = Sucursal::query()->where('clave', 'CORP01')->firstOrFail();
    $fernanda = Colaborador::factory()->create(['name' => 'Fernanda', 'apellidos' => 'Prueba', 'puesto_id' => $q1->id, 'sucursal_principal_id' => $corporativo->id]);

    CoberturaPuesto::query()->create([
        'colaborador_id' => $fernanda->id, 'puesto_id' => $q3->id, 'region_id' => ($this->region)('Q3')->id,
        'motivo' => MotivoCobertura::Vacante, 'fecha_inicio' => now()->toDateString(), 'activa' => true,
    ]);

    $nodos = nodosPersonasEstructura(rhAdminEstructura());
    $titular = $nodos['p'.$fernanda->id];
    $cobertura = collect($nodos)->first(fn (array $n) => $n['tipo'] === 'cobertura' && $n['puesto']['id'] === $q3->id);

    expect($fernanda->refresh()->puesto_id)->toBe($q1->id)
        ->and($titular['region']['nombre'])->toBe('Región Q1')
        ->and($cobertura)->not->toBeNull()
        ->and($cobertura['persona']['id'])->toBe($fernanda->id)
        ->and($cobertura['cobertura']['titular_de'])->toBe('Gerente Regional Q1')
        ->and($cobertura['region']['nombre'])->toBe('Región Q3');
});

test('el gerente de una sucursal de Q3 cuelga del Gerente Regional Q3 (vacante si nadie lo ocupa)', function () {
    $zonaQ3 = NodoComercial::query()->where('tipo', TipoNodoComercial::Zona->value)->where('parent_id', ($this->region)('Q3')->id)->whereNotNull('sucursal_id')->firstOrFail();
    $gerente = Colaborador::factory()->create(['puesto_id' => ($this->puesto)('Gerente de Sucursal')->id, 'sucursal_principal_id' => $zonaQ3->sucursal_id]);

    $nodos = nodosPersonasEstructura(rhAdminEstructura());
    $padre = $nodos[$nodos['p'.$gerente->id]['padre']];

    expect($padre['puesto']['nombre'])->toBe('Gerente Regional Q3')
        ->and($padre['tipo'])->toBe('vacante');
});

test('los puestos corporativos sin titular se ven como VACANTE (no se ocultan)', function () {
    $nodos = nodosPersonasEstructura(rhAdminEstructura());
    $vacantes = collect($nodos)->where('tipo', 'vacante')->pluck('puesto.nombre')->all();

    expect($vacantes)->toContain('Asistente de Dirección Comercial')
        ->toContain('Responsable de Sistemas')
        ->toContain('Gerente Regional Q1')
        ->toContain('Gerente Regional Q3');
});

test('la sincronización reporta Corporativo con coordinadora de sucursal y sucursales con 2 gerentes', function () {
    $corporativo = Sucursal::query()->where('clave', 'CORP01')->firstOrFail();
    $cordoba = Sucursal::query()->where('clave', 'COR01')->firstOrFail();
    Colaborador::factory()->create(['puesto_id' => ($this->puesto)('Coordinadora de Sucursal')->id, 'sucursal_principal_id' => $corporativo->id]);
    Colaborador::factory()->count(2)->create(['puesto_id' => ($this->puesto)('Gerente de Sucursal')->id, 'sucursal_principal_id' => $cordoba->id]);

    $conflictos = app(SincronizadorOrganigramaService::class)->sincronizar(simular: true)['conflictos'];

    expect(collect($conflictos)->contains(fn (string $c) => str_contains($c, 'Corporativo tiene Coordinadora de Sucursal')))->toBeTrue()
        ->and(collect($conflictos)->contains(fn (string $c) => str_contains($c, 'Gerente de Sucursal: 2 titulares')))->toBeTrue();
});

test('people:sincronizar-organigrama --simular no escribe nada', function () {
    ($this->puesto)('Dirección Comercial')->update(['nombre' => 'Director comercial']);
    NodoComercial::query()->where('nombre', 'CUERNAVACA GTE')->update(['tipo' => TipoNodoComercial::Ruta->value]);

    $this->artisan('people:sincronizar-organigrama', ['--simular' => true])
        ->expectsOutputToContain('«Director comercial» -> «Dirección Comercial»')
        ->assertSuccessful();

    expect(Puesto::query()->where('nombre', 'Director comercial')->exists())->toBeTrue()
        ->and(NodoComercial::query()->where('nombre', 'CUERNAVACA GTE')->value('tipo'))->toBe(TipoNodoComercial::Ruta);

    $this->artisan('people:sincronizar-organigrama')->assertSuccessful();

    expect(Puesto::query()->where('nombre', 'Dirección Comercial')->exists())->toBeTrue()
        ->and(NodoComercial::query()->where('nombre', 'CUERNAVACA GTE')->value('tipo'))->toBe(TipoNodoComercial::Gerencia);
});

test('Auditora mal colgada de Mesa de Control queda en Contraloría bajo su gerente, sin tocar a la persona', function () {
    $mesa = ($this->puesto)('Gerente de Mesa de Control');
    $auditora = ($this->puesto)('Auditora');
    // Como quedó en producción: mismo nivel/nodo que Mesa de Control.
    $auditora->update(['puesto_superior_id' => $mesa->puesto_superior_id, 'nivel_jerarquico' => 3, 'departamento_id' => $mesa->departamento_id, 'puesto_crecimiento_id' => null]);
    $daniela = Colaborador::factory()->create(['name' => 'Daniela', 'apellidos' => 'Dominguez Hernandez', 'puesto_id' => $auditora->id]);
    $antes = $daniela->only(['puesto_id', 'sucursal_principal_id', 'estatus']);

    $this->artisan('people:sincronizar-organigrama', ['--simular' => true])
        ->expectsOutputToContain('«Auditora» ahora reporta a «Gerente de Contraloría»')
        ->assertSuccessful();
    expect($auditora->fresh()->nivel_jerarquico)->toBe(3);

    $this->artisan('people:sincronizar-organigrama')->assertSuccessful();

    $auditora->refresh();
    $contraloria = ($this->puesto)('Gerente de Contraloría');
    expect($auditora->puesto_superior_id)->toBe($contraloria->id)
        ->and($auditora->puesto_crecimiento_id)->toBe($contraloria->id)
        // Conserva su nivel original (4): AprobacionJerarquicaService::esGerenciaOSuperior()
        // usa nivel_jerarquico <= 3 como umbral de "es gerencia" — Auditora
        // no es gerencia y no debe cruzarlo solo porque su jefe subió de nivel.
        ->and($auditora->nivel_jerarquico)->toBe(4)
        ->and($auditora->departamento?->nombre)->toBe('Contraloría')
        // Contraloría reporta directo a Dirección General, al mismo nivel
        // que Dirección Comercial (CLAUDE.md §27, estructura confirmada
        // 2026-10-06): nunca cuelga de Comercial.
        ->and($contraloria->puesto_superior_id)->toBe(($this->puesto)('Dirección General')->id)
        ->and(($this->puesto)('Analista de Mesa de Control')->puesto_superior_id)->toBe($mesa->id)
        ->and($daniela->fresh()->only(['puesto_id', 'sucursal_principal_id', 'estatus']))->toBe($antes);
});

test('Contraloría, Recursos Humanos y Sistemas reportan directo a Dirección General, al mismo nivel que Dirección Comercial', function () {
    $direccionGeneral = ($this->puesto)('Dirección General');
    $comercial = ($this->puesto)('Dirección Comercial');
    $contraloria = ($this->puesto)('Gerente de Contraloría');
    $rh = ($this->puesto)('Gerencia de Recursos Humanos');
    $sistemas = ($this->puesto)('Responsable de Sistemas');

    expect($comercial->puesto_superior_id)->toBe($direccionGeneral->id)
        ->and($contraloria->puesto_superior_id)->toBe($direccionGeneral->id)
        ->and($rh->puesto_superior_id)->toBe($direccionGeneral->id)
        ->and($sistemas->puesto_superior_id)->toBe($direccionGeneral->id)
        ->and($contraloria->nivel_jerarquico)->toBe($comercial->nivel_jerarquico)
        ->and($rh->nivel_jerarquico)->toBe($comercial->nivel_jerarquico)
        ->and($sistemas->nivel_jerarquico)->toBe($comercial->nivel_jerarquico)
        // Mesa de Control y la rama comercial (regionales, sucursales,
        // gestores) sí siguen bajo Dirección Comercial: el cambio de nivel
        // es solo para Contraloría, RH y Sistemas.
        ->and(($this->puesto)('Gerente de Mesa de Control')->puesto_superior_id)->toBe($comercial->id);
});
