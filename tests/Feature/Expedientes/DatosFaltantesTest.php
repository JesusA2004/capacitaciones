<?php

use App\Jobs\SendExpoPushJob;
use App\Models\AvisoDatosFaltantes;
use App\Models\Colaborador;
use App\Models\MobileDevice;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\DocumentosMaestros\ContextoDocumento;
use App\Services\DocumentosMaestros\DatosDocumentoService;
use App\Services\Expedientes\DatosFaltantesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    Bus::fake([SendExpoPushJob::class]);
    $estructura = clEstructura();
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id, 'puesto_id' => $estructura['puesto']->id,
        'contacto_emergencia_nombre' => null, 'contacto_emergencia_telefono' => null, 'contacto_emergencia_parentesco' => null,
        'curp' => null,
    ]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->persona->id]);
    $this->cuenta->assignRole('colaborador');
    MobileDevice::factory()->for($this->cuenta, 'usuario')->create(['push_token' => 'ExponentPushToken[datos]']);
    $this->rh = clUsuario('rh_admin');
});

test('el contacto de emergencia alimenta los contratos y, si falta, se puede pedir y guardar en el colaborador', function () {
    $datos = app(DatosDocumentoService::class);
    $this->persona->update(['contacto_emergencia_nombre' => 'María Pérez', 'contacto_emergencia_parentesco' => 'Madre', 'contacto_emergencia_telefono' => '7771234567']);

    $resuelto = $datos->resolver(new ContextoDocumento($this->persona->refresh()));
    expect($resuelto['contacto_emergencia_nombre'])->toBe('María Pérez')
        ->and($resuelto['contacto_emergencia_nombre_mayusculas'])->toBe('MARÍA PÉREZ')
        ->and($resuelto['contacto_emergencia_parentesco'])->toBe('Madre')
        ->and($resuelto['contacto_emergencia_telefono'])->toBe('7771234567');

    // Si un contrato lo exige y falta, el motor lo reporta como dato del colaborador (bloquea y se puede completar).
    $fuente = $datos->fuente('contacto_emergencia_nombre');
    expect($fuente['fuente'])->toBe('colaborador')
        ->and($fuente['columna'])->toBe('contacto_emergencia_nombre')
        ->and($datos->captura($fuente)['persistencia'])->toBe('colaborador')
        ->and(DatosDocumentoService::columnasEditablesColaborador())->toHaveKey('contacto_emergencia_telefono');
});

test('el expediente detecta los datos faltantes y RH avisa al colaborador (notificación + push, snapshot y sin spam)', function () {
    $servicio = app(DatosFaltantesService::class);
    $faltan = array_column($servicio->faltantes($this->persona)['personales'], 'campo');

    expect($faltan)->toContain('contacto_emergencia_nombre', 'contacto_emergencia_telefono', 'curp');

    $this->actingAs($this->rh)->post(route('rh.expedientes.avisar-datos-faltantes', $this->persona))->assertSessionHasNoErrors();

    $aviso = AvisoDatosFaltantes::query()->firstOrFail();
    expect($aviso->enviado_por)->toBe($this->rh->id)
        ->and(array_column($aviso->campos, 'campo'))->toContain('curp');
    Notification::assertSentTo($this->cuenta, PendienteRhNotification::class, fn (PendienteRhNotification $n) => $n->toDatabase($this->cuenta)['tipo'] === 'datos_faltantes');
    Bus::assertDispatched(SendExpoPushJob::class, fn (SendExpoPushJob $j) => $j->token === 'ExponentPushToken[datos]' && $j->data['accion'] === 'completar_datos');

    // Un segundo aviso el mismo día se rechaza (evita spam).
    $this->actingAs($this->rh)->post(route('rh.expedientes.avisar-datos-faltantes', $this->persona))->assertSessionHasErrors('datos');
    expect(AvisoDatosFaltantes::query()->count())->toBe(1);
});

test('la app muestra «Completa tu información» y se recalcula sola cuando los datos ya están', function () {
    Sanctum::actingAs($this->cuenta);

    $respuesta = $this->getJson('/api/v1/colaborador/datos-faltantes')->assertOk();
    expect($respuesta->json('data.completo'))->toBeFalse()
        ->and(collect($respuesta->json('data.faltan'))->pluck('campo')->all())->toContain('contacto_emergencia_nombre');

    // RH aprueba la actualización de datos (se refleja en el colaborador).
    $this->persona->update([
        'genero' => 'femenino', 'fecha_nacimiento' => '1990-01-01', 'estado_civil' => 'soltero', 'curp' => 'XEXX900101MNEXXXA4', 'rfc' => 'XEXX900101AB1',
        'nss' => '12345678901', 'telefono' => '7770000000', 'correo_personal' => 'ana@example.com', 'domicilio' => 'Calle 1', 'domicilio_cp' => '62000',
        'contacto_emergencia_nombre' => 'Luis', 'contacto_emergencia_parentesco' => 'Hermano', 'contacto_emergencia_telefono' => '7771111111',
        'beneficiario_nombre' => 'Luis', 'beneficiario_parentesco' => 'Hermano',
    ]);

    // Nueva petición con la cuenta recargada (actingAs conserva la instancia y su relación en caché).
    Sanctum::actingAs($this->cuenta->fresh());
    expect($this->getJson('/api/v1/colaborador/datos-faltantes')->json('data.completo'))->toBeTrue();
});

test('completar mis datos: la app propone, RH autoriza, el expediente se actualiza y la completitud se recalcula', function () {
    Sanctum::actingAs($this->cuenta);

    // La app recibe cómo capturar cada dato faltante.
    $faltan = collect($this->getJson('/api/v1/colaborador/datos-faltantes')->json('data.faltan'))->keyBy('campo');
    expect($faltan['curp']['tipo'])->toBe('text')
        ->and($faltan['contacto_emergencia_telefono']['tipo'])->toBe('tel');

    // Formato inválido: se rechaza con mensaje claro y no se crea nada.
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'actualizacion_datos', 'datos' => ['curp' => 'MAL']])
        ->assertUnprocessable()->assertJsonValidationErrors('datos.curp');

    // Un dato laboral (puesto, sueldo…) no se propone: lo captura RH.
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'actualizacion_datos', 'datos' => ['puesto_id' => '999']])
        ->assertUnprocessable()->assertJsonValidationErrors('datos');

    $this->postJson('/api/v1/solicitudes', ['tipo' => 'actualizacion_datos', 'datos' => [
        'curp' => 'xexx900101mnexxxa4', 'contacto_emergencia_nombre' => 'Luis Pérez', 'contacto_emergencia_parentesco' => 'Hermano', 'contacto_emergencia_telefono' => '7771111111',
    ]])->assertCreated()->assertJsonPath('datos_propuestos.0.campo', 'curp');

    $solicitud = SolicitudInterna::query()->latest('id')->firstOrFail();
    expect($solicitud->motivo)->toContain('CURP')
        ->and($solicitud->datos_propuestos)->toHaveKey('curp', 'XEXX900101MNEXXXA4');
    // Nada cambia en el expediente mientras RH no autorice.
    expect($this->persona->refresh()->curp)->toBeNull();
    $this->actingAs($this->rh)->get(route('rh.solicitudes.show', $solicitud))->assertInertia(fn ($page) => $page->where('datosPropuestos.0.actual', null)->where('datosPropuestos.0.propuesto', 'XEXX900101MNEXXXA4'));

    $this->actingAs($this->rh)->post(route('rh.solicitudes.aprobar', $solicitud))->assertSessionHasNoErrors();

    $this->persona->refresh();
    expect($this->persona->curp)->toBe('XEXX900101MNEXXXA4')
        ->and($this->persona->contacto_emergencia_nombre)->toBe('Luis Pérez')
        ->and($this->persona->contacto_emergencia_telefono)->toBe('7771111111')
        ->and($solicitud->refresh()->datos_anteriores)->toHaveKey('curp', null);

    $faltanDespues = array_column(app(DatosFaltantesService::class)->faltantes($this->persona)['personales'], 'campo');
    expect($faltanDespues)->not->toContain('curp', 'contacto_emergencia_nombre', 'contacto_emergencia_telefono');
});

test('la completitud se muestra por sección (Identidad, Fiscal, Domicilio, Contacto de emergencia…), no solo como porcentaje', function () {
    $this->persona->update(['domicilio' => null, 'domicilio_cp' => null]);

    $secciones = collect(app(DatosFaltantesService::class)->resumen($this->persona->refresh())['secciones'])->keyBy('clave');

    expect($secciones->keys()->all())->toBe(['identidad', 'fiscal', 'laboral', 'domicilio', 'contacto', 'emergencia', 'beneficiario'])
        // Falta la CURP: Identidad incompleta (⚠), no faltante entera.
        ->and($secciones['identidad']['estado'])->toBe('incompleto')
        ->and($secciones['identidad']['faltantes'])->toContain('CURP')
        // Sin domicilio ni CP, ni contacto de emergencia: faltantes (✗).
        ->and($secciones['domicilio']['estado'])->toBe('faltante')
        ->and($secciones['emergencia']['estado'])->toBe('faltante');

    $this->persona->update(['domicilio' => 'Calle 1', 'domicilio_cp' => '62000', 'contacto_emergencia_nombre' => 'Ana', 'contacto_emergencia_parentesco' => 'Hermana', 'contacto_emergencia_telefono' => '7770000000']);
    $secciones = collect(app(DatosFaltantesService::class)->resumen($this->persona->refresh())['secciones'])->keyBy('clave');

    expect($secciones['domicilio']['estado'])->toBe('completo')
        ->and($secciones['emergencia']['estado'])->toBe('completo');
});
