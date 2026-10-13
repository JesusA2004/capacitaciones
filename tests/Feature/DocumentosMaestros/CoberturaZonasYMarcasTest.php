<?php

use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Services\DocumentosMaestros\AlmacenMaestrosService;
use App\Services\DocumentosMaestros\Calidad\CoberturaZonasService;
use App\Services\DocumentosMaestros\CatalogoMaestrosService;
use App\Services\DocumentosMaestros\DefinicionMaestroService;
use App\Services\DocumentosMaestros\Deteccion\SugeridorVariables;
use App\Services\DocumentosMaestros\Docx\LimpiadorMarcasEditoriales;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\PhpWord;

/*
| COBERTURA DE ZONAS: el documento no puede darse por listo mientras tenga
| una línea que debería llevar un dato. Caso real reportado: la cláusula
| DÉCIMA SÉPTIMA (beneficiarios, art. 501 LFT) salía con
| «se deberán cubrir a ______, quien guarda parentesco de ______» y la
| vista previa no lo reportaba (las dos reglas eran «opcionales»).
| Además: los marcatextos verdes de Jurídico nunca salen en el documento
| final, y un formato nuevo se configura completo sin tocar código.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->motor = dmMotorFielDePrueba();
    $this->rh = clUsuario('rh_admin');
    $this->adriana = clExpedienteCompleto(Colaborador::factory()->create([
        'name' => 'Adriana',
        'apellidos' => 'Cortés Beltrán',
        'rfc' => 'COBA900101AB1',
        'beneficiario_nombre' => 'MARÍA DEL CARMEN BELTRÁN HERNÁNDEZ',
        'beneficiario_parentesco' => 'MADRE',
    ]));
});

/**
 * Contrato de prueba como lo subiría RH: campos con línea, la cláusula de
 * beneficiarios dentro de un párrafo, marcatextos verdes de Jurídico y una
 * línea de firma.
 */
function czContratoDocx(bool $conResaltado = true): string
{
    $word = new PhpWord;
    $seccion = $word->addSection();
    $seccion->addText('CONTRATO DE PRUEBA 2027');
    $seccion->addText('Nombre: ______________________________');
    $seccion->addText('RFC: ____________________');
    $clausula = $seccion->addTextRun();
    $clausula->addText('DÉCIMA SÉPTIMA. DE LA DESIGNACIÓN DE BENEFICIARIOS A LOS QUE REFIERE EL ARTÍCULO 501 DE LA LEY FEDERAL DEL TRABAJO. ', $conResaltado ? ['fgColor' => 'green'] : []);
    $clausula->addText('El trabajador manifiesta que, a su fallecimiento, se deberán cubrir a ______________________________, quien guarda parentesco de ____________________ con el trabajador.');
    $seccion->addText('______________________________');
    $seccion->addText('Firma del trabajador');
    $ruta = tempnam(sys_get_temp_dir(), 'cz');
    $word->save($ruta, 'Word2007');
    $docx = (string) file_get_contents($ruta);
    @unlink($ruta);

    return $docx;
}

/**
 * Sube el contrato desde la UI y devuelve [id, campos del editor].
 *
 * @return array{0: int, 1: list<array<string, mixed>>}
 */
function czSubir(object $prueba, string $docx): array
{
    $id = $prueba->actingAs($prueba->rh)->post('/rh/documentos-maestros/nuevo', [
        'nombre' => 'Contrato prueba 2027',
        'proceso' => 'expediente',
        'archivo' => UploadedFile::fake()->createWithContent('Contrato_2027.docx', $docx),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    return [$id, $prueba->actingAs($prueba->rh)->get("/rh/documentos-maestros/{$id}/editor")->viewData('page')['props']['editor']['campos']];
}

/**
 * Lo que RH elige en el editor para cada espacio, por el texto cercano.
 *
 * @param  list<array<string, mixed>>  $campos
 * @return list<array<string, mixed>>
 */
function czMapear(array $campos, bool $conParentesco = true): array
{
    // Las dos líneas de la cláusula, en orden: beneficiario y parentesco.
    $clausula = ['beneficiario_nombre', $conParentesco ? 'beneficiario_parentesco' : null];
    $enClausula = 0;

    return array_map(function (array $c) use ($clausula, &$enClausula): array {
        $contexto = (string) $c['contexto'];
        $clave = match (true) {
            str_starts_with($contexto, 'Nombre') => 'nombre_completo',
            str_starts_with($contexto, 'RFC') => 'rfc',
            str_contains($contexto, 'cubrir a') || str_contains($contexto, 'parentesco de') => $clausula[$enClausula++] ?? null,
            default => null,
        };

        return $clave === null ? $c : [...$c, 'source_key' => $clave, 'expression' => null, 'estado' => 'confirmado'];
    }, $campos);
}

function czTextoWord(GeneratedDocument $documento): string
{
    $temporal = tempnam(sys_get_temp_dir(), 'cz');
    file_put_contents($temporal, Storage::disk((string) $documento->docx_disk)->get((string) $documento->docx_path));
    $zip = new ZipArchive;
    $zip->open($temporal);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($temporal);

    return $xml;
}

test('una línea del documento sin dato asignado: QA FALLA y no se activa; al asignarla, QA PASA', function () {
    [$id, $campos] = czSubir($this, czContratoDocx(false));
    $master = DocumentTemplate::query()->findOrFail($id);

    // 4 líneas para datos + 1 de firma; nada queda verde sin RH.
    $cobertura = app(CoberturaZonasService::class)->de($master);
    expect($cobertura['sin_clasificar'])->toBe(4)
        ->and($cobertura['firmas'])->toBe(1)
        ->and($cobertura['zonas'])->toBe(5)
        ->and($cobertura['mensaje'])->toBe('Hay 4 espacios del documento que todavía no tienen un dato asignado.');

    // Se asignan todos menos el parentesco: sigue faltando 1 campo.
    $editor = $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => czMapear($campos, false)])->assertOk()->json('data');
    expect($editor['preparacion']['cobertura']['sin_clasificar'])->toBe(1)
        ->and($editor['preparacion']['cobertura']['mensaje'])->toBe('Hay 1 espacio del documento que todavía no tiene un dato asignado.');
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/probar", ['colaborador_id' => $this->adriana->id])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/activar")->assertUnprocessable();

    $editor = $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => czMapear($campos)])->assertOk()->json('data');
    expect($editor['preparacion']['cobertura'])->toMatchArray(['sin_clasificar' => 0, 'dinamicas' => 4, 'firmas' => 1, 'zonas' => 5]);
});

test('BENEFICIARIO y PARENTESCO presentes: se escriben sobre las líneas de la cláusula (caso Adriana Cortés Beltrán)', function () {
    [$id, $campos] = czSubir($this, czContratoDocx());
    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => czMapear($campos)])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/marcas", ['decision' => 'limpiar'])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/probar", ['colaborador_id' => $this->adriana->id])->assertOk()->assertJsonPath('data.faltantes', []);
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/datos-largos")->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/activar")->assertOk();
    $clave = DocumentTemplate::query()->findOrFail($id)->clave;

    $this->actingAs($this->rh)->postJson("/rh/documentos-proceso/colaborador/{$this->adriana->id}/generar", ['clave' => $clave, 'proceso' => 'expediente'])->assertCreated();

    $xml = czTextoWord(GeneratedDocument::query()->sole());
    $texto = strip_tags($xml);
    expect($texto)->toContain('se deberán cubrir a MARÍA DEL CARMEN BELTRÁN HERNÁNDEZ, quien guarda parentesco de MADRE con el trabajador.')
        ->and($texto)->toContain('Nombre: Adriana Cortés Beltrán')
        ->and($texto)->toContain('RFC: COBA900101AB1')
        // Firma física: la línea se queda para firmar a mano.
        ->and($texto)->toMatch('/_{20,}\s*Firma del trabajador/u')
        // Sin marcatextos ni marcadores en el documento final.
        ->and($xml)->not->toContain('w:highlight')
        ->and($xml)->not->toContain('{{');
});

test('BENEFICIARIO ausente: la vista previa lo marca y el documento final se BLOQUEA (nunca sale con la línea vacía)', function () {
    $this->adriana->update(['beneficiario_nombre' => null, 'beneficiario_parentesco' => null]);
    [$id, $campos] = czSubir($this, czContratoDocx(false));
    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => czMapear($campos)])->assertOk();

    $prueba = $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/probar", ['colaborador_id' => $this->adriana->id])->assertOk()->json('data');
    expect($prueba['faltantes'])->toBe(['Beneficiario', 'Parentesco del beneficiario'])
        ->and($prueba['colaborador']['nombre'])->toBe('Adriana Cortés Beltrán')
        ->and(array_column($prueba['datos_faltantes'], 'columna'))->toBe(['beneficiario_nombre', 'beneficiario_parentesco'])
        ->and(collect($prueba['datos_faltantes'])->every(fn (array $f): bool => $f['editable']))->toBeTrue();

    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/datos-largos")->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/activar")->assertOk();
    $clave = DocumentTemplate::query()->findOrFail($id)->clave;

    $this->actingAs($this->rh)->postJson("/rh/documentos-proceso/colaborador/{$this->adriana->id}/generar", ['clave' => $clave, 'proceso' => 'expediente'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'DATOS_FALTANTES')
        ->assertJsonPath('faltantes.0.etiqueta', 'Beneficiario')
        ->assertJsonPath('faltantes.1.etiqueta', 'Parentesco del beneficiario');

    expect(GeneratedDocument::query()->count())->toBe(0);
});

test('PARENTESCO ausente: bloquea y pide SOLO el parentesco; al completarlo desde la vista previa se guarda y desaparece la advertencia', function () {
    $this->adriana->update(['beneficiario_parentesco' => null]);
    [$id, $campos] = czSubir($this, czContratoDocx(false));
    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => czMapear($campos)])->assertOk();

    $prueba = $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/probar", ['colaborador_id' => $this->adriana->id])->assertOk()->json('data');
    expect($prueba['faltantes'])->toBe(['Parentesco del beneficiario']);

    // «Completar datos del contrato» → guardar → recalcular → regenerar.
    $prueba = $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/probar", [
        'colaborador_id' => $this->adriana->id,
        'completar' => ['beneficiario_parentesco' => 'MADRE'],
    ])->assertOk()->json('data');

    expect($prueba['faltantes'])->toBe([])
        ->and($this->adriana->refresh()->beneficiario_parentesco)->toBe('MADRE');
});

test('firma física: la línea de firma NO se reporta como dato faltante', function () {
    [$id, $campos] = czSubir($this, czContratoDocx(false));
    $editor = $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => czMapear($campos)])->assertOk()->json('data');
    $prueba = $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/probar", ['colaborador_id' => $this->adriana->id])->assertOk()->json('data');

    expect($editor['preparacion']['cobertura']['firmas'])->toBe(1)
        ->and($prueba['faltantes'])->toBe([])
        ->and(collect($editor['campos'])->pluck('contexto')->filter(fn (?string $c) => str_contains((string) $c, 'Firma'))->all())->toBe([]);
});

test('marcatexto verde: se detecta, bloquea la activación hasta decidir, NO sale en el documento final y el ORIGINAL queda intacto', function () {
    [$id, $campos] = czSubir($this, czContratoDocx());
    $editor = $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => czMapear($campos)])->assertOk()->json('data');

    expect($editor['master']['marcas']['detectadas'])->toBe(1)
        ->and($editor['master']['marcas']['decision'])->toBeNull()
        ->and($editor['master']['marcas']['lista'][0]['texto'])->toStartWith('DÉCIMA SÉPTIMA. DE LA DESIGNACIÓN DE BENEFICIARIOS');

    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/probar", ['colaborador_id' => $this->adriana->id])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/activar")->assertUnprocessable();

    $editor = $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/marcas", ['decision' => 'limpiar'])->assertOk()->json('data');
    expect($editor['master']['marcas'])->toMatchArray(['detectadas' => 1, 'retiradas' => 1, 'decision' => 'limpiar']);

    $master = DocumentTemplate::query()->findOrFail($id);
    $almacen = app(AlmacenMaestrosService::class);
    $limpiador = app(LimpiadorMarcasEditoriales::class);

    // El ORIGINAL inmutable conserva su marca; el master (lo que se genera) no.
    expect($limpiador->analizar($almacen->original($master)))->toHaveCount(1)
        ->and($limpiador->analizar($almacen->master($master)))->toBe([]);

    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/datos-largos")->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/activar")->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-proceso/colaborador/{$this->adriana->id}/generar", ['clave' => $master->clave, 'proceso' => 'expediente'])->assertCreated();

    expect(czTextoWord(GeneratedDocument::query()->sole()))->not->toContain('w:highlight');
});

test('la limpieza solo quita el color: el texto jurídico queda idéntico', function () {
    $limpiador = app(LimpiadorMarcasEditoriales::class);
    $original = czContratoDocx();
    $limpio = $limpiador->limpiar($original)['docx'];

    $texto = function (string $docx): string {
        $temporal = tempnam(sys_get_temp_dir(), 'cz');
        file_put_contents($temporal, $docx);
        $zip = new ZipArchive;
        $zip->open($temporal);
        $xml = (string) $zip->getFromName('word/document.xml');
        $estilos = (string) $zip->getFromName('word/styles.xml');
        $zip->close();
        @unlink($temporal);

        return trim(strip_tags($xml)).'|'.hash('sha256', $estilos);
    };

    expect($texto($limpio))->toBe($texto($original))
        ->and($limpiador->analizar($original))->toHaveCount(1)
        ->and($limpiador->analizar($limpio))->toBe([]);
});

test('nombre largo del beneficiario: se escribe completo, nunca se corta', function () {
    $largo = 'MARÍA DEL CARMEN GUADALUPE DE LOS ÁNGELES BELTRÁN HERNÁNDEZ DE LA SANTÍSIMA TRINIDAD';
    $this->adriana->update(['beneficiario_nombre' => $largo]);
    [$id, $campos] = czSubir($this, czContratoDocx(false));
    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => czMapear($campos)])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/probar", ['colaborador_id' => $this->adriana->id])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/datos-largos")->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/activar")->assertOk();
    $clave = DocumentTemplate::query()->findOrFail($id)->clave;
    $this->actingAs($this->rh)->postJson("/rh/documentos-proceso/colaborador/{$this->adriana->id}/generar", ['clave' => $clave, 'proceso' => 'expediente'])->assertCreated();

    expect(strip_tags(czTextoWord(GeneratedDocument::query()->sole())))->toContain("se deberán cubrir a {$largo}, quien guarda parentesco de MADRE");
});

test('dato que puede quedar en blanco: solo con su razón (vacío intencional), nunca una línea vacía sin explicación', function () {
    [$id, $campos] = czSubir($this, czContratoDocx(false));
    $mapeo = array_map(fn (array $c): array => str_contains((string) $c['contexto'], 'RFC') ? [...$c, 'required' => false] : $c, czMapear($campos));

    $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => $mapeo])->assertUnprocessable()->assertJsonValidationErrors('campos');

    $mapeo = array_map(fn (array $c): array => str_contains((string) $c['contexto'], 'RFC') ? [...$c, 'motivo_blanco' => 'Se llena a mano en la firma.'] : $c, $mapeo);
    $editor = $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => $mapeo])->assertOk()->json('data');

    expect($editor['preparacion']['cobertura'])->toMatchArray(['sin_clasificar' => 0, 'intencionales' => 1, 'dinamicas' => 3]);
});

test('línea hecha con ESPACIOS SUBRAYADOS (sin «_»): se detecta como espacio, se asigna en el editor y se llena', function () {
    $word = new PhpWord;
    $seccion = $word->addSection();
    $seccion->addText('CARTA DE BENEFICIARIO');
    $parrafo = $seccion->addTextRun();
    $parrafo->addText('Designo como beneficiario a');
    $parrafo->addText(str_repeat(' ', 40), ['underline' => 'single']);
    $parrafo->addText(', quien es mi');
    $parrafo->addText(str_repeat(' ', 20), ['underline' => 'single']);
    $parrafo->addText(' para todos los efectos.');
    $ruta = tempnam(sys_get_temp_dir(), 'cz');
    $word->save($ruta, 'Word2007');
    $docx = (string) file_get_contents($ruta);
    @unlink($ruta);

    [$id, $campos] = czSubir($this, $docx);
    expect($campos)->toHaveCount(2)
        ->and(array_column($campos, 'detection_method'))->toBe(['docx_subrayado', 'docx_subrayado'])
        ->and($campos[0]['sugerencias'][0]['clave'] ?? null)->toBe('beneficiario_nombre');

    // Sin asignar: la versión no está preparada.
    expect(app(CoberturaZonasService::class)->de(DocumentTemplate::query()->findOrFail($id))['sin_clasificar'])->toBe(2);

    $mapeo = [
        [...$campos[0], 'source_key' => 'beneficiario_nombre', 'estado' => 'confirmado'],
        [...$campos[1], 'source_key' => 'beneficiario_parentesco', 'estado' => 'confirmado'],
    ];
    $editor = $this->actingAs($this->rh)->putJson("/rh/documentos-maestros/{$id}/campos", ['campos' => $mapeo])->assertOk()->json('data');
    expect($editor['preparacion']['cobertura']['sin_clasificar'])->toBe(0);

    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/probar", ['colaborador_id' => $this->adriana->id])->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/datos-largos")->assertOk();
    $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$id}/activar")->assertOk();
    $clave = DocumentTemplate::query()->findOrFail($id)->clave;
    $this->actingAs($this->rh)->postJson("/rh/documentos-proceso/colaborador/{$this->adriana->id}/generar", ['clave' => $clave, 'proceso' => 'expediente'])->assertCreated();

    expect(strip_tags(czTextoWord(GeneratedDocument::query()->sole())))->toContain('Designo como beneficiario a MARÍA DEL CARMEN BELTRÁN HERNÁNDEZ, quien es mi MADRE para todos los efectos.');
});

test('sugerencias: «Beneficiario: ___» y el «Parentesco» junto a él son del BENEFICIARIO (no del contacto de emergencia)', function () {
    $sugeridor = app(SugeridorVariables::class);

    expect($sugeridor->sugerir('Beneficiario:')[0]['clave'])->toBe('beneficiario_nombre')
        ->and($sugeridor->sugerir('Beneficiario: ______ Parentesco:')[0]['clave'])->toBe('beneficiario_parentesco')
        ->and($sugeridor->sugerir('Beneficiario:')[0]['etiqueta'])->toBe('Beneficiario');
});

test('registro jurídico: la cláusula de beneficiarios es obligatoria y en los overlays solo casillas y blancos con razón pueden ir vacíos', function () {
    $catalogo = app(CatalogoMaestrosService::class);

    foreach ((array) config('documentos_maestros.documentos') as $familia => $definicion) {
        foreach ((array) ($definicion['reglas'] ?? []) as $regla) {
            if (in_array($regla['campo'] ?? null, ['beneficiario_nombre', 'beneficiario_parentesco'], true)) {
                expect($regla['opcional'] ?? false)->toBeFalse("{$familia}: {$regla['campo']} no puede ser opcional");
            }
        }

        if (($definicion['motor'] ?? null) === 'pdf_overlay' && ($definicion['operativo'] ?? true)) {
            $cajas = array_values(array_filter((array) $definicion['campos'], 'is_array'));
            $requeridos = DefinicionMaestroService::requeridosOverlay($definicion, $cajas);
            $blancos = DefinicionMaestroService::blancosIntencionales($definicion);

            foreach ($cajas as $caja) {
                $casilla = str_starts_with((string) $caja['campo'], 'marca_') || ($caja['tipo'] ?? null) === 'check';

                expect($casilla || isset($blancos[$caja['campo']]) || in_array($caja['campo'], $requeridos, true))->toBeTrue("{$familia}: {$caja['campo']} quedaría vacío sin razón");
            }
        }
    }

    expect($catalogo->definicion('formato_permiso.general'))->not->toBeNull();
});

test('TODOS los formatos jurídicos reales: cada zona tiene destino (0 sin clasificar) y ningún marcatexto llega al master', function () {
    $fuente = base_path('docs/formatos_fuente');

    if (glob($fuente.'/*.docx') === []) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente.');
    }

    app(ImportadorFormatosJuridicosService::class)->importarCarpeta($fuente);
    $limpiador = app(LimpiadorMarcasEditoriales::class);
    $almacen = app(AlmacenMaestrosService::class);
    $cobertura = app(CoberturaZonasService::class);
    $activos = DocumentTemplate::query()->where('activo', true)->whereNotNull('familia')->get();

    expect($activos->count())->toBeGreaterThan(20);

    foreach ($activos as $master) {
        $c = $cobertura->de($master);

        expect($c['sin_clasificar'])->toBe(0, "{$master->familia}: ".json_encode($c['problemas'], JSON_UNESCAPED_UNICODE));

        if ($master->motor->value === 'docx') {
            expect($limpiador->analizar($almacen->master($master)))->toBe([], "{$master->familia} conserva marcatextos")
                ->and($c['marcas']['retiradas'])->toBe($c['marcas']['detectadas']);
        }
    }
});
