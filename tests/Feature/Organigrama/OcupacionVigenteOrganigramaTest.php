<?php

use App\Enums\EstadoCandidato;
use App\Enums\TipoNodoComercial;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Organigrama\PuestosSinUbicarService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Ocupación en el organigrama = vigentes (activo + en incorporación), igual que
| el headcount (docs/ORGANIGRAMA.md): quien firmó contrato y está en Inducción
| ocupa su plaza; un candidato autorizado por RH sin colaborador sale como «Por
| ingresar»; ningún puesto activo desaparece del árbol General.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    config(['organigrama.puestos_raiz_sucursal' => ['Gerente de Sucursal']]);
    Sucursal::query()->update(['activo' => false]);

    $this->dg = Puesto::factory()->create(['nombre' => 'Dirección General', 'nivel_jerarquico' => 1, 'puesto_superior_id' => null, 'activo' => true]);
    $this->dc = Puesto::factory()->create(['nombre' => 'Dirección Comercial', 'nivel_jerarquico' => 2, 'puesto_superior_id' => $this->dg->id, 'activo' => true]);
    $this->gr = Puesto::factory()->create(['nombre' => 'Gerente Regional', 'nivel_jerarquico' => 3, 'puesto_superior_id' => $this->dc->id, 'activo' => true]);
    $this->sr = Puesto::factory()->create(['nombre' => 'Subgerente Regional', 'nivel_jerarquico' => 4, 'puesto_superior_id' => $this->gr->id, 'activo' => true]);
    $this->gs = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'nivel_jerarquico' => 5, 'puesto_superior_id' => $this->gr->id, 'activo' => true]);

    $this->corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'activo' => true, 'es_corporativo' => true]);
    $this->cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'activo' => true, 'es_corporativo' => false]);

    Colaborador::factory()->create(['name' => 'Zuriel', 'apellidos' => 'Ávila', 'estatus' => 'activo', 'puesto_id' => $this->dg->id, 'sucursal_principal_id' => $this->corporativo->id]);
    Colaborador::factory()->create(['name' => 'Alberto', 'apellidos' => 'Comercial', 'estatus' => 'activo', 'puesto_id' => $this->dc->id, 'sucursal_principal_id' => $this->corporativo->id]);
    Colaborador::factory()->create(['name' => 'Regina', 'apellidos' => 'Regional', 'estatus' => 'activo', 'puesto_id' => $this->gr->id, 'sucursal_principal_id' => $this->corporativo->id]);

    $this->rh = clUsuario('rh_admin');
});

/**
 * @return array<string, mixed>
 */
function propsOrganigrama(mixed $usuario): array
{
    return test()->actingAs($usuario)->get(route('administracion.jerarquia-puestos.index'))->assertOk()->viewData('page')['props'];
}

test('quien está en incorporación ocupa su plaza en el árbol, con el chip de su fase, y en las láminas', function () {
    $nancy = Colaborador::factory()->create(['name' => 'Nancy Nallely', 'apellidos' => 'López García', 'estatus' => 'en_incorporacion', 'puesto_id' => $this->gs->id, 'sucursal_principal_id' => $this->cuernavaca->id]);
    $jose = Colaborador::factory()->create(['name' => 'José Israel', 'apellidos' => 'Barranco Navarrón', 'estatus' => 'en_incorporacion', 'puesto_id' => $this->sr->id, 'sucursal_principal_id' => $this->corporativo->id]);
    Candidato::factory()->create(['colaborador_id' => $nancy->id, 'estado' => EstadoCandidato::Contratado->value, 'nombre' => 'Nancy Nallely', 'apellidos' => 'López García']);

    $props = propsOrganigrama($this->rh);
    $nodos = collect($props['personas'])->keyBy('clave');

    expect($nodos)->toHaveKey('p'.$nancy->id)
        ->and($nodos)->toHaveKey('p'.$jose->id)
        ->and($nodos['p'.$nancy->id]['incorporacion'])->toBe('Inducción')
        ->and($nodos['p'.$jose->id]['incorporacion'])->toBe('En incorporación')
        // Ninguna de las dos plazas queda como vacante.
        ->and($nodos->where('tipo', 'vacante')->where('puesto.id', $this->sr->id)->count())->toBe(0)
        ->and($nodos->where('tipo', 'vacante')->where('puesto.id', $this->gs->id)->where('sucursal.id', $this->cuernavaca->id)->count())->toBe(0);

    $regional = collect($props['laminas']['comercial']['columnas'])->firstWhere('titulo', 'Regional Comercial');
    $subregional = collect($regional['cadena'])->firstWhere('titulo', 'Subgerente Regional');
    $cuernavaca = collect($props['laminas']['sucursal']['sucursales'])->firstWhere('nombre', 'Cuernavaca');

    expect($subregional['personas'][0]['nombre'])->toBe('José Israel Barranco Navarrón')
        ->and($cuernavaca['gerente'][0]['nombre'])->toBe('Nancy Nallely López García')
        ->and($cuernavaca['gerente'][0]['incorporacion'])->toBe('Inducción');
});

test('un candidato autorizado por RH sin colaborador marca la plaza como «Por ingresar»', function () {
    // Otra sucursal con gerente: Cuernavaca, sin nadie, aparece con su plaza vacante.
    $cordoba = Sucursal::factory()->create(['nombre' => 'Córdoba', 'activo' => true, 'es_corporativo' => false]);
    Colaborador::factory()->create(['name' => 'Gerente', 'apellidos' => 'Córdoba', 'estatus' => 'activo', 'puesto_id' => $this->gs->id, 'sucursal_principal_id' => $cordoba->id]);
    $vacante = Vacante::factory()->create(['puesto_id' => $this->gs->id, 'sucursal_id' => $this->cuernavaca->id]);
    Candidato::factory()->create(['vacante_id' => $vacante->id, 'colaborador_id' => null, 'estado' => EstadoCandidato::AutorizadoRh->value, 'nombre' => 'Paola', 'apellidos' => 'Por Ingresar']);
    // En «Autorización de RH» todavía puede ser rechazada: no cuenta.
    Candidato::factory()->create(['vacante_id' => $vacante->id, 'colaborador_id' => null, 'estado' => EstadoCandidato::AutorizacionRhPendiente->value, 'nombre' => 'Pendiente', 'apellidos' => 'De RH']);

    $props = propsOrganigrama($this->rh);
    $plaza = collect($props['personas'])->first(fn (array $n) => $n['tipo'] === 'vacante' && $n['puesto']['id'] === $this->gs->id && ($n['sucursal']['id'] ?? null) === $this->cuernavaca->id);
    $cuernavaca = collect($props['laminas']['sucursal']['sucursales'])->firstWhere('nombre', 'Cuernavaca');

    expect($plaza)->not->toBeNull()
        ->and($plaza['por_ingresar'])->toHaveCount(1)
        ->and($plaza['por_ingresar'][0]['nombre'])->toBe('Paola Por Ingresar')
        ->and($cuernavaca['gerente'])->toBe([])
        ->and($cuernavaca['por_ingresar']['gerente'])->toBe(['Paola Por Ingresar']);
});

test('el Subgerente Regional siempre aparece en General: vacante, con titular y aunque la matriz aún lo ligue a una región', function () {
    $nodos = collect(propsOrganigrama($this->rh)['personas']);
    $plaza = $nodos->first(fn (array $n) => $n['puesto']['id'] === $this->sr->id);
    $porClave = $nodos->keyBy('clave');

    expect($plaza)->not->toBeNull()
        ->and($plaza['tipo'])->toBe('vacante')
        ->and($porClave[$plaza['padre']]['puesto']['id'])->toBe($this->gr->id);

    // Datos heredados: la Región sigue ligada a los dos puestos regionales.
    // Antes, el Subgerente Regional se resolvía como su propio superior y la
    // tarjeta no se dibujaba.
    $matriz = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Matriz->value, 'nombre' => 'MATRIZ']);
    $q1 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q1', 'region' => 'Q1', 'parent_id' => $matriz->id, 'puesto_id' => $this->gr->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q3', 'region' => 'Q3', 'parent_id' => $matriz->id, 'puesto_id' => $this->sr->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CUERNAVACA', 'parent_id' => $q1->id, 'sucursal_id' => $this->cuernavaca->id]);

    $nodos = collect(propsOrganigrama($this->rh)['personas'])->keyBy('clave');
    $plazas = $nodos->filter(fn (array $n) => $n['puesto']['id'] === $this->sr->id);

    expect($plazas)->not->toBeEmpty();

    // Toda tarjeta llega a una raíz (sin ciclos): el frontend la dibuja.
    foreach ($nodos as $clave => $nodo) {
        $vistos = [$clave => true];
        $padre = $nodo['padre'];

        while ($padre !== null && isset($nodos[$padre])) {
            expect($vistos)->not->toHaveKey($padre);
            $vistos[$padre] = true;
            $padre = $nodos[$padre]['padre'];
        }
    }
});

test('los puestos activos que no cuelgan de Dirección General van a la bandeja «sin ubicar»', function () {
    $suelto = Puesto::factory()->create(['nombre' => 'Gerente de Auditoría', 'puesto_superior_id' => null, 'activo' => true]);
    $retirado = Puesto::factory()->create(['nombre' => 'Puesto retirado', 'puesto_superior_id' => $this->dg->id, 'activo' => false]);
    $huerfano = Puesto::factory()->create(['nombre' => 'Auditor de Sucursal', 'puesto_superior_id' => $retirado->id, 'activo' => true]);
    $deSuelto = Puesto::factory()->create(['nombre' => 'Auxiliar de Auditoría', 'puesto_superior_id' => $suelto->id, 'activo' => true]);
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Auditora', 'estatus' => 'activo', 'puesto_id' => $huerfano->id, 'sucursal_principal_id' => $this->corporativo->id]);

    $bandeja = collect(app(PuestosSinUbicarService::class)->listar())->keyBy('id');

    expect($bandeja->keys()->all())->toEqualCanonicalizing([$suelto->id, $huerfano->id, $deSuelto->id])
        ->and($bandeja[$suelto->id]['motivo'])->toBe('sin_superior')
        ->and($bandeja[$huerfano->id]['motivo'])->toBe('superior_inactivo')
        ->and($bandeja[$huerfano->id]['ocupantes'])->toBe(['Ana Auditora'])
        ->and($bandeja[$deSuelto->id]['motivo'])->toBe('rama_suelta')
        // Los que sí cuelgan de Dirección General nunca están en la bandeja.
        ->and($bandeja)->not->toHaveKey($this->sr->id);

    expect(collect(propsOrganigrama($this->rh)['puestosSinUbicar'])->pluck('id')->all())->toContain($suelto->id);
});

test('una caja de lámina sin puesto en el catálogo dice «no registrado», no vacante', function () {
    $laminas = propsOrganigrama($this->rh)['laminas'];
    $auditoria = collect($laminas['comercial']['columnas'])->firstWhere('titulo', 'Gerente de Auditoría');
    $regional = collect($laminas['comercial']['columnas'])->firstWhere('titulo', 'Regional Comercial');
    $subregional = collect($regional['cadena'])->firstWhere('titulo', 'Subgerente Regional');

    expect($auditoria['registrado'])->toBeFalse()
        ->and($auditoria['hijos'][0]['registrado'])->toBeFalse()
        ->and($subregional['registrado'])->toBeTrue()
        ->and($subregional['personas'])->toBe([]);
});
