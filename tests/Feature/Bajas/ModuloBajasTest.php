<?php

use App\Enums\AccionBitacoraBaja;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoFiniquito;
use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Enums\Recontratable;
use App\Models\Baja;
use App\Models\BajaBitacora;
use App\Models\Candidato;
use App\Models\CausalRotacion;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\DocumentoRequeridoBaja;
use App\Models\FiniquitoCalculo;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Notifications\Bajas\AlertaBajaCorreoNotification;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\Bajas\AntecedenteBajaService;
use App\Services\Bajas\BajaService;
use App\Services\Bajas\CatalogoBajasService;
use App\Services\CierreLaboral\CierreLaboralService;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Módulo «Bajas» (especificación de RH v1.0, flujo DIRECTO del 2026-10-20):
| la baja no es una solicitud ni pasa por aprobación. El gerente (de su
| personal) o RH la REGISTRA y queda aplicada de inmediato —cierre laboral,
| acceso suspendido, documentos de la causa, finiquito propuesto y avisos—;
| después: documentos, Jurídico si la causal lo requiere y el cierre por RH
| con los documentos obligatorios completos. Criterios de aceptación 1–6 y
| 8, reglas RN-02/04/07/08/09/10/11/13 y quién accede.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->estructura = clEstructura();
    $sucursal = $this->estructura['sucursal'];
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $sucursal->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $sucursal->id]);
    $this->juridico = clUsuario('juridico', ['sucursal_principal_id' => $sucursal->id]);
    $this->contraloria = clUsuario('contraloria', ['sucursal_principal_id' => $sucursal->id]);
    $this->direccion = clUsuario('direccion', ['sucursal_principal_id' => $sucursal->id]);
    $this->sistemas = clUsuario('sistemas', ['sucursal_principal_id' => $sucursal->id]);
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'jefe_id' => $this->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2024-01-01',
        'sueldo_mensual' => 12000,
    ]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->persona->id]);
    $this->cuenta->assignRole('colaborador');
    $this->cuenta->createToken('app');
});

function bjCausal(string $clave): CausalRotacion
{
    return CausalRotacion::query()->where('clave', $clave)->firstOrFail();
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function bjDatos(string $clave, array $extra = []): array
{
    $causal = bjCausal($clave);

    return [
        'forma_salida' => $causal->forma()->value,
        'causal_rotacion_id' => $causal->id,
        'fecha_baja' => now()->subDay()->toDateString(),
        'recontratable' => 'si',
        'comentarios' => 'La persona presentó su renuncia por escrito con su jefe.',
        ...$extra,
    ];
}

/** Datos completos de una rescisión (RN-07). */
function bjRescision(string $clave, array $extra = []): array
{
    return [
        'recontratable' => 'no',
        'comentarios' => 'Se documentó la falta con el reporte de la sucursal.',
        'fecha_conocimiento_falta' => now()->subDays(5)->toDateString(),
        'entrega_aviso_rescision' => 'personal',
        'fecha_entrega_aviso' => now()->subDay()->toDateString(),
        ...$extra,
    ];
}

function bjAdjuntarTodos(Baja $baja, User $quien): void
{
    $baja->refresh()->load('causal');

    foreach (app(CatalogoBajasService::class)->documentosRequeridos($baja->forma_salida, $baja->causal) as $requerido) {
        test()->actingAs($quien)
            ->post(route('rh.bajas.documentos.store', $baja), [
                'archivo' => UploadedFile::fake()->create(sprintf('%s.pdf', $requerido->clave), 30, 'application/pdf'),
                'documento_requerido_id' => $requerido->id,
            ])
            ->assertSessionHasNoErrors();
    }
}

/**
 * Finiquito del cierre: el propuesto al registrar, revisado (la baja se
 * ejecuta al cerrarla) o entregado (la entrega ya ejecutó la baja laboral).
 */
function bjFiniquito(Baja $baja, bool $entregar = false): void
{
    $cierres = app(CierreLaboralService::class);
    $cierre = $baja->refresh()->cierre;
    $finiquito = $cierres->finiquito($cierre) ?? $cierres->calcularFiniquito($cierre, test()->rh, 12000.0);

    if ($entregar) {
        $cierres->registrarEntregaFiniquito($cierre->refresh(), test()->rh, ['fecha' => now()->toDateString()]);

        return;
    }

    $finiquito->forceFill(['estado' => EstadoFiniquito::Revisado])->save();
}

function bjRegistrar(string $clave, array $extra = [], ?User $quien = null): Baja
{
    test()->actingAs($quien ?? test()->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => test()->persona->id, ...bjDatos($clave, $extra)])
        ->assertSessionHasNoErrors();

    return Baja::query()->latest('id')->firstOrFail();
}

test('criterio 1: no se registra sin forma, causal, fecha de baja y recontratable — y nada se aplica', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, 'fecha_baja' => ''])
        ->assertSessionHasErrors(['forma_salida', 'causal_rotacion_id', 'fecha_baja', 'recontratable']);

    expect(Baja::query()->count())->toBe(0)
        ->and(CierreLaboral::query()->count())->toBe(0)
        ->and(SolicitudInterna::query()->count())->toBe(0)
        ->and($this->cuenta->refresh()->acceso_bloqueado_en)->toBeNull();
});

test('criterio 2 y RN-02: rescisión solo ofrece RE-01…RE-05; una causal de otra forma se rechaza; RN-07 pide los datos del aviso', function () {
    $opciones = collect(app(CatalogoBajasService::class)->opciones())->firstWhere('value', 'RE');
    expect(array_column($opciones['causales'], 'clave'))->toBe(['RE-01', 'RE-02', 'RE-03', 'RE-04', 'RE-05']);

    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-01', ['forma_salida' => 'RE'])])
        ->assertSessionHasErrors('causal_rotacion_id');

    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RE-04', ['comentarios' => 'Se presentó en estado inconveniente.'])])
        ->assertSessionHasErrors(['fecha_conocimiento_falta', 'entrega_aviso_rescision', 'fecha_entrega_aviso']);

    expect(Baja::query()->count())->toBe(0);
});

test('RN-04: comentario de al menos 20 caracteres en RV-05 y en rescisión', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-05', ['comentarios' => 'Corto'])])
        ->assertSessionHasErrors('comentarios');
    expect(Baja::query()->count())->toBe(0);

    // RV-01 no lo exige.
    $baja = bjRegistrar('RV-01', ['comentarios' => '']);
    expect($baja->estatus)->toBe(EstatusBaja::EnRevisionRh);
});

test('RN-10: la fecha de baja no es anterior al ingreso ni mayor a 15 días en el futuro', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-01', ['fecha_baja' => '2023-12-31'])])
        ->assertSessionHasErrors('fecha_baja');

    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-01', ['fecha_baja' => now()->addDays(16)->toDateString()])])
        ->assertSessionHasErrors('fecha_baja');

    bjRegistrar('RV-01', ['fecha_baja' => now()->addDays(15)->toDateString()]);
    expect(Baja::query()->count())->toBe(1);
});

test('registrar la baja la APLICA de inmediato: cierre, acceso suspendido, finiquito propuesto y aviso «Baja registrada» (quién, a quién, forma · causal, fecha) a RH y Sistemas', function () {
    $baja = bjRegistrar('RV-01');
    $cierre = $baja->cierre;

    expect($baja->estatus)->toBe(EstatusBaja::EnRevisionRh)
        ->and($baja->folio)->toMatch('/^BAJ-\d{4}-0001$/')
        ->and($cierre)->not->toBeNull()
        // Sin aprobación de nadie: el cierre nace aplicado.
        ->and($cierre?->autorizado_rh_en)->not->toBeNull()
        ->and($cierre?->solicitud)->not->toBeNull()
        ->and($this->cuenta->refresh()->acceso_bloqueado_en)->not->toBeNull()
        ->and($baja->acceso_suspendido_en)->not->toBeNull()
        ->and($baja->sucursal_id)->toBe($this->persona->sucursal_principal_id)
        ->and($baja->puesto_id)->toBe($this->persona->puesto_id)
        ->and($baja->nombre_colaborador)->toBe($this->persona->nombreCompleto())
        ->and(BajaBitacora::query()->where('baja_id', $baja->id)->where('accion', AccionBitacoraBaja::Registro->value)->exists())->toBeTrue()
        // Finiquito propuesto con el salario registrado.
        ->and(FiniquitoCalculo::query()->where('solicitud_interna_id', $cierre?->solicitud_interna_id)->value('estado'))->toBe(EstadoFiniquito::Borrador)
        ->and($cierre?->refresh()->estado)->toBe(EstadoCierreLaboral::FiniquitoEnProceso);

    $esAvisoDeBaja = function (PendienteRhNotification $n, array $canales, User $destino): bool {
        $datos = $n->toDatabase($destino);

        return $datos['tipo'] === 'cierre_baja_solicitada'
            && str_contains($datos['mensaje'], $this->gerente->name)
            && str_contains($datos['mensaje'], $this->persona->nombreCompleto())
            && str_contains($datos['mensaje'], 'renuncia voluntaria')
            && str_contains($datos['mensaje'], 'RV-01')
            && str_contains($datos['mensaje'], now()->subDay()->format('d/m/Y'));
    };

    Notification::assertSentTo($this->rh, PendienteRhNotification::class, $esAvisoDeBaja);
    Notification::assertSentTo($this->sistemas, PendienteRhNotification::class, $esAvisoDeBaja);
    // Confirmación al gerente que la registró.
    Notification::assertSentTo($this->gerente, PendienteRhNotification::class, fn (PendienteRhNotification $n, array $c, User $d) => $n->toDatabase($d)['titulo'] === 'Baja registrada');

    // RN-11: no se registra otra baja abierta de la misma persona.
    $this->actingAs($this->rh)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-02')])
        ->assertSessionHasErrors('colaborador_id');
    expect(Baja::query()->count())->toBe(1);
});

test('sin pasos de aprobación: no hay borrador, «enviar», «validar» ni «rechazar» en la web; la API anterior responde igual', function () {
    expect(fn () => route('rh.bajas.enviar', 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => route('rh.bajas.validar', 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => route('rh.bajas.edit', 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => route('rh.bajas.rechazar', 1))->toThrow(InvalidArgumentException::class);

    $baja = bjRegistrar('RV-01');
    $acciones = app(BajaService::class)->acciones($baja, $this->rh);

    expect($acciones)->toContain('cerrar', 'adjuntar', 'cancelar')
        ->not->toContain('validar', 'enviar', 'rechazar', 'editar')
        ->and(app(BajaService::class)->acciones($baja, $this->gerente))->toBe(['adjuntar']);

    // API v1 (compatibilidad): «enviar» ya no cambia nada.
    Sanctum::actingAs($this->gerente);
    $this->postJson(route('api.v1.rh.bajas.enviar', $baja))->assertOk()->assertJsonPath('data.estatus', 'en_revision_rh');
});

test('criterio 3 y RN-05: RH no cierra si falta un documento obligatorio de la forma o de la causal', function () {
    $baja = bjRegistrar('NR-05', [], $this->rh);

    // NR-05 requiere Jurídico: la baja pasa sola a Jurídico (RN-06).
    expect($baja->estatus)->toBe(EstatusBaja::EnRevisionJuridico);
    $this->actingAs($this->juridico)->post(route('rh.bajas.aprobar-juridico', $baja), ['motivo' => ''])->assertSessionHasNoErrors();

    $requeridos = app(CatalogoBajasService::class)->documentosRequeridos(FormaSalida::NoRenovacion, bjCausal('NR-05'));
    expect($requeridos->pluck('nombre')->all())->toContain('Autorización de Dirección General');

    foreach ($requeridos->whereNull('causal_rotacion_id') as $requerido) {
        $this->actingAs($this->gerente)->post(route('rh.bajas.documentos.store', $baja), [
            'archivo' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
            'documento_requerido_id' => $requerido->id,
        ])->assertSessionHasNoErrors();
    }

    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasErrors('documentos');
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::EnRevisionRh);

    bjAdjuntarTodos($baja, $this->gerente);
    // El finiquito propuesto todavía no se revisa ni se entrega.
    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasErrors('finiquito');
    bjFiniquito($baja, entregar: true);
    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::Cerrada);
});

test('criterio 4: una baja RE-03 llega a Jurídico antes del cierre y avisa a Contraloría (y a Jurídico)', function () {
    $baja = bjRegistrar('RE-03', bjRescision('RE-03'));

    expect($baja->estatus)->toBe(EstatusBaja::EnRevisionJuridico);
    Notification::assertSentTo($this->contraloria, PendienteRhNotification::class);
    Notification::assertSentTo($this->juridico, PendienteRhNotification::class, fn (PendienteRhNotification $n, array $c, User $d) => $n->toDatabase($d)['tipo'] === 'baja_revision_juridico');
    expect($baja->refresh()->alertas)->toHaveKeys(['contraloria', 'juridico_causal']);

    bjAdjuntarTodos($baja, $this->gerente);

    // En Jurídico no se cierra (RN-06) y Jurídico no cierra.
    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasErrors('estatus');
    $this->actingAs($this->juridico)->post(route('rh.bajas.cerrar', $baja))->assertForbidden();

    // Jurídico la devuelve con observaciones: sigue aplicada y RH la reenvía.
    $this->actingAs($this->juridico)->post(route('rh.bajas.devolver-juridico', $baja), ['motivo' => 'Falta el acta con dos testigos.'])->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::EnRevisionRh)
        ->and($baja->motivo_rechazo)->toBe('Falta el acta con dos testigos.')
        ->and($this->cuenta->refresh()->acceso_bloqueado_en)->not->toBeNull();
    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasErrors('estatus');
    $this->actingAs($this->rh)->post(route('rh.bajas.enviar-juridico', $baja))->assertSessionHasNoErrors();

    $this->actingAs($this->juridico)->post(route('rh.bajas.aprobar-juridico', $baja), ['motivo' => 'Acta y aviso en orden.'])->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::EnRevisionRh)->and($baja->juridico_en)->not->toBeNull();

    bjFiniquito($baja);
    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::Cerrada);
});

test('criterio 5: una baja RV-04 envía correo a RH al registrarse', function () {
    bjRegistrar('RV-04');

    Notification::assertSentTo($this->rh, AlertaBajaCorreoNotification::class, function (AlertaBajaCorreoNotification $n) {
        return in_array('mail', $n->via($this->rh), true)
            && str_contains($n->toMail($this->rh)->introLines[0] ?? '', 'NOM-035');
    });
});

test('rotación temprana (< 90 días) avisa a RH una sola vez', function () {
    $this->persona->update(['fecha_ingreso' => now()->subDays(40)->toDateString()]);
    $baja = bjRegistrar('RV-03');

    expect($baja->refresh()->antiguedad_dias)->toBeLessThan(90)
        ->and($baja->alertas)->toHaveKey('rotacion_temprana')
        ->and(BajaBitacora::query()->where('baja_id', $baja->id)->where('accion', AccionBitacoraBaja::Alerta->value)->count())->toBe(1);
});

test('RN-07 y RN-08: advertencia de prescripción (> 30 días) y aviso a Jurídico si el aviso ante el Tribunal se entrega tarde', function () {
    $baja = bjRegistrar('RE-01', bjRescision('RE-01', [
        'comentarios' => 'Dejó de presentarse a laborar desde hace más de una semana.',
        'fecha_baja' => now()->subDays(10)->toDateString(),
        'fecha_conocimiento_falta' => now()->subDays(45)->toDateString(),
        'entrega_aviso_rescision' => 'ante_tribunal',
        'fecha_entrega_aviso' => now()->toDateString(),
    ]));

    $detalle = app(BajaService::class)->detalle($baja->refresh(), $this->rh);
    expect(array_column($detalle['advertencias'], 'clave'))->toContain('prescripcion', 'tribunal')
        ->and($baja->alertas)->toHaveKey('tribunal');
    Notification::assertSentTo($this->juridico, PendienteRhNotification::class);
});

test('RN-09: en rescisión el gerente no cambia «No recontratable»; RH sí, con bitácora; Reclutamiento ve la alerta', function () {
    $baja = bjRegistrar('RE-04', bjRescision('RE-04', ['recontratable' => 'si', 'comentarios' => 'Se presentó en estado de ebriedad en la sucursal.']));
    expect($baja->refresh()->recontratable)->toBe(Recontratable::No);

    $this->actingAs($this->rh)->patch(route('rh.bajas.actualizar', $baja), ['recontratable' => 'con_reservas', 'motivo_cambio' => 'Revisado con Jurídico.'])->assertSessionHasNoErrors();
    expect($baja->refresh()->recontratable)->toBe(Recontratable::ConReservas)
        ->and(BajaBitacora::query()->where('baja_id', $baja->id)->where('accion', AccionBitacoraBaja::CambioRecontratable->value)->exists())->toBeTrue();

    $this->actingAs($this->rh)->patch(route('rh.bajas.actualizar', $baja), ['recontratable' => 'no'])->assertSessionHasNoErrors();
    $baja->forceFill(['estatus' => EstatusBaja::Cerrada])->save();

    $candidato = Candidato::factory()->create(['nombre' => $this->persona->name, 'apellidos' => $this->persona->apellidos]);
    $antecedente = app(AntecedenteBajaService::class)->deCandidato($candidato);
    expect($antecedente)->not->toBeNull()
        ->and($antecedente['folio'])->toBe($baja->folio)
        ->and($antecedente['recontratable'])->toBe('no');
});

test('criterio 8: todo cambio de causal queda en bitácora; el gerente no la cambia', function () {
    $baja = bjRegistrar('RV-01');

    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.cambiar-causal', $baja), ['causal_rotacion_id' => bjCausal('RV-02')->id, 'motivo' => 'Me equivoqué de causal.'])
        ->assertForbidden();

    $this->actingAs($this->rh)
        ->post(route('rh.bajas.cambiar-causal', $baja), ['causal_rotacion_id' => bjCausal('NR-01')->id, 'motivo' => 'Era bajo desempeño documentado.'])
        ->assertSessionHasNoErrors();

    $registro = BajaBitacora::query()->where('baja_id', $baja->id)->where('accion', AccionBitacoraBaja::CambioCausal->value)->firstOrFail();
    expect($registro->user_id)->toBe($this->rh->id)
        ->and($registro->created_at)->not->toBeNull()
        ->and($registro->valor_anterior)->toContain('RV-01')
        ->and($registro->valor_nuevo)->toContain('NR-01')
        ->and($registro->motivo)->toBe('Era bajo desempeño documentado.')
        ->and($baja->refresh()->forma_salida)->toBe(FormaSalida::NoRenovacion)
        // NR-01 requiere Jurídico: pasa sola a Jurídico.
        ->and($baja->estatus)->toBe(EstatusBaja::EnRevisionJuridico)
        ->and($baja->cierre?->causal_rotacion_id)->toBe(bjCausal('NR-01')->id)
        ->and($baja->cierre?->tipo_baja->value)->toBe('fin_contrato');
});

test('RN-11 al cerrar: colaborador inactivo y acceso bloqueado (tokens revocados); nunca se borra', function () {
    $baja = bjRegistrar('RV-01');
    bjAdjuntarTodos($baja, $this->gerente);

    // El gerente no cierra.
    $this->actingAs($this->gerente)->post(route('rh.bajas.cerrar', $baja))->assertForbidden();

    bjFiniquito($baja);
    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasNoErrors();

    $persona = Colaborador::query()->withTrashed()->findOrFail($this->persona->id);
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::Cerrada)
        ->and($persona->estatus)->toBe(EstadoUsuario::Inactivo)
        ->and($this->cuenta->refresh()->tokens()->count())->toBe(0)
        ->and($this->cuenta->acceso_bloqueado_en)->not->toBeNull()
        ->and(User::query()->whereKey($this->cuenta->id)->exists())->toBeTrue();
});

test('RN-13: una baja cerrada no se edita; RH la reabre con motivo y queda en bitácora', function () {
    $baja = bjRegistrar('RV-01');
    bjAdjuntarTodos($baja, $this->gerente);
    bjFiniquito($baja, entregar: true);
    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasNoErrors();

    $this->actingAs($this->rh)->patch(route('rh.bajas.actualizar', $baja), ['comentarios' => 'Otro texto distinto al original.'])->assertSessionHasErrors('estatus');
    $this->actingAs($this->rh)->post(route('rh.bajas.cambiar-causal', $baja), ['causal_rotacion_id' => bjCausal('RV-02')->id, 'motivo' => 'Corrección.'])->assertSessionHasErrors('estatus');
    $this->actingAs($this->gerente)->post(route('rh.bajas.documentos.store', $baja), ['archivo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertForbidden();

    $this->actingAs($this->rh)->post(route('rh.bajas.reabrir', $baja), ['motivo' => ''])->assertSessionHasErrors('motivo');
    $this->actingAs($this->rh)->post(route('rh.bajas.reabrir', $baja), ['motivo' => 'Falta corregir la fecha de baja.'])->assertSessionHasNoErrors();

    expect($baja->refresh()->estatus)->toBe(EstatusBaja::EnRevisionRh)
        ->and(BajaBitacora::query()->where('baja_id', $baja->id)->where('accion', AccionBitacoraBaja::Reabrio->value)->value('motivo'))->toBe('Falta corregir la fecha de baja.');
});

test('«No procede» (solo RH) revierte la baja: cancela el cierre y devuelve el acceso; el gerente no puede', function () {
    $baja = bjRegistrar('RV-01');

    $this->actingAs($this->gerente)->post(route('rh.bajas.cancelar', $baja), ['motivo' => 'Se arrepintió.'])->assertForbidden();

    $this->actingAs($this->rh)->post(route('rh.bajas.cancelar', $baja), ['motivo' => 'La persona decidió quedarse.'])->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::Cancelada)
        ->and($baja->cierre?->estado)->toBe(EstadoCierreLaboral::Cancelado)
        ->and($this->cuenta->refresh()->acceso_bloqueado_en)->toBeNull();
});

test('desde el ciclo laboral se registra la MISMA baja directa (volver=ciclo regresa a esa pantalla)', function () {
    $this->actingAs($this->rh)
        ->from(route('rh.colaboradores.ciclo', $this->persona))
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-06'), 'volver' => 'ciclo'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('rh.colaboradores.ciclo', $this->persona));

    $baja = Baja::query()->firstOrFail();
    expect($baja->estatus)->toBe(EstatusBaja::EnRevisionRh)
        ->and($baja->forma_salida)->toBe(FormaSalida::RenunciaVoluntaria)
        ->and($baja->recontratable)->toBe(Recontratable::Si);

    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasErrors('documentos');
});

test('quién accede: solo gerente (su alcance), RH y super admin registran/ven; Jurídico y Contraloría de lectura; los demás 403', function () {
    $otraSucursal = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id]);
    $ajena = Colaborador::factory()->create(['sucursal_principal_id' => $otraSucursal->id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => '2024-01-01']);
    $superAdmin = clUsuario('super_admin', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    // Gerente: no registra la baja de alguien de otra sucursal ni la encuentra.
    $this->actingAs($this->gerente)->post(route('rh.bajas.store'), ['colaborador_id' => $ajena->id, ...bjDatos('RV-01')])->assertForbidden();
    $ids = collect($this->actingAs($this->gerente)->getJson(route('rh.bajas.colaboradores'))->json('data'))->pluck('id');
    expect($ids)->toContain($this->persona->id)->not->toContain($ajena->id);

    $renuncia = bjRegistrar('RV-01');
    $this->actingAs($this->rh)->post(route('rh.bajas.cancelar', $renuncia), ['motivo' => 'Se queda en la empresa.'])->assertSessionHasNoErrors();
    $robo = bjRegistrar('RE-03', bjRescision('RE-03'));

    foreach ([$this->gerente, $this->rh, $superAdmin] as $quien) {
        $this->actingAs($quien)->get(route('rh.bajas.index'))->assertOk();
        $this->actingAs($quien)->get(route('rh.bajas.show', $renuncia))->assertOk();
        $this->actingAs($quien)->get(route('rh.bajas.create'))->assertOk();
    }

    // El gerente de otra sucursal no ve las bajas de esta.
    $otroGerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $otraSucursal->id]);
    $this->actingAs($otroGerente)->get(route('rh.bajas.show', $renuncia))->assertForbidden();
    $this->actingAs($otroGerente)->get(route('rh.bajas.index'))->assertOk()->assertInertia(fn ($p) => $p->where('bajas.data', []));

    // Ningún otro rol entra a «Bajas» ni registra.
    foreach (['jefe_directo', 'auditor', 'sistemas', 'rh_auxiliar', 'direccion', 'colaborador', 'coordinadora'] as $rol) {
        $usuario = clUsuario($rol, ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
        $this->actingAs($usuario)->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-02')])->assertForbidden();
        $this->actingAs($usuario)->get(route('rh.bajas.create'))->assertForbidden();

        if ($rol !== 'rh_auxiliar') {
            $this->actingAs($usuario)->get(route('rh.bajas.index'))->assertForbidden();
            $this->actingAs($usuario)->get(route('rh.bajas.show', $robo))->assertForbidden();
        }
    }

    // RH auxiliar: solo lectura (es RH).
    $this->actingAs(clUsuario('rh_auxiliar'))->get(route('rh.bajas.show', $robo))->assertOk()
        ->assertInertia(fn ($p) => $p->where('baja.acciones', []));

    // Jurídico: rescisiones y las que lo requieren; Contraloría: RE-02/RE-03, lectura.
    $this->actingAs($this->juridico)->get(route('rh.bajas.show', $robo))->assertOk();
    $this->actingAs($this->juridico)->get(route('rh.bajas.show', $renuncia))->assertForbidden();
    $this->actingAs($this->contraloria)->get(route('rh.bajas.show', $robo))->assertOk()
        ->assertInertia(fn ($p) => $p->where('baja.acciones', []));
    $this->actingAs($this->contraloria)->get(route('rh.bajas.show', $renuncia))->assertForbidden();
    $this->actingAs($this->contraloria)->post(route('rh.bajas.devolver-juridico', $robo), ['motivo' => 'No procede así.'])->assertForbidden();

    // Dirección: solo el tablero.
    $this->actingAs($this->direccion)->get(route('reportes.rotacion.tablero.index'))->assertOk();
    $this->actingAs($this->gerente)->get(route('reportes.rotacion.tablero.index'))->assertOk()
        ->assertInertia(fn ($p) => $p->has('tablero.opciones.sucursales', 1));
    $this->actingAs($this->juridico)->get(route('reportes.rotacion.tablero.index'))->assertForbidden();

    // Jurídico y Contraloría tienen modo operativo: su inicio es la bandeja.
    $this->actingAs($this->contraloria)->get(route('dashboard'))->assertRedirect(route('rh.bajas.index'));
});

test('documentos adjuntos se ven en línea (vista previa) y quitarlos queda en bitácora', function () {
    $baja = bjRegistrar('RV-01');
    $requerido = DocumentoRequeridoBaja::query()->where('clave', 'RV.entrevista_salida')->firstOrFail();

    $this->actingAs($this->gerente)->post(route('rh.bajas.documentos.store', $baja), [
        'archivo' => UploadedFile::fake()->image('entrevista.png'),
        'documento_requerido_id' => $requerido->id,
    ])->assertSessionHasNoErrors();

    $documento = $baja->documentos()->firstOrFail();
    $respuesta = $this->actingAs($this->rh)->get(route('rh.bajas.documentos.ver', [$baja, $documento]));
    $respuesta->assertOk();
    expect((string) $respuesta->headers->get('Content-Disposition'))->toStartWith('inline');

    $this->actingAs($this->gerente)->delete(route('rh.bajas.documentos.destroy', [$baja, $documento]))->assertSessionHasNoErrors();
    expect($baja->documentos()->count())->toBe(0)
        ->and(BajaBitacora::query()->where('baja_id', $baja->id)->where('accion', AccionBitacoraBaja::QuitoDocumento->value)->exists())->toBeTrue();
});

test('API v1 compatible: la misma baja se registra con el mismo servicio (con o sin «enviar») y el atajo de las APK sigue funcionando', function () {
    Sanctum::actingAs($this->gerente);

    $this->postJson(route('api.v1.rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-01'), 'enviar' => true])
        ->assertCreated()
        ->assertJsonPath('data.estatus', 'en_revision_rh')
        ->assertJsonPath('data.forma', 'RV');

    expect($this->cuenta->refresh()->acceso_bloqueado_en)->not->toBeNull();
    $this->getJson(route('api.v1.rh.bajas.catalogo'))->assertOk()->assertJsonCount(3, 'data.formas');

    // «update» del flujo anterior: el gerente ya no edita (RH corrige datos).
    $baja = Baja::query()->firstOrFail();
    $this->putJson(route('api.v1.rh.bajas.update', $baja), ['comentarios' => 'Cambio del gerente después de registrar.'])->assertForbidden();
    Sanctum::actingAs($this->rh);
    $this->putJson(route('api.v1.rh.bajas.update', $baja), ['comentarios' => 'RH completó el comentario de la baja.'])->assertOk()
        ->assertJsonPath('data.comentarios', 'RH completó el comentario de la baja.');

    // APK instalada: atajo de cierre sin causal ni campos nuevos.
    $otra = Colaborador::factory()->create(['sucursal_principal_id' => $this->persona->sucursal_principal_id, 'jefe_id' => $this->gerente->colaborador_id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => '2024-01-01']);
    $this->postJson(route('api.v1.rh.colaboradores.cierres.store', $otra), ['tipo_baja' => 'renuncia', 'fecha_efectiva' => now()->toDateString(), 'motivo' => 'Renuncia.'])
        ->assertSuccessful();

    expect(Baja::query()->where('colaborador_id', $otra->id)->value('estatus'))->toBe(EstatusBaja::EnRevisionRh);
});
