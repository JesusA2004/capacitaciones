<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
 * Baja de CUENTA (acceso) vs. baja de COLABORADOR (laboral). Nada se borra.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->otroRh = clUsuario('rh_admin');
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'estatus' => EstadoUsuario::Activo->value,
        'estado_alta' => EstadoAltaColaborador::Activo->value,
    ]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->cuenta->assignRole('colaborador');
    $this->cuenta->createToken('app');
});

test('revocar acceso deja la CUENTA inactiva con motivo, sin tocar el estatus laboral', function () {
    $this->actingAs($this->rh)
        ->post(route('administracion.usuarios.revocar-acceso', $this->cuenta), ['motivo' => 'Ya no usa el sistema'])
        ->assertSessionHasNoErrors();

    $cuenta = $this->cuenta->fresh();
    expect($cuenta->acceso_bloqueado_en)->not->toBeNull()
        ->and($cuenta->acceso_bloqueado_motivo)->toBe('Ya no usa el sistema')
        ->and($cuenta->acceso_bloqueado_por)->toBe($this->rh->id)
        ->and($cuenta->tokens()->count())->toBe(0)
        ->and($this->colaborador->fresh()->estatus)->toBe(EstadoUsuario::Activo);

    // Por defecto el listado solo muestra cuentas activas.
    $this->actingAs($this->rh)->get(route('administracion.usuarios.index'))
        ->assertInertia(fn ($page) => $page
            ->where('filtros.estado', 'activos')
            ->where('usuarios.data', fn ($filas) => collect($filas)->doesntContain('id', $this->cuenta->id)));

    $this->actingAs($this->rh)->get(route('administracion.usuarios.index', ['estado' => 'inactivos']))
        ->assertInertia(fn ($page) => $page
            ->where('usuarios.data', fn ($filas) => collect($filas)->contains(fn ($f) => $f['id'] === $this->cuenta->id
                && $f['estado_cuenta'] === 'inactiva'
                && $f['estado_colaborador']['clave'] === 'activo')));
});

test('al solicitar la baja se suspende el acceso al instante, se avisa a RH y si se rechaza el acceso vuelve', function () {
    Sanctum::actingAs($this->rh);

    $cierreId = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/cierres", [
        'tipo_baja' => 'renuncia',
        'motivo' => 'Renuncia voluntaria.',
        'fecha_efectiva' => now()->addDays(10)->toDateString(),
    ])->assertCreated()->json('data.id');

    $cuenta = $this->cuenta->fresh();
    expect($cuenta->acceso_bloqueado_en)->not->toBeNull()
        ->and($cuenta->acceso_bloqueado_motivo)->toStartWith('Baja en trámite:')
        ->and($cuenta->tokens()->count())->toBe(0)
        // La baja LABORAL aún no ocurre: sigue activo hasta la autorización y la fecha efectiva.
        ->and($this->colaborador->fresh()->estatus)->toBe(EstadoUsuario::Activo);

    Notification::assertSentTo($this->otroRh, PendienteRhNotification::class, fn (PendienteRhNotification $n, array $c, User $d) => $n->toDatabase($d)['tipo'] === 'cierre_baja_solicitada');

    $this->postJson("/api/v1/rh/cierres/{$cierreId}/rechazar", ['motivo' => 'No procede'])->assertOk();

    expect($this->cuenta->fresh()->acceso_bloqueado_en)->toBeNull();
});

test('un regional puede rehabilitar el acceso pero no revocar cuentas', function () {
    $regional = clUsuario('coordinadora_regional', ['sucursal_principal_id' => $this->colaborador->sucursal_principal_id]);
    $this->cuenta->forceFill(['acceso_bloqueado_en' => now(), 'acceso_bloqueado_motivo' => 'Baja en trámite: Renuncia'])->save();

    $this->actingAs($regional)
        ->post(route('administracion.usuarios.restablecer-acceso', $this->cuenta))
        ->assertSessionHasNoErrors();

    expect($this->cuenta->fresh()->acceso_bloqueado_en)->toBeNull();

    $this->actingAs($regional)
        ->post(route('administracion.usuarios.revocar-acceso', $this->cuenta))
        ->assertForbidden();
});
