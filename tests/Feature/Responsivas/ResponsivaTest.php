<?php

use App\Enums\EstadoResponsiva;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Responsiva;
use App\Models\Sucursal;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Responsivas\ResponsivaPdfService;
use App\Services\Responsivas\ResponsivaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| Responsivas de las tres familias oficiales (docs/responsiva/*.docx): bien
| mueble, equipo electrónico y vehículo. El gerente las genera para su equipo
| (y su subgerente) y RH para cualquiera; el PDF sale de Chrome (Browsershot)
| y se guarda en el expediente, carpeta «Actas». El colaborador no las ve.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');

    $empresa = Empresa::factory()->create(['nombre' => 'Mr. Lana', 'razon_social' => 'Productos y Servicios Mr Lana SAPI de C.V.']);
    $this->centro = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'ciudad' => 'Cuernavaca', 'estado' => 'Morelos', 'empresa_id' => $empresa->id]);
    $this->orizaba = Sucursal::factory()->create(['nombre' => 'Orizaba', 'ciudad' => 'Orizaba', 'estado' => 'Veracruz', 'empresa_id' => $empresa->id]);

    $this->rh = clUsuario('rh_admin', ['name' => 'Rocío', 'apellidos' => 'Hernández Paz', 'sucursal_principal_id' => $this->centro->id]);
    $this->gerente = clUsuario('gerente_sucursal', [
        'name' => 'Laura', 'apellidos' => 'Gómez Ruiz', 'sucursal_principal_id' => $this->centro->id,
        'puesto_id' => Puesto::factory()->create(['nombre' => 'Gerente de sucursal'])->id,
    ]);
    $this->gerenteOrizaba = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->orizaba->id]);

    $this->subgerente = Colaborador::factory()->create([
        'name' => 'Juan Manuel', 'apellidos' => 'Pantaleón Cruz', 'numero_empleado' => '0142',
        'sucursal_principal_id' => $this->centro->id, 'estatus' => EstadoUsuario::Activo,
        'puesto_id' => Puesto::factory()->create(['nombre' => 'Subgerente'])->id,
    ]);
});

function rsMueble(array $extra = []): array
{
    return [
        'tipo' => 'bien_mueble',
        'lugar' => 'Cuernavaca, Morelos',
        'fecha_entrega' => now()->toDateString(),
        'observaciones' => 'Se entrega limpio.',
        'datos' => [
            'tipo_mueble' => 'escritorio',
            'marca' => 'Officemax',
            'modelo' => 'OM-120',
            'numero_inventario' => 'INV-0001',
            'numero_serie' => 'SER-77',
            'color' => 'Café',
            'material' => 'Madera',
            'tamano' => '1.20 x 0.60 m',
            'estado' => 'buen_estado',
            'danos' => 'Rayón leve en la cubierta.',
            'ubicacion_lugar' => 'Recepción planta baja',
            'ubicacion_area' => 'Oficina Cuernavaca',
        ],
        ...$extra,
    ];
}

function rsEquipo(array $extra = []): array
{
    return [
        'tipo' => 'equipo_electronico',
        'lugar' => 'Cuernavaca, Morelos',
        'fecha_entrega' => now()->toDateString(),
        'datos' => [
            'tipo_equipo' => 'celular',
            'marca' => 'Samsung',
            'modelo' => 'Galaxy A54',
            'numero_serie' => 'SN-ABC-123',
            'numero_inventario' => 'INV-TEL-9',
            'imei1' => '350000000000001',
            'imei2' => '350000000000002',
            'numero_telefonico' => '7771234567',
            'estado' => 'nuevo',
            'accesorios' => ['cargador', 'cable', 'funda'],
        ],
        ...$extra,
    ];
}

function rsVehiculo(array $extra = []): array
{
    return [
        'tipo' => 'vehiculo',
        'lugar' => 'Cuernavaca, Morelos',
        'fecha_entrega' => now()->toDateString(),
        'datos' => [
            'marca' => 'Nissan',
            'submarca' => 'NP300',
            'modelo_anio' => '2022',
            'color' => 'Blanco',
            'niv' => 'VIN-9988776655',
            'numero_motor' => 'MOT-554433',
            'placas' => 'ABC-12-34',
            'kilometraje' => 15230,
            'combustible' => 'medio',
            'estado_exterior' => 'bueno',
            'estado_interior' => 'con_detalles',
            'danos' => 'Rayón en la defensa trasera.',
            'accesorios' => ['llaves', 'extintor', 'tarjeta_circulacion'],
        ],
        ...$extra,
    ];
}

/**
 * Renderer de Browsershot que captura el HTML sin imprimir (se enlaza con
 * $this->app->instance dentro del test).
 *
 * @param  ArrayObject<int, string>  $html
 */
function fakeBrowsershot(ArrayObject $html): BrowsershotRenderer
{
    return new class($html) extends BrowsershotRenderer
    {
        /** @param ArrayObject<int, string> $html */
        public function __construct(private ArrayObject $html) {}

        public function renderizar(string $html, OpcionesPdf $opciones): string
        {
            $this->html->append($html);

            return '%PDF-1.4 prueba';
        }
    };
}

test('bien mueble: el gerente la genera con folio, snapshot, datos congelados y PDF en el expediente', function () {
    $respuesta = $this->actingAs($this->gerente)
        ->postJson(route('rh.responsivas.store', $this->subgerente), rsMueble())
        ->assertCreated()
        ->assertJsonPath('responsiva.estado', 'generada')
        ->assertJsonPath('responsiva.tipo', 'bien_mueble')
        ->assertJsonPath('responsiva.tipo_etiqueta', 'Bien mueble');

    $responsiva = Responsiva::query()->findOrFail($respuesta->json('responsiva.id'));

    expect($responsiva->folio)->toBe(sprintf('RESP-%06d', $responsiva->id))
        ->and($responsiva->datos['tipo_mueble'])->toBe('escritorio')
        ->and($responsiva->datos['numero_inventario'])->toBe('INV-0001')
        ->and($responsiva->datos['estado'])->toBe('buen_estado')
        ->and($responsiva->snapshot['receptor']['nombre'])->toBe('Juan Manuel Pantaleón Cruz')
        ->and($responsiva->snapshot['receptor']['puesto'])->toBe('Subgerente')
        ->and($responsiva->snapshot['otorgante']['nombre'])->toBe('Laura Gómez Ruiz')
        // El snapshot congela una copia de los datos de la familia.
        ->and($responsiva->snapshot['datos']['numero_inventario'])->toBe('INV-0001')
        ->and($responsiva->pdf_path)->toContain('/Actas/');

    // Impreso = PDF producido y guardado en el NAS.
    Storage::disk('nas')->assertExists($responsiva->pdf_path);
    expect(Storage::disk('nas')->get($responsiva->pdf_path))->toStartWith('%PDF');

    $this->actingAs($this->gerente)->get(route('rh.responsivas.pdf', $responsiva))->assertOk();
    $this->actingAs($this->gerente)->getJson(route('rh.responsivas.index', $this->subgerente))
        ->assertOk()
        ->assertJsonPath('responsivas.0.folio', $responsiva->folio);
});

test('equipo electrónico: datos (IMEI, accesorios) y snapshot se guardan congelados', function () {
    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->rh)
            ->postJson(route('rh.responsivas.store', $this->subgerente), rsEquipo(['responsable_entrega_id' => $this->gerente->id]))
            ->assertCreated()
            ->json('responsiva.id'),
    );

    expect($responsiva->tipo->value)->toBe('equipo_electronico')
        ->and($responsiva->datos['tipo_equipo'])->toBe('celular')
        ->and($responsiva->datos['imei1'])->toBe('350000000000001')
        ->and($responsiva->datos['accesorios'])->toBe(['cargador', 'cable', 'funda'])
        ->and($responsiva->registrado_por)->toBe($this->rh->id)
        ->and($responsiva->entregado_por)->toBe($this->gerente->id);

    $this->actingAs($this->rh)->getJson(route('rh.responsivas.index', $this->subgerente))
        ->assertOk()
        ->assertJsonPath('responsivas.0.tipo_etiqueta', 'Equipo electrónico')
        ->assertJsonPath('responsivas.0.registrado_por', 'Rocío Hernández Paz');
});

test('vehículo: placas, combustible y estados se guardan; kilometraje como entero', function () {
    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->gerente)
            ->postJson(route('rh.responsivas.store', $this->subgerente), rsVehiculo())
            ->assertCreated()
            ->json('responsiva.id'),
    );

    expect($responsiva->datos['placas'])->toBe('ABC-12-34')
        ->and($responsiva->datos['combustible'])->toBe('medio')
        ->and($responsiva->datos['estado_exterior'])->toBe('bueno')
        ->and($responsiva->datos['estado_interior'])->toBe('con_detalles')
        ->and($responsiva->datos['kilometraje'])->toBe(15230)
        ->and($responsiva->datos['accesorios'])->toBe(['llaves', 'extintor', 'tarjeta_circulacion']);
});

test('el PDF de cada familia lleva el formato oficial, los datos del colaborador y el fundamento legal', function () {
    // Un solo capturador para las tres familias (acumula el HTML impreso en
    // orden: mueble, equipo, vehículo).
    config(['pdf.renderer' => 'browsershot']);
    $html = new ArrayObject;
    $this->app->instance(BrowsershotRenderer::class, fakeBrowsershot($html));

    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsMueble())->assertCreated();
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsEquipo())->assertCreated();
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsVehiculo())->assertCreated();

    // BIEN MUEBLE
    expect($html[0])
        ->toContain('RESPONSIVA DE BIEN MUEBLE')
        ->toContain('JUAN MANUEL PANTALEÓN CRUZ')
        ->toContain('PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. DE C.V.')
        ->not->toContain('PRODUCTOS Y SERVICIOS MR LANA SAPI DE C.V.')
        ->toContain('artículos 132, fracción III')
        ->toContain('REPRESENTANTE LEGAL')
        ->not->toContain('Apoderado legal')
        ->not->toContain('APODERADO')
        ->toContain('Trabajador')
        ->toContain('Escritorio')
        ->toContain('INV-0001')
        ->toContain('Recepción planta baja')
        ->toContain('recibí físicamente el mobiliario')
        ->not->toContain('VISTA PREVIA');

    // EQUIPO ELECTRÓNICO
    expect($html[1])
        ->toContain('RESPONSIVA DE EQUIPO ELECTRONICO')
        ->toContain('350000000000001')
        ->toContain('Cargador')
        ->toContain('recibí físicamente el equipo');

    // VEHÍCULO
    expect($html[2])
        ->toContain('RESPONSIVA DE VEHÍCULO')
        ->toContain('ABC-12-34')
        ->toContain('15230 km')
        ->toContain('recibí físicamente el vehículo');
});

test('firma patronal simétrica: rol «Representante legal» / «Trabajador» arriba, razón social legal y colaborador abajo al mismo nivel; nunca el nombre corto de la empresa, «Apoderado» ni quien entrega', function () {
    // Empresa con nombre comercial y sin razón social (lo que en producción
    // imprimía «SISTEMAS MR LANA»).
    $this->centro->empresa->update(['nombre' => 'Sistemas Mr Lana', 'razon_social' => null]);
    config(['pdf.renderer' => 'browsershot']);
    $html = new ArrayObject;
    $this->app->instance(BrowsershotRenderer::class, fakeBrowsershot($html));

    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsEquipo())->assertCreated();

    $firmas = (string) preg_replace('/\s+/', ' ', strip_tags((string) strstr($html[0], '<table class="firmas">')));

    // Etiquetas de rol arriba (al mismo nivel) y, debajo de la línea, la razón
    // social legal a la izquierda y el colaborador a la derecha.
    expect($firmas)
        ->toContain('REPRESENTANTE LEGAL Trabajador PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. DE C.V. JUAN MANUEL PANTALEÓN CRUZ')
        ->not->toContain('LAURA GÓMEZ RUIZ')
        ->and(mb_strtoupper($html[0], 'UTF-8'))->not->toContain('SISTEMAS MR LANA')->not->toContain('APODERADO');
});

test('la vista previa devuelve el PDF sin guardar nada', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.responsivas.vista-previa', $this->subgerente), rsVehiculo(), ['Accept' => 'application/pdf'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(Responsiva::query()->count())->toBe(0);
});

test('el snapshot congela los datos aunque la persona cambie de puesto después', function () {
    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsMueble())->json('responsiva.id'),
    );

    $this->gerente->colaborador->puesto->update(['nombre' => 'Otro puesto']);
    $this->subgerente->puesto->update(['nombre' => 'Otro cargo']);

    expect($responsiva->refresh()->snapshot['otorgante']['puesto'])->toBe('Gerente de sucursal')
        ->and($responsiva->snapshot['receptor']['puesto'])->toBe('Subgerente');
});

test('validación por familia: campos requeridos, «otro» sin texto, fecha futura y colaborador de baja', function () {
    // Bien mueble: falta número de inventario.
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsMueble(['datos' => [...rsMueble()['datos'], 'numero_inventario' => '']]))
        ->assertUnprocessable()->assertJsonValidationErrors('datos.numero_inventario');

    // Bien mueble: tipo «otro» sin describir cuál.
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsMueble(['datos' => [...rsMueble()['datos'], 'tipo_mueble' => 'otro', 'tipo_mueble_otro' => '']]))
        ->assertUnprocessable()->assertJsonValidationErrors('datos.tipo_mueble_otro');

    // Vehículo: faltan placas.
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsVehiculo(['datos' => [...rsVehiculo()['datos'], 'placas' => '']]))
        ->assertUnprocessable()->assertJsonValidationErrors('datos.placas');

    // Fecha futura.
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsMueble(['fecha_entrega' => now()->addDay()->toDateString()]))
        ->assertUnprocessable()->assertJsonValidationErrors('fecha_entrega');

    // Colaborador dado de baja.
    $this->subgerente->update(['estatus' => EstadoUsuario::Inactivo]);
    $this->actingAs($this->rh)->postJson(route('rh.responsivas.store', $this->subgerente), rsMueble())
        ->assertUnprocessable()->assertJsonValidationErrors('responsiva');

    expect(Responsiva::query()->count())->toBe(0);
});

test('no es inventario: dos responsivas con el mismo número de serie se permiten', function () {
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsEquipo())->assertCreated();
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsEquipo())->assertCreated();

    expect(Responsiva::query()->count())->toBe(2);
});

test('autorización: otra sucursal y el propio colaborador no ven ni generan', function () {
    $this->actingAs($this->gerenteOrizaba)->getJson(route('rh.responsivas.index', $this->subgerente))->assertForbidden();
    $this->actingAs($this->gerenteOrizaba)->postJson(route('rh.responsivas.store', $this->subgerente), rsMueble())->assertForbidden();

    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsMueble())->json('responsiva.id'),
    );

    $this->actingAs($this->gerenteOrizaba)->get(route('rh.responsivas.pdf', $responsiva))->assertForbidden();
    // Nadie se entrega equipo a sí mismo.
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->gerente->colaborador), rsMueble())->assertForbidden();
});

test('flujo: marcar firmada físicamente (sin archivo), devolución y cancelación con sus permisos', function () {
    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsMueble())->json('responsiva.id'),
    );

    // Firma física: estado Firmada, sin escaneo, con auditoría.
    $this->actingAs($this->gerente)
        ->postJson(route('rh.responsivas.marcar-firmada-fisica', $responsiva))
        ->assertOk()
        ->assertJsonPath('responsiva.estado', 'firmada')
        ->assertJsonPath('responsiva.tiene_firmada', false);

    $responsiva->refresh();
    expect($responsiva->estado)->toBe(EstadoResponsiva::Firmada)
        ->and($responsiva->firmada_path)->toBeNull()
        ->and($responsiva->firmada_fisicamente_por)->toBe($this->gerente->id);
    expect(Activity::query()->where('event', 'responsiva_firmada_fisicamente')->exists())->toBeTrue();

    // Firmada: el gerente ya no la cancela (solo RH).
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.cancelar', $responsiva), ['motivo' => 'Me equivoqué'])->assertForbidden();

    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.devolucion', $responsiva), ['devuelta_en' => now()->toDateString(), 'observaciones' => 'Regresa en buen estado'])
        ->assertOk()->assertJsonPath('responsiva.estado', 'devuelta');
    expect($responsiva->refresh()->estado)->toBe(EstadoResponsiva::Devuelta);

    // Recién generada: quien la generó puede cancelarla.
    $otra = Responsiva::query()->findOrFail(
        $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsEquipo())->json('responsiva.id'),
    );
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.cancelar', $otra), ['motivo' => 'Número de serie mal capturado'])
        ->assertOk()->assertJsonPath('responsiva.estado', 'cancelada');
});

test('P1-14: el POST legacy para subir escaneo firmado ya no existe, pero el GET legacy sigue leyendo', function () {
    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsMueble())->json('responsiva.id'),
    );

    // El POST para crear un escaneo firmado nuevo fue retirado: en esa ruta
    // solo queda el GET legacy, así que el verbo POST ya no es aceptado (405).
    $this->actingAs($this->gerente)
        ->post("/rh/responsivas/{$responsiva->id}/firmada", ['archivo' => UploadedFile::fake()->create('firmada.pdf', 120, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertStatus(405);

    // Una responsiva nueva no tiene escaneo firmado y el GET devuelve 404.
    $responsiva->refresh();
    expect($responsiva->firmada_path)->toBeNull();
    $this->actingAs($this->gerente)->get(route('rh.responsivas.firmada', $responsiva))->assertNotFound();

    // Escaneo legacy preexistente: el GET lo sigue leyendo (solo lectura histórica).
    $rutaLegacy = 'expedientes/legacy/Responsiva firmada.pdf';
    Storage::disk('nas')->put($rutaLegacy, '%PDF-legacy');
    $responsiva->update(['firmada_path' => $rutaLegacy, 'firmada_subida_en' => now()]);

    $this->actingAs($this->gerente)->get(route('rh.responsivas.firmada', $responsiva))->assertOk();
    $this->actingAs($this->gerente)->getJson(route('rh.responsivas.index', $this->subgerente))
        ->assertOk()
        ->assertJsonPath('responsivas.0.tiene_firmada', true);
});

test('valoresIniciales no revienta cuando el colaborador no tiene sucursal principal (P2-10)', function () {
    // Sin sucursal principal, lugarPorDefecto() accedía a ->ciudad/->municipio
    // sobre null y reventaba con 500; ahora cae a un lugar vacío sin romper.
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => null,
        'estatus' => EstadoUsuario::Activo,
    ]);

    $valores = app(ResponsivaService::class)->valoresIniciales($colaborador, $this->rh);

    expect($valores['lugar'])->toBe('');
});

test('regenerar reimprime el PDF con «Representante legal» (nunca «Apoderado» ni el nombre comercial) y no cambia el estado', function () {
    $id = $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsEquipo())->json('responsiva.id');
    $responsiva = Responsiva::findOrFail($id);

    $this->actingAs($this->rh)->postJson(route('rh.responsivas.regenerar', $responsiva))
        ->assertOk()
        ->assertJsonPath('responsiva.id', $id);

    $responsiva->refresh();
    expect($responsiva->estado)->toBe(EstadoResponsiva::Generada)
        ->and($responsiva->pdf_path)->not->toBeNull();

    $pdf = app(ResponsivaPdfService::class);
    $html = mb_strtoupper($pdf->html($pdf->datos($responsiva)), 'UTF-8');
    expect($html)->toContain('REPRESENTANTE LEGAL')
        ->and($html)->not->toContain('APODERADO');
});

test('un gerente de otra sucursal (sin alcance) no puede regenerar la responsiva', function () {
    $id = $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsMueble())->json('responsiva.id');

    $this->actingAs($this->gerenteOrizaba)->postJson(route('rh.responsivas.regenerar', $id))->assertForbidden();
});
