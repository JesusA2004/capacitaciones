<?php

use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoValidacionVisual;
use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Models\Empresa;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Services\DocumentosMaestros\Calidad\ComparadorVisual;
use App\Services\DocumentosMaestros\Calidad\DiagnosticoFuentesService;
use App\Services\DocumentosMaestros\Docx\DocumentoWord;
use App\Services\DocumentosMaestros\Docx\PreparadorMasterDocx;
use App\Services\DocumentosMaestros\Docx\RellenadorDocx;
use App\Services\DocumentosMaestros\Pdf\RenderizadorOverlayMaestro;
use App\Services\DocumentosMaestros\ResolvedorMaestroService;
use App\Services\Formatos\Motor\ConversorDocxPdf;
use App\Services\Notificaciones\DestinoNotificacionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\PhpWord;
use setasign\Fpdi\Fpdi;

/*
| Piezas del motor documental que no dependen de los originales de
| Jurídico: comparador visual, diagnóstico de fuentes, rellenado DOCX sin
| reconstruir, política de conversión (nunca PhpWord para definitivos),
| overlay con desbordes y casillas, prioridad de resolución del master y
| destino de las notificaciones documentales.
*/

function mpPng(callable $dibujar, int $ancho = 340, int $alto = 440): string
{
    $imagen = imagecreatetruecolor($ancho, $alto);
    imagefill($imagen, 0, 0, (int) imagecolorallocate($imagen, 255, 255, 255));
    $dibujar($imagen, (int) imagecolorallocate($imagen, 0, 0, 0));
    ob_start();
    imagepng($imagen);

    return (string) ob_get_clean();
}

function mpDocx(callable $construir): string
{
    $word = new PhpWord;
    $construir($word);
    $ruta = tempnam(sys_get_temp_dir(), 'mp').'.docx';
    $word->save($ruta, 'Word2007');
    $bytes = (string) file_get_contents($ruta);
    @unlink($ruta);

    return $bytes;
}

test('comparador visual: idéntico = 1.0; contenido movido baja la similitud; la zona enmascarada se ignora; otro tamaño se detecta', function () {
    $comparador = app(ComparadorVisual::class);
    $base = mpPng(fn ($i, $negro) => imagefilledrectangle($i, 40, 40, 300, 60, $negro));
    $movido = mpPng(fn ($i, $negro) => imagefilledrectangle($i, 40, 120, 300, 140, $negro));
    $conDato = mpPng(function ($i, $negro): void {
        imagefilledrectangle($i, 40, 40, 300, 60, $negro);
        imagefilledrectangle($i, 40, 200, 200, 210, $negro);
    });

    expect($comparador->comparar($base, $base, 40)['similitud'])->toBe(1.0)
        ->and($comparador->comparar($base, $movido, 40)['similitud'])->toBeLessThan(0.5)
        // El dato nuevo está dentro de una caja de campo (máscara en puntos: 40 dpi → 1.8 pt/px).
        ->and($comparador->comparar($base, $conDato, 40, [['x' => 30 * 1.8, 'y' => 190 * 1.8, 'ancho' => 190 * 1.8, 'alto' => 30 * 1.8]])['similitud'])->toBe(1.0)
        ->and($comparador->comparar($base, mpPng(fn () => null, 300, 440), 40)['mismo_tamano'])->toBeFalse();
});

test('diagnóstico de fuentes: detecta las fuentes usadas (estilos, runs y tema) y marca las que no existen en el servidor', function () {
    config(['documentos_maestros.validacion_visual.fuentes_disponibles' => ['Arial', 'Calibri']]);
    $docx = mpDocx(function (PhpWord $w): void {
        $w->setDefaultFontName('Calibri');
        $seccion = $w->addSection();
        $seccion->addText('Contrato', ['name' => 'Montserrat']);
        $seccion->addText('Cláusula', ['name' => 'Arial']);
    });

    $diagnostico = collect(app(DiagnosticoFuentesService::class)->diagnosticar($docx))->keyBy('fuente');

    expect($diagnostico->keys()->all())->toContain('Montserrat', 'Arial', 'Calibri')
        ->and($diagnostico['Montserrat']['disponible'])->toBeFalse()
        ->and($diagnostico['Arial']['disponible'])->toBeTrue();
});

test('rellenador: conserva el formato del run, restaura el original exacto (tramos con formato, tabuladores) y no deja marcadores', function () {
    $docx = mpDocx(function (PhpWord $w): void {
        $seccion = $w->addSection();
        $parrafo = $seccion->addTextRun();
        $parrafo->addText('Firmado en ', ['name' => 'Arial']);
        $parrafo->addText('______', ['name' => 'Arial']);
        $parrafo->addText(' de ', ['name' => 'Arial', 'bold' => true, 'underline' => 'single']);
        $parrafo->addText('______', ['name' => 'Arial']);
        $parrafo->addText('.', ['name' => 'Arial']);
        $seccion->addText("Nombre:\t\t.", ['name' => 'Arial']);
    });
    $reglas = [
        ['tipo' => 'entre', 'desde' => 'Firmado en', 'hasta' => '.', 'campo' => 'lugar_y_fecha'],
        ['tipo' => 'blanco', 'antes' => 'Nombre:', 'campo' => 'nombre_completo'],
    ];

    $preparado = app(PreparadorMasterDocx::class)->preparar($docx, $reglas);
    $instancias = RellenadorDocx::instanciasDe(['instancias' => $preparado['instancias']]);
    $rellenador = app(RellenadorDocx::class);

    // El blanco mezclaba formatos ("de" en negritas): se guardan los tramos.
    expect(collect($instancias)->firstWhere('campo', 'lugar_y_fecha')['piezas'] ?? [])->toHaveCount(3);

    $identidad = DocumentoWord::desdeBytes($rellenador->restaurarOriginal($preparado['master'], $instancias));
    $original = DocumentoWord::desdeBytes($docx);
    expect($identidad->texto())->toBe($original->texto());

    $lleno = $rellenador->rellenar($preparado['master'], $instancias, ['lugar_y_fecha' => 'Cuernavaca, a 5 de octubre', 'nombre_completo' => 'JOSÉ FRANCISCO DE JESÚS HERNÁNDEZ GONZÁLEZ']);
    $texto = DocumentoWord::desdeBytes($lleno['docx'])->texto();
    expect($texto)->toContain('Firmado en Cuernavaca, a 5 de octubre.')
        ->and($texto)->toContain('JOSÉ FRANCISCO DE JESÚS HERNÁNDEZ GONZÁLEZ')
        ->and($texto)->not->toContain('{{')
        ->and($lleno['sin_valor'])->toBe([]);
});

test('conversor: un documento definitivo nunca se convierte con PhpWord/DomPDF; la vista previa sí, marcada como aproximada', function () {
    config(['formatos_oficiales.conversor' => 'phpword']);
    $conversor = app(ConversorDocxPdf::class);
    $docx = mpDocx(fn (PhpWord $w) => $w->addSection()->addText('Contrato de prueba'));

    expect($conversor->fiel())->toBeFalse()
        ->and($conversor->convertirFiel($docx))->toBeNull()
        ->and($conversor->convertir($docx)['fidelidad'] ?? null)->toBe('aproximada');
});

test('overlay: la casilla es una X dentro de su caja y un dato que no cabe se reporta como desborde (nunca encimado)', function () {
    $pdf = new Fpdi;
    $pdf->AddPage('P', [215.9, 279.4]);
    $pdf->Rect(20, 20, 6, 6);
    $original = (string) $pdf->Output('S');
    $campos = [
        ['campo' => 'marca_faltar', 'pagina' => 1, 'x' => 20, 'y' => 20, 'ancho' => 6, 'alto' => 6],
        ['campo' => 'nombre', 'pagina' => 1, 'x' => 30, 'y' => 40, 'ancho' => 20, 'alto' => 4, 'tamano' => 9],
        ['campo' => 'motivo', 'pagina' => 1, 'x' => 30, 'y' => 60, 'ancho' => 100, 'alto' => 8, 'tamano' => 9, 'multilinea' => true],
    ];

    $resultado = app(RenderizadorOverlayMaestro::class)->renderizarConReporte($original, $campos, [
        'marca_faltar' => 'X',
        'nombre' => 'JOSÉ FRANCISCO DE JESÚS HERNÁNDEZ GONZÁLEZ',
        'motivo' => str_repeat('Cita médica programada en el IMSS. ', 30),
    ]);

    expect(array_column($resultado['desbordes'], 'campo'))->toBe(['nombre', 'motivo'])
        ->and($resultado['paginas'])->toBe(1)
        ->and(app(RenderizadorOverlayMaestro::class)->renderizarConReporte($original, $campos, ['marca_faltar' => 'X', 'nombre' => 'ANA', 'motivo' => 'Cita'])['desbordes'])->toBe([]);
});

test('resolución del master: puesto+empresa > puesto > grupo+empresa > grupo > general; nunca el de Gerente para un Gestor', function () {
    $empresa = Empresa::factory()->create();
    $otraEmpresa = Empresa::factory()->create();
    $gestor = Puesto::factory()->create(['grupo_documental' => 'gestor']);
    $gerente = Puesto::factory()->create(['grupo_documental' => 'gerente']);
    $colaborador = Colaborador::factory()->create(['puesto_id' => $gestor->id, 'sucursal_principal_id' => Sucursal::factory()->create(['empresa_id' => $empresa->id])->id]);
    $crear = fn (string $familia, array $datos) => DocumentTemplate::query()->create([
        'clave' => 'contrato_prueba', 'familia' => $familia, 'nombre' => $familia, 'tipo' => 'contrato', 'motor' => 'docx', 'version' => 1,
        'activo' => true, 'operativo' => true, 'estado_master' => 'listo', 'visual_validation_status' => EstadoValidacionVisual::Aprobada, ...$datos,
    ]);

    $crear('general', ['grupos_puesto' => null]);
    expect(app(ResolvedorMaestroService::class)->buscar('contrato_prueba', $colaborador)?->familia)->toBe('general');

    $crear('gerente', ['grupos_puesto' => ['gerente']]);
    // Con variantes por grupo y sin fallback configurado, la general ya no aplica a un Gestor; la de Gerente nunca.
    expect(app(ResolvedorMaestroService::class)->buscar('contrato_prueba', $colaborador->refresh()))->toBeNull();

    $crear('grupo', ['grupos_puesto' => ['gestor']]);
    $crear('grupo_otra_empresa', ['grupos_puesto' => ['gestor'], 'empresa_id' => $otraEmpresa->id]);
    expect(app(ResolvedorMaestroService::class)->buscar('contrato_prueba', $colaborador->refresh())?->familia)->toBe('grupo');

    $crear('grupo_empresa', ['grupos_puesto' => ['gestor'], 'empresa_id' => $empresa->id]);
    expect(app(ResolvedorMaestroService::class)->buscar('contrato_prueba', $colaborador->refresh())?->familia)->toBe('grupo_empresa');

    $crear('puesto', ['puesto_id' => $gestor->id]);
    expect(app(ResolvedorMaestroService::class)->buscar('contrato_prueba', $colaborador->refresh())?->familia)->toBe('puesto');

    $crear('puesto_empresa', ['puesto_id' => $gestor->id, 'empresa_id' => $empresa->id]);
    $crear('puesto_gerente', ['puesto_id' => $gerente->id, 'empresa_id' => $empresa->id]);
    expect(app(ResolvedorMaestroService::class)->buscar('contrato_prueba', $colaborador->refresh())?->familia)->toBe('puesto_empresa');
});

test('notificación de un documento laboral: el colaborador va a Mi expediente; RH al proceso de la persona (nunca a Documentos maestros)', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $rh = clUsuario('rh_admin');
    $titular = clUsuario('colaborador');
    $documento = GeneratedDocument::factory()->create(['colaborador_id' => $titular->colaborador_id, 'estado_flujo' => EstadoFlujoDocumento::Generado]);
    $solicitud = SolicitudInterna::factory()->create(['colaborador_id' => $titular->colaborador_id]);
    $permiso = GeneratedDocument::factory()->create(['colaborador_id' => $titular->colaborador_id, 'estado_flujo' => EstadoFlujoDocumento::Generado, 'documentable_type' => $solicitud->getMorphClass(), 'documentable_id' => $solicitud->id]);
    $aviso = fn (GeneratedDocument $d) => new DatabaseNotification(['id' => (string) Str::uuid(), 'data' => ['type' => 'documento_firma_pendiente', 'related_type' => 'GeneratedDocument', 'resource_id' => $d->id]]);
    $destino = app(DestinoNotificacionService::class);

    expect($destino->resolver($aviso($documento), $titular)['url'])->toContain('mi-expediente')
        ->and($destino->resolver($aviso($documento), $rh)['url'])->toBe(route('rh.colaboradores.ciclo', $titular->colaborador_id, false))
        ->and($destino->resolver($aviso($permiso), $rh)['url'])->toBe(route('rh.solicitudes.show', $solicitud->id, false));
});
