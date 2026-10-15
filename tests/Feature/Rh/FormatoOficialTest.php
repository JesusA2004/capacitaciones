<?php

use App\Enums\EstadoVersionFormato;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\OfficialFormat;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Formatos\Analisis\AnalizadorImagenOcr;
use App\Services\Formatos\GeneradorFormatoService;
use App\Services\Formatos\Variables\CatalogoVariablesFormato;
use App\Services\Formatos\Variables\ContextoFormato;
use App\Services\Formatos\Variables\NumeroALetras;
use App\Services\Formatos\Variables\ResolvedorVariablesFormato;
use Database\Factories\OfficialFormatVersionFactory;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use setasign\Fpdi\Fpdi;
use Smalot\PdfParser\Parser as PdfParser;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    config(['expedientes.disk' => 'nas', 'formatos_oficiales.disk' => 'nas']);

    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');
});

/**
 * PDF real de prueba (una o varias páginas) con etiquetas de formulario.
 *
 * @param  list<list<string>>  $paginas
 */
function pdfFormato(array $paginas = [['NOMBRE DEL COLABORADOR:', 'CURP:', 'PUESTO:']]): string
{
    $pdf = new Fpdi;
    $pdf->SetFont('Helvetica', '', 11);

    foreach ($paginas as $lineas) {
        $pdf->AddPage();

        foreach ($lineas as $i => $linea) {
            $pdf->SetXY(20, 30 + $i * 12);
            $pdf->Cell(0, 8, $linea);
        }
    }

    return (string) $pdf->Output('S');
}

function archivoPdf(string $contenido, string $nombre = 'formato.pdf'): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($ruta, $contenido);

    return new UploadedFile($ruta, $nombre, 'application/pdf', null, true);
}

function archivoDocx(string $texto): UploadedFile
{
    $word = new PhpWord;
    $word->addSection()->addText($texto);
    $ruta = tempnam(sys_get_temp_dir(), 'docx').'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($ruta);

    return new UploadedFile($ruta, 'contrato.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
}

function textoPdf(string $contenido): string
{
    return (new PdfParser)->parseContent($contenido)->getText();
}

/**
 * Plantilla publicada lista para generar, con los campos dados.
 *
 * @param  list<array<string, mixed>>  $campos
 */
function plantillaPublicada(array $campos, array $atributos = [], ?string $pdf = null): OfficialFormat
{
    $ruta = 'formatos-oficiales/plantillas/prueba-'.uniqid().'.pdf';
    Storage::disk('nas')->put($ruta, $pdf ?? pdfFormato());

    return OfficialFormat::factory()->configurado($campos)->create(['source_path' => $ruta, ...$atributos]);
}

function colaboradorCompleto(array $atributos = []): Colaborador
{
    $empresa = Empresa::factory()->create(['nombre' => 'MR. LANA', 'razon_social' => 'PRODUCTOS Y SERVICIOS MR. LANA, S.A.P.I. DE C.V.', 'rfc' => 'PSM010101AB1']);
    $sucursal = Sucursal::factory()->create(['nombre' => 'Córdoba', 'empresa_id' => $empresa->id, 'ciudad' => 'Córdoba', 'estado' => 'Veracruz']);

    return Colaborador::factory()->create([
        'name' => 'Ana',
        'apellidos' => 'López Martínez',
        'curp' => 'LOMA900926MVZPRN05',
        'rfc' => 'LOMA900926AB1',
        'nss' => '12345678901',
        'fecha_nacimiento' => '1990-09-26',
        'fecha_ingreso' => '2019-09-25',
        'puesto_id' => Puesto::factory()->create(['nombre' => 'Gestor'])->id,
        'departamento_id' => Departamento::factory()->create(['nombre' => 'Ventas'])->id,
        'sucursal_principal_id' => $sucursal->id,
        ...$atributos,
    ]);
}

function generarPara(OfficialFormat $formato, Colaborador $colaborador, array $extra = []): array
{
    return ['tipo_sujeto' => 'colaborador', 'sujeto_id' => $colaborador->id, ...$extra];
}

// --- Subida y validación ------------------------------------------------

// --- Versionado -----------------------------------------------------------

// --- Variables ---------------------------------------------------------------

test('el resolvedor obtiene nombre, CURP, edad exacta, puesto, departamento, sucursal, empresa y fechas', function () {
    Carbon::setTestNow('2026-09-25 10:00:00');
    $colaborador = colaboradorCompleto()->load(['sucursalPrincipal.empresa', 'puesto', 'departamento']);

    $valores = app(ResolvedorVariablesFormato::class)->resolver(new ContextoFormato($colaborador, fecha: now()));

    expect($valores['colaborador.nombre_completo'])->toBe('Ana López Martínez')
        ->and($valores['colaborador.curp'])->toBe('LOMA900926MVZPRN05')
        // Nació 1990-09-26: el 2026-09-25 aún tiene 35 (no 36 por redondeo de días).
        ->and($valores['colaborador.edad'])->toBe(35)
        ->and($valores['laboral.puesto'])->toBe('Gestor')
        ->and($valores['laboral.departamento'])->toBe('Ventas')
        ->and($valores['laboral.sucursal'])->toBe('Córdoba')
        ->and($valores['laboral.sucursal_ciudad_estado'])->toBe('Córdoba, Veracruz')
        ->and($valores['laboral.antiguedad'])->toBe('7 años')
        ->and($valores['empresa.razon_social'])->toBe('PRODUCTOS Y SERVICIOS MR. LANA, S.A.P.I. DE C.V.')
        ->and($valores['fecha.actual']->toDateString())->toBe('2026-09-25');

    Carbon::setTestNow();
});

test('cada variable declarada en el catálogo tiene valor calculado en el resolvedor', function () {
    $claves = array_keys(app(ResolvedorVariablesFormato::class)->resolver(new ContextoFormato(null)));

    foreach (app(CatalogoVariablesFormato::class)->claves() as $clave) {
        expect($claves)->toContain($clave);
    }
});

test('números y montos en letra', function () {
    $letras = new NumeroALetras;

    expect($letras->moneda(1234.56))->toBe('MIL DOSCIENTOS TREINTA Y CUATRO PESOS 56/100 M.N.')
        ->and($letras->moneda(21000))->toBe('VEINTIÚN MIL PESOS 00/100 M.N.')
        ->and($letras->moneda(1))->toBe('UN PESO 00/100 M.N.')
        ->and($letras->moneda(1000000))->toBe('UN MILLÓN DE PESOS 00/100 M.N.')
        ->and($letras->entero(2026))->toBe('dos mil veintiséis')
        ->and($letras->entero(115))->toBe('ciento quince');
});

// --- Generación ---------------------------------------------------------------

// --- Análisis ------------------------------------------------------------------

test('con Tesseract configurado, el OCR convierte el TSV en líneas con posición', function () {
    config(['formatos_oficiales.ocr.tesseract' => 'tesseract']);
    Process::fake([
        '*' => Process::result("level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n"
            ."5\t1\t1\t1\t1\t1\t100\t200\t80\t20\t95\tNOMBRE:\n"
            ."5\t1\t1\t1\t1\t2\t190\t200\t120\t20\t90\t________\n"),
    ]);

    $imagen = UploadedFile::fake()->image('formato.png', 850, 1100);
    $resultado = app(AnalizadorImagenOcr::class)->extraer($imagen->getRealPath(), [['numero' => 1, 'ancho' => 215.9, 'alto' => 279.4]]);

    expect($resultado['metodo'])->toBe('ocr')
        ->and($resultado['bloques'])->toHaveCount(1)
        ->and($resultado['bloques'][0]['texto'])->toBe('NOMBRE: ________')
        ->and($resultado['bloques'][0]['x'])->toBe(round(100 * 215.9 / 850, 2));
});

test('el importador crea versión 1 y un PDF modificado genera un borrador nuevo, nunca sobrescribe', function () {
    $carpeta = sys_get_temp_dir().'/formatos-prueba-'.uniqid();
    mkdir($carpeta);
    $archivo = $carpeta.'/formato permiso mr. lana.pdf';
    file_put_contents($archivo, pdfFormato([['NOMBRE:']]));
    config(['formatos_oficiales.origen_local' => $carpeta]);

    $this->artisan('formatos:importar-originales')->assertExitCode(0);
    $this->artisan('formatos:importar-originales')->assertExitCode(0);

    $formato = OfficialFormat::query()->where('slug', 'formato-permiso')->sole();
    expect($formato->versiones()->count())->toBe(1);

    $formato->versiones()->first()->update(['estado' => 'publicada']);
    file_put_contents($archivo, pdfFormato([['NOMBRE:', 'CURP:']]));
    $this->artisan('formatos:importar-originales')->assertExitCode(0);

    expect($formato->versiones()->count())->toBe(2)
        ->and($formato->versiones()->where('numero', 2)->first()?->estado)->toBe(EstadoVersionFormato::Borrador);

    unlink($archivo);
    rmdir($carpeta);
});

test('el servicio bloquea con mensaje legible cuando falta elegir el contexto', function () {
    $colaborador = colaboradorCompleto();
    $formato = plantillaPublicada([OfficialFormatVersionFactory::campo('prestamo.monto_autorizado')]);
    $generador = app(GeneradorFormatoService::class);

    $preparacion = $generador->preparar($formato->versionVigente, $generador->contexto($colaborador, null, null, null, $this->rh), [], true);

    expect($generador->motivoBloqueo($preparacion))->toBe('No se puede generar todavía: selecciona el préstamo.');
});

// --- API móvil -------------------------------------------------------------------
