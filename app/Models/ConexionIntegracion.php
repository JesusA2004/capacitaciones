<?php

namespace App\Models;

use App\Enums\EstadoConexionIntegracion;
use App\Enums\ProveedorSesion;
use Database\Factories\ConexionIntegracionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Estado de salud de una integración externa (nunca guarda credenciales).
 *
 * @property int $id
 * @property ProveedorSesion $proveedor
 * @property EstadoConexionIntegracion $estado
 * @property Carbon|null $verificado_en
 * @property string|null $ultimo_error
 * @property array<string, mixed>|null $metadatos
 */
class ConexionIntegracion extends Model
{
    /** @use HasFactory<ConexionIntegracionFactory> */
    use HasFactory;

    protected $table = 'conexiones_integracion';

    protected $fillable = ['proveedor', 'estado', 'verificado_en', 'ultimo_error', 'metadatos'];

    protected function casts(): array
    {
        return [
            'proveedor' => ProveedorSesion::class,
            'estado' => EstadoConexionIntegracion::class,
            'verificado_en' => 'datetime',
            'metadatos' => 'array',
        ];
    }
}
