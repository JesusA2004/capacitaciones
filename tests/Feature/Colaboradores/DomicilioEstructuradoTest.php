<?php

use App\Enums\EstadoRepublica;
use App\Models\AltaDigital;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\DocumentosMaestros\ContextoDocumento;
use App\Services\DocumentosMaestros\DatosDocumentoService;
use App\Services\Expedientes\DatosFaltantesService;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Domicilio ESTRUCTURADO (DomicilioService): calle, número exterior e
| interior, colonia, C.P., municipio/alcaldía y estado se capturan en todos
| los puntos de entrada; `domicilio` (texto único) se compone al guardar y
| la API sigue aceptando el payload viejo (solo el texto).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->estructura = clEstructura();
});

/** @return array<string, string> */
function domEstructurado(array $extra = []): array
{
    return [
        'domicilio_calle' => 'Av. Emiliano Zapata',
        'domicilio_numero_exterior' => '1234',
        'domicilio_numero_interior' => '5',
        'domicilio_colonia' => 'Lomas de la Selva',
        'domicilio_cp' => '62270',
        'domicilio_municipio' => 'Cuernavaca',
        'domicilio_estado' => 'Morelos',
        ...$extra,
    ];
}

test('alta de colaborador (API RH) guarda el domicilio estructurado y compone el texto', function () {
    Sanctum::actingAs($this->rh);

    $respuesta = $this->postJson('/api/v1/rh/colaboradores', [
        'name' => 'Ana',
        'apellidos' => 'Martínez Ruiz',
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'sueldo_mensual' => 15000,
        'fecha_ingreso' => now()->toDateString(),
        'tipo_contratacion' => 'periodo_prueba',
        'fecha_fin_contrato' => now()->addDays(30)->toDateString(),
        'crear_acceso' => false,
        ...domEstructurado(['domicilio_estado' => 'MORELOS']),
    ])->assertCreated();

    $colaborador = Colaborador::query()->findOrFail($respuesta->json('colaborador_id'));

    expect($colaborador->domicilio_calle)->toBe('Av. Emiliano Zapata')
        ->and($colaborador->domicilio_numero_interior)->toBe('5')
        ->and($colaborador->domicilio_cp)->toBe('62270')
        // El estado se guarda con su nombre oficial.
        ->and($colaborador->domicilio_estado)->toBe('Morelos')
        ->and($colaborador->domicilio)->toBe('Av. Emiliano Zapata 1234 Int. 5, col. Lomas de la Selva, Cuernavaca, Morelos, C.P. 62270');
});

test('alta de colaborador rechaza un C.P. que no es de 5 dígitos y un estado fuera de la lista', function () {
    Sanctum::actingAs($this->rh);

    $this->postJson('/api/v1/rh/colaboradores', [
        'name' => 'Ana',
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'sueldo_mensual' => 15000,
        'fecha_ingreso' => now()->toDateString(),
        'tipo_contratacion' => 'periodo_prueba',
        ...domEstructurado(['domicilio_cp' => '6227', 'domicilio_estado' => 'Narnia']),
    ])->assertUnprocessable()->assertJsonValidationErrors(['domicilio_cp', 'domicilio_estado']);

    expect(Colaborador::query()->where('name', 'Ana')->exists())->toBeFalse();
});

test('editar datos personales del expediente guarda los campos, valida C.P./estado y respeta el texto previo si no se capturan', function () {
    $persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'domicilio' => 'Calle Vieja 10, Centro, Cuernavaca',
    ]);

    // Sin campos estructurados el texto previo no se toca (no se parte adivinando).
    $this->actingAs($this->rh)->put(route('rh.expedientes.datos-personales.update', $persona), ['telefono' => '7771234567'])
        ->assertSessionHasNoErrors();
    expect($persona->refresh()->domicilio)->toBe('Calle Vieja 10, Centro, Cuernavaca')
        ->and($persona->domicilio_calle)->toBeNull();

    $this->actingAs($this->rh)->put(route('rh.expedientes.datos-personales.update', $persona), domEstructurado(['domicilio_cp' => '12AB5']))
        ->assertSessionHasErrors('domicilio_cp');
    $this->actingAs($this->rh)->put(route('rh.expedientes.datos-personales.update', $persona), domEstructurado(['domicilio_estado' => 'Atlantis']))
        ->assertSessionHasErrors('domicilio_estado');

    $this->actingAs($this->rh)->put(route('rh.expedientes.datos-personales.update', $persona), domEstructurado(['domicilio_numero_interior' => null, 'domicilio_estado' => 'ciudad de mexico']))
        ->assertSessionHasNoErrors();

    $persona->refresh();
    expect($persona->domicilio_estado)->toBe(EstadoRepublica::CiudadDeMexico->value)
        ->and($persona->domicilio_numero_interior)->toBeNull()
        ->and($persona->domicilio)->toBe('Av. Emiliano Zapata 1234, col. Lomas de la Selva, Cuernavaca, Ciudad de México, C.P. 62270');

    // Los documentos imprimen cada parte en su celda.
    $valores = app(DatosDocumentoService::class)->resolver(new ContextoDocumento($persona));
    expect($valores['domicilio_calle'])->toBe('Av. Emiliano Zapata 1234')
        ->and($valores['domicilio_numero_exterior'])->toBe('1234')
        ->and($valores['domicilio_colonia'])->toBe('Lomas de la Selva')
        ->and($valores['domicilio_cp'])->toBe('62270')
        ->and($valores['domicilio_municipio'])->toBe('Cuernavaca')
        ->and($valores['domicilio_estado'])->toBe('Ciudad de México')
        ->and($valores['domicilio_completo'])->toBe($persona->domicilio);
});

test('el wizard público de alta digital exige el domicilio estructurado y lo pasa al colaborador', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $alta = AltaDigital::factory()->create([
        'empresa_id' => $empresa->id,
        'sucursal_id' => $sucursal->id,
        'estado' => 'enviada',
        'token_expira_en' => now()->addDays(3),
    ]);
    $base = [
        'nombre' => 'Juana',
        'apellidos' => 'Pérez',
        'telefono' => '8112345678',
        'fecha_nacimiento' => now()->subYears(25)->toDateString(),
        'curp' => 'PEPJ990101MNLRRN01',
        'contacto_emergencia_nombre' => 'María Pérez',
        'contacto_emergencia_telefono' => '8187654321',
    ];

    // Solo el texto ya no basta: se piden los campos.
    $this->put(route('alta-publica.datos-personales', $alta->token), [...$base, 'domicilio' => 'Calle Falsa 123'])
        ->assertSessionHasErrors(['domicilio_calle', 'domicilio_numero_exterior', 'domicilio_colonia', 'domicilio_cp', 'domicilio_municipio', 'domicilio_estado']);

    $this->put(route('alta-publica.datos-personales', $alta->token), [...$base, ...domEstructurado(['domicilio_numero_interior' => ''])])
        ->assertSessionHasNoErrors();

    $alta->refresh();
    expect($alta->domicilio_calle)->toBe('Av. Emiliano Zapata')
        ->and($alta->domicilio_numero_interior)->toBeNull()
        ->and($alta->domicilio)->toBe('Av. Emiliano Zapata 1234, col. Lomas de la Selva, Cuernavaca, Morelos, C.P. 62270');
});

test('API V1: la solicitud de actualización acepta el payload viejo (solo domicilio) y el nuevo (campos)', function () {
    $persona = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id, 'domicilio' => null]);
    $cuenta = User::factory()->create(['colaborador_id' => $persona->id]);
    $cuenta->assignRole('colaborador');
    Sanctum::actingAs($cuenta);

    // APK anterior: un solo texto.
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'actualizacion_datos', 'datos' => ['domicilio' => 'Calle Vieja 10, Centro']])
        ->assertCreated();
    $vieja = SolicitudInterna::query()->latest('id')->firstOrFail();
    expect($vieja->datos_propuestos)->toBe(['domicilio' => 'Calle Vieja 10, Centro']);
    $this->actingAs($this->rh)->post(route('rh.solicitudes.aprobar', $vieja))->assertSessionHasNoErrors();
    expect($persona->refresh()->domicilio)->toBe('Calle Vieja 10, Centro');

    // APK nueva: campos estructurados, con validación de C.P. y estado.
    Sanctum::actingAs($cuenta->fresh());
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'actualizacion_datos', 'datos' => domEstructurado(['domicilio_cp' => '622'])])
        ->assertUnprocessable()->assertJsonValidationErrors('datos.domicilio_cp');
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'actualizacion_datos', 'datos' => domEstructurado(['domicilio_estado' => 'Gondor'])])
        ->assertUnprocessable()->assertJsonValidationErrors('datos.domicilio_estado');

    $this->postJson('/api/v1/solicitudes', ['tipo' => 'actualizacion_datos', 'datos' => domEstructurado(['domicilio_estado' => 'morelos'])])
        ->assertCreated();
    $nueva = SolicitudInterna::query()->latest('id')->firstOrFail();
    expect($nueva->datos_propuestos)->toHaveKey('domicilio_estado', 'Morelos');

    $this->actingAs($this->rh)->post(route('rh.solicitudes.aprobar', $nueva))->assertSessionHasNoErrors();

    $persona->refresh();
    expect($persona->domicilio_calle)->toBe('Av. Emiliano Zapata')
        ->and($persona->domicilio)->toBe('Av. Emiliano Zapata 1234 Int. 5, col. Lomas de la Selva, Cuernavaca, Morelos, C.P. 62270')
        // Lo anterior queda en la solicitud (auditable).
        ->and($nueva->refresh()->datos_anteriores)->toHaveKey('domicilio', 'Calle Vieja 10, Centro');

    expect(array_column(app(DatosFaltantesService::class)->faltantes($persona)['personales'], 'campo'))
        ->not->toContain('domicilio_calle', 'domicilio_cp', 'domicilio_estado');
});

test('API V1: datos-faltantes pide cada campo del domicilio (estado con opciones) y entrega el domicilio vigente', function () {
    $persona = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id, 'domicilio' => 'Calle Vieja 10']);
    $cuenta = User::factory()->create(['colaborador_id' => $persona->id]);
    $cuenta->assignRole('colaborador');
    Sanctum::actingAs($cuenta);

    $datos = $this->getJson('/api/v1/colaborador/datos-faltantes')->assertOk()->json('data');
    $faltan = collect($datos['faltan'])->keyBy('campo');

    expect($faltan->keys()->all())->toContain('domicilio_calle', 'domicilio_numero_exterior', 'domicilio_numero_interior', 'domicilio_colonia', 'domicilio_cp', 'domicilio_municipio', 'domicilio_estado')
        ->and($faltan['domicilio_cp']['tipo'])->toBe('number')
        ->and($faltan['domicilio_estado']['tipo'])->toBe('opciones')
        ->and($faltan['domicilio_estado']['opciones'])->toHaveCount(32)
        ->and($datos['domicilio']['texto'])->toBe('Calle Vieja 10')
        ->and($datos['domicilio']['completo'])->toBeFalse()
        ->and(collect($datos['domicilio']['campos'])->pluck('campo')->all())->toBe([
            'domicilio_calle', 'domicilio_numero_exterior', 'domicilio_numero_interior', 'domicilio_colonia', 'domicilio_cp', 'domicilio_municipio', 'domicilio_estado',
        ]);
});

test('registro por QR: acepta el payload viejo (direccion) y el nuevo (campos estructurados)', function () {
    $servicio = app(IncorporacionInvitacionService::class);

    ['token' => $tokenViejo] = $servicio->crear(['colaborador_id' => clColaboradorEnContratacion()->id], $this->rh);
    $this->postJson("/api/v1/incorporacion/invitaciones/{$tokenViejo}/registrar", [
        'name' => 'Pedro', 'apellidos' => 'Luna', 'password' => 'Capacitacion2026!', 'password_confirmation' => 'Capacitacion2026!',
        'direccion' => 'Calle Vieja 10, Centro',
    ])->assertSuccessful();
    expect(Colaborador::query()->where('domicilio', 'Calle Vieja 10, Centro')->exists())->toBeTrue();

    $persona = clColaboradorEnContratacion();
    ['token' => $tokenNuevo] = $servicio->crear(['colaborador_id' => $persona->id], $this->rh);
    $this->postJson("/api/v1/incorporacion/invitaciones/{$tokenNuevo}/registrar", [
        'name' => 'Rosa', 'apellidos' => 'Sol', 'password' => 'Capacitacion2026!', 'password_confirmation' => 'Capacitacion2026!',
        ...domEstructurado(),
    ])->assertSuccessful();

    expect($persona->refresh()->domicilio_colonia)->toBe('Lomas de la Selva')
        ->and($persona->domicilio)->toBe('Av. Emiliano Zapata 1234 Int. 5, col. Lomas de la Selva, Cuernavaca, Morelos, C.P. 62270');
});

test('la lista de estados de la web es la misma que el Enum EstadoRepublica', function () {
    $ts = (string) file_get_contents(resource_path('js/lib/estadosRepublica.ts'));
    preg_match_all("/^\s+'([^']+)',\r?$/m", $ts, $coincidencias);

    expect($coincidencias[1])->toBe(array_map(fn (EstadoRepublica $e): string => $e->value, EstadoRepublica::cases()))
        ->and(EstadoRepublica::desdeTexto('EDO. DE MÉXICO'))->toBe(EstadoRepublica::EstadoDeMexico)
        ->and(EstadoRepublica::desdeTexto('Ciudad de Mexico'))->toBe(EstadoRepublica::CiudadDeMexico)
        ->and(EstadoRepublica::desdeTexto('Narnia'))->toBeNull();
});
