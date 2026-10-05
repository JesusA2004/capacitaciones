<?php

use App\Enums\EstadoValidacionVisual;
use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Services\DocumentosMaestros\Calidad\RasterizadorPdf;
use App\Services\DocumentosMaestros\Calidad\ValidacionVisualMaestroService;
use App\Services\DocumentosMaestros\CatalogoMaestrosService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use App\Services\Formatos\Motor\ConversorDocxPdf;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| FIDELIDAD REAL (grupo "fidelidad"): Microsoft Word convierte y el motor
| PDF nativo de Windows rasteriza; se comparan ORIGINAL vs GENERADO página
| por página con los originales reales de Jurídico. Se omite donde no hay
| Word + rasterizador + originales (p. ej. CI en Linux sin LibreOffice ni
| pdftoppm). Cubre documentos de 1, 5, 9 y 11 páginas y datos largos.
*/

function frImportar(array $familias): void
{
    $catalogo = app(CatalogoMaestrosService::class);

    foreach (glob(base_path('docs/formatos_fuente').'/*') ?: [] as $ruta) {
        foreach ($catalogo->todasPorHash((string) hash_file('sha256', $ruta)) as $fuente) {
            if (in_array($fuente['familia'], $familias, true)) {
                app(ImportadorFormatosJuridicosService::class)->registrarVersion($fuente['familia'], $fuente['version'], (string) file_get_contents($ruta), [basename($ruta)], $fuente, null);
            }
        }
    }
}

beforeEach(function () {
    config(['formatos_oficiales.conversor' => 'auto', 'documentos_maestros.validacion_visual.al_importar' => false]);

    if (! is_dir(base_path('docs/formatos_fuente')) || ! app(ConversorDocxPdf::class)->wordDisponible() || ! app(RasterizadorPdf::class)->disponible()) {
        $this->markTestSkipped('Requiere Microsoft Word, rasterizador de PDF y los originales de Jurídico.');
    }

    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
});

test('QA visual real: el master preparado se ve idéntico al original y conserva su estructura', function (string $familia, int $paginas) {
    frImportar([$familia]);
    $master = app(ValidacionVisualMaestroService::class)->validar(DocumentTemplate::query()->where('familia', $familia)->firstOrFail());
    $reporte = $master->visual_report;

    expect($master->visual_validation_status)->toBe(EstadoValidacionVisual::Aprobada)
        ->and($master->visual_engine)->toBe('word')
        ->and($reporte['fidelidad'])->toBe('nativa')
        ->and($master->page_count_original)->toBe($paginas)
        ->and($reporte['paginas']['identidad'])->toBe($paginas)
        ->and((float) $master->visual_similarity)->toBeGreaterThanOrEqual(0.985)
        ->and($reporte['estructura']['generado']['marcadores'])->toBe(0)
        ->and($reporte['estructura']['generado']['imagenes'])->toBe($reporte['estructura']['original']['imagenes'])
        ->and($reporte['problemas'])->toBe([]);
})->with([
    'carta de renuncia (1 página)' => ['carta_renuncia.general', 1],
    'no competencia Gestor (5 páginas)' => ['contrato_no_competencia.gestor', 5],
    'capacitación Gestor (9 páginas)' => ['contrato_capacitacion.gestor', 9],
    'confidencialidad general (11 páginas)' => ['contrato_confidencialidad.general', 11],
])->group('fidelidad');

test('QA visual real del overlay: el PDF original del permiso se conserva y ningún dato largo se sale de su caja', function () {
    frImportar(['formato_permiso.general']);
    $master = app(ValidacionVisualMaestroService::class)->validar(DocumentTemplate::query()->where('familia', 'formato_permiso.general')->firstOrFail());

    expect($master->visual_validation_status)->toBe(EstadoValidacionVisual::Aprobada)
        ->and($master->visual_report['desbordes'])->toBe([])
        ->and((float) $master->visual_similarity)->toBeGreaterThanOrEqual(0.985);
})->group('fidelidad');

test('documento real con datos largos (Word nativo): mismas páginas que el original, Word y PDF guardados', function () {
    frImportar(['contrato_capacitacion.gestor']);
    $master = DocumentTemplate::query()->where('familia', 'contrato_capacitacion.gestor')->firstOrFail();
    $master = app(ValidacionVisualMaestroService::class)->validar($master);
    app(ImportadorFormatosJuridicosService::class)->activar($master, null);
    $rh = clUsuario('rh_admin');
    Sanctum::actingAs($rh);
    $estructura = clEstructura();
    $estructura['sucursal']->update(['nombre' => 'Pachuca de Soto Centro Histórico', 'direccion' => 'Avenida Revolución número 1520, local 3', 'ciudad' => 'Pachuca de Soto', 'estado' => 'Hidalgo', 'codigo_postal' => '42000']);
    $colaborador = Colaborador::factory()->create([
        'name' => 'José Francisco de Jesús', 'apellidos' => 'Hernández González', 'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => Puesto::factory()->create(['nombre' => 'Gestor', 'grupo_documental' => 'gestor', 'meses_periodo_prueba' => 2])->id,
        'genero' => 'masculino', 'fecha_nacimiento' => '1985-03-15', 'curp' => 'HEGJ850315HHGRNS09', 'rfc' => 'HEGJ850315AB1', 'nss' => '12345678901',
        'telefono' => '7712345678', 'correo_personal' => 'jose.francisco.hernandez@correo-ejemplo.com.mx', 'nacionalidad' => 'Mexicana', 'estado_civil' => 'union_libre',
        'domicilio' => 'Calle Prolongación Emiliano Zapata número 1250 interior 4-B, colonia Lomas de la Selva Norte, C.P. 62270, Cuernavaca, Morelos',
        'lugar_nacimiento' => 'Pachuca de Soto, Hidalgo', 'clave_elector' => 'HRGNJS85031513H400', 'profesion' => 'Licenciado en Administración de Empresas',
        'fecha_ingreso' => '2026-10-05', 'sueldo_mensual' => 123456.78,
    ]);
    $contrato = ContratoLaboral::query()->create([
        'colaborador_id' => $colaborador->id, 'tipo' => TipoContratacion::CapacitacionInicial, 'fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-12-04',
        'estado' => 'vigente', 'sueldo_mensual' => 123456.78, 'puesto_id' => $colaborador->puesto_id, 'sucursal_id' => $colaborador->sucursal_principal_id,
    ]);

    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])->assertCreated();
    $documento = GeneratedDocument::query()->firstOrFail();

    expect($documento->conversion_engine)->toBe('word')
        ->and($documento->conversion_fidelity)->toBe('nativa')
        ->and($documento->paginas)->toBe($master->page_count_original)
        ->and(Storage::disk('nas')->exists((string) $documento->docx_path))->toBeTrue()
        ->and(str_starts_with((string) Storage::disk('nas')->get($documento->path), '%PDF'))->toBeTrue();
})->group('fidelidad');
