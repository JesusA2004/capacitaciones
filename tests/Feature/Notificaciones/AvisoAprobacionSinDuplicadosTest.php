<?php

use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoSolicitudVacaciones;
use App\Models\NotificacionEntrega;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Vacaciones\VacacionesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| Cierre Fase 1 · P2 «avisos duplicados ocasionales (misma aprobación dos veces
| con segundos de diferencia)». Causa: doble envío (doble toque en la app o
| reintento por timeout mientras la primera aprobación seguía generando sus
| documentos). Las dos peticiones leían la solicitud ANTES de que la primera
| confirmara, ambas pasaban la validación de estado y ambas aprobaban; como la
| ocurrencia del aviso incluye updated_at (segundos), la segunda clave era
| distinta y el colaborador recibía el aviso dos veces. Ahora el cambio de
| estado se revalida con la fila bloqueada dentro de la transacción.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();

    $this->rh = clUsuario('rh_admin');
    $this->colaborador = clUsuario('colaborador');
});

test('una segunda aprobación con la solicitud leída antes (doble envío) se rechaza y no duplica el aviso', function () {
    $solicitud = SolicitudInterna::factory()->create([
        'user_id' => $this->colaborador->id,
        'colaborador_id' => $this->colaborador->colaborador_id,
        'estado' => EstadoSolicitudInterna::EnRevision->value,
    ]);

    // Las dos peticiones cargaron la solicitud cuando aún estaba en revisión.
    $primera = SolicitudInterna::query()->findOrFail($solicitud->id);
    $segunda = SolicitudInterna::query()->findOrFail($solicitud->id);

    app(SolicitudesService::class)->aprobar($primera, $this->rh);
    $this->travel(3)->seconds();

    expect(fn () => app(SolicitudesService::class)->aprobar($segunda, $this->rh))
        ->toThrow(ValidationException::class);

    expect(NotificacionEntrega::query()->where('user_id', $this->colaborador->id)->where('evento', 'solicitud_actualizada')->count())->toBe(1)
        ->and($solicitud->historial()->where('accion', EstadoSolicitudInterna::Aprobada->value)->count())->toBe(1)
        ->and($solicitud->fresh()->estado)->toBe(EstadoSolicitudInterna::Aprobada);
});

test('vacaciones (flujo de la app): repetir la misma aprobación no vuelve a avisar', function () {
    $vacaciones = SolicitudVacaciones::factory()->create(['user_id' => $this->colaborador->id]);

    $primera = SolicitudVacaciones::query()->findOrFail($vacaciones->id);
    $segunda = SolicitudVacaciones::query()->findOrFail($vacaciones->id);

    app(VacacionesService::class)->aprobar($primera, $this->rh);
    $this->travel(3)->seconds();

    expect(fn () => app(VacacionesService::class)->aprobar($segunda, $this->rh))
        ->toThrow(ValidationException::class);

    expect(NotificacionEntrega::query()->where('user_id', $this->colaborador->id)->where('evento', 'vacaciones_actualizadas')->count())->toBe(1)
        ->and($vacaciones->fresh()->estado)->toBe(EstadoSolicitudVacaciones::Aprobada);
});

test('un superior todavía puede cambiar la decisión de vacaciones (aprobada → rechazada) y eso sí avisa', function () {
    $vacaciones = SolicitudVacaciones::factory()->create(['user_id' => $this->colaborador->id]);

    app(VacacionesService::class)->aprobar($vacaciones, $this->rh);
    $this->travel(3)->seconds();
    app(VacacionesService::class)->rechazar($vacaciones->fresh(), $this->rh, 'Sin cobertura.');

    expect(NotificacionEntrega::query()->where('user_id', $this->colaborador->id)->where('evento', 'vacaciones_actualizadas')->count())->toBe(2);
});
