<?php

namespace App\Models;

use App\Enums\FamiliaAdministrativa;
use App\Enums\MotorPdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una versión del diseño de un documento administrativo HTML. Solo una
 * versión por familia está activa; las activadas nunca se editan (se crea
 * otra a partir de ella). Ver App\Services\DocumentosAdministrativos.
 *
 * @property int $id
 * @property FamiliaAdministrativa $familia
 * @property int $version
 * @property string $estado
 * @property MotorPdf|null $motor
 * @property array<string, mixed> $diseno
 * @property string $hash
 * @property string|null $notas
 * @property int|null $creado_por
 * @property int|null $activado_por
 * @property Carbon|null $activado_en
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $creadoPor
 * @property-read User|null $activadoPor
 */
class PlantillaAdministrativa extends Model
{
    public const BORRADOR = 'borrador';

    public const ACTIVA = 'activa';

    public const ARCHIVADA = 'archivada';

    protected $table = 'plantillas_administrativas';

    protected $fillable = ['familia', 'version', 'estado', 'motor', 'diseno', 'hash', 'notas', 'creado_por', 'activado_por', 'activado_en'];

    protected function casts(): array
    {
        return [
            'familia' => FamiliaAdministrativa::class,
            'motor' => MotorPdf::class,
            'diseno' => 'array',
            'activado_en' => 'datetime',
        ];
    }

    public function motorEfectivo(): MotorPdf
    {
        return $this->motor ?? MotorPdf::porDefecto();
    }

    /** @return BelongsTo<User, $this> */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /** @return BelongsTo<User, $this> */
    public function activadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activado_por');
    }
}
