<?php

use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Services\Contratos\RegularizacionContratosService;
use App\Services\DocumentosMaestros\Calidad\ValidacionVisualMaestroService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use App\Services\Formatos\FormatoPreviewService;
use App\Services\Formatos\Motor\ConversorDocxPdf;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\Support\ConversorFielDePrueba;
use Tests\Support\ValidacionVisualDePrueba;

/*
| Punto 12 del cierre (prueba K): desde el expediente, la «Vista previa del
| contrato» abre aunque falten datos —cada faltante impreso como
| «PENDIENTE: <dato>», marca de agua de vista previa— y NO crea ningún
| documento oficial. La generación sigue siendo estricta.
| Usa los originales reales de Jurídico (docs/formatos_fuente): sin ellos se omite.
*/

/** Conversor de prueba que además guarda el último DOCX que recibió. */
class ConversorQueCaptura extends ConversorFielDePrueba
{
    public ?string $ultimoDocx = null;

    public function convertirFiel(string $contenidoDocx, ?array $soloCon = null): ?array
    {
        $this->ultimoDocx = $contenidoDocx;

        return parent::convertirFiel($contenidoDocx, $soloCon);
    }
}

function vcTextoDocx(string $docx): string
{
    $ruta = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($ruta, $docx);
    $zip = new ZipArchive;
    $zip->open($ruta);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($ruta);

    return html_entity_decode(strip_tags($xml));
}

beforeEach(function () {
    if (! is_dir(base_path('docs/formatos_fuente')) || glob(base_path('docs/formatos_fuente').'/*.docx') === []) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente (no se versionan).');
    }

    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $this->disco = sprintf('nas_vp_contrato_%d', getmypid());
    config(['expedientes.disk' => $this->disco, 'documentos_maestros.disk' => $this->disco, 'documentos_maestros.validacion_visual.al_importar' => true]);
    Storage::fake($this->disco);
    Notification::fake();

    $this->conversor = new ConversorQueCaptura(app(FormatoPreviewService::class));
    app()->instance(ConversorDocxPdf::class, $this->conversor);
    app()->instance(ValidacionVisualMaestroService::class, new ValidacionVisualDePrueba);
    app(ImportadorFormatosJuridicosService::class)->importarCarpeta(base_path('docs/formatos_fuente'));

    $this->estructura = clEstructura();
    $this->estructura['sucursal']->update(['direccion' => 'Av. Juárez 10', 'ciudad' => 'Tula de Allende', 'estado' => 'Hidalgo', 'codigo_postal' => '42800']);
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    $puesto = Puesto::factory()->create(['nombre' => 'Gestor de cobranza', 'grupo_documental' => 'gestor', 'meses_periodo_prueba' => 2]);
    // Faltan a propósito: estado civil y nacionalidad.
    $this->colaborador = Colaborador::factory()->create([
        'estatus' => 'activo',
        'estado_alta' => null,
        'name' => 'Adrián',
        'apellidos' => 'Díaz Mena',
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'departamento_id' => $this->estructura['departamento']->id,
        'puesto_id' => $puesto->id,
        'genero' => 'masculino',
        'fecha_nacimiento' => '1990-05-10',
        'curp' => 'DIMA900510HHGZNR01',
        'rfc' => 'DIMA900510AB1',
        'nss' => '12345678901',
        'telefono' => '7731234567',
        'domicilio_calle' => 'Hidalgo',
        'domicilio_numero_exterior' => '5',
        'domicilio_colonia' => 'Centro',
        'domicilio_municipio' => 'Tula de Allende',
        'domicilio_estado' => 'Hidalgo',
        'domicilio_cp' => '42800',
        'estado_civil' => null,
        'nacionalidad' => null,
        'fecha_ingreso' => CarbonImmutable::now('America/Mexico_City')->subDays(5)->toDateString(),
        'sueldo_mensual' => 9000,
    ]);
});

test('K · vista previa del contrato con datos faltantes: PDF con «PENDIENTE: …», sin documento oficial; generar sigue bloqueado', function () {
    $plan = app(RegularizacionContratosService::class)->plan($this->colaborador);
    expect($plan['estado'])->toBe('faltan_datos')
        ->and($plan['generable'])->toBeFalse();

    $respuesta = $this->actingAs($this->rh)
        ->get(route('rh.contratos.vista-previa', ['colaborador' => $this->colaborador->id, 'clave' => 'contrato_capacitacion', 'pendientes' => 1]));

    $respuesta->assertOk();
    expect($respuesta->headers->get('Content-Type'))->toStartWith('application/pdf')
        ->and($respuesta->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and((int) $respuesta->headers->get('X-Datos-Faltantes'))->toBeGreaterThan(0)
        ->and(substr((string) $respuesta->getContent(), 0, 4))->toBe('%PDF');

    $texto = vcTextoDocx((string) $this->conversor->ultimoDocx);
    // Cada dato faltante se imprime en su lugar (la marca de agua «VISTA
    // PREVIA — NO VÁLIDA PARA FIRMA» la agrega el motor al render).
    expect($texto)->toContain('PENDIENTE: estado civil')
        ->and($texto)->toContain('PENDIENTE: nacionalidad')
        ->and($texto)->toContain('DIMA900510HHGZNR01')
        // La declaración imprime al representante de la empresa; su firma es a pluma.
        ->and($texto)->toContain('representada por la señora Lesli Maribel Rodríguez Herrera');

    // El PDF de la vista previa lleva la marca en cada página.
    $pdf = (new Parser)->parseContent((string) $respuesta->getContent());
    expect($pdf->getPages())->not->toBeEmpty();

    foreach ($pdf->getPages() as $pagina) {
        expect($pagina->getText())->toContain('VISTA PREVIA')->and($pagina->getText())->toContain('PARA FIRMA');
    }

    // Nada oficial se creó.
    expect(GeneratedDocument::query()->count())->toBe(0);

    // Sin ?pendientes (módulo Contratos) los faltantes quedan en blanco.
    $this->actingAs($this->rh)->get(route('rh.contratos.vista-previa', ['colaborador' => $this->colaborador->id, 'clave' => 'contrato_capacitacion']))->assertOk();
    expect(vcTextoDocx((string) $this->conversor->ultimoDocx))->not->toContain('PENDIENTE:');

    // Generación estricta: con faltantes no genera nada.
    $this->actingAs($this->rh)->post(route('rh.contratos.generar', $this->colaborador->id))->assertRedirect();
    expect(GeneratedDocument::query()->count())->toBe(0);
});

/**
 * Párrafos del DOCX (texto plano de cada <w:p>, en orden).
 *
 * @return list<string>
 */
function vcParrafos(string $docx): array
{
    $ruta = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($ruta, $docx);
    $zip = new ZipArchive;
    $zip->open($ruta);
    $dom = new DOMDocument;
    $dom->loadXML((string) $zip->getFromName('word/document.xml'));
    $zip->close();
    @unlink($ruta);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $parrafos = [];

    foreach ($xpath->query('//w:p') ?: [] as $p) {
        $texto = '';

        foreach ($xpath->query('.//w:t', $p) ?: [] as $t) {
            $texto .= $t->textContent;
        }

        $parrafos[] = trim(str_replace("\u{00A0}", ' ', $texto));
    }

    return $parrafos;
}

test('paquete Gestor (capacitación inicial, confidencialidad, no competencia): imprime al representante de la empresa en la declaración, firmas simétricas con línea y sin marcadores', function () {
    // El nombre sale de la EMPRESA (Administración → Empresas), no del texto del formato.
    $this->estructura['empresa']->update(['representante_legal_nombre' => 'María Fernanda Ruiz Soto']);

    foreach (['contrato_capacitacion', 'contrato_confidencialidad', 'contrato_no_competencia'] as $clave) {
        $this->actingAs($this->rh)
            ->get(route('rh.contratos.vista-previa', ['colaborador' => $this->colaborador->id, 'clave' => $clave, 'pendientes' => 1]))
            ->assertOk();

        $docx = (string) $this->conversor->ultimoDocx;
        $texto = vcTextoDocx($docx);
        $mayus = mb_strtoupper($texto);
        $parrafos = vcParrafos($docx);

        // 1. Representante legal en la declaración (dato de la empresa), nunca en blanco.
        expect($mayus)->toContain('MARÍA FERNANDA RUIZ SOTO')
            ->and($mayus)->not->toContain('LESLI')
            ->and($texto)->not->toMatch('/representada por (la señora )?_{3,}/iu', $clave)
            // 2. Nombre del trabajador y sin marcadores sin reemplazar.
            ->and($texto)->toContain('ADRIÁN DÍAZ MENA')
            ->and($texto)->not->toMatch('/\{\{|\}\}|\$\{|«|»/u', $clave)
            // Género: «EL C.» para un trabajador (nunca «LA C.» ni «C.ADRIÁN»).
            ->and($texto)->not->toContain('LA C. ADRIÁN')
            ->and($texto)->not->toContain('C.ADRIÁN');

        // 3. Firmas: línea arriba de «REPRESENTANTE LEGAL» y arriba del nombre del
        // trabajador, del mismo largo; el renglón del representante ya no trae su nombre.
        $etiqueta = array_search('REPRESENTANTE LEGAL', $parrafos, true);
        $trabajador = array_search('ADRIÁN DÍAZ MENA', $parrafos, true);

        expect($etiqueta)->not->toBeFalse($clave)
            ->and($trabajador)->not->toBeFalse($clave)
            ->and($parrafos[$etiqueta - 1])->toMatch('/^_{15,}$/', $clave)
            ->and($parrafos[$trabajador - 1])->toBe($parrafos[$etiqueta - 1], $clave)
            ->and(preg_grep('/^(?:(?:EL|LA) )?C\.\s*MARÍA FERNANDA RUIZ SOTO\.?$|^MARÍA FERNANDA RUIZ SOTO$/iu', $parrafos))->toBe([], $clave);
    }

    // Una trabajadora: «LA C.» en confidencialidad y no competencia.
    $this->colaborador->update(['genero' => 'femenino']);

    foreach (['contrato_confidencialidad', 'contrato_no_competencia'] as $clave) {
        $this->actingAs($this->rh)
            ->get(route('rh.contratos.vista-previa', ['colaborador' => $this->colaborador->id, 'clave' => $clave, 'pendientes' => 1]))
            ->assertOk();
        $texto = vcTextoDocx((string) $this->conversor->ultimoDocx);

        expect($texto)->toContain('LA C. ADRIÁN DÍAZ MENA')
            ->and($texto)->not->toContain('EL C. ADRIÁN');
    }

    expect(GeneratedDocument::query()->count())->toBe(0);
});
