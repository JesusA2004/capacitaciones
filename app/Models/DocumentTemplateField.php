<?php

namespace App\Models;

use App\Enums\EstadoCampoDocumento;
use App\Enums\TransformacionTexto;
use App\Enums\ZonaCampoDocumento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un espacio de UNA versión de documento maestro: qué dato va ahí (o si es
 * firma/huella/zona sin dato) y dónde/cómo se imprime. Es la fuente vigente
 * del mapa de campos (no-code); document_templates.mapping es su versión
 * compilada (DefinicionMaestroService → PreparacionMaestroService).
 *
 * DOCX: `selector` = ancla serializada del preparador (tipo blanco/entre/
 * texto/celda + antes/despues/desde/hasta/buscar/etiqueta/parrafo/fila/
 * ocurrencia/en_parrafo). PDF: page + caja en mm (x, y, width, height)
 * desde la esquina superior izquierda.
 *
 * @property int $id
 * @property int $document_template_id
 * @property string $field_key
 * @property ZonaCampoDocumento $zona
 * @property EstadoCampoDocumento $estado
 * @property string|null $source_key
 * @property array<string, mixed>|null $expression
 * @property TransformacionTexto|null $transform
 * @property string|null $label
 * @property string $type
 * @property int|null $page
 * @property float|null $x
 * @property float|null $y
 * @property float|null $width
 * @property float|null $height
 * @property string|null $font_family
 * @property float|null $font_size
 * @property float|null $font_size_min
 * @property string|null $font_weight
 * @property string|null $text_align
 * @property float|null $line_height
 * @property bool $multiline
 * @property bool $required
 * @property string|null $background_color
 * @property array<string, mixed>|null $selector
 * @property string|null $placeholder_original
 * @property string|null $detection_method
 * @property float|null $detection_confidence
 * @property int $sort_order
 * @property array<string, mixed>|null $metadata
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DocumentTemplateField extends Model
{
    protected $table = 'document_template_fields';

    protected $fillable = [
        'document_template_id', 'field_key', 'zona', 'estado', 'source_key', 'expression', 'transform', 'label', 'type',
        'page', 'x', 'y', 'width', 'height', 'font_family', 'font_size', 'font_size_min', 'font_weight', 'text_align',
        'line_height', 'multiline', 'required', 'background_color', 'selector', 'placeholder_original',
        'detection_method', 'detection_confidence', 'sort_order', 'metadata', 'created_by', 'updated_by',
    ];

    protected $attributes = [
        'zona' => 'dato',
        'estado' => 'sugerido',
        'type' => 'texto',
        'multiline' => false,
        'required' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'zona' => ZonaCampoDocumento::class,
            'estado' => EstadoCampoDocumento::class,
            'transform' => TransformacionTexto::class,
            'expression' => 'array',
            'selector' => 'array',
            'metadata' => 'array',
            'page' => 'integer',
            'x' => 'float',
            'y' => 'float',
            'width' => 'float',
            'height' => 'float',
            'font_size' => 'float',
            'font_size_min' => 'float',
            'line_height' => 'float',
            'detection_confidence' => 'float',
            'multiline' => 'boolean',
            'required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DocumentTemplate, $this>
     */
    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    /**
     * Clave con la que el motor llena este espacio. Un dato directo usa la
     * clave del catálogo tal cual (compatible con el mapa heredado); con
     * transformación o expresión compuesta se usa una clave propia del
     * espacio, calculada por ExpresionesDocumentoService.
     */
    public function campoMotor(): string
    {
        if ($this->expression !== null || $this->transform !== null || $this->source_key === null) {
            return sprintf('x_%s', preg_replace('/[^a-z0-9_]/', '_', strtolower($this->field_key)));
        }

        return $this->source_key;
    }

    public function asignado(): bool
    {
        return $this->zona !== ZonaCampoDocumento::Dato || $this->source_key !== null || $this->expression !== null;
    }
}
