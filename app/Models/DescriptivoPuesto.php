<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Versión de un descriptivo de puesto OFICIAL (PDF intacto en el NAS +
 * su contenido estructurado). Ver DescriptivoPuestoService.
 *
 * `datos` (todo lo que el PDF trae, nada inferido):
 * - nombre_puesto, area, jefe_inmediato, edad, sexo, tipo_empleado,
 *   personal_a_cargo: string|null
 * - formacion: {minima, deseable}; experiencia: {minima, deseable,
 *   puestos_referencia[]}
 * - conocimientos: [{conocimiento, funcion, anios}]
 * - competencias: [{competencia, funcion}]
 * - objetivo: string|null; funciones: string[]
 * - actividades_criticas, responsables_ausencia, facultades,
 *   plan_formacion, compromisos_culturales: string[]
 * - metadatos: {elaboro, reviso, autorizo, fecha_elaboracion, fecha_revision}
 *
 * Edad y sexo son datos documentales del PDF: nunca se usan para filtrar,
 * calificar ni rechazar candidatos.
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre_puesto
 * @property int $version
 * @property string $archivo_nombre
 * @property string $disk
 * @property string $path
 * @property string $sha256
 * @property int $paginas
 * @property array<string, mixed> $datos
 * @property list<string>|null $advertencias
 * @property int|null $importado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Puesto> $puestos
 * @property-read User|null $importador
 */
class DescriptivoPuesto extends Model
{
    protected $table = 'descriptivos_puesto';

    protected $hidden = ['disk', 'path'];

    protected $fillable = ['clave', 'nombre_puesto', 'version', 'archivo_nombre', 'disk', 'path', 'sha256', 'paginas', 'datos', 'advertencias', 'importado_por'];

    protected function casts(): array
    {
        return ['datos' => 'array', 'advertencias' => 'array', 'version' => 'integer', 'paginas' => 'integer'];
    }

    /**
     * @return BelongsToMany<Puesto, $this>
     */
    public function puestos(): BelongsToMany
    {
        return $this->belongsToMany(Puesto::class, 'descriptivo_puesto_puestos')->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function importador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'importado_por');
    }
}
