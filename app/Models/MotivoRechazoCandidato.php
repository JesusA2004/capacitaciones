<?php

namespace App\Models;

use App\Enums\CategoriaMotivoRechazo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo administrable de motivos de rechazo de candidatos (CLAUDE.md
 * §10). Nunca se borra físicamente si ya se usó: solo se desactiva
 * (`activo = false`), para que el histórico de candidatos ya rechazados con
 * ese motivo siga siendo válido.
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property CategoriaMotivoRechazo $categoria
 * @property bool $activo
 * @property bool $no_recontratable_por_defecto
 */
class MotivoRechazoCandidato extends Model
{
    protected $table = 'motivos_rechazo_candidato';

    protected $fillable = ['clave', 'nombre', 'categoria', 'activo', 'no_recontratable_por_defecto'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'categoria' => 'empresa',
    ];

    protected function casts(): array
    {
        return [
            'categoria' => CategoriaMotivoRechazo::class,
            'activo' => 'boolean',
            'no_recontratable_por_defecto' => 'boolean',
        ];
    }

    /**
     * @param  Builder<MotivoRechazoCandidato>  $query
     * @return Builder<MotivoRechazoCandidato>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
