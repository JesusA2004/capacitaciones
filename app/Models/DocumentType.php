<?php

namespace App\Models;

use Database\Factories\DocumentTypeFactory;
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
 * @property bool $aplica_alta
 * @property bool $activo
 */
class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory;

    protected $table = 'document_types';

    protected $fillable = ['nombre', 'clave', 'descripcion', 'requerido', 'aplica_alta', 'activo'];

    protected function casts(): array
    {
        return [
            'requerido' => 'boolean',
            'aplica_alta' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<EmployeeDocument, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}
