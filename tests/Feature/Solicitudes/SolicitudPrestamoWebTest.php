<?php

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/*
| Caso real: Nueva solicitud → Préstamo → Enviar «no hacía nada». El
| formulario web manda TODOS sus campos (también `dias: []` y los del
| permiso vacíos); un error de un campo que la pantalla no pinta dejaba la
| acción muda. El colaborador solo pide monto y motivo: plazo, periodicidad
| y fechas los define RH después.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    $persona = Colaborador::factory()->create(['fecha_ingreso' => '2024-01-01']);
    $this->colaborador = User::factory()->create(['colaborador_id' => $persona->id]);
    $this->colaborador->assignRole('colaborador');
});

/** El cuerpo EXACTO que manda Solicitudes/Index.vue (useForm completo). */
function prestamoWebPayload(array $cambios = []): array
{
    return [
        'tipo' => 'prestamo',
        'motivo' => 'Gastos médicos.',
        'observaciones' => '',
        'fecha_inicio' => '',
        'duracion_dias' => '',
        'dias' => [],
        'monto_solicitado' => '3000',
        'colaborador_objetivo_id' => '',
        'fecha_efectiva' => '',
        'tipo_baja' => '',
        'permiso_tipo' => '',
        'permiso_goce' => '',
        'permiso_causal' => '',
        'hora_salida' => '',
        'hora_entrada' => '',
        ...$cambios,
    ];
}

test('el formulario web completo crea el préstamo enviado con folio y aparece en Mis solicitudes', function () {
    $rh = clUsuario('rh_admin');

    $this->actingAs($this->colaborador)
        ->post(route('solicitudes.store'), prestamoWebPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $solicitud = SolicitudInterna::query()->where('user_id', $this->colaborador->id)->firstOrFail();

    expect($solicitud->tipo)->toBe(TipoSolicitudInterna::PrestamoInterno)
        ->and($solicitud->estado)->toBe(EstadoSolicitudInterna::Enviada)
        ->and($solicitud->folio)->toStartWith('SOL-')
        ->and((float) $solicitud->monto_solicitado)->toBe(3000.0)
        ->and($solicitud->plazo_meses)->toBeNull();

    $this->actingAs($this->colaborador)
        ->get(route('solicitudes.index'))
        ->assertInertia(fn ($page) => $page->where('solicitudes.data.0.id', $solicitud->id));

    // Pendiente para RH (quien autoriza préstamos) y confirmación al colaborador.
    expect($rh->notifications()->count())->toBeGreaterThan(0)
        ->and($this->colaborador->notifications()->count())->toBeGreaterThan(0);
});

test('el colaborador no define el plazo: si llega se descarta', function () {
    $this->actingAs($this->colaborador)
        ->post(route('solicitudes.store'), prestamoWebPayload(['plazo_meses' => '12']))
        ->assertSessionHasNoErrors();

    expect(SolicitudInterna::query()->firstOrFail()->plazo_meses)->toBeNull();
});

test('monto vacío regresa un error visible del campo monto', function () {
    $this->actingAs($this->colaborador)
        ->post(route('solicitudes.store'), prestamoWebPayload(['monto_solicitado' => '']))
        ->assertSessionHasErrors(['monto_solicitado']);

    expect(SolicitudInterna::query()->count())->toBe(0);
});
