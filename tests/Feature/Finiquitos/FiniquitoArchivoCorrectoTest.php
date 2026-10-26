<?php

use App\Enums\EstadoUsuario;
use App\Models\Baja;
use App\Models\BajaDocumento;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\DocumentoRequeridoBaja;
use App\Models\EmployeeDocument;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Bajas\BajaDocumentosService;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use setasign\Fpdi\Fpdi;

/*
| FINIQUITO: el archivo que se sube (PDF o foto) es EXACTAMENTE el que queda
| guardado, ligado a la baja/finiquito/colaborador correctos, con su tipo
| real, y el que devuelve la vista previa/descarga. Cubre la vía vigente
| (espacio «Finiquito firmado» de la baja, web y API) y la legacy (escaneo
| del finiquito firmado con features.cierre_flujo_legacy).
*/

beforeEach(function () {
    clRelojMediodiaMexico();
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->estructura = clEstructura();
    $sucursal = $this->estructura['sucursal'];
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $sucursal->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $sucursal->id]);
});

/** Bytes de un PDF real (no un archivo vacío con MIME declarado). */
function faqPdf(string $texto = 'Finiquito firmado'): string
{
    $pdf = new Fpdi;
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 14);
    $pdf->Cell(0, 10, $texto);

    return (string) $pdf->Output('S');
}

/** Bytes de una foto PNG real. */
function faqPng(): string
{
    $imagen = imagecreatetruecolor(40, 30);
    imagefill($imagen, 0, 0, (int) imagecolorallocate($imagen, 200, 120, 40));
    ob_start();
    imagepng($imagen);

    return (string) ob_get_clean();
}

/**
 * Archivo subido «de verdad»: el MIME se detecta del CONTENIDO (finfo), no
 * del nombre — como en producción. (Los fakes de Laravel lo infieren del nombre.)
 */
function faqArchivo(string $nombre, string $contenido): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'faq');
    file_put_contents($ruta, $contenido);

    return new UploadedFile($ruta, $nombre, null, null, true);
}

function faqPersona(): Colaborador
{
    $persona = Colaborador::factory()->create([
        'sucursal_principal_id' => test()->estructura['sucursal']->id,
        'puesto_id' => test()->estructura['puesto']->id,
        'jefe_id' => test()->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2024-01-01',
        'sueldo_mensual' => 12000,
    ]);
    User::factory()->create(['colaborador_id' => $persona->id])->assignRole('colaborador');

    return $persona;
}

/** Baja directa por renuncia (RV-01): su forma pide «Finiquito firmado». */
function faqBaja(Colaborador $persona): Baja
{
    $causal = CausalRotacion::query()->where('clave', 'RV-01')->firstOrFail();

    test()->actingAs(test()->gerente)->post(route('rh.bajas.store'), [
        'colaborador_id' => $persona->id,
        'forma_salida' => $causal->forma()->value,
        'causal_rotacion_id' => $causal->id,
        'fecha_baja' => now()->subDay()->toDateString(),
        'recontratable' => 'si',
        'comentarios' => 'La persona presentó su renuncia por escrito con su jefe.',
    ])->assertSessionHasNoErrors();

    return Baja::query()->where('colaborador_id', $persona->id)->latest('id')->firstOrFail();
}

function faqEspacioFiniquito(): DocumentoRequeridoBaja
{
    return DocumentoRequeridoBaja::query()->where('clave', 'RV.finiquito')->firstOrFail();
}

test('el PDF del finiquito firmado se guarda tal cual en su espacio, en su baja, y la vista previa devuelve ese mismo archivo', function () {
    $baja = faqBaja(faqPersona());
    $espacio = faqEspacioFiniquito();
    $contenido = faqPdf();

    $this->actingAs($this->rh)->post(route('rh.bajas.documentos.store', $baja), [
        'archivo' => faqArchivo('finiquito-firmado.pdf', $contenido),
        'documento_requerido_id' => $espacio->id,
    ])->assertSessionHasNoErrors();

    $documento = BajaDocumento::query()->sole();

    expect($documento->baja_id)->toBe($baja->id)
        ->and($documento->documento_requerido_id)->toBe($espacio->id)
        ->and($documento->nombre)->toBe('Finiquito firmado')
        ->and($documento->nombre_original)->toBe('finiquito-firmado.pdf')
        ->and($documento->mime)->toBe('application/pdf')
        ->and($documento->tamano)->toBe(strlen($contenido))
        ->and($documento->ruta)->toStartWith(sprintf('bajas/%d/', $baja->id))->toEndWith('.pdf')
        ->and(hash('sha256', (string) Storage::disk('nas')->get($documento->ruta)))->toBe(hash('sha256', $contenido));

    $respuesta = $this->actingAs($this->rh)->get(route('rh.bajas.documentos.ver', [$baja, $documento]));
    $respuesta->assertOk();

    expect(hash('sha256', $respuesta->streamedContent()))->toBe(hash('sha256', $contenido))
        ->and((string) $respuesta->headers->get('Content-Type'))->toStartWith('application/pdf');

    $espacioFiniquito = collect(app(BajaDocumentosService::class)->espacios($baja->refresh()))->firstWhere('id', $espacio->id);
    expect($espacioFiniquito['cumplido'])->toBeTrue()
        ->and(array_column($espacioFiniquito['archivos'], 'id'))->toBe([$documento->id]);
});

test('una foto del finiquito firmado se guarda como imagen y la vista previa la sirve como imagen (mismo contenido)', function () {
    $baja = faqBaja(faqPersona());
    $contenido = faqPng();

    $this->actingAs($this->rh)->post(route('rh.bajas.documentos.store', $baja), [
        'archivo' => faqArchivo('foto-finiquito.png', $contenido),
        'documento_requerido_id' => faqEspacioFiniquito()->id,
    ])->assertSessionHasNoErrors();

    $documento = BajaDocumento::query()->sole();
    $respuesta = $this->actingAs($this->rh)->get(route('rh.bajas.documentos.ver', [$baja, $documento]));

    expect($documento->mime)->toBe('image/png')
        ->and($documento->ruta)->toEndWith('.png')
        ->and(hash('sha256', $respuesta->streamedContent()))->toBe(hash('sha256', $contenido))
        ->and((string) $respuesta->headers->get('Content-Type'))->toStartWith('image/png');
});

test('una foto renombrada «.pdf» se guarda con la extensión de su contenido real, no la del nombre', function () {
    $baja = faqBaja(faqPersona());
    $contenido = faqPng();

    $this->actingAs($this->rh)->post(route('rh.bajas.documentos.store', $baja), [
        'archivo' => faqArchivo('finiquito.pdf', $contenido),
        'documento_requerido_id' => faqEspacioFiniquito()->id,
    ])->assertSessionHasNoErrors();

    $documento = BajaDocumento::query()->sole();

    expect($documento->ruta)->toEndWith('.png')
        ->and($documento->mime)->toBe('image/png')
        ->and(hash('sha256', (string) Storage::disk('nas')->get($documento->ruta)))->toBe(hash('sha256', $contenido));
});

test('un archivo que no es PDF ni imagen se rechaza con mensaje en español y no se guarda nada', function () {
    $baja = faqBaja(faqPersona());

    // Texto disfrazado de PDF: el contenido manda, no el nombre.
    $this->actingAs($this->rh)->post(route('rh.bajas.documentos.store', $baja), [
        'archivo' => faqArchivo('finiquito.pdf', 'esto no es un finiquito'),
        'documento_requerido_id' => faqEspacioFiniquito()->id,
    ])->assertSessionHasErrors(['archivo' => 'Solo se aceptan PDF o imágenes (JPG, PNG, WEBP).']);

    expect(BajaDocumento::query()->count())->toBe(0)
        ->and(Storage::disk('nas')->allFiles())->toBe([]);
});

test('el finiquito no cae en otro espacio ni en otra baja: espacio inexistente o de otra forma de salida se rechaza', function () {
    $baja = faqBaja(faqPersona());
    $otraBaja = faqBaja(faqPersona());
    $deRescision = DocumentoRequeridoBaja::query()->where('clave', 'RE.finiquito')->firstOrFail();

    // Antes: un id inexistente dejaba el archivo en «Otros documentos» y el
    // espacio del finiquito seguía pendiente.
    $this->actingAs($this->rh)->post(route('rh.bajas.documentos.store', $baja), [
        'archivo' => faqArchivo('finiquito.pdf', faqPdf()),
        'documento_requerido_id' => 999999,
    ])->assertSessionHasErrors(['documento_requerido_id' => 'Ese documento no corresponde a esta baja.']);

    $this->actingAs($this->rh)->post(route('rh.bajas.documentos.store', $baja), [
        'archivo' => faqArchivo('finiquito.pdf', faqPdf()),
        'documento_requerido_id' => $deRescision->id,
    ])->assertSessionHasErrors('documento_requerido_id');

    expect(BajaDocumento::query()->count())->toBe(0)
        ->and(Storage::disk('nas')->allFiles())->toBe([]);

    // Subido a la baja correcta: la otra baja no lo ve ni lo puede servir.
    $this->actingAs($this->rh)->post(route('rh.bajas.documentos.store', $baja), [
        'archivo' => faqArchivo('finiquito.pdf', faqPdf()),
        'documento_requerido_id' => faqEspacioFiniquito()->id,
    ])->assertSessionHasNoErrors();

    $documento = BajaDocumento::query()->sole();
    expect($documento->baja_id)->toBe($baja->id)
        ->and($otraBaja->documentos()->count())->toBe(0);

    $this->actingAs($this->rh)->get(route('rh.bajas.documentos.ver', [$otraBaja, $documento]))->assertNotFound();
});

test('API v1: el finiquito firmado se adjunta con el mismo servicio y queda el mismo archivo', function () {
    $baja = faqBaja(faqPersona());
    $contenido = faqPdf('Finiquito API');
    Sanctum::actingAs($this->rh);

    $this->postJson(route('api.v1.rh.bajas.documentos.store', $baja), [
        'archivo' => faqArchivo('finiquito.pdf', $contenido),
        'documento_requerido_id' => faqEspacioFiniquito()->id,
    ])->assertCreated()->assertJsonPath('data.nombre', 'Finiquito firmado');

    $documento = BajaDocumento::query()->sole();

    expect($documento->baja_id)->toBe($baja->id)
        ->and($documento->documento_requerido_id)->toBe(faqEspacioFiniquito()->id)
        ->and(hash('sha256', (string) Storage::disk('nas')->get($documento->ruta)))->toBe(hash('sha256', $contenido));

    $this->postJson(route('api.v1.rh.bajas.documentos.store', $baja), [
        'archivo' => faqArchivo('finiquito.exe', 'MZ ejecutable'),
        'documento_requerido_id' => faqEspacioFiniquito()->id,
    ])->assertUnprocessable()->assertJsonPath('errors.archivo.0', 'Solo se aceptan PDF o imágenes (JPG, PNG, WEBP).');
});

/** Finiquito legacy (solicitud de baja + cálculo) para el escaneo firmado. */
function faqFiniquitoLegacy(): SolicitudInterna
{
    config(['features.cierre_flujo_legacy' => true]);
    $persona = Colaborador::factory()->create(['fecha_ingreso' => now()->subYears(2)]);
    $solicitud = SolicitudInterna::factory()->create([
        'tipo' => 'baja_colaborador',
        'estado' => 'en_revision',
        'objetivo_colaborador_id' => $persona->id,
        'fecha_efectiva' => now()->addWeek(),
        'tipo_baja' => 'rescision',
    ]);
    test()->actingAs(test()->rh)->post(route('rh.solicitudes.finiquito.calcular', $solicitud), ['sueldo_mensual' => 15000])->assertSessionHasNoErrors();

    return $solicitud;
}

test('legacy: el escaneo firmado (foto renombrada .pdf) se guarda en SU finiquito con su tipo real y la descarga devuelve ese archivo sin caché', function () {
    $solicitud = faqFiniquitoLegacy();
    $contenido = faqPng();

    $this->actingAs($this->rh)->post(route('rh.solicitudes.finiquito.firmado', $solicitud), [
        'archivo' => faqArchivo('finiquito-firmado.pdf', $contenido),
    ])->assertSessionHasNoErrors();

    $finiquito = $solicitud->finiquitoCalculo()->firstOrFail();

    expect($finiquito->estado->value)->toBe('firmado')
        ->and($finiquito->documento_firmado_path)->toStartWith(sprintf('solicitudes/%d/finiquito/firmado-', $solicitud->id))->toEndWith('.png')
        ->and(hash('sha256', (string) Storage::disk('nas')->get((string) $finiquito->documento_firmado_path)))->toBe(hash('sha256', $contenido));

    $respuesta = $this->actingAs($this->rh)->get(route('rh.solicitudes.finiquito.descargar-pdf', $solicitud));
    $respuesta->assertOk();

    expect(hash('sha256', $respuesta->streamedContent()))->toBe(hash('sha256', $contenido))
        ->and((string) $respuesta->headers->get('Content-Disposition'))->toContain(sprintf('finiquito-%d.png', $solicitud->id))
        ->and((string) $respuesta->headers->get('Cache-Control'))->toContain('no-store');

    // La copia del expediente es del colaborador de la baja, tipo «Finiquito firmado», mismo contenido.
    $copia = EmployeeDocument::query()->whereHas('tipo', fn ($q) => $q->where('clave', 'finiquito_firmado'))->sole();
    expect($copia->colaborador_id)->toBe($solicitud->objetivo_colaborador_id)
        ->and(hash('sha256', (string) Storage::disk('nas')->get($copia->path)))->toBe(hash('sha256', $contenido));
});

test('legacy: el escaneo firmado rechaza lo que no es PDF/imagen con mensaje en español', function () {
    $solicitud = faqFiniquitoLegacy();

    $this->actingAs($this->rh)->post(route('rh.solicitudes.finiquito.firmado', $solicitud), [
        'archivo' => faqArchivo('finiquito.pdf', 'texto plano'),
    ])->assertSessionHasErrors(['archivo' => 'Solo se aceptan estos tipos de archivo: PDF, JPG, JPEG, PNG.']);

    expect($solicitud->finiquitoCalculo()->firstOrFail()->documento_firmado_path)->toBeNull();
});
