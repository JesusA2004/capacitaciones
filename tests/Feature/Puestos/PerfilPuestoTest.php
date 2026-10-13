<?php

use App\Models\Candidato;
use App\Models\PerfilPuesto;
use App\Models\Puesto;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Perfiles de puesto: RH define lo que debe tener el candidato y el «deber
| ser» del puesto; gerencias solo lo consultan; quien entrevista lo ve en la
| ficha del candidato.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->jefe = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'activo' => true]);
    $this->puesto = Puesto::factory()->create(['nombre' => 'Subgerente', 'puesto_superior_id' => $this->jefe->id, 'activo' => true, 'requisitos' => "Licencia vigente\nDisponibilidad de horario"]);
});

test('un puesto sin perfil ofrece un borrador (funciones del manual de inducción y requisitos ya capturados)', function () {
    $this->actingAs(clUsuario('rh_admin'))->get(route('rh.perfiles-puesto.show', $this->puesto))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('es_borrador', true)
            ->where('puedeEditar', true)
            ->where('puesto.reporta_a', 'Gerente de Sucursal')
            ->where('perfil.requisitos', ['Licencia vigente', 'Disponibilidad de horario'])
            ->where('perfil.funciones', fn ($f) => collect($f)->contains('Asignar cartera a los gestores')));

    expect(PerfilPuesto::query()->count())->toBe(0);
});

test('RH guarda el perfil; las listas se limpian y queda auditado quién lo editó', function () {
    $rh = clUsuario('rh_admin');

    $this->actingAs($rh)->put(route('rh.perfiles-puesto.update', $this->puesto), [
        'objetivo' => 'Dirigir la cobranza de la sucursal.',
        'escolaridad' => 'Bachillerato',
        'habilidades' => ['  • Negociación', 'Negociación', '', 'Liderazgo'],
        'funciones' => ['Asignar cartera'],
        'valores' => ['Honestidad'],
    ])->assertSessionHasNoErrors();

    $perfil = PerfilPuesto::query()->where('puesto_id', $this->puesto->id)->firstOrFail();

    expect($perfil->habilidades)->toBe(['Negociación', 'Liderazgo'])
        ->and($perfil->objetivo)->toBe('Dirigir la cobranza de la sucursal.')
        ->and($perfil->actualizado_por)->toBe($rh->id);

    $this->actingAs($rh)->get(route('rh.perfiles-puesto.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('puestos', fn ($p) => collect($p)->firstWhere('id', $this->puesto->id)['avance'] > 0));
});

test('un gerente consulta el perfil pero no lo edita; un colaborador no lo ve', function () {
    $gerente = clUsuario('gerente_sucursal');

    $this->actingAs($gerente)->get(route('rh.perfiles-puesto.show', $this->puesto))->assertOk()->assertInertia(fn ($page) => $page->where('puedeEditar', false));
    $this->actingAs($gerente)->put(route('rh.perfiles-puesto.update', $this->puesto), ['objetivo' => 'x'])->assertForbidden();
    $this->actingAs(clUsuario('colaborador'))->get(route('rh.perfiles-puesto.index'))->assertForbidden();
});

test('la ficha del candidato muestra lo que se busca para su puesto', function () {
    PerfilPuesto::query()->create(['puesto_id' => $this->puesto->id, 'habilidades' => ['Negociación'], 'valores' => ['Honestidad']]);
    $candidato = Candidato::factory()->create(['puesto_objetivo_id' => $this->puesto->id]);

    $this->actingAs(clUsuario('rh_admin'))->get(route('rh.candidatos.show', $candidato))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('perfilPuesto.puesto', 'Subgerente')
            ->where('perfilPuesto.perfil.habilidades', ['Negociación']));
});

test('la edición vive en su propia página: RH entra, una gerencia no; guardar regresa a la lectura', function () {
    $this->actingAs(clUsuario('gerente_sucursal'))->get(route('rh.perfiles-puesto.edit', $this->puesto))->assertForbidden();

    $rh = clUsuario('rh_admin');
    $this->actingAs($rh)->get(route('rh.perfiles-puesto.edit', $this->puesto))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/PerfilesPuesto/Editar')->where('es_borrador', true));

    $this->actingAs($rh)->put(route('rh.perfiles-puesto.update', $this->puesto), ['valores' => ['Honestidad']])
        ->assertRedirect(route('rh.perfiles-puesto.show', $this->puesto));
});
