<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Perfil de un puesto: lo que se busca en el candidato y el «deber ser» del
 * puesto (ver PerfilPuestoService).
 *
 * @property int $id
 * @property int $puesto_id
 * @property string $origen documento = borrador del descriptivo oficial; rh = ajustado por RH
 * @property int|null $descriptivo_puesto_id
 * @property string|null $objetivo
 * @property list<string>|null $funciones
 * @property list<string>|null $responsabilidades
 * @property list<string>|null $indicadores
 * @property string|null $escolaridad
 * @property string|null $experiencia
 * @property list<string>|null $conocimientos
 * @property list<string>|null $habilidades
 * @property list<string>|null $competencias
 * @property list<string>|null $valores
 * @property list<string>|null $requisitos
 * @property int|null $actualizado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Puesto $puesto
 * @property-read User|null $editor
 */
class PerfilPuesto extends Model
{
    protected $table = 'perfiles_puesto';

    public const LISTAS = ['funciones', 'responsabilidades', 'indicadores', 'conocimientos', 'habilidades', 'competencias', 'valores', 'requisitos'];

    protected $fillable = [
        'puesto_id', 'origen', 'descriptivo_puesto_id', 'objetivo', 'funciones', 'responsabilidades', 'indicadores', 'escolaridad', 'experiencia',
        'conocimientos', 'habilidades', 'competencias', 'valores', 'requisitos', 'actualizado_por',
    ];

    protected function casts(): array
    {
        return array_fill_keys(self::LISTAS, 'array');
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
