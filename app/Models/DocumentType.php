<?php

namespace App\Models;

use App\Enums\CategoriaDocumento;
use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catalogo de tipos de documento del expediente (INE, CURP, RFC, contrato,
 * etc). No tiene softDeletes a proposito: un tipo de documento se desactiva
 * (activo=false), nunca se borra, porque employee_documents.document_type_id
 * lo referencia con restrictOnDelete().
 *
 * @property int $id
 * @property string $nombre
 * @property string $clave
 * @property string|null $descripcion
 * @property bool $requerido
 * @property bool $checklist_opcional
 * @property bool $aplica_alta
 * @property bool $activo
 * @property CategoriaDocumento $categoria
 * @property int|null $vigencia_meses
 */
class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory;

    protected $table = 'document_types';

    protected $fillable = ['nombre', 'clave', 'categoria', 'descripcion', 'requerido', 'checklist_opcional', 'aplica_alta', 'activo', 'vigencia_meses', 'orden'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'categoria' => 'personales',
    ];

    protected function casts(): array
    {
        return [
            'requerido' => 'boolean',
            'checklist_opcional' => 'boolean',
            'aplica_alta' => 'boolean',
            'activo' => 'boolean',
            'categoria' => CategoriaDocumento::class,
            'vigencia_meses' => 'integer',
        ];
    }

    /**
     * Tipos que se muestran en el checklist del expediente: los requeridos y
     * los OPCIONALES del checklist (pagaré, antecedentes no penales,
     * licencia de conducir). Los opcionales se ven y se pueden asignar, pero
     * nunca cuentan para el avance, los faltantes, el bloqueo de la app ni la
     * carta compromiso: esas reglas leen solo `requerido`.
     *
     * @param  Builder<DocumentType>  $query
     */
    public function scopeDelChecklist(Builder $query): void
    {
        $query->where('activo', true)
            ->where(fn (Builder $q) => $q->where('requerido', true)->orWhere('checklist_opcional', true));
    }

    public function estaEnChecklist(): bool
    {
        return $this->activo && ($this->requerido || $this->checklist_opcional);
    }

    /**
     * @return HasMany<EmployeeDocument, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}
