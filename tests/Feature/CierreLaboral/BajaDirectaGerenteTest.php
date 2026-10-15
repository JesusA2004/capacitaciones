<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoUsuario;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Solicitudes\SolicitudesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| La baja NO es una solicitud: el jefe directo (o RH) la inicia desde el
| colaborador → Cierre laboral, sin preautorización de un superior. Nadie
| fuera de la cadena de mando la inicia, y nunca se borra al colaborador.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $estructura = clEstructura();
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'jefe_id' => $this->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2024-01-01',
    ]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->persona->id]);
    $this->cuenta->assignRole('colaborador');
    $this->otroGerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $estructura['sucursal']->id]);
});

function bajaDirectaDatos(): array
{
    return ['tipo_baja' => 'renuncia', 'motivo' => 'Renuncia voluntaria.', 'fecha_efectiva' => now()->addWeek()->toDateString()];
}

test('el jefe directo inicia la baja desde el colaborador: cierre laboral directo a RH, sin preautorización', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), bajaDirectaDatos())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $cierre = CierreLaboral::query()->where('colaborador_id', $this->persona->id)->firstOrFail();

    expect($cierre->estado)->toBe(EstadoCierreLaboral::PendienteRh)
        ->and(Colaborador::query()->whereKey($this->persona->id)->exists())->toBeTrue()
        // Accesos suspendidos al iniciar (se conserva todo el historial).
        ->and($this->cuenta->fresh()->acceso_bloqueado_en)->not->toBeNull();

    // No aparece como «solicitud» en Mis solicitudes del gerente.
    $this->actingAs($this->gerente)
        ->get(route('solicitudes.index'))
        ->assertInertia(fn ($page) => $page->where('solicitudes.total', 0));
});

test('un gerente fuera de la cadena de mando no puede iniciar la baja (aunque sea de la misma sucursal)', function () {
    $this->actingAs($this->otroGerente)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), bajaDirectaDatos())
        ->assertForbidden();

    expect(CierreLaboral::query()->count())->toBe(0);
});

test('RH inicia la baja de cualquier persona de su alcance, también sin preautorización', function () {
    $rh = clUsuario('rh_admin');

    $this->actingAs($rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), bajaDirectaDatos())
        ->assertSessionHasNoErrors();

    expect(CierreLaboral::query()->firstOrFail()->estado)->toBe(EstadoCierreLaboral::PendienteRh);
});

test('no se puede iniciar dos veces la baja de la misma persona', function () {
    $this->actingAs($this->gerente)->post(route('rh.colaboradores.cierres.store', $this->persona), bajaDirectaDatos());
    $this->actingAs($this->gerente)->post(route('rh.colaboradores.cierres.store', $this->persona), bajaDirectaDatos());

    expect(CierreLaboral::query()->where('colaborador_id', $this->persona->id)->count())->toBe(1);
});

test('la baja ya no aparece en el catálogo de «Nueva solicitud»', function () {
    $claves = collect(app(SolicitudesService::class)->tiposConFormulario())->pluck('clave');

    expect($claves)->not->toContain('baja_colaborador');
});
