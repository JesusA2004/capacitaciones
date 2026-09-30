<?php

namespace App\Models;

use App\Enums\TipoCelebracion;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuración editable por RH de un tipo de celebración (mensaje de la
 * tarjeta, fondo propio, activo, envío automático). Ver docs/CELEBRACIONES.md.
 *
 * @property int $id
 * @property TipoCelebracion $tipo
 * @property bool $activo
 * @property string|null $mensaje
 * @property string|null $fondo_path
 * @property bool $auto_enviar_colaborador
 * @property bool $mostrar_logo
 * @property float|null $texto_titulo_y
 * @property float|null $texto_nombre_y
 * @property float|null $texto_frase_y
 * @property int|null $updated_by
 */
class CelebracionConfiguracion extends Model
{
    protected $table = 'celebracion_configuraciones';

    protected $hidden = ['fondo_path'];

    protected $fillable = [
        'tipo', 'activo', 'mensaje', 'fondo_path', 'auto_enviar_colaborador',
        'mostrar_logo', 'texto_titulo_y', 'texto_nombre_y', 'texto_frase_y', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoCelebracion::class,
            'activo' => 'boolean',
            'auto_enviar_colaborador' => 'boolean',
            'mostrar_logo' => 'boolean',
            'texto_titulo_y' => 'float',
            'texto_nombre_y' => 'float',
            'texto_frase_y' => 'float',
        ];
    }

    public static function de(TipoCelebracion $tipo): self
    {
        return self::query()->firstOrCreate(['tipo' => $tipo->value], [
            'activo' => true,
            'mensaje' => $tipo === TipoCelebracion::AniversarioLaboral ? (string) config('celebraciones.aniversario.mensaje') : null,
            'auto_enviar_colaborador' => false,
            'mostrar_logo' => true,
        ]);
    }
}
