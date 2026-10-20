<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una corrección hoja por hoja que RH confirmó sobre una división del PDF
 * histórico (editor de hojas, docs/DIVISION_EXPEDIENTE_HISTORICO.md).
 * Versionada por división; nunca se edita ni se borra.
 *
 * `plan`: los documentos resultantes, en orden: [{document_type_id, paginas}].
 * `cambios`: auditoría hoja por hoja: [{pagina, de, a}] con el nombre del
 * documento (o «Sin clasificar») antes y después.
 *
 * @property int $id
 * @property int $division_id
 * @property int $version
 * @property list<array{document_type_id: int|null, paginas: list<int>}> $plan
 * @property string $hash_plan
 * @property list<array{pagina: int, de: string, a: string}> $cambios
 * @property list<string>|null $advertencias
 * @property int|null $hecha_por
 * @property Carbon|null $created_at
 * @property-read DivisionExpedienteHistorico $division
 * @property-read User|null $autor
 */
class RevisionDivisionHistorico extends Model
{
    protected $table = 'revisiones_division_historico';

    protected $fillable = ['division_id', 'version', 'plan', 'hash_plan', 'cambios', 'advertencias', 'hecha_por'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'plan' => 'array',
            'cambios' => 'array',
            'advertencias' => 'array',
        ];
    }

    /**
     * @return BelongsTo<DivisionExpedienteHistorico, $this>
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(DivisionExpedienteHistorico::class, 'division_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hecha_por');
    }
}
