<?php

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Solicitudes\SolicitudesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
| Rutas sin autoridad: aprobar o pasar a revisión no comparaban rango. Un puesto
| inferior no pasa por encima de lo que decidió uno superior; RH conserva su facultad.
*/

function rfSoUsuario(string $rol, Sucursal $sucursal, string $puesto): User
{
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'estatus' => 'activo',
        'puesto_id' => Puesto::query()->firstOrCreate(['nombre' => $puesto])->id,
    ]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole($rol);
    $usuario->givePermissionTo(['solicitudes.aprobar', 'solicitudes.revisar', 'rh.solicitudes.ver', 'rh.solicitudes.detalle']);

    return $usuario;
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->sucursal = Sucursal::factory()->create();
    $this->rh = clUsuario('rh_admin');
    $this->regional = rfSoUsuario('gerente_regional', $this->sucursal, 'Gerente Regional');
    $this->gerente = rfSoUsuario('gerente_sucursal', $this->sucursal, 'Gerente de Sucursal');
    $autor = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->solicitud = SolicitudInterna::factory()->create([
        'user_id' => $autor->id,
        'colaborador_id' => $autor->colaborador_id,
        'tipo' => TipoSolicitudInterna::ConstanciaLaboral,
        'estado' => EstadoSolicitudInterna::Enviada,
    ]);
    $this->servicio = app(SolicitudesService::class);
    $this->decidio = fn (User $autorDecision) => SolicitudAprobacion::query()->create([
        'solicitud_interna_id' => $this->solicitud->id,
        'nivel' => SolicitudAprobacion::NIVEL_REGIONAL,
        'decision' => 'aprobada',
        'user_id' => $autorDecision->id,
    ]);
});

test('un puesto inferior no aprueba ni pasa a revisión sobre lo decidido arriba', function () {
    ($this->decidio)($this->regional);

    expect(fn () => $this->servicio->aprobar($this->solicitud, $this->gerente))->toThrow(AuthorizationException::class);
    expect(fn () => $this->servicio->marcarEnRevision($this->solicitud, $this->gerente))->toThrow(AuthorizationException::class);
    expect($this->gerente->can('aprobar', $this->solicitud))->toBeFalse()
        ->and($this->gerente->can('revisar', $this->solicitud))->toBeFalse();
    expect($this->solicitud->refresh()->estado)->toBe(EstadoSolicitudInterna::Enviada);
});

test('la API también lo niega', function () {
    ($this->decidio)($this->regional);
    Sanctum::actingAs($this->gerente);

    $this->postJson("/api/v1/rh/solicitudes/{$this->solicitud->id}/aprobar")->assertForbidden();
});

test('sin decisión previa, quien sigue en el flujo no se bloquea', function () {
    expect($this->servicio->marcarEnRevision($this->solicitud, $this->gerente)->estado)->toBe(EstadoSolicitudInterna::EnRevision);
});

test('RH aprueba aunque haya decidido un puesto de la línea', function () {
    ($this->decidio)($this->regional);

    expect($this->servicio->aprobar($this->solicitud, $this->rh)->estado)->toBe(EstadoSolicitudInterna::Aprobada);
});
