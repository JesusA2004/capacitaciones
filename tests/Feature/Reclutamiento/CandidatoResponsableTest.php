<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoUsuario;
use App\Enums\TipoNodoComercial;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Caso real: candidato a «Subgerente · Ixtlahuaca» mostraba «El candidato no
| tiene gerente asignado ni la sucursal tiene responsable capturado» aunque
| el organigrama de Ixtlahuaca tenía Gerente de Sucursal (Luis Garay). El
| responsable sale de vacante → sucursal → puesto → organigrama.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->ixtlahuaca = Sucursal::factory()->create(['nombre' => 'Ixtlahuaca', 'responsable_id' => null]);
    $this->puestoGerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal']);
    $this->puestoSubgerente = Puesto::factory()->create(['nombre' => 'Subgerente']);
    $this->puestoRegional = Puesto::factory()->create(['nombre' => 'Gerente regional']);

    $this->luis = clUsuario('gerente_sucursal', [
        'name' => 'Luis', 'apellidos' => 'Garay Perez',
        'puesto_id' => $this->puestoGerente->id,
        'sucursal_principal_id' => $this->ixtlahuaca->id,
        'estatus' => EstadoUsuario::Activo,
    ]);
});

function crResponsableVacante(Sucursal $sucursal, Puesto $puesto): Vacante
{
    return Vacante::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'empresa_id' => $sucursal->empresa_id]);
}

test('Subgerente · Ixtlahuaca: el candidato hereda la sucursal de la vacante y su responsable es el gerente del organigrama', function () {
    $vacante = crResponsableVacante($this->ixtlahuaca, $this->puestoSubgerente);
    $candidato = Candidato::factory()->create(['vacante_id' => $vacante->id, 'sucursal_id' => null, 'gerente_involucrado_id' => null, 'estado' => EstadoCandidato::EntrevistaPendiente]);

    expect($candidato->refresh()->sucursal_id)->toBe($this->ixtlahuaca->id);

    $responsable = app(OrganizacionJerarquiaService::class)->responsableDeCandidato($candidato);
    expect($responsable['usuario']?->id)->toBe($this->luis->id);

    $ciclo = app(CicloLaboralService::class)->obtenerEstado($candidato, $this->luis);
    expect(collect($ciclo['bloqueos'] ?? [])->implode(' '))->not->toContain('no tiene gerente');
});

test('una vacante de Gerente de sucursal nunca se entrevista a sí misma: responde el Gerente Regional', function () {
    $region = NodoComercial::query()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Norte', 'activa' => true, 'puesto_id' => $this->puestoRegional->id]);
    NodoComercial::query()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'Zona Ixtlahuaca', 'parent_id' => $region->id, 'sucursal_id' => $this->ixtlahuaca->id, 'activa' => true]);
    $regional = clUsuario('gerente_regional', ['puesto_id' => $this->puestoRegional->id, 'estatus' => EstadoUsuario::Activo]);

    $vacante = crResponsableVacante($this->ixtlahuaca, $this->puestoGerente);
    $candidato = Candidato::factory()->create(['vacante_id' => $vacante->id, 'gerente_involucrado_id' => null]);

    expect(app(OrganizacionJerarquiaService::class)->responsableDeCandidato($candidato)['usuario']?->id)->toBe($regional->id);
});

test('sin gerente de sucursal en el organigrama responde el Regional y, sin Regional, Dirección Comercial', function () {
    $otra = Sucursal::factory()->create(['nombre' => 'Sin gerente', 'responsable_id' => null]);
    $puestoDc = Puesto::factory()->create(['nombre' => 'Dirección Comercial']);
    $direccion = clUsuario('director_comercial', ['puesto_id' => $puestoDc->id, 'estatus' => EstadoUsuario::Activo]);

    $candidato = Candidato::factory()->create(['vacante_id' => crResponsableVacante($otra, $this->puestoSubgerente)->id, 'gerente_involucrado_id' => null]);

    expect(app(OrganizacionJerarquiaService::class)->responsableDeCandidato($candidato)['usuario']?->id)->toBe($direccion->id);
});

test('un gerente asignado explícitamente al candidato tiene prioridad', function () {
    $asignado = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $candidato = Candidato::factory()->create(['vacante_id' => crResponsableVacante($this->ixtlahuaca, $this->puestoSubgerente)->id, 'gerente_involucrado_id' => $asignado->id]);

    expect(app(OrganizacionJerarquiaService::class)->responsableDeCandidato($candidato)['usuario']?->id)->toBe($asignado->id);
});
