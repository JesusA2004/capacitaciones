<?php

use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Solicitudes\SolicitudesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

/**
 * Aprueba la baja como lo hace CierreLaboralService (única puerta válida)
 * y regresa los errores de validación, o [] si se aprobó.
 *
 * @return array<string, array<int, string>>
 */
function aprobarBajaDesdeCierre(SolicitudInterna $solicitud, User $actor): array
{
    try {
        app(SolicitudesService::class)->aprobar($solicitud, $actor, null, [], desdeCierreLaboral: true);

        return [];
    } catch (ValidationException $e) {
        return $e->errors();
    }
}

test('la baja ya no se puede crear como solicitud nueva (ni RH): se inicia desde el Cierre laboral', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = Colaborador::factory()->create();

    $this->actingAs($rh)
        ->post(route('solicitudes.store'), [
            'tipo' => 'baja_colaborador',
            'motivo' => 'Renuncia voluntaria.',
            'colaborador_objetivo_id' => $colaborador->id,
            'fecha_efectiva' => now()->addWeek()->toDateString(),
            'tipo_baja' => 'renuncia',
        ])
        ->assertSessionHasErrors(['tipo']);

    expect(SolicitudInterna::where('objetivo_colaborador_id', $colaborador->id)->exists())->toBeFalse();
});

test('el cierre laboral no exige evidencia adjunta para aprobar la baja (la renuncia firmada vive en el cierre), pero sí el finiquito revisado', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = Colaborador::factory()->create();
    $solicitud = SolicitudInterna::factory()->create([
        'tipo' => 'baja_colaborador',
        'estado' => 'en_revision',
        'objetivo_colaborador_id' => $colaborador->id,
        'fecha_efectiva' => now()->addWeek(),
        'tipo_baja' => 'renuncia',
    ]);

    config(['finiquitos.exigir_finiquito_revisado_para_aprobar_baja' => true]);

    expect(aprobarBajaDesdeCierre($solicitud, $rh))->not->toHaveKey('evidencia')->toHaveKey('finiquito')
        ->and($solicitud->fresh()->estado->value)->toBe('en_revision')
        ->and($colaborador->fresh()->estatus->value)->toBe('activo');
});

test('el cierre laboral aprueba una baja con evidencia y finiquito revisado', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = Colaborador::factory()->create(['fecha_ingreso' => now()->subYears(2)]);
    $solicitud = SolicitudInterna::factory()->create([
        'tipo' => 'baja_colaborador',
        'estado' => 'en_revision',
        'objetivo_colaborador_id' => $colaborador->id,
        'fecha_efectiva' => now()->addWeek(),
        'tipo_baja' => 'renuncia',
    ]);

    $this->actingAs($rh)
        ->post(route('solicitudes.documentos.store', $solicitud), [
            'archivo' => UploadedFile::fake()->create('autorizacion.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors();

    expect(aprobarBajaDesdeCierre($solicitud->fresh(), $rh))->toHaveKey('finiquito');

    $this->actingAs($rh)
        ->post(route('rh.solicitudes.finiquito.calcular', $solicitud), ['sueldo_mensual' => 12000])
        ->assertSessionHasNoErrors();

    $this->actingAs($rh)
        ->post(route('rh.solicitudes.finiquito.revisar', $solicitud))
        ->assertSessionHasNoErrors();

    expect(aprobarBajaDesdeCierre($solicitud->fresh(), $rh))->toBe([]);

    expect($solicitud->fresh())
        ->estado->value->toBe('aprobada')
        ->and($colaborador->fresh()->estatus->value)->toBe('inactivo')
        ->and($solicitud->fresh()->finiquitoCalculo->estado->value)->toBe('aprobado');
});

test('una baja NO se aprueba, rechaza ni corrige desde la bandeja web: se gestiona en el Cierre laboral', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = Colaborador::factory()->create();
    $solicitud = SolicitudInterna::factory()->create([
        'tipo' => 'baja_colaborador',
        'estado' => 'en_revision',
        'objetivo_colaborador_id' => $colaborador->id,
        'fecha_efectiva' => now()->addWeek(),
        'tipo_baja' => 'renuncia',
    ]);
    $this->actingAs($rh)->post(route('solicitudes.documentos.store', $solicitud), [
        'archivo' => UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $mensaje = 'La baja se gestiona en el Cierre laboral del colaborador.';

    $this->actingAs($rh)
        ->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasErrors(['estado' => $mensaje]);

    $this->actingAs($rh)
        ->post(route('rh.solicitudes.rechazar', $solicitud), ['motivo_rechazo' => 'No procede.'])
        ->assertSessionHasErrors(['estado' => $mensaje]);

    $this->actingAs($rh)
        ->post(route('rh.solicitudes.requerir-correccion', $solicitud), ['comentario' => 'Falta firma.'])
        ->assertSessionHasErrors(['estado' => $mensaje]);

    $this->actingAs($rh)
        ->patch(route('rh.solicitudes.actualizar-estado', $solicitud), ['estado' => 'aprobada'])
        ->assertSessionHasErrors(['estado' => $mensaje]);

    expect($solicitud->fresh()->estado->value)->toBe('en_revision')
        ->and($colaborador->fresh()->estatus->value)->toBe('activo');

    // El detalle la muestra en solo lectura.
    $this->actingAs($rh)
        ->get(route('rh.solicitudes.show', $solicitud))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina
            ->where('puedeAprobar', false)
            ->where('puedeRechazar', false)
            ->where('puedeRevisar', false)
            ->where('bajaEnCierre.mensaje', $mensaje)
            ->where('bajaEnCierre.colaboradorId', $colaborador->id));
});

test('rh puede ver la evidencia de una baja y un usuario sin acceso no puede', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $ajeno = User::factory()->create();
    $ajeno->assignRole('colaborador');

    $solicitud = SolicitudInterna::factory()->create(['tipo' => 'baja_colaborador']);

    $this->actingAs($rh)->post(route('solicitudes.documentos.store', $solicitud), [
        'archivo' => UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf'),
    ]);

    $documento = $solicitud->documentos()->first();

    $this->actingAs($rh)
        ->get(route('rh.solicitudes.documentos.ver', [$solicitud, $documento]))
        ->assertOk();

    $this->actingAs($ajeno)
        ->get(route('rh.solicitudes.documentos.ver', [$solicitud, $documento]))
        ->assertForbidden();
});
