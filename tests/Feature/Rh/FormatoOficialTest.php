<?php

use App\Enums\EstadoVersionFormato;
use App\Enums\EstrategiaFormato;
use App\Enums\TipoArchivoFormato;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\OfficialFormat;
use App\Models\OfficialFormatGeneration;
use App\Models\OfficialFormatVersion;
use App\Models\Prestamo;
use App\Models\Puesto;
use App\Models\SolicitudInterna;
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

test('subir un PDF válido crea el formato, su versión 1 en borrador y el análisis', function () {
    $this->actingAs($this->rh)
        ->post(route('rh.formatos-oficiales.store'), [
            'nombre' => 'Constancia laboral',
            'tipo' => 'constancia',
            'aplica_a' => 'colaborador',
            'archivo' => archivoPdf(pdfFormato()),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $formato = OfficialFormat::query()->where('nombre', 'Constancia laboral')->sole();
    $version = $formato->versiones()->sole();

    expect($version->numero)->toBe(1)
        ->and($version->estado)->toBe(EstadoVersionFormato::Borrador)
        ->and($version->file_type)->toBe(TipoArchivoFormato::Pdf)
        ->and($version->source_hash)->toHaveLength(64)
        ->and($version->paginas)->toHaveCount(1)
        ->and($version->created_by)->toBe($this->rh->id)
        ->and($formato->version_vigente_id)->toBeNull()
        ->and($formato->tieneConfiguracion())->toBeFalse();

    Storage::disk('nas')->assertExists($version->source_path);

    // El análisis detecta las etiquetas del formato.
    $variables = collect($version->analisis['sugerencias'])->pluck('variable');
    expect($variables)->toContain('colaborador.nombre_completo', 'colaborador.curp', 'laboral.puesto');
    $this->assertDatabaseHas('activity_log', ['description' => 'formato_oficial_creado']);
});

test('rechaza archivos que no son PDF, Word o imagen reales aunque digan serlo', function () {
    $falso = UploadedFile::fake()->createWithContent('formato.pdf', 'MZ esto es un ejecutable');

    $this->actingAs($this->rh)
        ->post(route('rh.formatos-oficiales.store'), [
            'nombre' => 'Falso', 'tipo' => 'otro', 'aplica_a' => 'colaborador', 'archivo' => $falso,
        ])
        ->assertSessionHasErrors('archivo');

    $exe = UploadedFile::fake()->create('programa.exe', 10, 'application/x-msdownload');

    $this->actingAs($this->rh)
        ->post(route('rh.formatos-oficiales.store'), [
            'nombre' => 'Exe', 'tipo' => 'otro', 'aplica_a' => 'colaborador', 'archivo' => $exe,
        ])
        ->assertSessionHasErrors('archivo');

    expect(OfficialFormat::query()->count())->toBe(0);
});

test('subir una imagen la normaliza a un PDF base de una página', function () {
    $this->actingAs($this->rh)
        ->post(route('rh.formatos-oficiales.store'), [
            'nombre' => 'Permiso escaneado', 'tipo' => 'permiso', 'aplica_a' => 'colaborador',
            'archivo' => UploadedFile::fake()->image('permiso.png', 850, 1100),
        ])
        ->assertSessionHasNoErrors();

    $version = OfficialFormatVersion::query()->sole();

    expect($version->file_type)->toBe(TipoArchivoFormato::Imagen)
        ->and($version->base_path)->not->toBe($version->source_path)
        ->and($version->paginas[0]['ancho'])->toBe(215.9)
        ->and($version->paginas[0]['alto'])->toBeGreaterThan(270.0)
        ->and($version->analisis['mensajes'][0] ?? '')->toContain('manualmente');

    expect(str_starts_with(Storage::disk('nas')->get($version->base_path), '%PDF'))->toBeTrue();
});

test('un Word con marcadores usa la estrategia de variables y convierte los desconocidos en campos manuales', function () {
    $this->actingAs($this->rh)
        ->post(route('rh.formatos-oficiales.store'), [
            'nombre' => 'Contrato individual', 'tipo' => 'contrato', 'aplica_a' => 'colaborador',
            'archivo' => archivoDocx('Contrato de {{colaborador.nombre_completo}} con CURP {{curp}}. Testigo: {{testigo_1}}'),
        ])
        ->assertSessionHasNoErrors();

    $version = OfficialFormatVersion::query()->sole();
    $campos = collect($version->campos)->keyBy('placeholder');

    expect($version->file_type)->toBe(TipoArchivoFormato::Docx)
        ->and($version->estrategia)->toBe(EstrategiaFormato::DocxVariables)
        ->and($campos['colaborador.nombre_completo']['variable'])->toBe('colaborador.nombre_completo')
        ->and($campos['curp']['variable'])->toBe('colaborador.curp')
        ->and($campos['testigo_1']['tipo'])->toBe('manual')
        ->and($campos['testigo_1']['requerido'])->toBeTrue();
});

test('sin permiso de crear/configurar/versionar no se modifica ninguna plantilla', function () {
    $auxiliar = User::factory()->create();
    $auxiliar->assignRole('rh_auxiliar');
    $formato = OfficialFormat::factory()->create();
    $borrador = OfficialFormatVersion::factory()->create(['official_format_id' => $formato->id]);

    $this->actingAs($auxiliar)
        ->post(route('rh.formatos-oficiales.store'), ['nombre' => 'X', 'tipo' => 'otro', 'aplica_a' => 'colaborador', 'archivo' => archivoPdf(pdfFormato())])
        ->assertForbidden();

    $this->actingAs($auxiliar)
        ->putJson(route('rh.formatos-oficiales.versiones.campos', $borrador), ['campos' => [OfficialFormatVersionFactory::campo('colaborador.curp')]])
        ->assertForbidden();

    $this->actingAs($auxiliar)->post(route('rh.formatos-oficiales.versiones.publicar', $borrador))->assertForbidden();
    $this->actingAs($auxiliar)->post(route('rh.formatos-oficiales.archivar', $formato))->assertForbidden();

    expect($borrador->refresh()->estado)->toBe(EstadoVersionFormato::Borrador)
        ->and($borrador->campos)->toBe([]);
});

// --- Versionado -----------------------------------------------------------

test('publicar una versión nueva retira la anterior sin borrarla y los documentos viejos conservan su versión', function () {
    $colaborador = colaboradorCompleto();
    $formato = plantillaPublicada([OfficialFormatVersionFactory::campo('colaborador.nombre_completo')]);
    $v1 = $formato->versionVigente;

    $this->actingAs($this->rh)->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador))->assertOk();
    $generacionV1 = OfficialFormatGeneration::query()->sole();

    // Versión nueva con otro PDF: queda en borrador y la v1 sigue vigente.
    $this->actingAs($this->rh)
        ->post(route('rh.formatos-oficiales.versiones.store', $formato), ['archivo' => archivoPdf(pdfFormato([['NOMBRE:', 'CURP:']]))])
        ->assertSessionHasNoErrors();

    $v2 = $formato->versiones()->where('numero', 2)->sole();
    expect($v2->estado)->toBe(EstadoVersionFormato::Borrador)
        ->and($formato->refresh()->version_vigente_id)->toBe($v1->id)
        // Conserva el mapeo de la versión anterior.
        ->and($v2->campos)->toHaveCount(1);

    $this->actingAs($this->rh)->post(route('rh.formatos-oficiales.versiones.publicar', $v2))->assertSessionHasNoErrors();

    expect($formato->refresh()->version_vigente_id)->toBe($v2->id)
        ->and($v1->refresh()->estado)->toBe(EstadoVersionFormato::Retirada)
        ->and($generacionV1->refresh()->official_format_version_id)->toBe($v1->id)
        ->and($generacionV1->version_numero)->toBe(1);

    Storage::disk('nas')->assertExists($v1->source_path);

    // Lo nuevo ya sale con la v2.
    $this->actingAs($this->rh)->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador))->assertOk();
    expect(OfficialFormatGeneration::query()->latest('id')->first()->version_numero)->toBe(2);
});

test('una versión publicada no se puede modificar: exige versión nueva', function () {
    $formato = plantillaPublicada([OfficialFormatVersionFactory::campo('colaborador.nombre_completo')]);

    $this->actingAs($this->rh)
        ->putJson(route('rh.formatos-oficiales.versiones.campos', $formato->versionVigente), ['campos' => []])
        ->assertUnprocessable();

    expect($formato->versionVigente->refresh()->campos)->toHaveCount(1);
});

test('solo puede haber un borrador a la vez y se puede descartar si nunca generó documentos', function () {
    $formato = plantillaPublicada([OfficialFormatVersionFactory::campo('colaborador.nombre_completo')]);

    $this->actingAs($this->rh)->post(route('rh.formatos-oficiales.versiones.store', $formato))->assertSessionHasNoErrors();
    $this->actingAs($this->rh)->post(route('rh.formatos-oficiales.versiones.store', $formato))->assertSessionHasErrors('formato');

    $borrador = $formato->versiones()->where('estado', 'borrador')->sole();
    $this->actingAs($this->rh)->delete(route('rh.formatos-oficiales.versiones.descartar', $borrador))->assertSessionHasNoErrors();

    expect(OfficialFormatVersion::query()->whereKey($borrador->id)->exists())->toBeFalse();
    // El archivo compartido con la v1 no se borra.
    Storage::disk('nas')->assertExists($formato->versionVigente->source_path);
});

test('guardar el mapeo valida que la variable exista y que el formato aplique', function () {
    $formato = OfficialFormat::factory()->create();
    $borrador = OfficialFormatVersion::factory()->create(['official_format_id' => $formato->id]);

    $this->actingAs($this->rh)
        ->putJson(route('rh.formatos-oficiales.versiones.campos', $borrador), ['campos' => [OfficialFormatVersionFactory::campo('colaborador.estado_civil')]])
        ->assertUnprocessable();

    $this->actingAs($this->rh)
        ->putJson(route('rh.formatos-oficiales.versiones.campos', $borrador), ['campos' => [OfficialFormatVersionFactory::campo('colaborador.curp', ['formato' => 'moneda_letra'])]])
        ->assertUnprocessable();

    $this->actingAs($this->rh)
        ->putJson(route('rh.formatos-oficiales.versiones.campos', $borrador), ['campos' => [
            OfficialFormatVersionFactory::campo('laboral.fecha_ingreso', ['formato' => 'larga', 'requerido' => true]),
            ['id' => 'm1', 'tipo' => 'manual', 'etiqueta' => 'Lugar de firma', 'pagina' => 1, 'x' => 10, 'y' => 10, 'ancho' => 50, 'alto' => 6],
        ]])
        ->assertOk();

    expect($borrador->refresh()->campos)->toHaveCount(2);
});

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

test('genera el PDF final con los datos del colaborador, lo guarda en su expediente y lo audita', function () {
    $colaborador = colaboradorCompleto();
    $formato = plantillaPublicada([
        OfficialFormatVersionFactory::campo('colaborador.nombre_completo', ['requerido' => true]),
        OfficialFormatVersionFactory::campo('colaborador.curp', ['y' => 50, 'requerido' => true]),
        OfficialFormatVersionFactory::campo('laboral.puesto', ['y' => 60, 'formato' => 'mayusculas']),
        OfficialFormatVersionFactory::campo('laboral.fecha_ingreso', ['y' => 70, 'formato' => 'larga']),
    ], ['tipo' => 'constancia']);

    $respuesta = $this->actingAs($this->rh)
        ->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador))
        ->assertOk();

    $generacion = OfficialFormatGeneration::query()->sole();

    expect($generacion->colaborador_id)->toBe($colaborador->id)
        ->and($generacion->official_format_version_id)->toBe($formato->version_vigente_id)
        ->and($generacion->version_numero)->toBe(1)
        ->and($generacion->generated_by_id)->toBe($this->rh->id)
        ->and($generacion->en_expediente)->toBeTrue()
        ->and($generacion->data_snapshot['colaborador.curp'])->toBe('LOMA900926MVZPRN05')
        ->and($generacion->data_snapshot['laboral.puesto'])->toBe('GESTOR')
        ->and($generacion->data_snapshot['laboral.fecha_ingreso'])->toBe('25 de septiembre de 2019');

    $pdf = Storage::disk('nas')->get($generacion->generated_path);
    expect($generacion->checksum)->toBe(hash('sha256', $pdf))
        ->and(textoPdf($pdf))->toContain('Ana López Martínez')
        ->and(textoPdf($pdf))->toContain('LOMA900926MVZPRN05')
        ->and(textoPdf($pdf))->not->toContain('VISTA PREVIA');

    // Nunca se exponen rutas del NAS.
    expect($respuesta->getContent())->not->toContain($generacion->generated_path)
        ->and($respuesta->getContent())->not->toContain('formatos-oficiales/plantillas');

    $this->assertDatabaseHas('activity_log', ['description' => 'formato_oficial_generado', 'subject_id' => $generacion->id]);

    $this->actingAs($this->rh)->get(route('rh.formatos-oficiales.descargar', $generacion))->assertOk();
});

test('un dato requerido faltante bloquea la generación y dice cuál falta con liga al expediente', function () {
    $colaborador = colaboradorCompleto(['curp' => null]);
    $formato = plantillaPublicada([
        OfficialFormatVersionFactory::campo('colaborador.nombre_completo', ['requerido' => true]),
        OfficialFormatVersionFactory::campo('colaborador.curp', ['y' => 50, 'requerido' => true]),
    ]);

    $preparacion = $this->actingAs($this->rh)
        ->postJson(route('rh.formatos-oficiales.preparar', $formato), generarPara($formato, $colaborador))
        ->assertOk()
        ->json();

    expect($preparacion['puede_generar'])->toBeFalse()
        ->and($preparacion['faltantes'][0]['etiqueta'])->toBe('CURP')
        ->and($preparacion['faltantes'][0]['completar_url'])->toBe(route('rh.expedientes.show', $colaborador->id))
        ->and($preparacion['motivo'])->toContain('CURP');

    $this->actingAs($this->rh)
        ->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador))
        ->assertUnprocessable()
        ->assertJsonPath('errors.documento.0', fn (string $mensaje) => str_contains($mensaje, 'Falta: CURP') || str_contains($mensaje, 'falta: CURP'));

    expect(OfficialFormatGeneration::query()->count())->toBe(0);
});

test('un dato opcional faltante no bloquea', function () {
    $colaborador = colaboradorCompleto(['nss' => null]);
    $formato = plantillaPublicada([
        OfficialFormatVersionFactory::campo('colaborador.nombre_completo', ['requerido' => true]),
        OfficialFormatVersionFactory::campo('colaborador.nss', ['y' => 50, 'requerido' => false]),
    ]);

    $this->actingAs($this->rh)->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador))->assertOk();

    expect(OfficialFormatGeneration::query()->count())->toBe(1);
});

test('los campos manuales declarados se piden al generar y se guardan con el documento', function () {
    $colaborador = colaboradorCompleto();
    $formato = plantillaPublicada([
        OfficialFormatVersionFactory::campo('colaborador.nombre_completo'),
        ['id' => 'lugar', 'tipo' => 'manual', 'etiqueta' => 'Lugar de firma', 'requerido' => true, 'pagina' => 1, 'x' => 20, 'y' => 90, 'ancho' => 80, 'alto' => 6, 'font_size' => 10],
    ]);

    $preparacion = $this->actingAs($this->rh)
        ->postJson(route('rh.formatos-oficiales.preparar', $formato), generarPara($formato, $colaborador))
        ->json();

    expect($preparacion['manuales'][0]['clave'])->toBe('lugar_de_firma')
        ->and($preparacion['manuales'][0]['etiqueta'])->toBe('Lugar de firma')
        ->and($preparacion['puede_generar'])->toBeFalse();

    $this->actingAs($this->rh)
        ->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador, ['manuales' => ['lugar_de_firma' => 'Córdoba, Veracruz']]))
        ->assertOk();

    $generacion = OfficialFormatGeneration::query()->sole();
    expect($generacion->valores_manuales)->toBe(['lugar_de_firma' => 'Córdoba, Veracruz'])
        ->and(textoPdf(Storage::disk('nas')->get($generacion->generated_path)))->toContain('Córdoba, Veracruz');
});

test('un documento de varias páginas conserva todas y escribe en la página indicada', function () {
    $colaborador = colaboradorCompleto();
    $formato = plantillaPublicada(
        [OfficialFormatVersionFactory::campo('colaborador.curp', ['pagina' => 2])],
        [],
        pdfFormato([['PRIMERA HOJA'], ['SEGUNDA HOJA', 'CURP:']]),
    );
    $formato->versionVigente->update(['paginas' => [['numero' => 1, 'ancho' => 210, 'alto' => 297], ['numero' => 2, 'ancho' => 210, 'alto' => 297]]]);

    $this->actingAs($this->rh)->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador))->assertOk();

    $paginas = (new PdfParser)->parseContent(Storage::disk('nas')->get(OfficialFormatGeneration::query()->sole()->generated_path))->getPages();

    expect($paginas)->toHaveCount(2)
        ->and($paginas[0]->getText())->not->toContain('LOMA900926MVZPRN05')
        ->and($paginas[1]->getText())->toContain('LOMA900926MVZPRN05');
});

test('una plantilla archivada no se usa para generar documentos nuevos pero conserva los anteriores', function () {
    $colaborador = colaboradorCompleto();
    $formato = plantillaPublicada([OfficialFormatVersionFactory::campo('colaborador.nombre_completo')]);
    $this->actingAs($this->rh)->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador))->assertOk();

    $this->actingAs($this->rh)->post(route('rh.formatos-oficiales.archivar', $formato))->assertSessionHasNoErrors();

    $this->actingAs($this->rh)
        ->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador))
        ->assertUnprocessable();

    expect(OfficialFormatGeneration::query()->count())->toBe(1)
        ->and(OfficialFormat::query()->whereKey($formato->id)->exists())->toBeTrue();
});

test('los datos de solicitud, préstamo y contrato salen del registro elegido, solo si es de esa persona', function () {
    $colaborador = colaboradorCompleto();
    $solicitud = SolicitudInterna::factory()->create([
        'colaborador_id' => $colaborador->id,
        'tipo' => 'permiso_con_goce',
        'fecha_inicio' => '2026-10-01',
        'fecha_fin' => '2026-10-03',
        'motivo' => 'Trámite personal',
    ]);
    $prestamo = Prestamo::factory()->create(['colaborador_id' => $colaborador->id, 'monto_original' => 1234.56, 'monto_solicitado' => 2000]);
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id]);

    $formato = plantillaPublicada([
        OfficialFormatVersionFactory::campo('solicitud.fecha_inicio', ['requerido' => true]),
        OfficialFormatVersionFactory::campo('solicitud.motivo', ['y' => 50]),
        OfficialFormatVersionFactory::campo('prestamo.monto_autorizado', ['y' => 60, 'formato' => 'moneda_letra', 'ancho' => 180]),
        OfficialFormatVersionFactory::campo('contrato.tipo', ['y' => 70]),
    ]);

    // Sin elegir de qué solicitud/préstamo/contrato: no se puede generar.
    $preparacion = $this->actingAs($this->rh)->postJson(route('rh.formatos-oficiales.preparar', $formato), generarPara($formato, $colaborador))->json();
    expect($preparacion['contextos']['usados'])->toEqualCanonicalizing(['solicitud', 'prestamo', 'contrato'])
        ->and($preparacion['contextos']['opciones']['solicitud'][0]['id'])->toBe($solicitud->id)
        ->and($preparacion['puede_generar'])->toBeFalse();

    $this->actingAs($this->rh)
        ->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador, [
            'solicitud_id' => $solicitud->id, 'prestamo_id' => $prestamo->id, 'contrato_id' => $contrato->id,
        ]))
        ->assertOk();

    $generacion = OfficialFormatGeneration::query()->sole();
    expect($generacion->data_snapshot['solicitud.fecha_inicio'])->toBe('01/10/2026')
        ->and($generacion->data_snapshot['solicitud.motivo'])->toBe('Trámite personal')
        ->and($generacion->data_snapshot['prestamo.monto_autorizado'])->toBe('MIL DOSCIENTOS TREINTA Y CUATRO PESOS 56/100 M.N.')
        ->and($generacion->data_snapshot['contrato.tipo'])->toBe($contrato->tipo->etiqueta())
        ->and($generacion->solicitud_interna_id)->toBe($solicitud->id)
        ->and($generacion->prestamo_id)->toBe($prestamo->id);

    // Una solicitud de otra persona se rechaza.
    $ajena = SolicitudInterna::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $this->actingAs($this->rh)
        ->postJson(route('rh.formatos-oficiales.preparar', $formato), generarPara($formato, $colaborador, ['solicitud_id' => $ajena->id]))
        ->assertUnprocessable();
});

test('los datos salariales solo se imprimen con permiso', function () {
    $colaborador = colaboradorCompleto(['sueldo_mensual' => 15000]);
    $formato = plantillaPublicada([OfficialFormatVersionFactory::campo('laboral.sueldo_mensual', ['formato' => 'moneda'])]);
    $auxiliar = User::factory()->create();
    $auxiliar->assignRole('rh_auxiliar');

    $this->actingAs($auxiliar)
        ->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador))
        ->assertUnprocessable();

    $this->actingAs($this->rh)->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador))->assertOk();
    expect(OfficialFormatGeneration::query()->sole()->data_snapshot['laboral.sueldo_mensual'])->toBe('$15,000.00');
});

test('la vista previa real marca los faltantes y la del editor usa datos de ejemplo rotulados', function () {
    $colaborador = colaboradorCompleto(['curp' => null]);
    $formato = plantillaPublicada([OfficialFormatVersionFactory::campo('colaborador.curp', ['requerido' => true])]);

    $respuesta = $this->actingAs($this->rh)
        ->postJson(route('rh.formatos-oficiales.vista-previa', $formato), generarPara($formato, $colaborador))
        ->assertOk()
        ->json();

    $texto = textoPdf(base64_decode($respuesta['pdf_base64']));
    expect($texto)->toContain('Falta: CURP')->and($texto)->toContain('VISTA PREVIA');

    $borrador = OfficialFormatVersion::factory()->create(['official_format_id' => $formato->id, 'numero' => 2, 'source_path' => $formato->versionVigente->source_path, 'base_path' => $formato->versionVigente->base_path]);
    $ejemplo = $this->actingAs($this->rh)
        ->postJson(route('rh.formatos-oficiales.versiones.vista-previa', $borrador), ['campos' => [OfficialFormatVersionFactory::campo('colaborador.curp')]])
        ->assertOk()
        ->json();

    $textoEjemplo = textoPdf(base64_decode($ejemplo['pdf_base64']));
    expect($textoEjemplo)->toContain('PEGJ800101HDFRRN01')->and($textoEjemplo)->toContain('DATOS DE EJEMPLO');
});

test('un colaborador fuera del alcance no se puede usar para generar', function () {
    $gerente = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['sucursal_principal_id' => Sucursal::factory()->create()->id])->id]);
    $gerente->assignRole('gerente_sucursal');
    $ajeno = colaboradorCompleto();
    $formato = plantillaPublicada([OfficialFormatVersionFactory::campo('colaborador.nombre_completo')]);

    $this->actingAs($gerente)
        ->postJson(route('rh.formatos-oficiales.generar', $formato), generarPara($formato, $ajeno))
        ->assertNotFound();
});

// --- Análisis ------------------------------------------------------------------

test('el editor refina la detección con las posiciones exactas del visor', function () {
    $formato = OfficialFormat::factory()->create();
    $version = OfficialFormatVersion::factory()->create(['official_format_id' => $formato->id]);

    $analisis = $this->actingAs($this->rh)
        ->postJson(route('rh.formatos-oficiales.versiones.analisis', $version), ['bloques' => [
            ['pagina' => 1, 'texto' => 'CURP:', 'x' => 20, 'y' => 40, 'ancho' => 12, 'alto' => 4],
            ['pagina' => 1, 'texto' => 'Área: __________', 'x' => 20, 'y' => 50, 'ancho' => 40, 'alto' => 4],
            ['pagina' => 1, 'texto' => 'FIRMA DEL COLABORADOR', 'x' => 20, 'y' => 200, 'ancho' => 50, 'alto' => 4],
        ]])
        ->assertOk()
        ->json('analisis');

    $sugerencias = collect($analisis['sugerencias'])->keyBy('variable');

    expect($sugerencias['colaborador.curp']['estado'])->toBe('seguro')
        ->and($sugerencias['colaborador.curp']['x'])->toBeGreaterThan(32.0)
        ->and($sugerencias['laboral.departamento']['estado'])->toBe('dudoso')
        ->and($sugerencias)->toHaveCount(2);
});

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

test('la API genera con el mismo motor y respeta faltantes, permisos y rutas protegidas', function () {
    $colaborador = colaboradorCompleto();
    $formato = plantillaPublicada([OfficialFormatVersionFactory::campo('colaborador.curp', ['requerido' => true])]);

    $this->actingAs($this->rh, 'sanctum')->getJson(route('api.v1.rh.formatos-oficiales.index'))
        ->assertOk()->assertJsonPath('data.0.id', $formato->id);

    $preparacion = $this->actingAs($this->rh, 'sanctum')
        ->postJson(route('api.v1.rh.formatos-oficiales.preparar', $formato), generarPara($formato, $colaborador))
        ->assertOk()->json('data');
    expect($preparacion['puede_generar'])->toBeTrue();

    $generado = $this->actingAs($this->rh, 'sanctum')
        ->postJson(route('api.v1.rh.formatos-oficiales.generar', $formato), generarPara($formato, $colaborador))
        ->assertCreated()->json('data');

    expect($generado['version'])->toBe(1)
        ->and($generado['descargar_url'])->toContain('/api/v1/rh/formatos-oficiales/generados/')
        ->and(json_encode($generado))->not->toContain('formatos-oficiales/plantillas');

    $this->actingAs($this->rh, 'sanctum')->get($generado['descargar_url'])->assertOk();

    // Sin CURP: bloqueado también por API.
    $sinCurp = colaboradorCompleto(['curp' => null]);
    $this->actingAs($this->rh, 'sanctum')
        ->postJson(route('api.v1.rh.formatos-oficiales.generar', $formato), generarPara($formato, $sinCurp))
        ->assertUnprocessable();

    // Un colaborador no administra plantillas.
    $colaboradorUsuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $colaboradorUsuario->assignRole('colaborador');
    $this->actingAs($colaboradorUsuario, 'sanctum')->getJson(route('api.v1.rh.formatos-oficiales.index'))->assertForbidden();
});
