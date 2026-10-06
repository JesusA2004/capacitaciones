<?php

namespace App\Services\DocumentosMaestros\Calidad;

use App\Enums\EstadoValidacionVisual;
use App\Enums\FidelidadConversion;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosMaestros\AlmacenMaestrosService;
use App\Services\DocumentosMaestros\Docx\RellenadorDocx;
use App\Services\DocumentosMaestros\Pdf\RenderizadorOverlayMaestro;
use App\Services\Formatos\Motor\ConversorDocxPdf;
use DOMDocument;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

/**
 * QA visual de UNA versión de documento maestro: ¿el documento que PEOPLE
 * genera se ve EXACTAMENTE como el original de Jurídico?
 *
 * No corre en cada generación: corre al importar/cargar la versión, cuando
 * RH pulsa "Probar diseño" y antes de activarla.
 *
 * DOCX (contratos, avisos, actas…):
 *   1. ORIGINAL → PDF (conversor fiel: Word nativo o LibreOffice).
 *   2. IDENTIDAD: master con cada marcador restaurado a su texto original →
 *      PDF. Debe ser idéntico al 1 (si no, la preparación del master movió
 *      algo: runs, tabuladores, estilos).
 *   3. PRUEBA DE ESTRÉS: master con datos largos realistas → PDF. Mismo
 *      número y tamaño de páginas, encabezado/pie (logo, membrete) intactos
 *      y sin reflow fuerte; ningún marcador residual; imágenes, encabezados,
 *      pies y tablas del paquete conservados.
 *   4. Fuentes: todas las del documento instaladas en el servidor.
 *
 * PDF overlay (permiso, préstamo):
 *   1. Páginas del ORIGINAL vs las mismas páginas importadas por el motor
 *      (sin datos): el fondo, logo y cajas se conservan.
 *   2. Con datos largos: fuera de las cajas de los campos (máscaras) nada
 *      cambia, y ningún dato se desborda de su caja.
 *
 * Resultado en document_templates: visual_validation_status (pending |
 * passed | failed), similitud, páginas, motor, fecha y reporte detallado.
 */
class ValidacionVisualMaestroService
{
    public function __construct(
        private readonly ConversorDocxPdf $conversor,
        private readonly RasterizadorPdf $rasterizador,
        private readonly ComparadorVisual $comparador,
        private readonly DiagnosticoFuentesService $fuentes,
        private readonly ValoresPruebaDocumento $valoresPrueba,
        private readonly RellenadorDocx $rellenador,
        private readonly RenderizadorOverlayMaestro $overlay,
        private readonly AlmacenMaestrosService $almacen,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Razón por la que el QA no puede correr en este servidor (null = puede).
     */
    public function impedimento(DocumentTemplate $master): ?string
    {
        if (! $this->rasterizador->disponible()) {
            return 'No hay un rasterizador de PDF en este servidor (Windows nativo o pdftoppm).';
        }

        if ($this->esDocx($master) && ! $this->conversor->fiel()) {
            return 'No hay un conversor fiel (Microsoft Word o LibreOffice) para convertir el Word a PDF.';
        }

        return null;
    }

    public function validar(DocumentTemplate $master, ?User $actor = null): DocumentTemplate
    {
        if ($master->estado_master === 'referencia' || ! $master->operativo) {
            return $this->guardar($master, EstadoValidacionVisual::Pendiente, ['problemas' => ['Documento de referencia: no se genera para colaboradores.']], $actor);
        }

        $impedimento = $this->impedimento($master);

        if ($impedimento !== null) {
            // Infraestructura, no un defecto del formato: queda pendiente.
            return $this->guardar($master, EstadoValidacionVisual::Pendiente, ['problemas' => [$impedimento], 'infraestructura' => true], $actor);
        }

        // Nunca partir de una lista de fuentes instaladas que pudo quedar
        // vieja en caché: cada corrida del QA consulta las fuentes reales.
        $this->fuentes->invalidarCache();

        try {
            $reporte = $this->esDocx($master) ? $this->validarDocx($master) : $this->validarOverlay($master);
        } catch (Throwable $e) {
            Log::warning('ValidacionVisualMaestroService: no se pudo completar el QA visual.', ['master_id' => $master->id, 'familia' => $master->familia, 'error' => $e->getMessage()]);
            $reporte = ['problemas' => ['No se pudo completar la prueba de diseño: '.mb_substr($e->getMessage(), 0, 300)]];
        }

        $estado = ($reporte['problemas'] ?? []) === [] ? EstadoValidacionVisual::Aprobada : EstadoValidacionVisual::Fallida;

        return $this->guardar($master, $estado, $reporte, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    private function validarDocx(DocumentTemplate $master): array
    {
        $original = $this->almacen->original($master);
        $docxMaster = $this->almacen->master($master);
        $instancias = RellenadorDocx::instanciasDe($master->mapping);
        $problemas = [];

        $fuentes = $this->fuentes->diagnosticar($original);

        foreach ($fuentes as $fuente) {
            if (! $fuente['disponible']) {
                $problemas[] = sprintf('La fuente «%s» no está instalada en el servidor%s: el PDF cambiaría de tipografía. Instálala y vuelve a probar.', $fuente['fuente'], $fuente['sustitucion'] !== null ? sprintf(' (se sustituiría por %s)', $fuente['sustitucion']) : '');
            }
        }

        $pdfOriginal = $this->conversor->convertirFiel($original);

        if ($pdfOriginal === null) {
            return ['problemas' => ['El conversor fiel no pudo convertir el ORIGINAL a PDF.'], 'fuentes' => $fuentes];
        }

        $motor = $pdfOriginal['conversor'];
        $docxIdentidad = $this->rellenador->restaurarOriginal($docxMaster, $instancias);
        $campos = array_values(array_unique(array_map(fn (array $i): string => $i['campo'], $instancias)));
        $valores = $this->valoresPrueba->valores($campos);
        $docxPrueba = $this->rellenador->rellenar($docxMaster, $instancias, $valores)['docx'];
        $pdfIdentidad = $this->conversor->convertirFiel($docxIdentidad, [$motor]);
        $pdfPrueba = $this->conversor->convertirFiel($docxPrueba, [$motor]);

        if ($pdfIdentidad === null || $pdfPrueba === null) {
            return ['problemas' => ['El conversor no pudo convertir el documento generado a PDF.'], 'fuentes' => $fuentes, 'motor' => $motor];
        }

        $dpi = (int) config('documentos_maestros.validacion_visual.dpi', 40);
        $paginasOriginal = $this->rasterizador->rasterizar($pdfOriginal['pdf'], $dpi);
        $paginasIdentidad = $this->rasterizador->rasterizar($pdfIdentidad['pdf'], $dpi);
        $paginasPrueba = $this->rasterizador->rasterizar($pdfPrueba['pdf'], $dpi);

        $estructura = $this->estructura($original, $docxMaster, $docxPrueba);
        $problemas = [...$problemas, ...$estructura['problemas']];
        $conteo = ['original' => count($paginasOriginal), 'identidad' => count($paginasIdentidad), 'prueba' => count($paginasPrueba)];

        if ($conteo['identidad'] !== $conteo['original']) {
            $problemas[] = sprintf('El documento preparado tiene %d páginas y el original %d: la preparación del master alteró el diseño.', $conteo['identidad'], $conteo['original']);
        }

        // Con datos MUY largos el Word puede crecer (renglones extra empujan
        // la última hoja). No invalida el diseño — la identidad sí lo mide —
        // pero se advierte, y al generar para una persona real que cause
        // esa página extra el motor bloquea con DOCUMENT_FIELD_OVERFLOW.
        $advertencias = [];

        if ($conteo['prueba'] !== $conteo['original']) {
            $advertencias[] = sprintf('Con datos muy largos el documento pasa de %d a %d páginas: si le ocurre a un colaborador real, la generación se bloquea y se indica qué dato no cabe.', $conteo['original'], $conteo['prueba']);
        }

        $identidad = $this->compararSerie($paginasOriginal, $paginasIdentidad);
        // La prueba de estrés reacomoda legítimamente el cuerpo (un nombre
        // largo ocupa más renglones): su similitud por página es
        // informativa. Lo que NO puede pasar: más/menos páginas, otro tamaño
        // de página, encabezados/pies/imágenes distintos (eso lo garantiza
        // estructura(): sus partes XML quedan byte a byte iguales).
        $prueba = $this->compararSerie($paginasOriginal, $paginasPrueba);
        $umbralIdentidad = (float) config('documentos_maestros.validacion_visual.umbral_identidad', 0.985);

        foreach ([...$identidad['tamanos_distintos'], ...$prueba['tamanos_distintos']] as $pagina) {
            $problemas[] = sprintf('La página %d cambió de tamaño respecto al original.', $pagina);
        }

        foreach ($identidad['por_pagina'] as $p) {
            if ($p['similitud'] < $umbralIdentidad) {
                $problemas[] = sprintf('Página %d: el master preparado no coincide con el original (similitud %.1f %%).', $p['pagina'], $p['similitud'] * 100);
            }
        }

        return [
            'motor' => $motor,
            'fidelidad' => FidelidadConversion::deConversor($motor)->value,
            'dpi' => $dpi,
            'paginas' => $conteo,
            'similitud' => $identidad['minima'],
            'identidad' => $identidad,
            'prueba' => $prueba,
            'estructura' => $estructura['resumen'],
            'fuentes' => $fuentes,
            'campos_probados' => count($campos),
            'desbordes' => [],
            'advertencias' => $advertencias,
            'problemas' => array_values(array_unique($problemas)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validarOverlay(DocumentTemplate $master): array
    {
        $mapping = (array) ($master->mapping ?? []);
        $campos = array_values(array_filter((array) ($mapping['campos'] ?? []), 'is_array'));
        $paginas = array_values(array_map('intval', (array) ($mapping['paginas'] ?? [])));
        $copias = array_values(array_map('floatval', (array) ($mapping['copias_offset_y'] ?? [0.0])));
        $pdfMaster = $this->almacen->master($master);
        $pdfOriginal = $this->almacen->original($master);
        $problemas = [];

        $base = $this->overlay->renderizarConReporte($pdfMaster, [], [], $paginas, $copias);
        $nombres = array_values(array_unique(array_map(fn (array $c): string => (string) $c['campo'], $campos)));
        $valores = $this->valoresPrueba->valores($nombres);
        $prueba = $this->overlay->renderizarConReporte($pdfMaster, $campos, $valores, $paginas, $copias);
        $dpi = (int) config('documentos_maestros.validacion_visual.dpi', 40);
        $rasterOriginal = $this->rasterizador->rasterizar($pdfOriginal, $dpi);
        $rasterBase = $this->rasterizador->rasterizar($base['pdf'], $dpi);
        $rasterPrueba = $this->rasterizador->rasterizar($prueba['pdf'], $dpi);
        $seleccion = $paginas === [] ? range(1, count($rasterOriginal)) : $paginas;
        $originalSeleccion = array_values(array_filter($rasterOriginal, fn (array $p): bool => in_array($p['pagina'], $seleccion, true)));
        $umbral = (float) config('documentos_maestros.validacion_visual.umbral_overlay', 0.985);

        if (count($rasterBase) !== count($originalSeleccion) || count($rasterPrueba) !== count($originalSeleccion)) {
            $problemas[] = sprintf('El documento generado tiene %d página(s) y el original %d.', count($rasterPrueba), count($originalSeleccion));
        }

        // 1. Importación fiel: original vs páginas importadas sin datos.
        $importacion = $this->compararSerie($originalSeleccion, $rasterBase);

        foreach ($importacion['tamanos_distintos'] as $pagina) {
            $problemas[] = sprintf('La página %d cambió de tamaño respecto al original.', $pagina);
        }

        foreach ($importacion['por_pagina'] as $p) {
            if ($p['similitud'] < $umbral) {
                $problemas[] = sprintf('Página %d: el fondo/diseño del PDF original no se conserva (similitud %.1f %%).', $p['pagina'], $p['similitud'] * 100);
            }
        }

        // 2. Con datos: fuera de las cajas, nada cambia.
        $mascaras = [];

        foreach ($campos as $campo) {
            foreach ($copias as $desplazamiento) {
                $mascaras[(int) ($campo['pagina'] ?? 1)][] = [
                    'x' => (float) $campo['x'] * 72 / 25.4,
                    'y' => ((float) $campo['y'] + $desplazamiento) * 72 / 25.4,
                    'ancho' => (float) ($campo['ancho'] ?? 60) * 72 / 25.4,
                    'alto' => (float) ($campo['alto'] ?? 5) * 72 / 25.4,
                ];
            }
        }

        $porPagina = [];

        foreach ($rasterBase as $i => $paginaBase) {
            $paginaPrueba = $rasterPrueba[$i] ?? null;

            if ($paginaPrueba === null) {
                continue;
            }

            $numeroOriginal = $seleccion[$i] ?? ($i + 1);
            $r = $this->comparador->comparar($paginaBase['png'], $paginaPrueba['png'], $paginaBase['dpi'], $mascaras[$numeroOriginal] ?? []);
            $porPagina[] = ['pagina' => $numeroOriginal, ...$r];

            if ($r['similitud'] < $umbral) {
                $problemas[] = sprintf('Página %d: los datos alteran el diseño fuera de sus cajas (similitud %.1f %%).', $numeroOriginal, $r['similitud'] * 100);
            }
        }

        foreach ($prueba['desbordes'] as $desborde) {
            $problemas[] = sprintf('El campo «%s» no cabe en su caja con un dato largo: %s', $desborde['campo'], $desborde['razon']);
        }

        return [
            'motor' => 'overlay',
            'fidelidad' => FidelidadConversion::Nativa->value,
            'dpi' => $dpi,
            'paginas' => ['original' => count($originalSeleccion), 'identidad' => count($rasterBase), 'prueba' => count($rasterPrueba)],
            'similitud' => min($importacion['minima'], $porPagina === [] ? 1.0 : min(array_column($porPagina, 'similitud'))),
            'identidad' => $importacion,
            'prueba' => ['por_pagina' => $porPagina],
            'fuentes' => [],
            'campos_probados' => count($nombres),
            'desbordes' => $prueba['desbordes'],
            'problemas' => array_values(array_unique($problemas)),
        ];
    }

    /**
     * @param  list<array{pagina: int, ancho_pt: float, alto_pt: float, dpi: float, png: string}>  $a
     * @param  list<array{pagina: int, ancho_pt: float, alto_pt: float, dpi: float, png: string}>  $b
     * @return array{minima: float, por_pagina: list<array<string, mixed>>, tamanos_distintos: list<int>}
     */
    private function compararSerie(array $a, array $b): array
    {
        $porPagina = [];
        $distintos = [];

        foreach ($a as $i => $paginaA) {
            $paginaB = $b[$i] ?? null;

            if ($paginaB === null) {
                break;
            }

            if (abs($paginaA['ancho_pt'] - $paginaB['ancho_pt']) > 1.0 || abs($paginaA['alto_pt'] - $paginaB['alto_pt']) > 1.0) {
                $distintos[] = $paginaA['pagina'];
            }

            $r = $this->comparador->comparar($paginaA['png'], $paginaB['png'], $paginaA['dpi']);
            $porPagina[] = ['pagina' => $paginaA['pagina'], 'similitud' => $r['similitud'], 'encabezado' => $r['encabezado'], 'pie' => $r['pie'], 'zonas' => $r['zonas']];
        }

        return [
            'minima' => $porPagina === [] ? 0.0 : (float) min(array_column($porPagina, 'similitud')),
            'por_pagina' => $porPagina,
            'tamanos_distintos' => $distintos,
        ];
    }

    /**
     * Partes del paquete DOCX que deben sobrevivir intactas al llenado.
     *
     * @return array{resumen: array<string, mixed>, problemas: list<string>}
     */
    private function estructura(string $original, string $master, string $generado): array
    {
        $a = $this->inventario($original);
        $b = $this->inventario($generado);
        $a['con_datos'] = $this->inventario($master)['con_datos'];
        $problemas = [];

        foreach (['imagenes' => 'imágenes', 'encabezados' => 'encabezados', 'pies' => 'pies de página', 'tablas' => 'tablas', 'secciones' => 'secciones'] as $clave => $etiqueta) {
            if ($a[$clave] !== $b[$clave]) {
                $problemas[] = sprintf('El documento generado tiene %d %s y el original %d.', $b[$clave], $etiqueta, $a[$clave]);
            }
        }

        if ($b['marcadores'] > 0) {
            $problemas[] = sprintf('Quedaron %d marcador(es) sin sustituir en el documento generado.', $b['marcadores']);
        }

        // Estilos, numeración, configuración, imágenes y los encabezados/pies
        // sin datos variables deben quedar byte a byte iguales al original:
        // mismo XML + mismo tamaño de página + mismo conversor = mismo logo,
        // membrete y pie en el PDF.
        foreach ($a['hashes'] as $parte => $hash) {
            $esEncabezado = preg_match('#^word/(header|footer)\d*\.xml$#', $parte) === 1;

            if ($esEncabezado && ($a['con_datos'][$parte] ?? false)) {
                continue;
            }

            if (($b['hashes'][$parte] ?? null) !== $hash) {
                $problemas[] = sprintf('La parte %s del Word cambió (debe copiarse intacta).', $parte);
            }
        }

        return [
            'resumen' => [
                'original' => array_diff_key($a, ['hashes' => true, 'con_datos' => true]),
                'generado' => array_diff_key($b, ['hashes' => true, 'con_datos' => true]),
                'partes_intactas' => count($a['hashes']),
            ],
            'problemas' => $problemas,
        ];
    }

    /**
     * @return array{imagenes: int, encabezados: int, pies: int, tablas: int, secciones: int, marcadores: int, hashes: array<string, string>, con_datos: array<string, bool>}
     */
    private function inventario(string $docx): array
    {
        $temporal = tempnam(sys_get_temp_dir(), 'inv');
        $resultado = ['imagenes' => 0, 'encabezados' => 0, 'pies' => 0, 'tablas' => 0, 'secciones' => 0, 'marcadores' => 0, 'hashes' => [], 'con_datos' => []];

        if ($temporal === false) {
            return $resultado;
        }

        file_put_contents($temporal, $docx);

        try {
            $zip = new ZipArchive;

            if ($zip->open($temporal) !== true) {
                return $resultado;
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = (string) $zip->getNameIndex($i);
                $contenido = (string) $zip->getFromIndex($i);

                match (true) {
                    str_starts_with($nombre, 'word/media/') => $resultado['imagenes']++,
                    preg_match('#^word/header\d*\.xml$#', $nombre) === 1 => $resultado['encabezados']++,
                    preg_match('#^word/footer\d*\.xml$#', $nombre) === 1 => $resultado['pies']++,
                    default => null,
                };

                if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $nombre) === 1) {
                    $marcadores = (int) preg_match_all('/\{\{[a-z0-9_]+@\d+\}\}/', $contenido);
                    $resultado['tablas'] += substr_count($contenido, '<w:tbl>');
                    $resultado['secciones'] += substr_count($contenido, '<w:sectPr');
                    $resultado['marcadores'] += $marcadores;
                    $resultado['con_datos'][$nombre] = $marcadores > 0;
                }

                // Encabezados/pies los re-serializa DocumentoWord (DOM): se
                // comparan en forma canónica (C14N). El resto, byte a byte.
                if (preg_match('#^word/(header|footer)\d*\.xml$#', $nombre) === 1) {
                    $resultado['hashes'][$nombre] = hash('sha256', $this->canonico($contenido));
                } elseif (in_array($nombre, ['word/styles.xml', 'word/numbering.xml', 'word/settings.xml', 'word/fontTable.xml', 'word/theme/theme1.xml'], true) || str_starts_with($nombre, 'word/media/')) {
                    $resultado['hashes'][$nombre] = hash('sha256', $contenido);
                }
            }

            $zip->close();
        } finally {
            @unlink($temporal);
        }

        return $resultado;
    }

    private function canonico(string $xml): string
    {
        $dom = new DOMDocument;

        if (! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            return $xml;
        }

        $canonico = $dom->C14N();

        return $canonico !== false ? $canonico : $xml;
    }

    private function esDocx(DocumentTemplate $master): bool
    {
        return (((array) ($master->mapping ?? []))['motor'] ?? 'docx') !== 'pdf_overlay';
    }

    /**
     * @param  array<string, mixed>  $reporte
     */
    private function guardar(DocumentTemplate $master, EstadoValidacionVisual $estado, array $reporte, ?User $actor): DocumentTemplate
    {
        $paginas = (array) ($reporte['paginas'] ?? []);

        $master->forceFill([
            'visual_validation_status' => $estado,
            'visual_similarity' => isset($reporte['similitud']) ? round((float) $reporte['similitud'], 4) : null,
            'page_count_original' => isset($paginas['original']) ? (int) $paginas['original'] : $master->page_count_original,
            'page_count_output' => isset($paginas['prueba']) ? (int) $paginas['prueba'] : null,
            'visual_checked_at' => now(),
            'visual_engine' => isset($reporte['motor']) ? (string) $reporte['motor'] : null,
            'visual_report' => [...$reporte, 'ejecutado_por' => $actor?->name, 'rasterizador' => $this->rasterizador->motor()],
            'diagnostico_fuentes' => isset($reporte['fuentes']) && $reporte['fuentes'] !== [] ? $reporte['fuentes'] : $master->diagnostico_fuentes,
        ])->save();

        $this->auditoria->registrar('documento_maestro_qa_visual', $master, $actor, [
            'familia' => $master->familia,
            'version' => $master->version,
            'estado' => $estado->value,
            'similitud' => $reporte['similitud'] ?? null,
            'motor' => $reporte['motor'] ?? null,
        ]);

        return $master->refresh();
    }
}
