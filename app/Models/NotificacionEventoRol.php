<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Excepción del checklist de avisos: un rol marcado o desmarcado respecto de
 * config/notificaciones_catalogo.php.
 *
 * @property int $id
 * @property int $role_id
 * @property string $evento
 * @property bool $activo
 * @property int|null $actualizado_por
 */
class NotificacionEventoRol extends Model
{
    protected $table = 'notificacion_evento_rol';

    protected $fillable = ['role_id', 'evento', 'activo', 'actualizado_por'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
