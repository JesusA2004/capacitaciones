<?php

use App\Enums\TipoNodoComercial;
use App\Models\ClasificacionRegion;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\ResponsableAprobacion;
use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Organigrama\JefeDirectoService;
use App\Services\Solicitudes\AprobacionOrganizacionalService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
| Motor central de aprobaciones (docs/SOLICITUDES_UNIFICADAS.md → «Motor de
| aprobaciones»). Punto 19 del pedido del 20-oct-2026:
|  A) < 6 meses no puede pedir préstamo (web y API, 422).
|  B) exactamente 6 meses sí.
|  C) RH entra antes que el jefe: ve el estado y NO puede aprobar.
|  D) el jefe aprueba → RH recibe aviso → RH ya puede aprobar.
|  E) Gerente de Sucursal → su regional Q1/Q3 (clasificación), nunca
|     Dirección Comercial directo; sin clasificación → detenido.
|  F) responsable explícito ≠ jefe organizacional → manda el explícito.
| Más el contrato de la API (flujo, puede_actuar, elegibilidad).
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00'));
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    config(['organigrama.puestos_raiz_sucursal' => ['Gerente de Sucursal']]);
    Sucursal::query()->update(['activo' => false]);

    $this->puestoDc = Puesto::factory()->create(['nombre' => 'Dirección Comercial', 'nivel_jerarquico' => 2, 'activo' => true]);
    $this->puestoQ1 = Puesto::factory()->create(['nombre' => 'Gerente Regional Q1', 'nivel_jerarquico' => 3, 'puesto_superior_id' => $this->puestoDc->id, 'activo' => true]);
    $this->puestoQ3 = Puesto::factory()->create(['nombre' => 'Gerente Regional Q3', 'nivel_jerarquico' => 3, 'puesto_superior_id' => $this->puestoDc->id, 'activo' => true]);
    $this->puestoGerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'nivel_jerarquico' => 4, 'puesto_superior_id' => $this->puestoQ1->id, 'activo' => true]);
    $this->puestoGestor = Puesto::factory()->create(['nombre' => 'Gestor', 'nivel_jerarquico' => 6, 'puesto_superior_id' => $this->puestoGerente->id, 'activo' => true]);

    $this->cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'activo' => true, 'es_corporativo' => false]);
    $this->cordoba = Sucursal::factory()->create(['nombre' => 'Córdoba', 'activo' => true, 'es_corporativo' => false]);

    $matriz = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Matriz->value, 'nombre' => 'MATRIZ']);
    $this->q1 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q1', 'region' => 'Q1', 'parent_id' => $matriz->id, 'puesto_id' => $this->puestoQ1->id]);
    $this->q3 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q3', 'region' => 'Q3', 'parent_id' => $matriz->id, 'puesto_id' => $this->puestoQ3->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CUERNAVACA', 'parent_id' => $this->q1->id, 'sucursal_id' => $this->cuernavaca->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CORDOBA', 'parent_id' => $this->q3->id, 'sucursal_id' => $this->cordoba->id]);

    $cuenta = function (string $nombre, Puesto $puesto, Sucursal $sucursal, string $rol, string $ingreso = '2020-01-15'): User {
        $persona = Colaborador::factory()->create([
            'name' => $nombre, 'apellidos' => 'Prueba', 'estatus' => 'activo', 'fecha_ingreso' => $ingreso,
            'puesto_id' => $puesto->id, 'sucursal_principal_id' => $sucursal->id, 'jefe_id' => null,
        ]);
        $usuario = User::factory()->create(['colaborador_id' => $persona->id]);
        $usuario->assignRole($rol);

        return $usuario;
    };

    $this->dc = $cuenta('Alberto', $this->puestoDc, $this->cuernavaca, 'director_comercial');
    $this->regionalQ1 = $cuenta('Regional Uno', $this->puestoQ1, $this->cuernavaca, 'gerente_regional');
    $this->regionalQ3 = $cuenta('Regional Tres', $this->puestoQ3, $this->cordoba, 'gerente_regional');
    $this->gerente = $cuenta('Gerente Cuernavaca', $this->puestoGerente, $this->cuernavaca, 'gerente_sucursal');
    $this->gestor = $cuenta('Gestor Cuernavaca', $this->puestoGestor, $this->cuernavaca, 'colaborador');

    app(JefeDirectoService::class)->sincronizar();

    $this->rh = clUsuario('rh_admin');
    $this->motor = app(AprobacionOrganizacionalService::class);
});

afterEach(fn () => Carbon::setTestNow());

function maSolicitud(User $cuenta, string $tipo = 'permiso_con_goce'): SolicitudInterna
{
    return SolicitudInterna::factory()->create([
        'user_id' => $cuenta->id,
        'colaborador_id' => $cuenta->colaborador_id,
        'sucursal_id' => $cuenta->colaborador?->sucursal_principal_id,
        'tipo' => $tipo,
        'estado' => 'enviada',
        'monto_solicitado' => $tipo === 'prestamo' ? 5000 : null,
    ]);
}

// ---------------------------------------------------------------- A / B

test('A) con menos de 6 meses de antigüedad no se crea el préstamo: 422 en API y error en web, con la fecha exacta', function () {
    $this->gestor->colaborador->update(['fecha_ingreso' => '2026-05-08']);

    Sanctum::actingAs($this->gestor);
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Gastos'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('monto_solicitado')
        ->assertJsonFragment(['monto_solicitado' => ['Necesitas mínimo 6 meses de antigüedad para solicitar un préstamo. Podrás solicitarlo a partir del 08/11/2026.']]);

    $this->actingAs($this->gestor)
        ->post(route('solicitudes.store'), [
            'tipo' => 'prestamo', 'motivo' => 'Gastos', 'observaciones' => '', 'fecha_inicio' => '', 'duracion_dias' => '',
            'dias' => [], 'monto_solicitado' => '5000', 'colaborador_objetivo_id' => '', 'fecha_efectiva' => '', 'tipo_baja' => '',
            'permiso_tipo' => '', 'permiso_goce' => '', 'permiso_causal' => '', 'hora_salida' => '', 'hora_entrada' => '',
        ])
        ->assertSessionHasErrors('monto_solicitado');

    expect(SolicitudInterna::query()->where('tipo', 'prestamo')->count())->toBe(0);

    // La app lee la elegibilidad del servidor (nunca la fecha del teléfono).
    Sanctum::actingAs($this->gestor);
    $this->getJson('/api/v1/solicitudes/prestamo/elegibilidad')
        ->assertOk()
        ->assertJsonPath('data.cumple', false)
        ->assertJsonPath('data.antiguedad_texto', '5 meses 12 días')
        ->assertJsonPath('data.disponible_desde', '2026-11-08')
        ->assertJsonPath('data.disponible_desde_texto', '08/11/2026')
        ->assertJsonPath('data.fecha_servidor', '2026-10-20');

    $tipos = collect($this->getJson('/api/v1/solicitudes/configuracion')->assertOk()->json('tipos'))->keyBy('clave');
    expect($tipos['prestamo']['disponible'])->toBeFalse()
        ->and($tipos['prestamo']['elegibilidad']['mensaje'])->toContain('08/11/2026');

    // Web: la pantalla recibe la misma elegibilidad para deshabilitar el botón.
    $this->actingAs($this->gestor)->get(route('solicitudes.index'))
        ->assertInertia(fn ($page) => $page->where('elegibilidadPrestamo.cumple', false)->where('elegibilidadPrestamo.disponible_desde', '2026-11-08'));
});

test('B) con exactamente 6 meses completos sí se crea (ingreso 20/04/2026 → hoy 20/10/2026)', function () {
    $this->gestor->colaborador->update(['fecha_ingreso' => '2026-04-20']);

    Sanctum::actingAs($this->gestor);
    $this->getJson('/api/v1/solicitudes/prestamo/elegibilidad')->assertJsonPath('data.cumple', true)->assertJsonPath('data.antiguedad_texto', '6 meses');
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Gastos'])->assertCreated();

    // Un día antes NO: ingreso 21/04/2026 → disponible el 21/10/2026.
    $otro = Colaborador::factory()->create(['fecha_ingreso' => '2026-04-21']);
    $elegibilidad = $this->motor->elegibilidadPrestamo($otro);
    expect($elegibilidad['cumple'])->toBeFalse()->and($elegibilidad['disponible_desde'])->toBe('2026-10-21');

    // Ejemplo del pedido: ingreso 20/10/2026 → disponible 20/04/2027.
    $nuevo = Colaborador::factory()->create(['fecha_ingreso' => '2026-10-20']);
    expect($this->motor->elegibilidadPrestamo($nuevo)['disponible_desde_texto'])->toBe('20/04/2027');
});

test('B2) con 4 meses de antigüedad se rechaza con el mensaje y la fecha en que podrá solicitarlo; el payload lo refleja', function () {
    $this->gestor->colaborador->update(['fecha_ingreso' => '2026-06-20']);

    Sanctum::actingAs($this->gestor);
    $this->getJson('/api/v1/solicitudes/prestamo/elegibilidad')
        ->assertOk()
        ->assertJsonPath('data.cumple', false)
        ->assertJsonPath('data.meses_minimos', 6)
        ->assertJsonPath('data.antiguedad_meses', 4)
        ->assertJsonPath('data.disponible_desde', '2026-12-20')
        ->assertJsonPath('data.mensaje', 'Necesitas mínimo 6 meses de antigüedad para solicitar un préstamo. Podrás solicitarlo a partir del 20/12/2026.');

    $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Gastos'])
        ->assertUnprocessable()
        ->assertJsonFragment(['monto_solicitado' => ['Necesitas mínimo 6 meses de antigüedad para solicitar un préstamo. Podrás solicitarlo a partir del 20/12/2026.']]);

    expect(SolicitudInterna::query()->where('tipo', 'prestamo')->count())->toBe(0);
});

test('B3) sin fecha de ingreso no es elegible: se rechaza y se le pide acudir a RH', function () {
    $this->gestor->colaborador->update(['fecha_ingreso' => null]);

    Sanctum::actingAs($this->gestor);
    $this->getJson('/api/v1/solicitudes/prestamo/elegibilidad')
        ->assertOk()
        ->assertJsonPath('data.cumple', false)
        ->assertJsonPath('data.antiguedad_meses', 0)
        ->assertJsonPath('data.disponible_desde', null)
        ->assertJsonPath('data.mensaje', 'Necesitas mínimo 6 meses de antigüedad para solicitar un préstamo. No tenemos registrada tu fecha de ingreso; pide a RH que la capture en tu expediente.');

    $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Gastos'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('monto_solicitado');

    expect(SolicitudInterna::query()->where('tipo', 'prestamo')->count())->toBe(0);
});

test('B4) el mínimo sale de config solicitudes.prestamo_meses_minimos', function () {
    config(['solicitudes.prestamo_meses_minimos' => 3]);
    $this->gestor->colaborador->update(['fecha_ingreso' => '2026-06-20']);

    $elegibilidad = $this->motor->elegibilidadPrestamo($this->gestor->colaborador->fresh());
    expect($elegibilidad['cumple'])->toBeTrue()
        ->and($elegibilidad['meses_minimos'])->toBe(3)
        ->and($elegibilidad['antiguedad_meses'])->toBe(4);
});

// ---------------------------------------------------------------- C / D

test('C) RH entra antes que el jefe: ve «pendiente de» y NO puede aprobar (ni web ni API); nadie le avisó todavía', function () {
    ClasificacionRegion::query()->create(['colaborador_id' => $this->gerente->colaborador_id, 'region_id' => $this->q1->id]);

    Sanctum::actingAs($this->gestor);
    $id = $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Gastos'])->assertCreated()->json('id');
    $solicitud = SolicitudInterna::query()->findOrFail($id);

    // Solo a quien puede actuar: el gerente sí tiene aviso; RH y el regional, no.
    expect($this->gerente->notifications()->count())->toBeGreaterThan(0)
        ->and($this->rh->notifications()->count())->toBe(0)
        ->and($this->regionalQ1->notifications()->count())->toBe(0);

    Sanctum::actingAs($this->rh);
    $this->getJson("/api/v1/rh/solicitudes/{$id}")
        ->assertOk()
        ->assertJsonPath('data.puede_actuar_usuario_actual', false)
        ->assertJsonPath('data.pendiente_de.texto', 'Pendiente de aprobación de: Gerente Cuernavaca Prueba')
        ->assertJsonPath('data.motivo_no_puede', 'RH podrá aprobar cuando Gerente Cuernavaca Prueba dé su visto bueno.');

    $this->postJson("/api/v1/rh/solicitudes/{$id}/prestamo/autorizar", ['monto_autorizado' => 5000, 'plazo_autorizado' => 10])
        ->assertStatus(422);

    $this->actingAs($this->rh)->get(route('rh.solicitudes.show', $solicitud))
        ->assertInertia(fn ($page) => $page
            ->where('puedeAprobar', false)
            ->where('motivoAprobarEnEspera', 'RH podrá aprobar cuando Gerente Cuernavaca Prueba dé su visto bueno.')
            ->where('flujo.pasos.0.clave', 'creada')
            ->where('flujo.pasos.1.estado', 'pendiente')
            ->where('flujo.pasos.2.estado', 'esperando')
            ->where('flujo.pasos.3.clave', 'rh')
            ->where('flujo.pasos.3.estado', 'esperando'));

    expect($solicitud->fresh()->estado->value)->toBe('enviada');
});

test('badge web «Pendientes»: el mismo conteo único — al jefe le cuenta su visto bueno; a RH nada mientras espera', function () {
    ClasificacionRegion::query()->create(['colaborador_id' => $this->gerente->colaborador_id, 'region_id' => $this->q3->id]);

    Sanctum::actingAs($this->gestor);
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Gastos'])->assertCreated();

    $this->actingAs($this->gerente)->get('/ayuda')
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina->where('aprobacionesPendientes', 1));

    $this->actingAs($this->rh)->get('/ayuda')
        ->assertInertia(fn (AssertableInertia $pagina) => $pagina->where('aprobacionesPendientes', 0));
});

test('D) el jefe aprueba → avisa al regional; el regional aprueba → RH recibe aviso y ya puede autorizar', function () {
    ClasificacionRegion::query()->create(['colaborador_id' => $this->gerente->colaborador_id, 'region_id' => $this->q3->id]);

    Sanctum::actingAs($this->gestor);
    $id = $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Gastos'])->assertCreated()->json('id');

    // El regional (Q3, por la clasificación del gerente de su sucursal) aún no puede.
    Sanctum::actingAs($this->regionalQ3);
    $this->getJson('/api/v1/equipo/pendientes')->assertOk()
        ->assertJsonCount(0, 'data.solicitudes')
        ->assertJsonPath('data.en_espera.0.id', $id)
        ->assertJsonPath('data.en_espera.0.puede_actuar_usuario_actual', false)
        ->assertJsonPath('data.en_espera.0.motivo_no_puede', 'Podrás dar tu visto bueno cuando Gerente Cuernavaca Prueba dé el suyo.');
    $this->postJson("/api/v1/equipo/solicitudes/{$id}/visto-bueno", ['aprobado' => true])->assertUnprocessable();

    Sanctum::actingAs($this->gerente);
    $this->getJson('/api/v1/equipo/pendientes')->assertJsonPath('data.solicitudes.0.puede_actuar_usuario_actual', true);
    $this->postJson("/api/v1/equipo/solicitudes/{$id}/visto-bueno", ['aprobado' => true])->assertOk();

    Sanctum::actingAs($this->regionalQ3);
    $this->getJson('/api/v1/equipo/aprobaciones/conteo')->assertJsonPath('data.visto_bueno', 1)->assertJsonPath('data.autorizar', 0);
    expect($this->motor->conteoPendientes($this->rh)['total'])->toBe(0);

    expect($this->regionalQ3->notifications()->count())->toBeGreaterThan(0)
        ->and($this->rh->notifications()->count())->toBe(0)
        // Al solicitante: «Tu solicitud avanzó».
        ->and($this->gestor->notifications()->get()->contains(fn ($n) => ($n->data['titulo'] ?? null) === 'Tu solicitud avanzó'))->toBeTrue();

    Sanctum::actingAs($this->regionalQ3);
    $this->postJson("/api/v1/equipo/solicitudes/{$id}/visto-bueno", ['aprobado' => true])->assertOk();

    expect($this->rh->notifications()->count())->toBeGreaterThan(0)
        // Conteo único web/app: al regional ya no le queda nada; a RH, autorizar 1.
        ->and($this->motor->conteoPendientes($this->regionalQ3)['total'])->toBe(0)
        ->and($this->motor->conteoPendientes($this->rh))->toBe(['visto_bueno' => 0, 'autorizar' => 1, 'total' => 1]);

    Sanctum::actingAs($this->rh);
    $this->getJson("/api/v1/rh/solicitudes/{$id}")
        ->assertJsonPath('data.puede_actuar_usuario_actual', true)
        ->assertJsonPath('data.accion_usuario_actual', 'autorizar_prestamo')
        ->assertJsonPath('data.flujo.1.estado', 'aprobado')
        ->assertJsonPath('data.flujo.2.estado', 'aprobado')
        ->assertJsonPath('data.flujo.3.estado', 'pendiente');
});

// ---------------------------------------------------------------- E

test('E) Gerente de Sucursal → su regional Q1/Q3 de la clasificación (no Dirección Comercial); sin clasificar queda detenido', function () {
    $gerente = $this->gerente->colaborador;

    // Sin clasificación: paso bloqueado, nadie puede darlo y RH no aprueba.
    $responsable = $this->motor->responsableDeAprobacion($gerente);
    expect($responsable['bloqueado'])->toBeTrue()
        ->and($responsable['origen'])->toBe(AprobacionOrganizacionalService::ORIGEN_PENDIENTE_REGION)
        ->and($responsable['usuarios'])->toBeEmpty();

    $solicitud = maSolicitud($this->gerente);
    $flujo = $this->motor->flujo($solicitud, $this->rh);
    expect($flujo['bloqueado'])->toBeTrue()
        ->and($flujo['pendiente_de']['texto'])->toBe('Pendiente de asignar región de aprobación')
        ->and($flujo['puede_actuar_usuario_actual'])->toBeFalse();

    $this->actingAs($this->rh)->post(route('rh.solicitudes.aprobar', $solicitud))->assertSessionHasErrors('visto_bueno');

    // Clasificado en Q3 (aunque la matriz diga Q1 para Cuernavaca): su regional Q3.
    $this->actingAs($this->rh)
        ->put(route('administracion.jerarquia-puestos.personas.clasificar', $gerente), ['region_id' => $this->q3->id])
        ->assertSessionHasNoErrors();

    $pasos = $this->motor->pasos($solicitud->fresh());
    expect($pasos)->toHaveCount(1)
        ->and($pasos[0]['nivel'])->toBe(SolicitudAprobacion::NIVEL_REGIONAL)
        ->and($pasos[0]['etiqueta'])->toBe('Gerente Regional Q3')
        ->and($pasos[0]['usuarios']->pluck('id')->all())->toBe([$this->regionalQ3->id])
        // Vacaciones/permisos de gerente: nunca Dirección Comercial.
        ->and(collect($pasos)->pluck('nivel')->all())->not->toContain(SolicitudAprobacion::NIVEL_DIRECCION_COMERCIAL);

    // Al clasificarlo, el regional que ya puede actuar recibe su aviso.
    expect($this->regionalQ3->notifications()->count())->toBeGreaterThan(0);

    // Préstamo de gerente: regional y DESPUÉS Dirección Comercial (el tipo lo exige).
    $prestamo = maSolicitud($this->gerente, 'prestamo');
    expect(collect($this->motor->pasos($prestamo))->pluck('nivel')->all())
        ->toBe([SolicitudAprobacion::NIVEL_REGIONAL, SolicitudAprobacion::NIVEL_DIRECCION_COMERCIAL]);

    // Plaza regional Q3 vacante y sin cobertura: la regional de respaldo
    // (la otra regional vigente), nunca Dirección Comercial.
    $this->regionalQ3->colaborador->update(['estatus' => 'inactivo']);
    $respaldo = $this->motor->responsableDeAprobacion($gerente->fresh());
    expect($respaldo['bloqueado'])->toBeFalse()
        ->and($respaldo['origen'])->toBe(AprobacionOrganizacionalService::ORIGEN_REGIONAL_RESPALDO)
        ->and($respaldo['usuarios']->pluck('id')->all())->toBe([$this->regionalQ1->id]);

    // Sin NINGUNA regional vigente: detenido con su motivo (no sube a Dirección Comercial).
    $this->regionalQ1->colaborador->update(['estatus' => 'inactivo']);
    $vacante = $this->motor->responsableDeAprobacion($gerente->fresh());
    expect($vacante['bloqueado'])->toBeTrue()
        ->and($vacante['origen'])->toBe(AprobacionOrganizacionalService::ORIGEN_REGIONAL_VACANTE)
        ->and($vacante['usuarios']->pluck('id')->all())->not->toContain($this->dc->id);
});

// ---------------------------------------------------------------- F

test('F) responsable explícito distinto del jefe organizacional: manda el explícito, queda auditado y no cambia el organigrama', function () {
    // Recién sincronizado: su jefe organizacional sale del organigrama.
    $gestor = $this->gestor->colaborador->fresh();
    $jefeAntes = $gestor->jefe_id;

    expect($this->motor->responsableDeAprobacion($gestor)['usuarios']->pluck('id')->all())->toBe([$this->gerente->id]);

    $this->actingAs($this->rh)
        ->post(route('administracion.responsables-aprobacion.store'), [
            'colaborador_id' => $gestor->id,
            'responsable_colaborador_id' => $this->dc->colaborador_id,
            'motivo' => 'Alberto es su jefe operativo.',
        ])
        ->assertSessionHasNoErrors();

    $resumen = $this->motor->resumenResponsables($gestor);
    expect($resumen['responsable_aprobaciones']['id'])->toBe($this->dc->colaborador_id)
        ->and($resumen['jefe_organizacional']['id'])->toBe($this->gerente->colaborador_id)
        ->and($resumen['distinto_del_jefe'])->toBeTrue()
        ->and($resumen['origen'])->toBe(AprobacionOrganizacionalService::ORIGEN_EXPLICITO)
        ->and($gestor->fresh()->jefe_id)->toBe($jefeAntes);

    $pasos = $this->motor->pasos(maSolicitud($this->gestor));
    expect($pasos[0]['usuarios']->pluck('id')->all())->toBe([$this->dc->id])
        ->and($pasos[0]['etiqueta'])->toBe('Responsable de aprobación');

    $this->assertDatabaseHas('activity_log', ['log_name' => 'organigrama', 'event' => 'responsable_aprobacion_asignado', 'subject_id' => $gestor->id]);

    // No se aprueban entre sí: el gerente no puede quedar aprobado por su propio aprobado.
    $this->actingAs($this->rh)
        ->post(route('administracion.responsables-aprobacion.store'), [
            'colaborador_id' => $this->dc->colaborador_id,
            'responsable_colaborador_id' => $gestor->id,
            'motivo' => 'Prueba de ciclo.',
        ])
        ->assertSessionHasErrors('responsable_colaborador_id');

    // Quitarlo regresa a la regla del organigrama.
    $regla = ResponsableAprobacion::query()->sole();
    $this->actingAs($this->rh)->delete(route('administracion.responsables-aprobacion.destroy', $regla))->assertSessionHasNoErrors();
    expect($this->motor->responsableDeAprobacion($gestor)['usuarios']->pluck('id')->all())->toBe([$this->gerente->id]);

    // Solo organigrama.editar puede asignar.
    $this->actingAs($this->gestor)
        ->post(route('administracion.responsables-aprobacion.store'), ['colaborador_id' => $gestor->id, 'responsable_colaborador_id' => $this->dc->colaborador_id, 'motivo' => 'Sin permiso.'])
        ->assertForbidden();
});

// ---------------------------------------------------------------- Contrato API

test('contrato API: el colaborador ve el flujo de su solicitud con pasos, responsables y quién sigue', function () {
    ClasificacionRegion::query()->create(['colaborador_id' => $this->gerente->colaborador_id, 'region_id' => $this->q1->id]);
    $solicitud = maSolicitud($this->gestor);

    Sanctum::actingAs($this->gestor);
    $respuesta = $this->getJson("/api/v1/solicitudes/{$solicitud->id}")->assertOk();

    $respuesta->assertJsonPath('puede_actuar_usuario_actual', false)
        ->assertJsonPath('pendiente_de.nombres.0', 'Gerente Cuernavaca Prueba')
        ->assertJsonPath('estado_flujo_texto', 'Pendiente de aprobación de: Gerente Cuernavaca Prueba')
        ->assertJsonStructure([
            'flujo' => [['orden', 'clave', 'rol', 'etiqueta', 'responsable', 'responsables', 'estado', 'estado_etiqueta', 'fecha', 'comentario', 'decidio', 'bloqueado', 'puede_actuar', 'motivo_no_puede']],
            'pendiente_de' => ['clave', 'etiqueta', 'nombres', 'texto'],
            'motivo_no_puede',
        ]);

    $flujo = $respuesta->json('flujo');
    expect(collect($flujo)->pluck('estado')->all())->toBe(['aprobado', 'pendiente', 'esperando', 'esperando'])
        ->and($flujo[1]['responsable']['nombre'])->toBe('Gerente Cuernavaca Prueba')
        ->and($flujo[2]['etiqueta'])->toBe('Gerente Regional')
        ->and($flujo[2]['responsable']['nombre'])->toBe('Regional Uno Prueba')
        ->and($flujo[3]['clave'])->toBe('rh');
});

test('contrato API: una corrección de datos va directo a Recursos Humanos (sin vistos buenos)', function () {
    $solicitud = maSolicitud($this->gestor, 'actualizacion_datos');

    $flujo = $this->motor->flujo($solicitud, $this->rh);

    expect(collect($flujo['pasos'])->pluck('clave')->all())->toBe(['creada', 'rh'])
        ->and($flujo['pasos'][1]['estado'])->toBe('pendiente')
        ->and($flujo['pendiente_de']['texto'])->toBe('Pendiente de autorización de Recursos Humanos')
        ->and($flujo['puede_actuar_usuario_actual'])->toBeTrue()
        ->and($flujo['accion_usuario_actual'])->toBe('aprobar');
});
