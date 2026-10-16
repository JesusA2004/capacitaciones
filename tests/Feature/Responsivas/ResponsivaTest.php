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
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| Responsivas de equipo (docs/responsiva/ResponsivaLaptopPanta.docx): el
| gerente las genera para su equipo (y su subgerente) y RH para cualquiera;
| el PDF sale de Chrome (Browsershot) y se guarda en el expediente, carpeta
| «Actas» (Actas y responsivas). El colaborador no las ve.
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

function rsDatos(array $extra = []): array
{
    return [
        'tipo' => 'equipo_trabajo',
        'lugar' => 'Cuernavaca, Morelos',
        'fecha_entrega' => now()->toDateString(),
        'observaciones' => 'Se entrega con funda.',
        'activos' => [
            ['descripcion' => 'Casco de motociclista', 'marca' => 'LS2', 'modelo' => 'FF353', 'numero_serie' => 'LS2-88231', 'color' => 'Negro', 'cantidad' => 1, 'estado' => 'nuevo', 'caracteristicas' => "Talla: M\nCertificación: DOT", 'accesorios' => 'Visera transparente'],
            ['descripcion' => 'Chaleco reflejante', 'cantidad' => 2, 'estado' => 'buen_estado'],
        ],
        ...$extra,
    ];
}

test('el gerente genera la responsiva de su subgerente: folio, equipos, snapshot y PDF en el expediente', function () {
    $respuesta = $this->actingAs($this->gerente)
        ->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos())
        ->assertCreated()
        ->assertJsonPath('responsiva.estado', 'generada');

    $responsiva = Responsiva::query()->with('activos')->findOrFail($respuesta->json('responsiva.id'));

    expect($responsiva->folio)->toBe(sprintf('RESP-%06d', $responsiva->id))
        ->and($responsiva->activos)->toHaveCount(2)
        ->and($responsiva->activos[1]->cantidad)->toBe(2)
        ->and($responsiva->entregado_por)->toBe($this->gerente->id)
        ->and($responsiva->snapshot['receptor']['nombre'])->toBe('Juan Manuel Pantaleón Cruz')
        ->and($responsiva->snapshot['receptor']['puesto'])->toBe('Subgerente')
        ->and($responsiva->snapshot['otorgante']['nombre'])->toBe('Laura Gómez Ruiz')
        ->and($responsiva->snapshot['otorgante']['puesto'])->toBe('Gerente de sucursal')
        ->and($responsiva->snapshot['empresa']['razon_social'])->toBe('Productos y Servicios Mr Lana SAPI de C.V.')
        ->and($responsiva->pdf_path)->toContain('/Actas/');

    Storage::disk('nas')->assertExists($responsiva->pdf_path);
    expect(Storage::disk('nas')->get($responsiva->pdf_path))->toStartWith('%PDF');

    $this->actingAs($this->gerente)->get(route('rh.responsivas.pdf', $responsiva))->assertOk();
    $this->actingAs($this->gerente)->getJson(route('rh.responsivas.index', $this->subgerente))
        ->assertOk()
        ->assertJsonPath('puede_generar', true)
        ->assertJsonPath('responsivas.0.folio', $responsiva->folio)
        ->assertJsonPath('valores_iniciales.lugar', 'Cuernavaca, Morelos');
});

test('el PDF lo imprime Chrome con el texto del formato y los datos del colaborador', function () {
    config(['pdf.renderer' => 'browsershot']);
    $html = new ArrayObject;
    $this->app->instance(BrowsershotRenderer::class, new class($html) extends BrowsershotRenderer
    {
        /** @param ArrayObject<int, string> $html */
        public function __construct(private ArrayObject $html) {}

        public function renderizar(string $html, OpcionesPdf $opciones): string
        {
            $this->html->append($html);

            return '%PDF-1.4 prueba';
        }
    });

    $this->actingAs($this->rh)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos())->assertCreated();

    $documento = $html[0];
    expect($documento)
        ->toContain('RESPONSIVA DE EQUIPO DE TRABAJO')
        ->toContain('JUAN MANUEL PANTALEÓN CRUZ')
        ->toContain('PRODUCTOS Y SERVICIOS MR LANA SAPI DE C.V.')
        ->toContain('Cuernavaca, Morelos, a')
        ->toContain('artículo 32, fracción III de la Ley Federal del Trabajo')
        ->toContain('artículo 134, fracción VI de la Ley Federal del Trabajo')
        ->toContain('autorizo al patrón para que se realicen los descuentos de mi nómina')
        ->toContain('Casco de motociclista')
        ->toContain('LS2-88231')
        ->toContain('Certificación')
        ->toContain('2 piezas')
        ->toContain('Receptor')
        ->toContain('Otorgante')
        ->toContain('ROCÍO HERNÁNDEZ PAZ')
        ->not->toContain('VISTA PREVIA');
});

test('la vista previa devuelve el PDF sin guardar nada', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.responsivas.vista-previa', $this->subgerente), rsDatos(), ['Accept' => 'application/pdf'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(Responsiva::query()->count())->toBe(0);
});

test('un gerente de otra sucursal no ve ni genera responsivas', function () {
    $this->actingAs($this->gerenteOrizaba)->getJson(route('rh.responsivas.index', $this->subgerente))->assertForbidden();
    $this->actingAs($this->gerenteOrizaba)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos())->assertForbidden();

    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos())->json('responsiva.id'),
    );

    $this->actingAs($this->gerenteOrizaba)->get(route('rh.responsivas.pdf', $responsiva))->assertForbidden();
    $this->actingAs($this->gerenteOrizaba)->postJson(route('rh.responsivas.cancelar', $responsiva), ['motivo' => 'No es de mi sucursal'])->assertForbidden();
});

test('el colaborador no ve responsivas, ni las suyas', function () {
    $colaborador = clUsuario('colaborador', ['sucursal_principal_id' => $this->centro->id]);
    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $colaborador->colaborador), rsDatos())->json('responsiva.id'),
    );

    $this->actingAs($colaborador)->getJson(route('rh.responsivas.index', $colaborador->colaborador))->assertForbidden();
    $this->actingAs($colaborador)->get(route('rh.responsivas.pdf', $responsiva))->assertForbidden();
    $this->actingAs($colaborador)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos())->assertForbidden();

    // Nadie se entrega equipo a sí mismo.
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->gerente->colaborador), rsDatos())->assertForbidden();
});

test('valida equipos, fecha y colaborador dado de baja', function () {
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos(['activos' => []]))
        ->assertUnprocessable()->assertJsonValidationErrors('activos');
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos(['fecha_entrega' => now()->addDay()->toDateString()]))
        ->assertUnprocessable()->assertJsonValidationErrors('fecha_entrega');

    $this->subgerente->update(['estatus' => EstadoUsuario::Inactivo]);
    $this->actingAs($this->rh)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos())
        ->assertUnprocessable()->assertJsonValidationErrors('responsiva');

    expect(Responsiva::query()->count())->toBe(0);
});

test('quien captura y quien entrega: prellenado cuando coinciden', function () {
    // El gerente captura y entrega él mismo: registrado_por == entregado_por.
    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos())->json('responsiva.id'),
    );

    expect($responsiva->entregado_por)->toBe($this->gerente->id)
        ->and($responsiva->registrado_por)->toBe($this->gerente->id)
        ->and($responsiva->snapshot['otorgante']['nombre'])->toBe('Laura Gómez Ruiz')
        ->and($responsiva->snapshot['otorgante']['puesto'])->toBe('Gerente de sucursal');
});

test('RH captura por otro: registrado_por es RH y entregado_por el responsable real elegido', function () {
    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->rh)
            ->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos(['responsable_entrega_id' => $this->gerente->id]))
            ->assertCreated()
            ->json('responsiva.id'),
    );

    expect($responsiva->registrado_por)->toBe($this->rh->id)
        ->and($responsiva->entregado_por)->toBe($this->gerente->id)
        // El snapshot congela al RESPONSABLE de entrega (quien entregó), no al capturador.
        ->and($responsiva->snapshot['otorgante']['nombre'])->toBe('Laura Gómez Ruiz')
        ->and($responsiva->snapshot['otorgante']['puesto'])->toBe('Gerente de sucursal');

    // El listado expone ambos.
    $this->actingAs($this->rh)->getJson(route('rh.responsivas.index', $this->subgerente))
        ->assertOk()
        ->assertJsonPath('responsivas.0.entregado_por', 'Laura Gómez Ruiz')
        ->assertJsonPath('responsivas.0.registrado_por', 'Rocío Hernández Paz');
});

test('el responsable de entrega fuera de alcance se rechaza', function () {
    // El gerente de Cuernavaca no puede poner como responsable al gerente de Orizaba.
    $this->actingAs($this->gerente)
        ->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos(['responsable_entrega_id' => $this->gerenteOrizaba->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('responsable_entrega_id');

    expect(Responsiva::query()->count())->toBe(0);
});

test('el snapshot congela receptor, responsable (nombre+puesto), empresa, sucursal, fecha y equipos', function () {
    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->rh)
            ->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos(['responsable_entrega_id' => $this->gerente->id]))
            ->json('responsiva.id'),
    );

    $s = $responsiva->snapshot;

    expect($s['receptor']['nombre'])->toBe('Juan Manuel Pantaleón Cruz')
        ->and($s['receptor']['puesto'])->toBe('Subgerente')
        ->and($s['receptor']['sucursal'])->toBe('Cuernavaca')
        ->and($s['otorgante']['nombre'])->toBe('Laura Gómez Ruiz')
        ->and($s['otorgante']['puesto'])->toBe('Gerente de sucursal')
        ->and($s['empresa']['razon_social'])->toBe('Productos y Servicios Mr Lana SAPI de C.V.')
        ->and($responsiva->fecha_entrega->toDateString())->toBe(now()->toDateString())
        ->and($s['equipos'])->toHaveCount(2)
        ->and($s['equipos'][0]['descripcion'])->toBe('Casco de motociclista')
        ->and($s['equipos'][0]['numero_serie'])->toBe('LS2-88231')
        ->and($s['equipos'][1]['cantidad'])->toBe(2);

    // Cambiar el puesto del responsable después NO altera lo ya congelado.
    $this->gerente->colaborador->puesto->update(['nombre' => 'Otro puesto']);
    expect($responsiva->refresh()->snapshot['otorgante']['puesto'])->toBe('Gerente de sucursal');
});

test('marcar firmada físicamente: estado Firmada sin archivo y con auditoría', function () {
    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos())->json('responsiva.id'),
    );

    $this->actingAs($this->gerente)
        ->postJson(route('rh.responsivas.marcar-firmada-fisica', $responsiva))
        ->assertOk()
        ->assertJsonPath('responsiva.estado', 'firmada')
        // No hay escaneo: el flujo nuevo no sube archivo.
        ->assertJsonPath('responsiva.tiene_firmada', false);

    $responsiva->refresh();

    expect($responsiva->estado)->toBe(EstadoResponsiva::Firmada)
        ->and($responsiva->firmada_path)->toBeNull()
        ->and($responsiva->firmada_fisicamente_en)->not->toBeNull()
        ->and($responsiva->firmada_fisicamente_por)->toBe($this->gerente->id);

    expect(Activity::query()->where('log_name', 'rh')->where('event', 'responsiva_firmada_fisicamente')->exists())->toBeTrue();
});

test('la responsiva no es inventario: se permiten dos responsivas con el mismo número de serie', function () {
    $serie = ['activos' => [['descripcion' => 'Laptop', 'numero_serie' => 'SN-DUP-001', 'cantidad' => 1, 'estado' => 'nuevo']]];

    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos($serie))->assertCreated();
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos($serie))->assertCreated();

    expect(Responsiva::query()->count())->toBe(2);
});

test('firmada, devolución y cancelación: estados y permisos', function () {
    $responsiva = Responsiva::query()->findOrFail(
        $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos())->json('responsiva.id'),
    );

    $this->actingAs($this->gerente)
        ->post(route('rh.responsivas.subir-firmada', $responsiva), ['archivo' => UploadedFile::fake()->create('firmada.pdf', 120, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('responsiva.estado', 'firmada')
        ->assertJsonPath('responsiva.tiene_firmada', true);

    $responsiva->refresh();
    Storage::disk('nas')->assertExists($responsiva->firmada_path);
    expect($responsiva->firmada_path)->toContain('/Actas/');

    // Firmada: el gerente ya no la cancela (solo RH).
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.cancelar', $responsiva), ['motivo' => 'Me equivoqué'])->assertForbidden();

    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.devolucion', $responsiva), ['devuelta_en' => now()->toDateString(), 'observaciones' => 'Regresa en buen estado'])
        ->assertOk()->assertJsonPath('responsiva.estado', 'devuelta');

    expect($responsiva->refresh()->estado)->toBe(EstadoResponsiva::Devuelta);
    $this->actingAs($this->rh)->postJson(route('rh.responsivas.cancelar', $responsiva), ['motivo' => 'Ya fue devuelta'])->assertForbidden();

    // Recién generada: quien la generó puede cancelarla; queda como historial.
    $otra = Responsiva::query()->findOrFail(
        $this->actingAs($this->gerente)->postJson(route('rh.responsivas.store', $this->subgerente), rsDatos())->json('responsiva.id'),
    );
    $this->actingAs($this->gerente)->postJson(route('rh.responsivas.cancelar', $otra), ['motivo' => 'Número de serie mal capturado'])
        ->assertOk()->assertJsonPath('responsiva.estado', 'cancelada');

    expect(Responsiva::query()->count())->toBe(2)
        ->and($otra->refresh()->motivo_cancelacion)->toBe('Número de serie mal capturado');
});
