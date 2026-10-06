<?php

use App\Models\DocumentTemplate;
use App\Services\DocumentosMaestros\AlmacenMaestrosService;
use App\Services\DocumentosMaestros\Calidad\DiagnosticoFuentesService;
use App\Services\DocumentosMaestros\Calidad\ValidacionVisualMaestroService;
use App\Services\Formatos\FormatoPreviewService;
use App\Services\Formatos\Motor\ConversorDocxPdf;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpWord\PhpWord;
use Tests\Support\ConversorFielDePrueba;

/*
| Reproduce el bug reportado en producción: fontconfig ya detectaba
| Calibri y Century Gothic (`fc-match` las resolvía), pero "Probar diseño"
| seguía diciendo "no está instalada" porque
| DiagnosticoFuentesService::disponibles() cachea un día y nada la
| invalidaba al correr el QA. La corrección vive en
| ValidacionVisualMaestroService::validar(), que ahora llama a
| DiagnosticoFuentesService::invalidarCache() antes de diagnosticar.
*/

function qfcDocx(): string
{
    $word = new PhpWord;
    $word->setDefaultFontName('Calibri');
    $word->addSection()->addText('Documento de prueba sin marcadores.', ['name' => 'Century Gothic']);
    $ruta = tempnam(sys_get_temp_dir(), 'qfc').'.docx';
    $word->save($ruta, 'Word2007');
    $bytes = (string) file_get_contents($ruta);
    @unlink($ruta);

    return $bytes;
}

function qfcMaster(): DocumentTemplate
{
    $almacen = app(AlmacenMaestrosService::class);
    $docx = qfcDocx();
    $original = $almacen->guardarOriginal($docx, 'docx');
    $master = $almacen->guardarMaster('prueba.cache_fuentes', 1, $docx, 'docx');

    return DocumentTemplate::factory()->create([
        'familia' => 'prueba.cache_fuentes',
        'motor' => 'docx',
        'estado_master' => 'listo',
        'operativo' => true,
        'original_disk' => $almacen->nombreDisco(),
        'original_path' => $original,
        'original_hash' => hash('sha256', $docx),
        'disk' => $almacen->nombreDisco(),
        'path' => $master,
        'mapping' => ['motor' => 'docx', 'instancias' => []],
    ]);
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    app()->instance(ConversorDocxPdf::class, new ConversorFielDePrueba(app(FormatoPreviewService::class)));
});

test('invalidarCache(): borra solo la clave de fuentes de este sistema operativo, no cache:clear global', function () {
    $clave = 'documentos-maestros:fuentes:'.PHP_OS_FAMILY;
    Cache::put($clave, ['fuente-vieja' => ['familia' => 'Fuente Vieja', 'archivo' => null]], now()->addDay());
    Cache::put('otra-clave-sin-relacion', 'no debe tocarse', now()->addDay());

    app(DiagnosticoFuentesService::class)->invalidarCache();

    expect(Cache::has($clave))->toBeFalse()
        ->and(Cache::get('otra-clave-sin-relacion'))->toBe('no debe tocarse');
});

test('Probar diseño nunca usa una lista de fuentes vieja en caché: siempre recalcula antes del QA', function () {
    $clave = 'documentos-maestros:fuentes:'.PHP_OS_FAMILY;
    // Caché "vieja": ni Calibri ni Century Gothic existían la última vez que
    // alguien corrió el diagnóstico (antes de instalarlas en el servidor).
    $viejo = ['solo-una-fuente-vieja' => ['familia' => 'Solo Una Fuente Vieja', 'archivo' => null]];
    Cache::put($clave, $viejo, now()->addDay());

    $master = qfcMaster();
    $rh = clUsuario('rh_admin');

    // validar() siempre invalida el caché de fuentes antes de diagnosticar,
    // sin importar si el resto del QA (rasterizado) puede completarse o no
    // en este entorno de prueba.
    app(ValidacionVisualMaestroService::class)->validar($master, $rh);

    expect(Cache::get($clave))->not->toBe($viejo);

    // Con el caché ya invalidado, el diagnóstico en vivo refleja el estado
    // REAL del servidor: nunca la lista vieja que había antes de "Probar diseño".
    $almacen = app(AlmacenMaestrosService::class);
    $fuentes = collect(app(DiagnosticoFuentesService::class)->diagnosticar($almacen->original($master)))->keyBy('fuente');

    expect($fuentes['Calibri']['disponible'])->toBeTrue()
        ->and($fuentes['Century Gothic']['disponible'])->toBeTrue();
});

test('endpoint "validar-diseno": el JSON no repite errores de fuentes viejos ni los bloqueos de activación los mencionan', function () {
    $clave = 'documentos-maestros:fuentes:'.PHP_OS_FAMILY;
    Cache::put($clave, [], now()->addDay());

    $master = qfcMaster();
    $rh = clUsuario('rh_admin');
    Sanctum::actingAs($rh);

    $respuesta = $this->postJson("/rh/documentos-maestros/{$master->id}/validar-diseno")->assertOk()->json('data');

    $fuentes = collect($respuesta['fuentes_documento'])->keyBy('fuente');
    $bloqueos = implode(' | ', array_column($respuesta['activacion']['bloqueos'], 'mensaje'));

    expect($fuentes['Calibri']['disponible'])->toBeTrue()
        ->and($fuentes['Century Gothic']['disponible'])->toBeTrue()
        ->and($bloqueos)->not->toContain('fuente');
});
