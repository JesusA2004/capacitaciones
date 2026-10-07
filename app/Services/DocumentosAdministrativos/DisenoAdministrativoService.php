<?php

namespace App\Services\DocumentosAdministrativos;

use App\Enums\FamiliaAdministrativa;
use App\Enums\TipoDocumentAsset;
use App\Models\DocumentAsset;
use Illuminate\Validation\ValidationException;

/**
 * Esquema del DISEÑO de un documento administrativo HTML y su
 * normalización: todo valor que venga del editor se acota a un rango
 * seguro (márgenes, tamaños, colores, opciones cerradas) y lo que falte se
 * completa con el diseño por defecto de la familia. El diseño solo decide
 * cómo se ve el documento; nunca contiene datos ni montos.
 */
class DisenoAdministrativoService
{
    /** Fuentes disponibles => pila CSS (seguras en DomPDF y en Chrome). */
    public const FUENTES = [
        'Helvetica' => "'Helvetica', 'Arial', sans-serif",
        'Arial' => "'Arial', 'Helvetica', sans-serif",
        'Georgia' => "'Georgia', 'Times New Roman', serif",
        'Times New Roman' => "'Times New Roman', 'Times', serif",
        'Courier' => "'Courier New', 'Courier', monospace",
        'DejaVu Sans' => "'DejaVu Sans', 'Arial', sans-serif",
    ];

    /**
     * @return array<string, mixed>
     */
    public function porDefecto(FamiliaAdministrativa $familia): array
    {
        return [
            'page' => ['size' => 'letter', 'orientation' => 'portrait', 'margins_mm' => ['top' => 14, 'right' => 15, 'bottom' => 16, 'left' => 15]],
            'typography' => ['font_family' => 'Helvetica', 'base_size_pt' => 9.5, 'line_height' => 1.4, 'color' => '#1f2937', 'weight' => 400],
            'paragraph' => ['indent_left_mm' => 0, 'indent_right_mm' => 0, 'first_line_mm' => 0, 'space_before_pt' => 0, 'space_after_pt' => 4, 'align' => 'left'],
            'colors' => ['primary' => '#274754', 'accent' => '#2dc7d3', 'muted' => '#6b7280', 'table_header_bg' => '#274754', 'table_header_text' => '#ffffff', 'total_bg' => '#dcfce7', 'total_text' => '#166534'],
            // Encabezado patronal: la razón social REAL del colaborador
            // (CLAUDE.md §21), nunca "MR. LANA PEOPLE" (eso es el sistema,
            // no el patrón). El placeholder lo resuelve DocumentoAdministrativoService::html()
            // igual que el resto del contenido, contra los datos reales del documento.
            'header' => ['show' => true, 'logo_asset_id' => null, 'logo_height_mm' => 12, 'logo_position' => 'left', 'height_mm' => 18, 'brand_text' => '{{empresa_razon_social}}'],
            'footer' => ['show' => true, 'text' => 'MR. LANA PEOPLE · Documento generado por el sistema', 'page_numbers' => true, 'distance_mm' => 8],
            'background' => ['asset_id' => null, 'apply_to' => 'all_pages', 'fit' => 'stretch', 'opacity' => 100, 'position' => 'center', 'safe_area_mm' => 0],
            'tables' => ['font_size_pt' => 9, 'padding_mm' => 1.6, 'border_color' => '#e5e7eb', 'border_width_px' => 1, 'header_style' => 'solid', 'repeat_header' => true, 'avoid_row_break' => true, 'zebra' => false],
            'signatures' => ['show' => $familia->firmasPorDefecto() !== [], 'line_width_mm' => 65, 'gap_mm' => 22, 'position' => 'split', 'blocks' => $familia->firmasPorDefecto()],
            'sections' => ['spacing_mm' => 4, 'title_size_pt' => 11, 'title_color' => '#274754', 'divider' => true, 'visibles' => array_map(fn () => true, $familia->secciones())],
            'content' => $familia->contenidoPorDefecto(),
        ];
    }

    /**
     * Combina lo enviado sobre el diseño por defecto y acota cada valor.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     *
     * @throws ValidationException recurso inexistente o del tipo equivocado.
     */
    public function normalizar(FamiliaAdministrativa $familia, array $entrada): array
    {
        $base = $this->porDefecto($familia);
        $g = fn (string $ruta, mixed $defecto) => data_get($entrada, $ruta, $defecto);

        $diseno = [
            'page' => [
                'size' => $this->opcion($g('page.size', 'letter'), ['letter', 'a4'], 'letter'),
                'orientation' => $this->opcion($g('page.orientation', 'portrait'), ['portrait', 'landscape'], 'portrait'),
                'margins_mm' => [
                    'top' => $this->numero($g('page.margins_mm.top', $base['page']['margins_mm']['top']), 0, 60),
                    'right' => $this->numero($g('page.margins_mm.right', $base['page']['margins_mm']['right']), 0, 60),
                    'bottom' => $this->numero($g('page.margins_mm.bottom', $base['page']['margins_mm']['bottom']), 0, 60),
                    'left' => $this->numero($g('page.margins_mm.left', $base['page']['margins_mm']['left']), 0, 60),
                ],
            ],
            'typography' => [
                'font_family' => $this->opcion($g('typography.font_family', 'Helvetica'), array_keys(self::FUENTES), 'Helvetica'),
                'base_size_pt' => $this->numero($g('typography.base_size_pt', 9.5), 6, 16),
                'line_height' => $this->numero($g('typography.line_height', 1.4), 1, 2.5),
                'color' => $this->color($g('typography.color', '#1f2937'), '#1f2937'),
                'weight' => (int) $this->opcion((string) $g('typography.weight', 400), ['300', '400', '500', '600', '700'], '400'),
            ],
            'paragraph' => [
                'indent_left_mm' => $this->numero($g('paragraph.indent_left_mm', 0), 0, 40),
                'indent_right_mm' => $this->numero($g('paragraph.indent_right_mm', 0), 0, 40),
                'first_line_mm' => $this->numero($g('paragraph.first_line_mm', 0), 0, 30),
                'space_before_pt' => $this->numero($g('paragraph.space_before_pt', 0), 0, 30),
                'space_after_pt' => $this->numero($g('paragraph.space_after_pt', 4), 0, 30),
                'align' => $this->opcion($g('paragraph.align', 'left'), ['left', 'justify', 'center', 'right'], 'left'),
            ],
            'colors' => array_map(
                fn (int|string $clave) => $this->color($g("colors.{$clave}", $base['colors'][$clave]), $base['colors'][$clave]),
                array_combine(array_keys($base['colors']), array_keys($base['colors'])),
            ),
            'header' => [
                'show' => (bool) $g('header.show', true),
                'logo_asset_id' => $this->recurso($g('header.logo_asset_id', null), [TipoDocumentAsset::Logo, TipoDocumentAsset::Imagen, TipoDocumentAsset::Sello], 'header.logo_asset_id'),
                'logo_height_mm' => $this->numero($g('header.logo_height_mm', 12), 4, 50),
                'logo_position' => $this->opcion($g('header.logo_position', 'left'), ['left', 'center', 'right'], 'left'),
                'height_mm' => $this->numero($g('header.height_mm', 18), 0, 80),
                'brand_text' => $this->texto($g('header.brand_text', '{{empresa_razon_social}}'), 80),
            ],
            'footer' => [
                'show' => (bool) $g('footer.show', true),
                'text' => $this->texto($g('footer.text', ''), 200),
                'page_numbers' => (bool) $g('footer.page_numbers', true),
                'distance_mm' => $this->numero($g('footer.distance_mm', 8), 2, 30),
            ],
            'background' => [
                'asset_id' => $this->recurso($g('background.asset_id', null), [TipoDocumentAsset::Fondo, TipoDocumentAsset::MarcaAgua, TipoDocumentAsset::Imagen], 'background.asset_id'),
                'apply_to' => $this->opcion($g('background.apply_to', 'all_pages'), ['all_pages', 'first_page'], 'all_pages'),
                'fit' => $this->opcion($g('background.fit', 'stretch'), ['contain', 'cover', 'stretch'], 'stretch'),
                'opacity' => (int) $this->numero($g('background.opacity', 100), 0, 100),
                'position' => $this->opcion($g('background.position', 'center'), ['center', 'top', 'bottom', 'left', 'right'], 'center'),
                'safe_area_mm' => $this->numero($g('background.safe_area_mm', 0), 0, 40),
            ],
            'tables' => [
                'font_size_pt' => $this->numero($g('tables.font_size_pt', 9), 6, 14),
                'padding_mm' => $this->numero($g('tables.padding_mm', 1.6), 0, 6),
                'border_color' => $this->color($g('tables.border_color', '#e5e7eb'), '#e5e7eb'),
                'border_width_px' => $this->numero($g('tables.border_width_px', 1), 0, 4),
                'header_style' => $this->opcion($g('tables.header_style', 'solid'), ['solid', 'light', 'none'], 'solid'),
                'repeat_header' => (bool) $g('tables.repeat_header', true),
                'avoid_row_break' => (bool) $g('tables.avoid_row_break', true),
                'zebra' => (bool) $g('tables.zebra', false),
            ],
            'signatures' => [
                'show' => (bool) $g('signatures.show', $base['signatures']['show']),
                'line_width_mm' => $this->numero($g('signatures.line_width_mm', 65), 20, 120),
                'gap_mm' => $this->numero($g('signatures.gap_mm', 22), 5, 80),
                'position' => $this->opcion($g('signatures.position', 'split'), ['left', 'center', 'right', 'split'], 'split'),
                'blocks' => $this->firmas($g('signatures.blocks', $base['signatures']['blocks'])),
            ],
            'sections' => [
                'spacing_mm' => $this->numero($g('sections.spacing_mm', 4), 0, 20),
                'title_size_pt' => $this->numero($g('sections.title_size_pt', 11), 7, 24),
                'title_color' => $this->color($g('sections.title_color', '#274754'), '#274754'),
                'divider' => (bool) $g('sections.divider', true),
                'visibles' => array_map(
                    fn (int|string $clave) => (bool) $g("sections.visibles.{$clave}", true),
                    array_combine(array_keys($familia->secciones()), array_keys($familia->secciones())),
                ),
            ],
            'content' => [
                'titulo' => $this->texto($g('content.titulo', $base['content']['titulo']), 150),
                'subtitulo' => $this->texto($g('content.subtitulo', $base['content']['subtitulo']), 250),
                'leyenda' => $this->texto($g('content.leyenda', $base['content']['leyenda']), 1000),
                'nota' => $this->texto($g('content.nota', $base['content']['nota']), 1500),
            ],
        ];

        return $diseno;
    }

    /**
     * Huella del diseño: cambia si cambia cualquier regla o el ARCHIVO de
     * un recurso (fondo/logo) que use.
     *
     * @param  array<string, mixed>  $diseno
     */
    public function hash(array $diseno): string
    {
        return hash('sha256', (string) json_encode([$diseno, $this->recursosUsados($diseno)]));
    }

    /**
     * @param  array<string, mixed>  $diseno
     * @return list<array{id: int, tipo: string, sha256: string}>
     */
    public function recursosUsados(array $diseno): array
    {
        $ids = array_values(array_filter([data_get($diseno, 'background.asset_id'), data_get($diseno, 'header.logo_asset_id')], fn ($v) => is_numeric($v)));

        return array_values(DocumentAsset::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'tipo', 'sha256'])
            ->map(fn (DocumentAsset $a) => ['id' => $a->id, 'tipo' => $a->tipo->value, 'sha256' => $a->sha256])->all());
    }

    private function numero(mixed $valor, float $minimo, float $maximo): float
    {
        return round(max($minimo, min($maximo, is_numeric($valor) ? (float) $valor : $minimo)), 2);
    }

    /**
     * @param  list<string>  $permitidos
     */
    private function opcion(mixed $valor, array $permitidos, string $defecto): string
    {
        return is_string($valor) && in_array($valor, $permitidos, true) ? $valor : $defecto;
    }

    private function color(mixed $valor, string $defecto): string
    {
        return is_string($valor) && preg_match('/^#[0-9a-fA-F]{6}$/', $valor) === 1 ? strtolower($valor) : $defecto;
    }

    private function texto(mixed $valor, int $maximo): string
    {
        return mb_substr(trim(is_scalar($valor) ? (string) $valor : ''), 0, $maximo);
    }

    /**
     * @param  list<TipoDocumentAsset>  $tipos
     */
    private function recurso(mixed $valor, array $tipos, string $campo): ?int
    {
        if ($valor === null || $valor === '' || $valor === 0) {
            return null;
        }

        $asset = DocumentAsset::query()->where('id', (int) $valor)->where('activo', true)->first();

        if ($asset === null || ! in_array($asset->tipo, $tipos, true)) {
            throw ValidationException::withMessages([$campo => 'Elige un recurso válido de la biblioteca (Fondos y recursos).']);
        }

        return $asset->id;
    }

    /**
     * @return list<array{label: string}>
     */
    private function firmas(mixed $valor): array
    {
        $bloques = [];

        foreach (is_array($valor) ? array_slice(array_values($valor), 0, 4) : [] as $bloque) {
            $etiqueta = $this->texto(is_array($bloque) ? ($bloque['label'] ?? '') : $bloque, 80);

            if ($etiqueta !== '') {
                $bloques[] = ['label' => $etiqueta];
            }
        }

        return $bloques;
    }
}
