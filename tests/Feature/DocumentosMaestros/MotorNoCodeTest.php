<?php

use App\Enums\EstadoValidacionVisual;
use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Services\DocumentosMaestros\Calidad\RasterizadorPdf;
use App\Services\DocumentosMaestros\Calidad\ValidacionVisualMaestroService;
use App\Services\DocumentosMaestros\Deteccion\LectorTextoPdf;
use App\Services\DocumentosMaestros\Deteccion\OcrLocalService;
use App\Services\DocumentosMaestros\Deteccion\TipoArchivoDocumento;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\PhpWord;
use setasign\Fpdi\Fpdi;
use Smalot\PdfParser\Parser;
use Tests\Support\ValidacionVisualDePrueba;

/*
| MOTOR NO-CODE (prueba obligatoria del cierre de Fase 1): un formato que
| NO existe en config se sube, se detectan sus espacios, se mapea desde la
| API del editor, se prueba, se activa y se genera para un colaborador —
| sin tocar PHP, config ni rutas. Igual con PDF escaneado (OCR local) y con
| un DOCX nuevo.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->colaborador = clExpedienteCompleto(Colaborador::factory()->create([
        'name' => 'María',
        'apellidos' => 'López Hernández',
        'curp' => 'LOHM900101MMSPRR09',
        'nss' => '12345678901',
        'contacto_emergencia_nombre' => 'MARÍA LÓPEZ',
        'contacto_emergencia_parentesco' => 'MADRE',
        'contacto_emergencia_telefono' => '7771234567',
        'contacto_emergencia_direccion' => 'CALLE EJEMPLO 123',
    ]));
});

/**
 * PDF digital de prueba: etiquetas con línea dibujada (vector), un blanco
 * escrito con guiones bajos y una línea de firma.
 */
function ncPdfDigital(string $tamano = 'Letter'): string
{
    $pdf = new Fpdi('P', 'mm', $tamano);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Text(55, 25, 'CARTA DE PRUEBA NO-CODE');
    $pdf->SetFont('Helvetica', '', 11);
    $pdf->Text(20, 50, 'Nombre del trabajador:');
    $pdf->Line(68, 50.5, 190, 50.5);
    $pdf->Text(20, 65, 'CURP:');
    $pdf->Line(35, 65.5, 120, 65.5);
    $pdf->Text(20, 80, 'Numero de seguridad social: ____________________');
    $pdf->Text(20, 95, 'Contacto de emergencia:');
    $pdf->Line(68, 95.5, 190, 95.5);
    $pdf->Line(30, 140, 100, 140);
    $pdf->Text(45, 146, 'Firma del trabajador');

    return (string) $pdf->Output('S');
}

/**
 * Asigna en el payload del editor el dato que corresponde a cada espacio
 * según su texto (como lo haría RH) y crea a mano los que el detector no
 * encontró en este servidor (sin Poppler/rasterizador).
 *
 * @param  list<array<string, mixed>>  $campos
 * @return list<array<string, mixed>>
 */
function ncMapear(array $campos, bool $pdf): array
{
    $reglas = ['nombre' => 'nombre_completo', 'curp' => 'curp', 'seguridad' => 'nss', 'emergencia' => 'contacto_emergencia_completo'];
    $asignados = [];

    foreach ($campos as &$campo) {
        $contexto = mb_strtolower((string) ($campo['contexto'] ?? ''));

        if ($campo['zona'] !== 'dato') {
            $campo['estado'] = 'confirmado';

            continue;
        }

        foreach ($reglas as $palabra => $clave) {
            if (str_contains($contexto, $palabra) && ! in_array($clave, $asignados, true)) {
                $campo = [...$campo, 'source_key' => $clave, 'expression' => null, 'estado' => 'confirmado', 'label' => $clave];
                $asignados[] = $clave;

                continue 2;
            }
        }

        // Espacio que no es de esta prueba: no lleva dato.
        $campo = [...$campo, 'zona' => 'ignorar', 'estado' => 'confirmado', 'source_key' => null];
    }
    unset($campo);

    if ($pdf) {
        $posiciones = ['nombre_completo' => [68, 46], 'curp' => [35, 61], 'nss' => [80, 76], 'contacto_emergencia_completo' => [68, 91]];

        foreach ($posiciones as $clave => [$x, $y]) {
            if (! in_array($clave, $asignados, true)) {
                $campos[] = ['field_key' => 'm_'.$clave, 'zona' => 'dato', 'estado' => 'confirmado', 'source_key' => $clave, 'expression' => null, 'transform' => null, 'label' => $clave, 'type' => 'texto', 'page' => 1, 'x' => $x, 'y' => $y, 'width' => 120, 'height' => 4.5, 'font_size' => 9, 'required' => true];
            }
        }
    }

    return $campos;
}

function ncTextoPdf(string $pdf): string
{
    return mb_strtoupper((new Parser)->parseContent($pdf)->getText());
}

test('PDF DIGITAL nuevo: subir → detectar → mapear en el editor → datos largos → probar → activar → generar, sin tocar código', function () {
    if (! app(RasterizadorPdf::class)->disponible()) {
        app()->instance(ValidacionVisualMaestroService::class, new ValidacionVisualDePrueba);
    }

    $respuesta = $this->actingAs($this->rh)->post('/rh/documentos-maestros/nuevo', [
        'nombre' => 'Carta de prueba no-code',
        'proceso' => 'expediente',
        'requiere_impresion' => '1',
        'requiere_firma_fisica' => '1',
        'archivo' => UploadedFile::fake()->createWithContent('carta.pdf', ncPdfDigital()),
    ], ['Accept' => 'application/json'])->assertCreated();

    $master = DocumentTemplate::query()->findOrFail($respuesta->json('data.id'));
    expect($master->familia)->toBe('carta_de_prueba_no_code.general')
        ->and($master->motor->value)->toBe('pdf_overlay')
        ->and(config('documentos_maestros.documentos.'.$master->familia))->toBeNull()
        ->and(($master->configuracion ?? [])['tipo_archivo'] ?? null)->toBe('pdf_texto');

    $editor = $this->actingAs($this->rh)->get("/rh/documentos-maestros/{$master->id}/editor")->assertOk()->viewData('page')['props']['editor'];

    if (app(LectorTextoPdf::class)->disponible() && app(RasterizadorPdf::class)->disponible()) {
        $sugerencias = collect($editor['campos'])->flatMap(fn (array $c) => array_column($c['sugerencias'], 'clave'))->all();
        expect($sugerencias)->toContain('nombre_completo')->toContain('curp')->toContain('nss')
            ->and($sugerencias)->toContain('contacto_emergencia_completo')->toContain('contacto_emergencia_telefono')
            ->and(collect($editor['campos'])->pluck('zona')->all())->toContain('firma')
            // Nada queda verde sin RH.
            ->and(collect($editor['campos'])->where('estado', 'confirmado')->count())->toBe(0);
    }

    $guardado = $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$master->id}/campos", ['campos' => ncMapear($editor['campos'], true)])->assertOk();
    expect($guardado->json('data.preparacion.chequeos.0.ok'))->toBeTrue();

    // Aún sin probar con un colaborador: no se activa.
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$master->id}/activar")->assertUnprocessable();

    // «Datos largos»: el contacto de emergencia completo con un dato largo
    // no cabe en una sola línea → se señala ese espacio; RH lo hace de
    // varias líneas y vuelve a probar.
    $largos = $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$master->id}/datos-largos")->assertOk()->json('data');
    $guardados = $guardado->json('data.campos');

    if (! $largos['ok']) {
        $senalados = array_column($largos['campos'], 'field_key');
        expect($senalados)->not->toBe([])
            ->and(collect($guardados)->whereIn('field_key', $senalados)->pluck('source_key')->all())->toBe(['contacto_emergencia_completo']);

        $corregidos = array_map(fn (array $c): array => in_array($c['field_key'], $senalados, true)
            ? [...$c, 'multiline' => true, 'y' => (float) $c['y'] - 4.5, 'height' => 9.5, 'font_size_min' => 6]
            : $c, $guardados);
        $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$master->id}/campos", ['campos' => $corregidos])->assertOk();
        $largos = $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$master->id}/datos-largos")->assertOk()->json('data');
    }

    expect($largos['ok'])->toBeTrue();

    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$master->id}/probar", ['colaborador_id' => $this->colaborador->id])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$master->id}/activar")->assertOk();

    expect($master->refresh()->activo)->toBeTrue();

    // El colaborador ya lo tiene «a demanda» en su expediente.
    $this->actingAs($this->rh)->postJson("/rh/documentos-proceso/colaborador/{$this->colaborador->id}/generar", ['clave' => $master->clave, 'proceso' => 'expediente'])->assertCreated();

    $documento = GeneratedDocument::query()->where('master_familia', $master->familia)->sole();
    $texto = ncTextoPdf(Storage::disk($documento->disk)->get($documento->path));

    expect($texto)->toContain('MARÍA LÓPEZ HERNÁNDEZ')
        ->and($texto)->toContain('LOHM900101MMSPRR09')
        ->and($texto)->toContain('12345678901')
        ->and($texto)->toContain('MADRE')
        ->and($texto)->toContain('7771234567');
});

test('PDF ESCANEADO: se reconoce como escaneado y el OCR LOCAL propone los espacios (RH confirma)', function () {
    app()->instance(ValidacionVisualMaestroService::class, new ValidacionVisualDePrueba);

    // OCR local de prueba: devuelve lo que Tesseract leería de la página.
    app()->instance(OcrLocalService::class, new class extends OcrLocalService
    {
        public function disponible(): bool
        {
            return true;
        }

        public function palabras(string $png, int $pagina, float $dpi): array
        {
            return [
                ['pagina' => 1, 'texto' => 'NSS:', 'x' => 20.0, 'y' => 46.0, 'ancho' => 12.0, 'alto' => 4.0, 'confianza' => 0.93],
                ['pagina' => 1, 'texto' => '____________________', 'x' => 34.0, 'y' => 46.0, 'ancho' => 80.0, 'alto' => 4.0, 'confianza' => 0.88],
            ];
        }
    });

    // Página solo imagen (sin capa de texto).
    $imagen = imagecreatetruecolor(850, 1100);
    imagefill($imagen, 0, 0, imagecolorallocate($imagen, 255, 255, 255));
    imagefilledrectangle($imagen, 130, 192, 450, 194, imagecolorallocate($imagen, 0, 0, 0));
    $ruta = tempnam(sys_get_temp_dir(), 'scan').'.png';
    imagepng($imagen, $ruta);
    $pdf = new Fpdi('P', 'mm', 'Letter');
    $pdf->AddPage();
    $pdf->Image($ruta, 0, 0, 215.9, 279.4);
    $escaneado = (string) $pdf->Output('S');
    @unlink($ruta);

    expect(app(TipoArchivoDocumento::class)->detectar($escaneado))->toBe('pdf_escaneado');

    $id = $this->actingAs($this->rh)->post('/rh/documentos-maestros/nuevo', [
        'nombre' => 'Formato escaneado',
        'proceso' => 'expediente',
        'archivo' => UploadedFile::fake()->createWithContent('escaneado.pdf', $escaneado),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    $master = DocumentTemplate::query()->findOrFail($id);
    $campos = $master->campos()->get();

    expect(($master->configuracion ?? [])['tipo_archivo'] ?? null)->toBe('pdf_escaneado')
        ->and($campos->pluck('detection_method')->all())->toContain('ocr_guiones')
        ->and($campos->firstWhere('detection_method', 'ocr_guiones')?->source_key)->toBe('nss')
        ->and($campos->firstWhere('detection_method', 'ocr_guiones')?->estado->value)->toBe('sugerido');

    $editor = $this->actingAs($this->rh)->get("/rh/documentos-maestros/{$id}/editor")->viewData('page')['props']['editor'];
    $mapeo = array_map(fn (array $c): array => $c['detection_method'] === 'ocr_guiones' ? [...$c, 'estado' => 'confirmado'] : [...$c, 'zona' => 'ignorar', 'estado' => 'confirmado'], $editor['campos']);
    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => $mapeo])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/datos-largos")->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/probar", ['colaborador_id' => $this->colaborador->id])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/activar")->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-proceso/colaborador/{$this->colaborador->id}/generar", ['clave' => $master->clave, 'proceso' => 'expediente'])->assertCreated();

    $documento = GeneratedDocument::query()->where('master_familia', $master->familia)->sole();
    expect(ncTextoPdf(Storage::disk($documento->disk)->get($documento->path)))->toContain('12345678901');
});

test('DOCX nuevo: los blancos se detectan del XML (sin OCR), se mapean en el editor y se generan; la firma queda en blanco', function () {
    $motor = dmMotorFielDePrueba();

    $word = new PhpWord;
    $seccion = $word->addSection();
    $seccion->addText('CONSTANCIA DE PRUEBA');
    $seccion->addText('Yo, ______________________________, con CURP ____________________, hago constar lo siguiente.');
    $seccion->addText('Contacto de emergencia: ______________________________');
    $seccion->addText('______________________________');
    $seccion->addText('Firma del colaborador');
    $ruta = tempnam(sys_get_temp_dir(), 'docx');
    $word->save($ruta, 'Word2007');
    $docx = (string) file_get_contents($ruta);
    @unlink($ruta);

    $id = $this->actingAs($this->rh)->post('/rh/documentos-maestros/nuevo', [
        'nombre' => 'Constancia de prueba',
        'proceso' => 'expediente',
        'archivo' => UploadedFile::fake()->createWithContent('constancia.docx', $docx),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    $master = DocumentTemplate::query()->findOrFail($id);
    $editor = $this->actingAs($this->rh)->get("/rh/documentos-maestros/{$id}/editor")->viewData('page')['props']['editor'];
    $campos = collect($editor['campos']);

    // 3 espacios para datos (la línea sola de firma no es un dato).
    expect($campos->where('zona', 'dato'))->toHaveCount(3)
        ->and($campos->first(fn (array $c) => str_contains((string) $c['contexto'], 'hago constar'))['sugerencias'][0]['clave'] ?? null)->toBe('curp')
        ->and(collect($campos->first(fn (array $c) => str_contains((string) $c['contexto'], 'emergencia'))['sugerencias'])->pluck('clave')->all())->toContain('contacto_emergencia_completo')
        ->and(collect($editor['parrafos'])->flatMap(fn (array $p) => array_column($p['segmentos'], 'tipo'))->all())->toContain('campo')->toContain('firma');

    $mapeo = $campos->map(fn (array $c): array => match (true) {
        str_starts_with((string) $c['contexto'], 'Yo,') && ! str_contains((string) $c['contexto'], 'hago constar') => [...$c, 'source_key' => 'nombre_completo', 'transform' => 'mayusculas', 'estado' => 'confirmado'],
        str_contains((string) $c['contexto'], 'hago constar') => [...$c, 'source_key' => 'curp', 'estado' => 'confirmado'],
        default => [...$c, 'source_key' => null, 'expression' => ['partes' => [
            ['tipo' => 'variable', 'clave' => 'contacto_emergencia_nombre'],
            ['tipo' => 'variable', 'clave' => 'contacto_emergencia_telefono', 'prefijo' => ' — Tel. ', 'opcional' => true],
        ]], 'estado' => 'confirmado'],
    })->values()->all();

    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => $mapeo])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/datos-largos")->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/probar", ['colaborador_id' => $this->colaborador->id])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/activar")->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-proceso/colaborador/{$this->colaborador->id}/generar", ['clave' => $master->clave, 'proceso' => 'expediente'])->assertCreated();

    $documento = GeneratedDocument::query()->where('master_familia', $master->familia)->sole();
    $temporal = tempnam(sys_get_temp_dir(), 'nc');
    file_put_contents($temporal, Storage::disk((string) $documento->docx_disk)->get((string) $documento->docx_path));
    $zip = new ZipArchive;
    $zip->open($temporal);
    $texto = strip_tags((string) $zip->getFromName('word/document.xml'));
    $zip->close();
    @unlink($temporal);

    expect($texto)->toContain('Yo, MARÍA LÓPEZ HERNÁNDEZ, con CURP LOHM900101MMSPRR09')
        ->and($texto)->toContain('MARÍA LÓPEZ — Tel. 7771234567')
        // La línea de firma se conserva tal cual.
        ->and($texto)->toMatch('/_{20,}/')
        ->and($motor['conversor']->conversiones)->toBeGreaterThan(0);
});

test('nueva versión con layout distinto: el mapa copiado vuelve a «por confirmar» y la versión no se activa', function () {
    app()->instance(ValidacionVisualMaestroService::class, new ValidacionVisualDePrueba);

    $id = $this->actingAs($this->rh)->post('/rh/documentos-maestros/nuevo', [
        'nombre' => 'Carta versionada',
        'proceso' => 'expediente',
        'archivo' => UploadedFile::fake()->createWithContent('carta.pdf', ncPdfDigital()),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $editor = $this->actingAs($this->rh)->get("/rh/documentos-maestros/{$id}/editor")->viewData('page')['props']['editor'];
    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => ncMapear($editor['campos'], true)])->assertOk();
    $v1 = DocumentTemplate::query()->findOrFail($id);

    // v2: mismo formato en tamaño A4 (las cajas ya no son confiables).
    $this->actingAs($this->rh)->post("/rh/documentos-maestros/familia/{$v1->familia}/versiones", [
        'archivo' => UploadedFile::fake()->createWithContent('carta-v2.pdf', ncPdfDigital('A4')),
    ], ['Accept' => 'application/json'])->assertCreated();

    $v2 = DocumentTemplate::query()->where('familia', $v1->familia)->where('version', 2)->sole();

    expect($v2->activo)->toBeFalse()
        ->and($v2->campos()->where('zona', 'dato')->where('estado', 'confirmado')->count())->toBe(0)
        ->and($v2->campos()->where('zona', 'dato')->where('estado', 'sugerido')->count())->toBeGreaterThan(0)
        ->and(collect(app(App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService::class)->bloqueosActivacion($v2))->pluck('clave')->all())->toContain('por_confirmar');
});

test('dato largo que no cabe en su caja BLOQUEA la generación (nunca se encima ni se corta)', function () {
    app()->instance(ValidacionVisualMaestroService::class, new ValidacionVisualDePrueba);

    $id = $this->actingAs($this->rh)->post('/rh/documentos-maestros/nuevo', [
        'nombre' => 'Caja chica',
        'proceso' => 'expediente',
        'archivo' => UploadedFile::fake()->createWithContent('caja.pdf', ncPdfDigital()),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $master = DocumentTemplate::query()->findOrFail($id);

    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => [
        ['field_key' => 'c1', 'zona' => 'dato', 'estado' => 'confirmado', 'source_key' => 'nombre_completo', 'type' => 'texto', 'page' => 1, 'x' => 68, 'y' => 46, 'width' => 8, 'height' => 3, 'font_size' => 9, 'font_size_min' => 8, 'required' => true],
    ]])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/probar", ['colaborador_id' => $this->colaborador->id])->assertOk();
    $master->refresh()->forceFill(['visual_validation_status' => EstadoValidacionVisual::Aprobada, 'visual_checked_at' => now(), 'visual_report' => ['desbordes' => []]])->save();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/activar")->assertOk();

    $this->actingAs($this->rh)->postJson("/rh/documentos-proceso/colaborador/{$this->colaborador->id}/generar", ['clave' => $master->clave, 'proceso' => 'expediente'])
        ->assertUnprocessable()->assertJsonPath('code', 'DOCUMENT_FIELD_OVERFLOW');

    expect(GeneratedDocument::query()->where('master_familia', $master->familia)->exists())->toBeFalse();
});

test('editor: una versión activa o con documentos emitidos no se edita (se crea versión nueva)', function () {
    app()->instance(ValidacionVisualMaestroService::class, new ValidacionVisualDePrueba);

    $id = $this->actingAs($this->rh)->post('/rh/documentos-maestros/nuevo', [
        'nombre' => 'Carta bloqueada',
        'proceso' => 'expediente',
        'archivo' => UploadedFile::fake()->createWithContent('carta.pdf', ncPdfDigital()),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $master = DocumentTemplate::query()->findOrFail($id);
    $master->update(['activo' => true]);

    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => []])->assertUnprocessable();
    $nueva = $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/copiar-version")->assertCreated()->json('data.id');

    expect(DocumentTemplate::query()->findOrFail($nueva))
        ->version->toBe(2)
        ->activo->toBeFalse();

    // Un colaborador no administra documentos maestros.
    $colaborador = clUsuario('colaborador');
    $this->actingAs($colaborador)->get("/rh/documentos-maestros/{$id}/editor")->assertForbidden();
    $this->actingAs($colaborador)->putJson("/rh/documentos-maestros/{$nueva}/campos", ['campos' => []])->assertForbidden();
});

test('editor: rechaza datos que no existen, expresiones con código y piezas no permitidas', function () {
    app()->instance(ValidacionVisualMaestroService::class, new ValidacionVisualDePrueba);

    $id = $this->actingAs($this->rh)->post('/rh/documentos-maestros/nuevo', [
        'nombre' => 'Carta segura',
        'proceso' => 'expediente',
        'archivo' => UploadedFile::fake()->createWithContent('carta.pdf', ncPdfDigital()),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    $caja = ['field_key' => 'c1', 'zona' => 'dato', 'estado' => 'confirmado', 'type' => 'texto', 'page' => 1, 'x' => 20, 'y' => 40, 'width' => 60, 'height' => 5];

    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => [[...$caja, 'source_key' => 'contacto_emergencia']]])->assertUnprocessable();
    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => [[...$caja, 'source_key' => null, 'expression' => ['partes' => [['tipo' => 'texto', 'valor' => '<?php echo 1; ?>']]]]]])->assertUnprocessable();
    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => [[...$caja, 'source_key' => null, 'expression' => ['partes' => [['tipo' => 'blade', 'valor' => '{{ $x }}']]]]]])->assertUnprocessable();
    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => [[...$caja, 'source_key' => 'nombre_completo']]])->assertOk();
});
