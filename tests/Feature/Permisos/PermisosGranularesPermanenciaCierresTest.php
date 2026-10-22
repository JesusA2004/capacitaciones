<?php

use App\Enums\EstadoActa;
use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Models\ActaAdministrativa;
use App\Models\Baja;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\NominaLote;
use App\Models\Reingreso;
use App\Models\Responsiva;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Bajas\BajaService;
use App\Services\CicloLaboral\ReingresoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Permisos por acción de Solicitudes, Actas, Nómina, Avisos, Cumpleaños,
 * Bajas, Cierres/finiquitos, Reingresos y Responsivas
 * (config/permisos_modulos.php → herencia): quitar UNA acción a un rol la
 * niega (web y API) sin tocar el resto; los roles base conservan lo que ya
 * podían hacer, salvo las descargas legales que pasan a RH (actas, finiquito).
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->estructura = clEstructura();
    $this->sucursal = $this->estructura['sucursal'];
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->persona = Colaborador::factory()->create(['sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo]);
});

/** Quita una acción a un rol base (como hace «Roles y permisos»). */
function pgpQuitar(string $rol, string $permiso): void
{
    Role::findByName($rol, 'web')->revokePermissionTo($permiso);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function pgpRefrescar(User $usuario): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $usuario->fresh() ?? $usuario;
}

test('herencia: RH conserva todo, el gerente conserva lo operativo pero no las descargas legales', function () {
    $base = RolesYPermisosSeeder::permisosBasePorRol();
    $nuevos = [
        'solicitudes.requerir_correccion', 'solicitudes.descargar_formato', 'solicitudes.exportar',
        'actas.editar', 'actas.anexos.subir', 'actas.registrar_firma', 'actas.seguimiento', 'actas.cerrar', 'actas.descargar',
        'nomina.recibos.emitir', 'nomina.recibos.cancelar', 'nomina.recibos.descargar',
        'bajas.documentos.adjuntar', 'bajas.finiquito.vista_previa', 'bajas.cambiar_causal', 'bajas.editar', 'bajas.enviar_juridico',
        'bajas.cerrar', 'bajas.reabrir', 'bajas.cancelar', 'cierres.aviso.subir', 'cierres.aviso.generar', 'cierres.cancelar',
        'finiquitos.descargar', 'reingresos.decidir', 'responsivas.descargar', 'responsivas.registrar_firma', 'responsivas.registrar_devolucion',
        'rh.cumpleanos.generar',
    ];

    expect(RolesYPermisosSeeder::catalogoPermisos())->toContain(...$nuevos)
        ->and($base['rh_admin'])->toContain(...$nuevos)
        ->and($base['gerente_sucursal'])->toContain('solicitudes.requerir_correccion', 'solicitudes.descargar_formato', 'solicitudes.exportar', 'bajas.documentos.adjuntar', 'cierres.aviso.subir', 'responsivas.descargar', 'responsivas.registrar_firma', 'responsivas.registrar_devolucion')
        ->and($base['gerente_sucursal'])->not->toContain('actas.descargar', 'actas.cerrar', 'bajas.finiquito.vista_previa', 'bajas.cerrar', 'cierres.cancelar', 'nomina.recibos.descargar', 'reingresos.decidir')
        ->and($base['juridico'])->toContain('actas.descargar', 'finiquitos.descargar')
        ->and($base['rh_auxiliar'])->toContain('nomina.recibos.emitir', 'nomina.recibos.descargar', 'actas.descargar')
        ->and($base['rh_auxiliar'])->not->toContain('actas.cerrar');
});

test('sincronizar: un permiso por acción nuevo se otorga a los roles (aun los creados a mano) que tenían el general', function () {
    $hechoAMano = Role::query()->create(['name' => 'rh_regional_mano', 'guard_name' => 'web']);
    $hechoAMano->givePermissionTo('actas.gestionar');
    Permission::query()->where('name', 'actas.cerrar')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('people:sincronizar-permisos')->assertSuccessful();

    expect($hechoAMano->fresh()->hasPermissionTo('actas.cerrar'))->toBeTrue()
        ->and(Role::findByName('rh_admin', 'web')->hasPermissionTo('actas.cerrar'))->toBeTrue()
        ->and(Role::findByName('gerente_sucursal', 'web')->hasPermissionTo('actas.cerrar'))->toBeFalse();

    // Ya existía: si RH se lo quita a un rol, el deploy no lo vuelve a dar.
    pgpQuitar('rh_admin', 'actas.cerrar');
    $this->artisan('people:sincronizar-permisos')->assertSuccessful();
    expect(Role::findByName('rh_admin', 'web')->hasPermissionTo('actas.cerrar'))->toBeFalse();
});

test('solicitudes: exportar y pedir corrección son acciones propias (web y API)', function () {
    $autor = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $autor->id, 'colaborador_id' => $autor->colaborador_id, 'estado' => 'enviada']);

    $this->actingAs($this->rh)->get(route('rh.solicitudes.exportarExcel'))->assertOk();
    expect(Gate::forUser($this->rh)->allows('requerirCorreccion', $solicitud))->toBeTrue();

    pgpQuitar('rh_admin', 'solicitudes.exportar');
    pgpQuitar('rh_admin', 'solicitudes.requerir_correccion');
    $rh = pgpRefrescar($this->rh);

    $this->actingAs($rh)->get(route('rh.solicitudes.exportarExcel'))->assertForbidden();
    $this->actingAs($rh)->get(route('rh.solicitudes.exportarPdf'))->assertForbidden();
    $this->actingAs($rh)->post(route('rh.solicitudes.requerir-correccion', $solicitud), ['comentario' => 'Falta la firma'])->assertForbidden();
    $this->actingAs($rh)->patch(route('rh.solicitudes.actualizar-estado', $solicitud), ['estado' => 'requiere_correccion', 'comentario' => 'Falta'])->assertForbidden();

    Sanctum::actingAs($rh);
    $this->postJson("/api/v1/rh/solicitudes/{$solicitud->id}/correccion", ['motivo' => 'Falta la firma'])->assertForbidden();

    // Marcar en revisión sigue permitido (otra acción).
    expect(Gate::forUser($rh)->allows('revisar', $solicitud))->toBeTrue();
});

test('actas: cerrar se quita sola; el gerente levanta pero no descarga el formato (web y API)', function () {
    $acta = ActaAdministrativa::query()->create([
        'colaborador_id' => $this->persona->id, 'tipo' => 'administrativa', 'estado' => EstadoActa::Generada,
        'fecha' => now()->subDay()->toDateString(), 'hechos' => 'Prueba', 'creado_por' => $this->rh->id,
    ]);

    pgpQuitar('rh_admin', 'actas.cerrar');
    $rh = pgpRefrescar($this->rh);

    $this->actingAs($rh)->post(route('rh.actas.cerrar', $acta))->assertForbidden();
    Sanctum::actingAs($rh);
    $this->postJson("/api/v1/rh/actas/{$acta->id}/cerrar")->assertForbidden();
    // El resto de la gestión sigue igual.
    expect(Gate::forUser($rh)->allows('seguimiento', $acta))->toBeTrue()
        ->and(Gate::forUser($rh)->allows('descargar', $acta))->toBeTrue();

    // Gerente: ve el acta de su sucursal, pero el formato (PDF) es de RH.
    expect(Gate::forUser($this->gerente)->allows('ver', $acta))->toBeTrue();
    $this->actingAs($this->gerente)->get(route('rh.actas.vista-previa', $acta))->assertForbidden();
    Sanctum::actingAs($this->gerente);
    $this->getJson("/api/v1/rh/actas/{$acta->id}/vista-previa")->assertForbidden();
    $this->getJson("/api/v1/rh/actas/{$acta->id}")->assertOk()->assertJsonPath('permisos.descargar', false)->assertJsonPath('permisos.cerrar', false);

    // Su propio borrador lo sigue completando con solo «Levantar acta».
    $borrador = ActaAdministrativa::query()->create([
        'colaborador_id' => $this->persona->id, 'tipo' => 'administrativa', 'estado' => EstadoActa::Borrador,
        'fecha' => now()->toDateString(), 'hechos' => 'Borrador', 'creado_por' => $this->gerente->id,
    ]);
    expect(Gate::forUser($this->gerente)->allows('editar', $borrador))->toBeTrue()
        ->and(Gate::forUser($this->gerente)->allows('subirAnexo', $borrador))->toBeTrue()
        ->and(Gate::forUser($this->gerente)->allows('registrarEntrega', $acta))->toBeFalse();
});

test('nómina: emitir, cancelar y descargar son acciones propias (web y API)', function () {
    $lote = NominaLote::query()->create([
        'folio' => 'NOM-PRUEBA-1', 'periodicidad' => 'quincenal', 'periodo_inicio' => '2026-10-01', 'periodo_fin' => '2026-10-15',
        'fecha_pago' => '2026-10-15', 'origen' => 'sistema', 'estado' => 'preparado',
    ]);

    pgpQuitar('rh_admin', 'nomina.recibos.emitir');
    pgpQuitar('rh_admin', 'nomina.recibos.descargar');
    $rh = pgpRefrescar($this->rh);

    $this->actingAs($rh)->post(route('rh.nomina.lotes.emitir', $lote))->assertForbidden();
    $this->actingAs($rh)->get(route('rh.nomina.lotes.imprimir', $lote))->assertForbidden();
    $this->actingAs($rh)->post(route('rh.nomina.emitir'), ['periodo' => '2026-10-1'])->assertForbidden();
    $this->actingAs($rh)->get(route('rh.nomina.lotes.show', $lote))->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeEmitir', false)->where('puedeDescargar', false)->where('puedeCancelar', true));

    Sanctum::actingAs($rh);
    $this->postJson("/api/v1/rh/nomina/lotes/{$lote->id}/emitir")->assertForbidden();
    $this->getJson("/api/v1/rh/nomina/lotes/{$lote->id}/imprimir")->assertForbidden();
});

test('avisos: con solo avisos.ver se consulta el historial, enviar exige avisos.enviar', function () {
    $sistemas = clUsuario('sistemas', ['sucursal_principal_id' => $this->sucursal->id]);

    $this->actingAs($sistemas)->get(route('rh.avisos.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeEnviar', false));
    $this->actingAs($sistemas)->post(route('rh.avisos.store'), ['alcance' => 'todos', 'titulo' => 'Hola', 'mensaje' => 'Prueba'])->assertForbidden();

    $this->actingAs($this->rh)->get(route('rh.avisos.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeEnviar', true));
});

test('cumpleaños: generar la felicitación es una acción propia', function () {
    $this->persona->update(['fecha_nacimiento' => now()->subYears(30)->toDateString()]);
    pgpQuitar('rh_admin', 'rh.cumpleanos.generar');
    $rh = pgpRefrescar($this->rh);

    $this->actingAs($rh)->post(route('rh.cumpleanos.felicitacion.regenerar', $this->persona))->assertForbidden();
    $this->actingAs($rh)->post(route('rh.cumpleanos.felicitacion.generar', $this->persona))->assertForbidden();
});

test('bajas: cada acción de RH se quita sola y el gerente no ve la vista previa del finiquito', function () {
    $baja = Baja::query()->create([
        'folio' => 'BJ-PRUEBA-1', 'colaborador_id' => $this->persona->id, 'nombre_colaborador' => $this->persona->nombreCompleto(),
        'sucursal_id' => $this->sucursal->id, 'estatus' => EstatusBaja::EnRevisionRh, 'creado_por' => $this->rh->id,
    ]);

    expect(Gate::forUser($this->rh)->allows('cerrar', $baja))->toBeTrue();

    pgpQuitar('rh_admin', 'bajas.cerrar');
    $rh = pgpRefrescar($this->rh);

    $this->actingAs($rh)->post(route('rh.bajas.cerrar', $baja))->assertForbidden();
    expect(Gate::forUser($rh)->allows('cambiarCausal', $baja))->toBeTrue()
        ->and(app(BajaService::class)->acciones($baja, $rh))->not->toContain('cerrar')->toContain('cambiar_causal');

    // El gerente registra bajas, pero la vista previa del finiquito es de RH.
    expect(Gate::forUser($this->gerente)->allows('create', Baja::class))->toBeTrue()
        ->and(Gate::forUser($this->gerente)->allows('vistaPreviaFiniquito', Baja::class))->toBeFalse()
        ->and(Gate::forUser($this->rh)->allows('vistaPreviaFiniquito', Baja::class))->toBeTrue();
});

test('cierres: cancelar y el PDF del finiquito son acciones propias (web y API)', function () {
    $cierre = CierreLaboral::query()->create([
        'colaborador_id' => $this->persona->id, 'tipo_baja' => 'renuncia', 'motivo' => 'Prueba', 'fecha_efectiva' => now()->toDateString(), 'estado' => 'iniciado',
    ]);

    expect(Gate::forUser($this->rh)->allows('cancelar', $cierre))->toBeTrue()
        ->and(Gate::forUser($this->rh)->allows('descargarFiniquito', $cierre))->toBeTrue()
        ->and(Gate::forUser($this->gerente)->allows('subirAviso', $cierre))->toBeTrue()
        ->and(Gate::forUser($this->gerente)->allows('cancelar', $cierre))->toBeFalse();

    pgpQuitar('rh_admin', 'cierres.cancelar');
    pgpQuitar('rh_admin', 'finiquitos.descargar');
    $rh = pgpRefrescar($this->rh);

    $this->actingAs($rh)->post(route('rh.cierres.cancelar', $cierre), ['motivo' => 'No procede'])->assertForbidden();
    $this->actingAs($rh)->get(route('rh.cierres.finiquito.vistaPrevia', $cierre))->assertForbidden();
    Sanctum::actingAs($rh);
    $this->postJson("/api/v1/rh/cierres/{$cierre->id}/cancelar", ['motivo' => 'No procede'])->assertForbidden();
});

test('reingresos: decidir exige reingresos.decidir', function () {
    $reingreso = Reingreso::query()->create([
        'colaborador_id' => $this->persona->id, 'motivo' => 'Regresa', 'estado' => 'revision_rh', 'solicitado_por' => $this->gerente->id,
    ]);

    pgpQuitar('rh_admin', 'reingresos.decidir');
    $rh = pgpRefrescar($this->rh);

    expect(fn () => app(ReingresoService::class)->decidir($reingreso, $rh, true))->toThrow(AuthorizationException::class);
    $this->actingAs($rh)->post(route('rh.reingresos.decidir', $reingreso), ['viable' => true])->assertForbidden();
});

test('responsivas: descargar, firma y devolución son acciones propias', function () {
    $responsiva = Responsiva::query()->create([
        'folio' => 'RSP-PRUEBA-1', 'colaborador_id' => $this->persona->id, 'tipo' => 'bien_mueble', 'lugar' => 'Cuernavaca', 'fecha_entrega' => now()->toDateString(),
        'entregado_por' => $this->gerente->id, 'snapshot' => [], 'estado' => 'generada',
    ]);

    expect(Gate::forUser($this->gerente)->allows('descargar', $responsiva))->toBeTrue()
        ->and(Gate::forUser($this->gerente)->allows('registrarFirma', $responsiva))->toBeTrue();

    pgpQuitar('gerente_sucursal', 'responsivas.descargar');
    pgpQuitar('gerente_sucursal', 'responsivas.registrar_devolucion');
    $gerente = pgpRefrescar($this->gerente);

    $this->actingAs($gerente)->get(route('rh.responsivas.pdf', $responsiva))->assertForbidden();
    $this->actingAs($gerente)->postJson(route('rh.responsivas.devolucion', $responsiva), ['devuelta_en' => now()->toDateString()])->assertForbidden();
    expect(Gate::forUser($gerente)->allows('registrarFirma', $responsiva))->toBeTrue();
});
