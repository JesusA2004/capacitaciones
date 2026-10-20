<?php

namespace App\Models;

use App\Enums\FormaSalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Catálogo de formas de salida (cat_forma_salida de la especificación):
 * RV, NR y RE. Las claves y su semántica son fijas (App\Enums\FormaSalida);
 * la tabla guarda nombre/descripción/orden/activo para consulta y reportes.
 *
 * @property int $id
 * @property FormaSalida $clave
 * @property string $nombre
 * @property string $descripcion
 * @property int $orden
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FormaSalidaCatalogo extends Model
{
    protected $table = 'formas_salida';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'clave' => FormaSalida::class,
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }
}
