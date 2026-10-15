<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoUsuario;
use App\Enums\ProcesoAprobacion;
use App\Enums\TipoTarea;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\CicloLaboral\AprobacionService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\CierreLaboral\ProcedimientoBajaService;
use App\Services\Solicitudes\SolicitudesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

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

test('RH devuelve la baja: regresa a quien la inició, sin preautorización, y al reenviarla vuelve directo a RH', function () {
    $rh = clUsuario('rh_admin');
    $this->actingAs($this->gerente)->post(route('rh.colaboradores.cierres.store', $this->persona), bajaDirectaDatos());
    $cierre = CierreLaboral::query()->firstOrFail();

    $this->actingAs($rh)
        ->post(route('rh.cierres.devolver', $cierre), ['motivo' => 'Falta la fecha correcta.'])
        ->assertSessionHasNoErrors();

    $cierre->refresh();
    $servicio = app(CierreLaboralService::class);

    // Regresa a «Solicitado» SIN ninguna preautorización pendiente.
    expect($cierre->estado)->toBe(EstadoCierreLaboral::Solicitado)
        ->and(app(AprobacionService::class)->pendiente($cierre, ProcesoAprobacion::CierreLaboral))->toBeNull()
        ->and(collect($servicio->accionesPermitidas($cierre, $this->gerente))->pluck('clave')->all())->toContain('reenviar')->not->toContain('preautorizar')
        ->and(collect($servicio->accionesPermitidas($cierre, $this->otroGerente))->pluck('clave')->all())->not->toContain('reenviar')
        ->and(TareaRh::query()->where('tipo', TipoTarea::CierrePreautorizacion->value)->whereNull('resuelta_en')->value('asignado_user_id'))->toBe($this->gerente->id);

    // Fuera de la cadena de mando no se reenvía.
    $this->actingAs($this->otroGerente)->post(route('rh.cierres.reenviar', $cierre))->assertForbidden();

    $this->actingAs($this->gerente)
        ->post(route('rh.cierres.reenviar', $cierre), ['comentario' => 'Fecha corregida.'])
        ->assertSessionHasNoErrors();

    expect($cierre->refresh()->estado)->toBe(EstadoCierreLaboral::PendienteRh);
});

test('el procedimiento de baja exige alcance de RH sobre la persona del cierre', function () {
    Role::query()->create(['name' => 'rh_sin_alcance_prueba', 'guard_name' => 'web'])->givePermissionTo(['cierres.gestionar']);
    $fuera = clUsuario('rh_sin_alcance_prueba');
    $this->actingAs($this->gerente)->post(route('rh.colaboradores.cierres.store', $this->persona), bajaDirectaDatos());
    $cierre = CierreLaboral::query()->firstOrFail();

    expect(fn () => app(ProcedimientoBajaService::class)->registrarEtapa($cierre, 'baja_asistencia', $fuera))
        ->toThrow(AuthorizationException::class, 'Esta baja es de un colaborador fuera de tu alcance.');

    // RH con alcance sí la registra.
    app(ProcedimientoBajaService::class)->registrarEtapa($cierre, 'baja_asistencia', clUsuario('rh_admin'));

    expect($cierre->refresh()->baja_asistencia_en)->not->toBeNull();
});
