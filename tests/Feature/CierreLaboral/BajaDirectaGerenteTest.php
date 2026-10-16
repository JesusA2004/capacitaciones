<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoUsuario;
use App\Enums\ProcesoAprobacion;
use App\Enums\TipoTarea;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\CicloLaboral\AprobacionService;
use App\Services\CicloLaboral\TimelineService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\CierreLaboral\ProcedimientoBajaService;
use App\Services\Solicitudes\SolicitudesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/*
| La baja NO es una solicitud: el jefe directo (o RH) la inicia desde el
| colaborador → Cierre laboral y queda APLICADA DE INMEDIATO, sin
| preautorización, sin autorización de RH y sin archivos. Nadie fuera de
| la cadena de mando la inicia, y nunca se borra al colaborador.
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

test('el jefe directo inicia la baja y queda APLICADA de inmediato: sin preautorización ni autorización de RH', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), bajaDirectaDatos())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $cierre = CierreLaboral::query()->where('colaborador_id', $this->persona->id)->firstOrFail();

    expect($cierre->estado)->toBe(EstadoCierreLaboral::Iniciado)
        ->and($cierre->autorizado_rh_en)->not->toBeNull()
        ->and($cierre->autorizado_rh_por)->toBe($this->gerente->id)
        // Sin ronda de aprobaciones (nada «espera a Recursos Humanos»).
        ->and(app(AprobacionService::class)->pendiente($cierre, ProcesoAprobacion::CierreLaboral))->toBeNull()
        ->and(TareaRh::query()->whereIn('tipo', [TipoTarea::CierrePreautorizacion->value, TipoTarea::CierreAutorizacionRh->value])->whereNull('resuelta_en')->exists())->toBeFalse()
        // Lo que sigue es el finiquito.
        ->and(TareaRh::query()->where('tipo', TipoTarea::FiniquitoPendiente->value)->whereNull('resuelta_en')->exists())->toBeTrue()
        ->and($cierre->solicitud?->estado)->toBe(EstadoSolicitudInterna::EnRevision)
        ->and(Colaborador::query()->whereKey($this->persona->id)->exists())->toBeTrue()
        // Accesos suspendidos al iniciar (se conserva todo el historial).
        ->and($this->cuenta->fresh()->acceso_bloqueado_en)->not->toBeNull();

    // Timeline: «Baja aplicada por jefe directo».
    $titulos = collect(app(TimelineService::class)->de($this->persona->fresh()))->pluck('titulo');
    expect($titulos)->toContain('Baja aplicada por jefe directo');

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

test('RH inicia la baja de cualquier persona de su alcance y también queda aplicada de inmediato', function () {
    $rh = clUsuario('rh_admin');

    $this->actingAs($rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), bajaDirectaDatos())
        ->assertSessionHasNoErrors();

    $cierre = CierreLaboral::query()->firstOrFail();

    expect($cierre->estado)->toBe(EstadoCierreLaboral::Iniciado)
        ->and($cierre->autorizado_rh_por)->toBe($rh->id)
        ->and(app(AprobacionService::class)->pendiente($cierre, ProcesoAprobacion::CierreLaboral))->toBeNull()
        ->and($this->cuenta->fresh()->acceso_bloqueado_en)->not->toBeNull();

    $titulos = collect(app(TimelineService::class)->de($this->persona->fresh()))->pluck('titulo');
    expect($titulos)->toContain('Baja aplicada por Recursos Humanos');
});

test('iniciar la baja no pide ningún archivo (web y API): basta causa, motivo y fecha', function () {
    $rh = clUsuario('rh_admin');

    $this->actingAs($rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), bajaDirectaDatos())
        ->assertSessionHasNoErrors();

    $cierre = CierreLaboral::query()->firstOrFail();
    expect($cierre->solicitud?->documentos()->count())->toBe(0)
        ->and($cierre->estado)->toBe(EstadoCierreLaboral::Iniciado);

    // API móvil: mismo Service, sin archivos.
    $otra = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->persona->sucursal_principal_id,
        'jefe_id' => $this->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2024-01-01',
    ]);

    Sanctum::actingAs($rh);
    $this->postJson(route('api.v1.rh.colaboradores.cierres.store', $otra), bajaDirectaDatos())
        ->assertCreated()
        ->assertJsonPath('data.estado', EstadoCierreLaboral::Iniciado->value);
});

test('el cierre no expone ninguna acción de autorizar, devolver ni reenviar la baja (y sus rutas ya no existen)', function () {
    $rh = clUsuario('rh_admin');
    $this->actingAs($this->gerente)->post(route('rh.colaboradores.cierres.store', $this->persona), bajaDirectaDatos());
    $cierre = CierreLaboral::query()->firstOrFail();
    $servicio = app(CierreLaboralService::class);

    foreach ([$rh, $this->gerente] as $usuario) {
        $claves = collect($servicio->accionesPermitidas($cierre, $usuario))->pluck('clave')->all();
        expect($claves)->not->toContain('autorizar_rh')
            ->not->toContain('preautorizar')
            ->not->toContain('devolver')
            ->not->toContain('reenviar')
            ->not->toContain('rechazar');
    }

    foreach (['rh.cierres.autorizar', 'rh.cierres.preautorizar', 'rh.cierres.devolver', 'rh.cierres.reenviar', 'rh.cierres.rechazar', 'api.v1.rh.cierres.autorizar', 'api.v1.rh.cierres.devolver'] as $ruta) {
        expect(Route::has($ruta))->toBeFalse();
    }

    // Ciclo laboral: el cierre ya no muestra el bloque de aprobaciones.
    $this->actingAs($rh)
        ->get(route('rh.colaboradores.ciclo', $this->persona))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('ciclo.aprobaciones', null));
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
