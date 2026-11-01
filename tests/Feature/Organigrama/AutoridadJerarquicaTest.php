<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoIntervencionCandidato;
use App\Enums\RutaIntervencionCandidato;
use App\Models\Candidato;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\Correccion;
use App\Models\IntervencionCandidato;
use App\Models\Puesto;
use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Bajas\BajaService;
use App\Services\Organigrama\AutoridadJerarquicaService;
use App\Services\Organigrama\JefeDirectoService;
use App\Services\Organigrama\SincronizadorOrganigramaService;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Reclutamiento\IntervencionCandidatoService;
use App\Services\Solicitudes\AprobacionOrganizacionalService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Vacaciones\VacacionesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| Regla confirmada: Gerente Regional > Subgerente Regional > Gerente de Sucursal >
| Subgerente de Sucursal > Gestores. Se modifica lo de un puesto inferior o igual,
| nunca lo de uno superior. RH y Dirección quedan fuera de esta regla.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    config(['organigrama.puestos_raiz_sucursal' => ['Gerente de Sucursal']]);

    $dc = Puesto::factory()->create(['nombre' => 'Dirección Comercial', 'activo' => true]);
    $gr = Puesto::factory()->create(['nombre' => 'Gerente Regional', 'puesto_superior_id' => $dc->id, 'activo' => true]);
    $sr = Puesto::factory()->create(['nombre' => 'Subgerente Regional', 'puesto_superior_id' => $gr->id, 'activo' => true]);
    $gs = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'puesto_superior_id' => $gr->id, 'activo' => true]);
    $ss = Puesto::factory()->create(['nombre' => 'Subgerente', 'puesto_superior_id' => $gs->id, 'activo' => true]);
    $gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'puesto_superior_id' => $ss->id, 'activo' => true]);

    $this->sucursal = Sucursal::factory()->create(['es_corporativo' => false, 'activo' => true]);
    $this->otraSucursal = Sucursal::factory()->create(['es_corporativo' => false, 'activo' => true]);

    $cuenta = fn (Puesto $puesto, string $rol, ?Sucursal $sucursal = null): User => clUsuario($rol, [
        'puesto_id' => $puesto->id, 'sucursal_principal_id' => ($sucursal ?? $this->sucursal)->id, 'estatus' => 'activo', 'fecha_ingreso' => '2020-01-15',
    ]);

    // La Gerente Regional vive en OTRA sucursal: su alcance no depende de eso.
    $this->gr = $cuenta($gr, 'gerente_regional', $this->otraSucursal);
    $this->sr = $cuenta($sr, 'gerente_regional', $this->otraSucursal);
    $this->gs = $cuenta($gs, 'gerente_sucursal');
    $this->otroGs = $cuenta($gs, 'gerente_sucursal', $this->otraSucursal);
    $this->ss = $cuenta($ss, 'subgerente');
    $this->gestor = $cuenta($gestor, 'colaborador');
    $this->rh = clUsuario('rh_admin');
    $this->autoridad = app(AutoridadJerarquicaService::class);

    foreach ([$this->gr, $this->sr, $this->gs, $this->otroGs] as $usuario) {
        $usuario->givePermissionTo(['expedientes.editar', 'expedientes.ver_todos']);
    }

    $this->solicitud = fn (?User $de = null): SolicitudInterna => SolicitudInterna::factory()->create([
        'user_id' => ($de ?? $this->gestor)->id,
        'colaborador_id' => ($de ?? $this->gestor)->colaborador_id,
        'sucursal_id' => $this->sucursal->id,
        'tipo' => 'permiso_con_goce',
        'estado' => 'enviada',
    ]);

    $this->visto = fn (SolicitudInterna $s, User $autor): SolicitudAprobacion => SolicitudAprobacion::query()->create([
        'solicitud_interna_id' => $s->id, 'nivel' => SolicitudAprobacion::NIVEL_REGIONAL, 'decision' => SolicitudAprobacion::DECISION_APROBADO, 'user_id' => $autor->id,
    ]);

    $this->corregir = function (User $como, array $valores, string $base = '/correcciones') {
        $id = $this->gestor->colaborador_id;
        $huella = $this->actingAs($como)->getJson("{$base}/personal/{$id}")->assertOk()->json('data.huella');

        return $this->actingAs($como)->postJson("{$base}/personal/{$id}", [
            'valores' => $valores, 'motivo' => 'Se capturó mal el dato original.', 'huella' => $huella, 'clave_idempotencia' => (string) Str::uuid(),
        ]);
    };
});

test('el orden de la línea decide quién modifica a quién', function () {
    $a = $this->autoridad;

    expect($a->puedeModificarLoHechoPor($this->gr, $this->sr))->toBeTrue()
        ->and($a->puedeModificarLoHechoPor($this->sr, $this->gr))->toBeFalse()
        ->and($a->puedeModificarLoHechoPor($this->sr, $this->gs))->toBeTrue()
        ->and($a->puedeModificarLoHechoPor($this->gs, $this->sr))->toBeFalse()
        ->and($a->puedeModificarLoHechoPor($this->gs, $this->gr))->toBeFalse()
        ->and($a->puedeModificarLoHechoPor($this->ss, $this->gs))->toBeFalse()
        ->and($a->puedeModificarLoHechoPor($this->gestor, $this->ss))->toBeFalse()
        // Mismo puesto y autor desconocido: se puede.
        ->and($a->puedeModificarLoHechoPor($this->gs, $this->otroGs))->toBeTrue()
        ->and($a->puedeModificarLoHechoPor($this->sr, null))->toBeTrue()
        // RH fuera de la línea, en ambos sentidos.
        ->and($a->puedeModificarLoHechoPor($this->rh, $this->gr))->toBeTrue()
        ->and($a->puedeModificarLoHechoPor($this->sr, $this->rh))->toBeTrue();
});

test('el rango no depende del nombre de la persona y una cuenta de baja conserva el suyo', function () {
    $this->gr->colaborador->update(['name' => 'Otra', 'apellidos' => 'Persona']);
    expect($this->autoridad->puedeModificarLoHechoPor($this->sr, $this->gr->fresh()))->toBeFalse();

    $this->gr->colaborador->delete();
    expect($this->autoridad->puedeModificarLoHechoPor($this->sr, $this->gr->fresh()))->toBeFalse();
});

test('quien cubre una plaza regional tiene el rango de esa plaza', function () {
    CoberturaPuesto::query()->create([
        'colaborador_id' => $this->sr->colaborador_id,
        'puesto_id' => Puesto::query()->where('nombre', 'Gerente Regional')->value('id'), 'activa' => true, 'motivo' => 'baja', 'fecha_inicio' => now()->toDateString(),
    ]);

    expect($this->autoridad->puedeModificarLoHechoPor($this->sr->fresh(), $this->gr))->toBeTrue();
});

test('la autoridad sobre una persona es estricta y, bajo los regionales, por sucursal', function () {
    $a = $this->autoridad;
    $g = $this->gestor->colaborador;

    expect($a->tieneAutoridadSobre($this->gr, $this->sr->colaborador))->toBeTrue()
        ->and($a->tieneAutoridadSobre($this->gr, $g))->toBeTrue()
        ->and($a->tieneAutoridadSobre($this->sr, $this->gs->colaborador))->toBeTrue()
        ->and($a->tieneAutoridadSobre($this->sr, $g))->toBeTrue()
        ->and($a->tieneAutoridadSobre($this->sr, $this->gr->colaborador))->toBeFalse()
        ->and($a->tieneAutoridadSobre($this->gs, $g))->toBeTrue()
        ->and($a->tieneAutoridadSobre($this->otroGs, $g))->toBeFalse()
        ->and($a->tieneAutoridadSobre($this->gs, $this->otroGs->colaborador))->toBeFalse()
        ->and($a->tieneAutoridadSobre($this->gs, $this->sr->colaborador))->toBeFalse()
        ->and($a->tieneAutoridadSobre($this->rh, $g))->toBeFalse();
});

test('Gerente de Sucursal reporta a la Gerente Regional, también con Subgerente Regional vigente', function () {
    expect(SincronizadorOrganigramaService::superiores())
        ->toMatchArray(['Gerente de Sucursal' => 'Gerente Regional', 'Subgerente Regional' => 'Gerente Regional', 'Gerente Regional' => 'Dirección Comercial']);

    app(JefeDirectoService::class)->sincronizar();

    expect($this->gs->colaborador->fresh()->jefe_id)->toBe($this->gr->colaborador_id)
        ->and($this->sr->colaborador->fresh()->jefe_id)->toBe($this->gr->colaborador_id);
});

test('ambos regionales pueden dar el visto bueno de un Gerente de Sucursal', function () {
    $pasos = app(AprobacionOrganizacionalService::class)->pasos(($this->solicitud)($this->gs));

    expect($pasos[0]['usuarios']->pluck('id')->all())->toBe([$this->gr->id, $this->sr->id]);

    foreach ([$this->gr, $this->sr] as $regional) {
        $solicitud = ($this->solicitud)($this->gs);
        Sanctum::actingAs($regional);
        $this->postJson("/api/v1/equipo/solicitudes/{$solicitud->id}/visto-bueno", ['aprobado' => true])->assertOk();
        expect(SolicitudAprobacion::query()->where('solicitud_interna_id', $solicitud->id)->value('user_id'))->toBe($regional->id);
    }
});

test('el Subgerente Regional no puede dar su visto bueno a la solicitud de la Gerente Regional ni aparece como su aprobador', function () {
    $pasos = app(AprobacionOrganizacionalService::class)->pasos(($this->solicitud)($this->sr));
    $usuarios = collect($pasos)->flatMap(fn (array $p) => $p['usuarios']->pluck('id'));

    expect($usuarios)->toContain($this->gr->id)->not->toContain($this->sr->id);

    $pasos = app(AprobacionOrganizacionalService::class)->pasos(($this->solicitud)($this->gr));
    expect(collect($pasos)->flatMap(fn (array $p) => $p['usuarios']->pluck('id')))->not->toContain($this->sr->id);
});

test('Subgerente Regional no deshace una decisión de la Gerente Regional: rechazo en web y API, sin cambio en BD', function () {
    $solicitud = ($this->solicitud)();
    ($this->visto)($solicitud, $this->gr);

    $this->actingAs($this->sr)
        ->post(route('rh.solicitudes.rechazar', $solicitud), ['motivo_rechazo' => 'No procede esta solicitud.'])
        ->assertForbidden();

    Sanctum::actingAs($this->sr);
    $this->postJson("/api/v1/rh/solicitudes/{$solicitud->id}/rechazar", ['motivo' => 'No procede esta solicitud.'])
        ->assertForbidden()
        ->assertJsonPath('message', AutoridadJerarquicaService::MENSAJE_PUESTO_SUPERIOR);

    expect($solicitud->fresh()->estado->value)->toBe('enviada')
        ->and($solicitud->fresh()->motivo_rechazo)->toBeNull()
        ->and($solicitud->historial()->count())->toBe(0);

    // También en el Service, sin pasar por la Policy.
    expect(fn () => app(SolicitudesService::class)->rechazar($solicitud, $this->sr, 'Directo al servicio.'))
        ->toThrow(AuthorizationException::class, AutoridadJerarquicaService::MENSAJE_PUESTO_SUPERIOR);
    expect($solicitud->fresh()->estado->value)->toBe('enviada');
});

test('la Gerente Regional sí cambia lo que decidió el Subgerente Regional', function () {
    $web = ($this->solicitud)();
    ($this->visto)($web, $this->sr);

    $this->actingAs($this->gr)
        ->post(route('rh.solicitudes.rechazar', $web), ['motivo_rechazo' => 'No procede esta solicitud.'])
        ->assertRedirect();
    expect($web->fresh()->estado->value)->toBe('rechazada');

    $api = ($this->solicitud)();
    ($this->visto)($api, $this->sr);
    Sanctum::actingAs($this->gr);
    $this->postJson("/api/v1/rh/solicitudes/{$api->id}/rechazar", ['motivo' => 'No procede esta solicitud.'])->assertOk();
    expect($api->fresh()->estado->value)->toBe('rechazada');
});

test('Gerente de Sucursal no toca lo decidido por un regional, y mismo nivel sí dentro de su alcance', function () {
    $this->gs->givePermissionTo('solicitudes.rechazar');

    foreach ([$this->gr, $this->sr] as $regional) {
        $solicitud = ($this->solicitud)();
        ($this->visto)($solicitud, $regional);

        $this->actingAs($this->gs)
            ->post(route('rh.solicitudes.rechazar', $solicitud), ['motivo_rechazo' => 'No procede esta solicitud.'])
            ->assertForbidden();
        expect($solicitud->fresh()->estado->value)->toBe('enviada');
    }

    $solicitud = ($this->solicitud)();
    ($this->visto)($solicitud, $this->otroGs);
    $this->actingAs($this->gs)
        ->post(route('rh.solicitudes.rechazar', $solicitud), ['motivo_rechazo' => 'No procede esta solicitud.'])
        ->assertRedirect();
    expect($solicitud->fresh()->estado->value)->toBe('rechazada');
});

test('decisión sin autor conocido: no bloquea y queda anotada en el historial', function () {
    $solicitud = ($this->solicitud)();
    SolicitudAprobacion::query()->create([
        'solicitud_interna_id' => $solicitud->id, 'nivel' => SolicitudAprobacion::NIVEL_REGIONAL, 'decision' => SolicitudAprobacion::DECISION_APROBADO, 'user_id' => null,
    ]);

    $this->actingAs($this->sr)
        ->post(route('rh.solicitudes.rechazar', $solicitud), ['motivo_rechazo' => 'No procede esta solicitud.'])
        ->assertRedirect();

    expect($solicitud->fresh()->estado->value)->toBe('rechazada')
        ->and($solicitud->historial()->where('accion', 'autor_anterior_desconocido')->value('comentario'))->toBe(AutoridadJerarquicaService::NOTA_AUTOR_DESCONOCIDO);
});

test('RH sigue rechazando lo que decidió la Gerente Regional, sin nota', function () {
    $solicitud = ($this->solicitud)();
    ($this->visto)($solicitud, $this->gr);

    $this->actingAs($this->rh)
        ->post(route('rh.solicitudes.rechazar', $solicitud), ['motivo_rechazo' => 'No procede esta solicitud.'])
        ->assertRedirect();

    expect($solicitud->fresh()->estado->value)->toBe('rechazada')
        ->and($solicitud->historial()->where('accion', 'autor_anterior_desconocido')->exists())->toBeFalse();
});

test('vacaciones: Subgerente Regional no cambia lo que revisó la Gerente Regional; ella sí lo de él', function () {
    $hecha = fn (User $revisor) => SolicitudVacaciones::factory()->create([
        'user_id' => $this->gestor->id, 'estado' => 'aprobada', 'revisado_por' => $revisor->id, 'revisado_en' => now(),
    ]);

    $deGr = $hecha($this->gr);
    expect(fn () => app(VacacionesService::class)->rechazar($deGr, $this->sr, 'No procede.'))
        ->toThrow(AuthorizationException::class, AutoridadJerarquicaService::MENSAJE_PUESTO_SUPERIOR)
        ->and($deGr->fresh()->revisado_por)->toBe($this->gr->id)
        ->and($this->sr->can('rechazar', $deGr))->toBeFalse();

    $deSr = $hecha($this->sr);
    expect($this->gr->can('rechazar', $deSr))->toBeTrue();
    app(VacacionesService::class)->rechazar($deSr, $this->gr, 'No procede.');
    expect($deSr->fresh()->revisado_por)->toBe($this->gr->id);
});

test('corrección: Subgerente Regional no cambia un valor que registró la Gerente Regional, en web ni API', function () {
    ($this->corregir)($this->gr, ['telefono' => '5511112222'])->assertCreated();

    foreach (['/correcciones', '/api/v1/rh/correcciones'] as $base) {
        ($this->corregir)($this->sr, ['telefono' => '5533334444'], $base)
            ->assertForbidden()
            ->assertJsonPath('message', AutoridadJerarquicaService::MENSAJE_PUESTO_SUPERIOR);
    }

    expect($this->gestor->colaborador->fresh()->telefono)->toBe('5511112222')
        ->and(Correccion::query()->count())->toBe(1);

    // La vista previa tampoco lo deja avanzar.
    $this->actingAs($this->gs)
        ->postJson("/correcciones/personal/{$this->gestor->colaborador_id}/vista-previa", ['valores' => ['telefono' => '5533334444']])
        ->assertForbidden();
});

test('corrección: la Gerente Regional cambia lo del Subgerente Regional, RH lo de ambos y sin autor queda anotado', function () {
    // Sin autor conocido: pasa con nota.
    ($this->corregir)($this->sr, ['telefono' => '5511112222'])->assertCreated();
    expect(collect(Correccion::query()->latest('id')->first()->afectados)->pluck('detalle'))->toContain(AutoridadJerarquicaService::NOTA_AUTOR_DESCONOCIDO);

    ($this->corregir)($this->gr, ['telefono' => '5533334444'])->assertCreated();
    expect($this->gestor->colaborador->fresh()->telefono)->toBe('5533334444');

    // Gerente de Sucursal no toca lo que dejó la regional.
    ($this->corregir)($this->otroGs, ['telefono' => '5533334445'])->assertForbidden();

    // RH, sin restricción ni nota.
    ($this->corregir)($this->rh, ['telefono' => '5555556666'])->assertCreated();
    expect(collect(Correccion::query()->latest('id')->first()->afectados)->pluck('detalle'))->not->toContain(AutoridadJerarquicaService::NOTA_AUTOR_DESCONOCIDO);
});

test('corrección: el autor también sale de la bitácora cuando no hay corrección previa', function () {
    $this->actingAs($this->gr);
    $this->gestor->colaborador->update(['apellidos' => 'Captura Regional']);

    ($this->corregir)($this->sr, ['apellidos' => 'Otro Apellido'])->assertForbidden();
    expect($this->gestor->colaborador->fresh()->apellidos)->toBe('Captura Regional');
});

test('bajas: los regionales alcanzan a toda la línea de abajo; nunca hacia arriba aunque compartan sucursal', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $bajas = app(BajaService::class);

    expect($bajas->puedeSolicitarPara($this->gr, $this->sr->colaborador))->toBeTrue()
        ->and($bajas->puedeSolicitarPara($this->gr, $this->gestor->colaborador))->toBeTrue()
        ->and($bajas->puedeSolicitarPara($this->sr, $this->gestor->colaborador))->toBeTrue()
        ->and($bajas->puedeSolicitarPara($this->sr, $this->gr->colaborador))->toBeFalse();

    // Comparten sucursal: el alcance no le alcanza para subir.
    $this->otroGs->givePermissionTo('bajas.solicitar');
    expect($bajas->puedeSolicitarPara($this->otroGs, $this->gr->colaborador))->toBeFalse()
        ->and($this->otroGs->can('iniciarCierre', $this->sr->colaborador))->toBeFalse()
        ->and($bajas->puedeSolicitarPara($this->rh, $this->gr->colaborador))->toBeTrue();
});

test('intervención de Gestor: deciden la Gerente Regional y el Subgerente Regional vigente; ajenos no', function () {
    $puesto = Puesto::query()->where('nombre', 'Gestor')->firstOrFail();
    $puesto->update(['grupo_indicador' => 'gestores']);
    $crear = function () use ($puesto): IntervencionCandidato {
        $candidato = Candidato::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_objetivo_id' => $puesto->id, 'estado' => EstadoCandidato::RechazadoRh->value]);

        return IntervencionCandidato::query()->create([
            'candidato_id' => $candidato->id, 'ruta' => RutaIntervencionCandidato::Regional, 'gerente_solicitante_id' => $this->gs->id,
            'motivo_solicitud' => 'Reconsiderar.', 'solicitada_en' => now(), 'estado' => EstadoIntervencionCandidato::Pendiente,
        ]);
    };

    foreach ([$this->gr, $this->sr] as $regional) {
        $intervencion = $crear();
        expect(app(IntervencionCandidatoService::class)->decidir($intervencion, $regional, false, null)->estado)->toBe(EstadoIntervencionCandidato::RechazoConfirmado);
    }

    $ajeno = $this->gs;
    $ajeno->givePermissionTo('candidatos.intervencion_decidir');
    expect(fn () => app(IntervencionCandidatoService::class)->decidir($crear(), $ajeno, true, null))->toThrow(AuthorizationException::class);
});

test('candidato: descartar respeta quién hizo el último avance', function () {
    $candidato = Candidato::factory()->create(['sucursal_id' => $this->sucursal->id, 'estado' => EstadoCandidato::EntrevistaPendiente->value]);
    $candidato->seguimientos()->create(['tipo' => 'cambio_estado', 'nota' => 'Avance', 'fecha' => now(), 'registrado_por' => $this->gr->id]);

    $this->sr->givePermissionTo('candidatos.cerrar_proceso');
    expect(fn () => app(CandidatoWorkflowService::class)->descartar($candidato, $this->sr, EstadoCandidato::NoViable, 'No procede.'))
        ->toThrow(AuthorizationException::class, AutoridadJerarquicaService::MENSAJE_PUESTO_SUPERIOR)
        ->and($candidato->fresh()->estado)->toBe(EstadoCandidato::EntrevistaPendiente);
});

test('ningún proceso renombra ni reemplaza a la Gerente Regional', function () {
    $persona = $this->gr->colaborador->only(['name', 'apellidos', 'puesto_id']);

    app(SincronizadorOrganigramaService::class)->sincronizar();
    app(JefeDirectoService::class)->sincronizar();

    expect(Colaborador::query()->whereKey($this->gr->colaborador_id)->first()->only(['name', 'apellidos', 'puesto_id']))->toBe($persona);
});

test('la migración corrige una base con la cadena anterior, es idempotente y reporta sin inventar personas', function () {
    Log::spy();
    $gs = Puesto::query()->where('nombre', 'Gerente de Sucursal')->firstOrFail();
    $gs->update(['puesto_superior_id' => Puesto::query()->where('nombre', 'Subgerente Regional')->value('id')]);
    // Segunda titular: se reporta, no se elige a nadie.
    Colaborador::factory()->create(['puesto_id' => Puesto::query()->where('nombre', 'Gerente Regional')->value('id'), 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => 'activo']);
    $personas = Colaborador::withTrashed()->count();
    $migracion = require database_path('migrations/2026_11_04_200000_gerente_de_sucursal_reporta_a_gerente_regional.php');

    $migracion->up();
    $migracion->up();

    expect($gs->fresh()->puestoSuperior?->nombre)->toBe('Gerente Regional')
        ->and(Colaborador::withTrashed()->count())->toBe($personas)
        ->and(Colaborador::query()->whereKey($this->gr->colaborador_id)->value('estatus'))->not->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $mensaje, array $contexto): bool => str_contains($contexto['conflicto'] ?? '', '«Gerente Regional»: 2 titulares'));
});
