<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoInvitacionIncorporacion;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\IncorporacionInvitacion;
use App\Services\Expedientes\ExpedienteCentroService;
use App\Services\Reclutamiento\CadenaContratacionService;
use Database\Seeders\RolesYPermisosSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Puntos 10 / 11 del cierre: cadena Candidato → incorporación (QR) →
| colaborador → expediente. El botón «Ver expediente…» solo aparece cuando
| ya existe la incorporación; el candidato no desaparece al avanzar.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
});

function ccCandidato(array $estructura, array $atributos = []): Candidato
{
    return Candidato::factory()->create([
        'empresa_id' => $estructura['empresa']->id,
        'sucursal_id' => $estructura['sucursal']->id,
        'puesto_objetivo_id' => $estructura['puesto']->id,
        ...$atributos,
    ]);
}

function ccEnContratacion(array $estructura): Candidato
{
    $colaborador = Colaborador::factory()->create([
        'estatus' => 'en_incorporacion',
        'estado_alta' => 'pendiente_documentos',
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
    ]);
    $candidato = ccCandidato($estructura, ['estado' => EstadoCandidato::EnContratacion->value, 'colaborador_id' => $colaborador->id]);
    $colaborador->update(['candidato_id' => $candidato->id]);
    IncorporacionInvitacion::factory()->create([
        'candidato_id' => $candidato->id,
        'colaborador_id' => $colaborador->id,
        'estado' => EstadoInvitacionIncorporacion::Usado->value,
        'used_at' => now(),
    ]);

    return $candidato;
}

test('I · candidato seleccionado con incorporación: botón «Ver expediente de contratación» visible en ficha y tablero', function () {
    $candidato = ccEnContratacion($this->estructura);

    $this->actingAs($this->rh)
        ->get(route('rh.candidatos.show', $candidato))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('cadenaContratacion.expediente_url', route('rh.expedientes.show', $candidato->colaborador_id))
            ->where('cadenaContratacion.expediente_etiqueta', 'Ver expediente de contratación')
            ->where('cadenaContratacion.colaborador_id', $candidato->colaborador_id)
        );

    $this->actingAs($this->rh)
        ->get(route('rh.candidatos.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('candidatos', fn ($lista) => collect($lista)->contains(
                fn ($c) => $c['id'] === $candidato->id && ($c['contratacion']['expediente_url'] ?? null) !== null,
            ))
        );
});

test('J · candidato seleccionado SIN incorporación: estado «Seleccionado» y sin botón al expediente', function () {
    $candidato = ccCandidato($this->estructura, ['estado' => EstadoCandidato::AutorizadoRh->value]);

    $this->actingAs($this->rh)
        ->get(route('rh.candidatos.show', $candidato))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('cadenaContratacion.clave', 'seleccionado')
            ->where('cadenaContratacion.expediente_url', null)
            ->where('cadenaContratacion.expediente_etiqueta', null)
        );

    // Un candidato que sigue en reclutamiento no trae estado de contratación.
    $enProceso = ccCandidato($this->estructura, ['estado' => EstadoCandidato::EntrevistaPendiente->value]);
    expect(app(CadenaContratacionService::class)->paraCandidato($enProceso, $this->rh))->toBeNull();
});

test('el candidato contratado sigue visible con su estado real (En inducción / Contratado) y el expediente lo enlaza de vuelta', function () {
    $candidato = ccEnContratacion($this->estructura);
    $candidato->update(['estado' => EstadoCandidato::Contratado->value, 'contratado_en' => now()]);
    $colaborador = $candidato->colaborador()->firstOrFail();

    expect(app(CadenaContratacionService::class)->paraCandidato($candidato->fresh(), $this->rh))
        ->toMatchArray(['clave' => 'en_induccion', 'etiqueta' => 'En inducción']);

    $colaborador->update(['estatus' => 'activo', 'activado_en' => now()]);
    expect(app(CadenaContratacionService::class)->paraCandidato($candidato->fresh(), $this->rh))
        ->toMatchArray(['clave' => 'contratado', 'expediente_etiqueta' => 'Ver expediente del colaborador']);

    // Desde el expediente: «Ver origen en reclutamiento».
    $origen = app(ExpedienteCentroService::class)->resumen($colaborador->fresh(), $this->rh)['origen'];
    expect($origen)->not->toBeNull()
        ->and($origen['candidato_id'])->toBe($candidato->id)
        ->and($origen['url'])->toBe(route('rh.candidatos.show', $candidato->id));
});

test('quien no puede ver el expediente no recibe el enlace aunque vea al candidato', function () {
    $candidato = ccEnContratacion($this->estructura);
    $soloCandidatos = clUsuario('colaborador');
    $soloCandidatos->givePermissionTo(['candidatos.ver', 'candidatos.ver_todos']);

    expect(app(CadenaContratacionService::class)->paraCandidato($candidato, $soloCandidatos)['expediente_url'])->toBeNull();
});
