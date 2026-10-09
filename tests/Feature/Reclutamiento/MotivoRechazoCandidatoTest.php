<?php

use App\Enums\EstadoCandidato;
use App\Models\Candidato;
use App\Models\MotivoRechazoCandidato;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Validation\ValidationException;

/*
 * Catálogo administrable de motivos de rechazo (CLAUDE.md §10): nunca texto
 * libre suelto, siempre respaldado por una fila activa del catálogo, con
 * marca "recontratable" que RH puede sobreescribir caso por caso.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->rh = clUsuario('rh_admin');
    $this->workflow = app(CandidatoWorkflowService::class);
});

test('descartar sin recontratable explícito respeta la sugerencia del catálogo', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => null, 'estado' => EstadoCandidato::Recibidos->value]);
    $motivo = MotivoRechazoCandidato::query()->create(['clave' => 'conducta_test', 'nombre' => 'Conducta', 'activo' => true, 'no_recontratable_por_defecto' => true]);

    $resultado = $this->workflow->descartar($candidato, $this->rh, EstadoCandidato::NoViable, 'Incidente reportado.', $motivo->id);

    expect($resultado->motivo_rechazo_id)->toBe($motivo->id)
        ->and($resultado->recontratable)->toBeFalse();
});

test('RH puede sobreescribir la sugerencia del catálogo caso por caso', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => null, 'estado' => EstadoCandidato::Recibidos->value]);
    $motivo = MotivoRechazoCandidato::query()->create(['clave' => 'conducta_test_2', 'nombre' => 'Conducta', 'activo' => true, 'no_recontratable_por_defecto' => true]);

    $resultado = $this->workflow->descartar($candidato, $this->rh, EstadoCandidato::NoViable, 'Caso particular.', $motivo->id, recontratable: true);

    expect($resultado->recontratable)->toBeTrue();
});

test('un motivo desactivado no puede usarse para un rechazo nuevo', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => null, 'estado' => EstadoCandidato::Recibidos->value]);
    $motivo = MotivoRechazoCandidato::query()->create(['clave' => 'viejo_test', 'nombre' => 'Viejo', 'activo' => false, 'no_recontratable_por_defecto' => false]);

    $this->workflow->descartar($candidato, $this->rh, EstadoCandidato::NoViable, 'Motivo inválido.', $motivo->id);
})->throws(ValidationException::class);

test('sin motivo de catálogo, el candidato queda recontratable por defecto', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => null, 'estado' => EstadoCandidato::Recibidos->value]);

    $resultado = $this->workflow->descartar($candidato, $this->rh, EstadoCandidato::Desistio, 'Ya no está interesado.');

    expect($resultado->motivo_rechazo_id)->toBeNull()
        ->and($resultado->recontratable)->toBeTrue();
});

test('el catálogo nunca se borra, solo se desactiva, y RH lo administra desde Configuración', function () {
    $this->actingAs($this->rh)
        ->post(route('administracion.configuracion.parametros-rh.motivos-rechazo.store'), [
            'clave' => 'prueba_catalogo',
            'nombre' => 'Motivo de prueba',
            'activo' => true,
            'no_recontratable_por_defecto' => false,
        ])
        ->assertSessionHasNoErrors();

    $motivo = MotivoRechazoCandidato::query()->where('clave', 'prueba_catalogo')->firstOrFail();

    $this->actingAs($this->rh)
        ->put(route('administracion.configuracion.parametros-rh.motivos-rechazo.update', $motivo), [
            'clave' => $motivo->clave,
            'nombre' => 'Motivo de prueba actualizado',
            'activo' => false,
            'no_recontratable_por_defecto' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($motivo->fresh()->nombre)->toBe('Motivo de prueba actualizado')
        ->and($motivo->fresh()->activo)->toBeFalse();

    $gerente = clUsuario('gerente_sucursal');
    $this->actingAs($gerente)
        ->put(route('administracion.configuracion.parametros-rh.motivos-rechazo.update', $motivo), ['clave' => $motivo->clave, 'nombre' => 'Hackeo'])
        ->assertForbidden();
});

test('Filtro RH: rechazar usa el motivo del catálogo y RH decide si puede considerarse nuevamente (queda en el histórico)', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => null, 'estado' => EstadoCandidato::Recibidos->value]);
    $motivo = MotivoRechazoCandidato::query()->create(['clave' => 'perfil_test', 'nombre' => 'No cumple perfil', 'activo' => true, 'no_recontratable_por_defecto' => false]);

    $this->actingAs($this->rh)
        ->post(route('rh.candidatos.perfil', $candidato), [
            'viable' => false,
            'observaciones' => 'Sin experiencia en cobranza (WhatsApp 09/10).',
            'motivo_rechazo_id' => $motivo->id,
            'recontratable' => false,
        ])
        ->assertSessionHasNoErrors();

    $candidato->refresh();

    expect($candidato->estado)->toBe(EstadoCandidato::NoViable)
        ->and($candidato->motivo_rechazo_id)->toBe($motivo->id)
        ->and($candidato->recontratable)->toBeFalse()
        ->and($candidato->salida_por)->toBe($this->rh->id)
        ->and($candidato->seguimientos()->where('estado_nuevo', EstadoCandidato::NoViable->value)->exists())->toBeTrue();
});

test('Filtro RH: continuar avanza a entrevista sin registrar rechazo', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => null, 'estado' => EstadoCandidato::Recibidos->value]);

    $this->workflow->evaluarPerfil($candidato, $this->rh, true, 'Contactado por WhatsApp: interesado.');

    expect($candidato->refresh()->estado)->toBe(EstadoCandidato::EntrevistaPendiente)
        ->and($candidato->motivo_rechazo_id)->toBeNull();
});
