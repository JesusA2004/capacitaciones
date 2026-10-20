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
use App\Models\Colaborador;
use App\Models\DocumentoRequeridoBaja;
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
| Módulo de Bajas y Causales (especificación de RH v1.0): criterios de
| aceptación 1–6 y 8, reglas RN-02/04/07/08/09/10/11/13 y permisos por rol.
| (El criterio 7, carga histórica de 1,181 bajas, quedó fuera por decisión
| del usuario: el histórico viene del Excel de rotación — ver
| TableroRotacionTest.)
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
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'jefe_id' => $this->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2024-01-01',
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
 * Finiquito del cierre de la baja: calculado y revisado (la baja se ejecuta
 * al cerrarla) o entregado (la entrega ya ejecutó la baja laboral).
 */
function bjFiniquito(Baja $baja, bool $entregar = false): void
{
    $cierres = app(CierreLaboralService::class);
    $cierre = $baja->refresh()->cierre;
    $finiquito = $cierres->calcularFiniquito($cierre, test()->rh, 12000.0);

    if ($entregar) {
        $cierres->registrarEntregaFiniquito($cierre->refresh(), test()->rh, ['fecha' => now()->toDateString()]);

        return;
    }

    $finiquito->forceFill(['estado' => EstadoFiniquito::Revisado])->save();
}

function bjEnviada(string $clave, array $extra = []): Baja
{
    test()->actingAs(test()->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => test()->persona->id, ...bjDatos($clave, $extra), 'enviar' => true])
        ->assertSessionHasNoErrors();

    return Baja::query()->latest('id')->firstOrFail();
}

test('criterio 1: el gerente no puede enviar sin forma, causal, fecha de baja y recontratable', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id])
        ->assertSessionHasNoErrors();
    $baja = Baja::query()->firstOrFail();

    expect($baja->estatus)->toBe(EstatusBaja::Borrador)
        ->and($baja->folio)->toMatch('/^BAJ-\d{4}-0001$/')
        ->and($this->cuenta->refresh()->acceso_bloqueado_en)->toBeNull();

    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.enviar', $baja))
        ->assertSessionHasErrors(['forma_salida', 'causal_rotacion_id', 'fecha_baja', 'recontratable']);

    expect($baja->refresh()->estatus)->toBe(EstatusBaja::Borrador);
});

test('criterio 2 y RN-02: rescisión solo ofrece RE-01…RE-05; cambiar la forma limpia la causal y una causal de otra forma se rechaza', function () {
    $opciones = collect(app(CatalogoBajasService::class)->opciones())->firstWhere('value', 'RE');
    expect(array_column($opciones['causales'], 'clave'))->toBe(['RE-01', 'RE-02', 'RE-03', 'RE-04', 'RE-05']);

    $baja = app(BajaService::class)->guardar(null, ['colaborador_id' => $this->persona->id, ...bjDatos('RV-01')], $this->gerente);
    $baja = app(BajaService::class)->guardar($baja, ['forma_salida' => 'RE'], $this->gerente);

    expect($baja->forma_salida)->toBe(FormaSalida::Rescision)
        ->and($baja->causal_rotacion_id)->toBeNull()
        // RN-09: en rescisión, «No» por defecto.
        ->and($baja->recontratable)->toBe(Recontratable::No);

    $this->actingAs($this->gerente)
        ->put(route('rh.bajas.update', $baja), ['forma_salida' => 'RE', 'causal_rotacion_id' => bjCausal('RV-02')->id])
        ->assertSessionHasErrors('causal_rotacion_id');

    // RN-07: en rescisión son obligatorios conocimiento de la falta y aviso.
    $this->actingAs($this->gerente)
        ->put(route('rh.bajas.update', $baja), ['forma_salida' => 'RE', 'causal_rotacion_id' => bjCausal('RE-04')->id, 'enviar' => true])
        ->assertSessionHasErrors(['fecha_conocimiento_falta', 'entrega_aviso_rescision', 'fecha_entrega_aviso']);
});

test('RN-04: comentario de al menos 20 caracteres en RV-05 y en rescisión', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-05', ['comentarios' => 'Corto']), 'enviar' => true])
        ->assertSessionHasErrors('comentarios');

    $baja = Baja::query()->firstOrFail();
    expect($baja->estatus)->toBe(EstatusBaja::Borrador);

    // RV-01 no lo exige.
    $this->actingAs($this->gerente)
        ->put(route('rh.bajas.update', $baja), [...bjDatos('RV-01', ['comentarios' => '']), 'enviar' => true])
        ->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::EnRevisionRh);
});

test('RN-10: la fecha de baja no es anterior al ingreso ni mayor a 15 días en el futuro', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-01', ['fecha_baja' => '2023-12-31'])])
        ->assertSessionHasErrors('fecha_baja');

    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-01', ['fecha_baja' => now()->addDays(16)->toDateString()])])
        ->assertSessionHasErrors('fecha_baja');

    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-01', ['fecha_baja' => now()->addDays(15)->toDateString()])])
        ->assertSessionHasNoErrors();
});

test('al enviar a RH se aplica el cierre laboral y se suspende el acceso; RN-11 solo una baja abierta', function () {
    $baja = bjEnviada('RV-01');

    expect($baja->estatus)->toBe(EstatusBaja::EnRevisionRh)
        ->and($baja->cierre_laboral_id)->not->toBeNull()
        ->and($baja->cierre?->estado)->toBe(EstadoCierreLaboral::Iniciado)
        ->and($this->cuenta->refresh()->acceso_bloqueado_en)->not->toBeNull()
        ->and($baja->sucursal_id)->toBe($this->persona->sucursal_principal_id)
        ->and($baja->puesto_id)->toBe($this->persona->puesto_id)
        ->and($baja->nombre_colaborador)->toBe($this->persona->nombreCompleto());

    Notification::assertSentTo($this->rh, PendienteRhNotification::class);

    // RN-11: ni otro borrador ni el atajo del ciclo laboral.
    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id])
        ->assertSessionHasErrors('colaborador_id');
    expect(Baja::query()->count())->toBe(1);
});

test('criterio 3 y RN-05: RH no cierra si falta un documento obligatorio de la forma o de la causal', function () {
    $baja = bjEnviada('NR-05');
    $this->actingAs($this->rh)->post(route('rh.bajas.validar', $baja))->assertSessionHasNoErrors();
    $this->actingAs($this->rh)->post(route('rh.bajas.enviar-juridico', $baja))->assertSessionHasNoErrors();
    $this->actingAs($this->juridico)->post(route('rh.bajas.aprobar-juridico', $baja), ['motivo' => ''])->assertSessionHasNoErrors();

    // Solo los de la forma: falta el adicional de NR-05 (autorización de Dirección General).
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
    // Sin finiquito revisado tampoco cierra.
    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasErrors('finiquito');
    bjFiniquito($baja, entregar: true);
    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::Cerrada);
});

test('criterio 4: una baja RE-03 pasa por Jurídico antes del cierre y avisa a Contraloría (y a Jurídico)', function () {
    $baja = bjEnviada('RE-03', [
        'recontratable' => 'no',
        'fecha_conocimiento_falta' => now()->subDays(5)->toDateString(),
        'entrega_aviso_rescision' => 'personal',
        'fecha_entrega_aviso' => now()->subDay()->toDateString(),
    ]);

    Notification::assertSentTo($this->contraloria, PendienteRhNotification::class);
    Notification::assertSentTo($this->juridico, PendienteRhNotification::class);
    expect($baja->refresh()->alertas)->toHaveKeys(['contraloria', 'juridico_causal']);

    bjAdjuntarTodos($baja, $this->gerente);
    $this->actingAs($this->rh)->post(route('rh.bajas.validar', $baja))->assertSessionHasNoErrors();

    // Sin Jurídico no cierra (RN-06).
    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasErrors('estatus');

    $this->actingAs($this->rh)->post(route('rh.bajas.enviar-juridico', $baja))->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::EnRevisionJuridico);

    // Jurídico no cierra.
    $this->actingAs($this->juridico)->post(route('rh.bajas.cerrar', $baja))->assertForbidden();
    $this->actingAs($this->juridico)->post(route('rh.bajas.aprobar-juridico', $baja), ['motivo' => 'Acta y aviso en orden.'])->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::EnRevisionRh)->and($baja->juridico_en)->not->toBeNull();

    bjFiniquito($baja);
    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::Cerrada);
});

test('criterio 5: una baja RV-04 envía correo a RH al enviarse', function () {
    bjEnviada('RV-04');

    Notification::assertSentTo($this->rh, AlertaBajaCorreoNotification::class, function (AlertaBajaCorreoNotification $n) {
        return in_array('mail', $n->via($this->rh), true)
            && str_contains($n->toMail($this->rh)->introLines[0] ?? '', 'NOM-035');
    });
});

test('rotación temprana (< 90 días) avisa a RH una sola vez', function () {
    $this->persona->update(['fecha_ingreso' => now()->subDays(40)->toDateString()]);
    $baja = bjEnviada('RV-03');

    expect($baja->refresh()->antiguedad_dias)->toBeLessThan(90)
        ->and($baja->alertas)->toHaveKey('rotacion_temprana')
        ->and(BajaBitacora::query()->where('baja_id', $baja->id)->where('accion', AccionBitacoraBaja::Alerta->value)->count())->toBe(1);
});

test('RN-07 y RN-08: advertencia de prescripción (> 30 días) y aviso a Jurídico si el aviso ante el Tribunal se entrega tarde', function () {
    $baja = bjEnviada('RE-01', [
        'recontratable' => 'no',
        'comentarios' => 'Dejó de presentarse a laborar desde hace más de una semana.',
        'fecha_baja' => now()->subDays(10)->toDateString(),
        'fecha_conocimiento_falta' => now()->subDays(45)->toDateString(),
        'entrega_aviso_rescision' => 'ante_tribunal',
        'fecha_entrega_aviso' => now()->toDateString(),
    ]);

    $detalle = app(BajaService::class)->detalle($baja->refresh(), $this->rh);
    expect(array_column($detalle['advertencias'], 'clave'))->toContain('prescripcion', 'tribunal')
        ->and($baja->alertas)->toHaveKey('tribunal');
    Notification::assertSentTo($this->juridico, PendienteRhNotification::class);
});

test('RN-09: en rescisión el gerente no cambia «No recontratable»; RH sí, con bitácora; Reclutamiento ve la alerta', function () {
    $baja = bjEnviada('RE-04', [
        'recontratable' => 'si',
        'comentarios' => 'Se presentó en estado de ebriedad en la sucursal.',
        'fecha_conocimiento_falta' => now()->subDays(2)->toDateString(),
        'entrega_aviso_rescision' => 'personal',
        'fecha_entrega_aviso' => now()->subDay()->toDateString(),
    ]);
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

test('criterio 8: todo cambio de causal queda en bitácora con usuario, fecha y valor anterior; el gerente no la cambia tras enviarla', function () {
    $baja = bjEnviada('RV-01');

    $this->actingAs($this->gerente)
        ->post(route('rh.bajas.cambiar-causal', $baja), ['causal_rotacion_id' => bjCausal('RV-02')->id, 'motivo' => 'Me equivoqué de causal.'])
        ->assertForbidden();
    $this->actingAs($this->gerente)
        ->put(route('rh.bajas.update', $baja), ['causal_rotacion_id' => bjCausal('RV-02')->id])
        ->assertSessionHasErrors('estatus');

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
        // El cierre laboral queda igual que la baja.
        ->and($baja->cierre?->causal_rotacion_id)->toBe(bjCausal('NR-01')->id)
        ->and($baja->cierre?->tipo_baja->value)->toBe('fin_contrato');
});

test('RN-11 al cerrar: colaborador inactivo y acceso bloqueado (tokens revocados); nunca se borra', function () {
    $baja = bjEnviada('RV-01');
    bjAdjuntarTodos($baja, $this->gerente);

    // El gerente no cierra.
    $this->actingAs($this->gerente)->post(route('rh.bajas.cerrar', $baja))->assertForbidden();

    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasErrors('estatus');
    $this->actingAs($this->rh)->post(route('rh.bajas.validar', $baja))->assertSessionHasNoErrors();
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
    $baja = bjEnviada('RV-01');
    bjAdjuntarTodos($baja, $this->gerente);
    $this->actingAs($this->rh)->post(route('rh.bajas.validar', $baja));
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

test('rechazo con motivo regresa al gerente; «no procede» cancela el cierre y devuelve el acceso', function () {
    $baja = bjEnviada('RV-01');

    $this->actingAs($this->rh)->post(route('rh.bajas.rechazar', $baja), ['motivo' => 'Falta la entrevista de salida.'])->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::Rechazada)
        ->and($this->cuenta->refresh()->acceso_bloqueado_en)->not->toBeNull();

    // El gerente corrige y reenvía; luego RH decide que no procede.
    $this->actingAs($this->gerente)->post(route('rh.bajas.enviar', $baja))->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::EnRevisionRh);

    $this->actingAs($this->rh)->post(route('rh.bajas.cancelar', $baja), ['motivo' => 'La persona decidió quedarse.'])->assertSessionHasNoErrors();
    expect($baja->refresh()->estatus)->toBe(EstatusBaja::Cancelada)
        ->and($baja->cierre?->estado)->toBe(EstadoCierreLaboral::Cancelado)
        ->and($this->cuenta->refresh()->acceso_bloqueado_en)->toBeNull();
});

test('el atajo de RH desde el ciclo laboral crea la baja «En revisión RH» con folio, sin saltarse los documentos para cerrar', function () {
    $this->actingAs($this->rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), [
            'tipo_baja' => 'renuncia',
            'causal_rotacion_id' => bjCausal('RV-06')->id,
            'fecha_efectiva' => now()->subDay()->toDateString(),
            'recontratable' => 'si',
        ])
        ->assertSessionHasNoErrors();

    $baja = Baja::query()->firstOrFail();
    expect($baja->estatus)->toBe(EstatusBaja::EnRevisionRh)
        ->and($baja->forma_salida)->toBe(FormaSalida::RenunciaVoluntaria)
        ->and($baja->recontratable)->toBe(Recontratable::Si);

    $this->actingAs($this->rh)->post(route('rh.bajas.validar', $baja))->assertSessionHasNoErrors();
    $this->actingAs($this->rh)->post(route('rh.bajas.cerrar', $baja))->assertSessionHasErrors('documentos');
});

test('permisos por rol: gerente solo su personal; Jurídico rescisiones y las que lo requieren; Contraloría solo RE-02/RE-03; Dirección solo el tablero', function () {
    $otraSucursal = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id]);
    $ajena = Colaborador::factory()->create(['sucursal_principal_id' => $otraSucursal->id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => '2024-01-01']);

    // Gerente: no crea la baja de alguien de otra sucursal ni la encuentra.
    $this->actingAs($this->gerente)->post(route('rh.bajas.store'), ['colaborador_id' => $ajena->id])->assertForbidden();
    $ids = collect($this->actingAs($this->gerente)->getJson(route('rh.bajas.colaboradores'))->json('data'))->pluck('id');
    expect($ids)->toContain($this->persona->id)->not->toContain($ajena->id);

    $renuncia = bjEnviada('RV-01');
    $this->actingAs($this->rh)->post(route('rh.bajas.cancelar', $renuncia), ['motivo' => 'Se queda en la empresa.'])->assertSessionHasNoErrors();
    $robo = bjEnviada('RE-03', [
        'recontratable' => 'no',
        'fecha_conocimiento_falta' => now()->subDays(3)->toDateString(),
        'entrega_aviso_rescision' => 'personal',
        'fecha_entrega_aviso' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($this->juridico)->get(route('rh.bajas.show', $robo))->assertOk();
    $this->actingAs($this->juridico)->get(route('rh.bajas.show', $renuncia))->assertForbidden();
    $this->actingAs($this->contraloria)->get(route('rh.bajas.show', $robo))->assertOk()
        ->assertInertia(fn ($p) => $p->where('baja.acciones', []));
    $this->actingAs($this->contraloria)->get(route('rh.bajas.show', $renuncia))->assertForbidden();
    $this->actingAs($this->contraloria)->post(route('rh.bajas.rechazar', $robo), ['motivo' => 'No procede así.'])->assertForbidden();

    $this->actingAs($this->direccion)->get(route('rh.bajas.index'))->assertForbidden();
    $this->actingAs($this->direccion)->get(route('reportes.rotacion.tablero.index'))->assertOk();
    $this->actingAs($this->gerente)->get(route('reportes.rotacion.tablero.index'))->assertOk()
        ->assertInertia(fn ($p) => $p->has('tablero.opciones.sucursales', 1));
    $this->actingAs($this->juridico)->get(route('reportes.rotacion.tablero.index'))->assertForbidden();

    // Jurídico y Contraloría tienen modo operativo: su inicio es la bandeja.
    $this->actingAs($this->contraloria)->get(route('dashboard'))->assertRedirect(route('rh.bajas.index'));
});

test('documentos adjuntos se ven en línea (vista previa) y quitarlos queda en bitácora', function () {
    $baja = bjEnviada('RV-01');
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

test('API v1: la misma solicitud se crea y envía con el mismo servicio; el endpoint anterior de las APK sigue funcionando', function () {
    Sanctum::actingAs($this->gerente);

    $this->postJson(route('api.v1.rh.bajas.store'), ['colaborador_id' => $this->persona->id, ...bjDatos('RV-01'), 'enviar' => true])
        ->assertCreated()
        ->assertJsonPath('data.estatus', 'en_revision_rh')
        ->assertJsonPath('data.forma', 'RV');

    $this->getJson(route('api.v1.rh.bajas.catalogo'))->assertOk()->assertJsonCount(3, 'data.formas');

    // APK instalada: atajo de cierre sin causal ni campos nuevos.
    $otra = Colaborador::factory()->create(['sucursal_principal_id' => $this->persona->sucursal_principal_id, 'jefe_id' => $this->gerente->colaborador_id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => '2024-01-01']);
    Sanctum::actingAs($this->rh);
    $this->postJson(route('api.v1.rh.colaboradores.cierres.store', $otra), ['tipo_baja' => 'renuncia', 'fecha_efectiva' => now()->toDateString(), 'motivo' => 'Renuncia.'])
        ->assertSuccessful();

    expect(Baja::query()->where('colaborador_id', $otra->id)->value('estatus'))->toBe(EstatusBaja::EnRevisionRh);
});
