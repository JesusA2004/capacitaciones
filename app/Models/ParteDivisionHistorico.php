<?php

namespace App\Models;

use App\Enums\EstadoParteDivision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una parte del PDF histórico dividido: COPIA con las páginas de un tipo del
 * checklist (o un tramo «sin clasificar»). `disk`/`path` apuntan a la copia
 * en {expediente}/Historico/Division por checklist/; nunca se exponen.
 *
 * @property int $id
 * @property int $division_id
 * @property int|null $document_type_id
 * @property int $orden
 * @property list<int> $paginas
 * @property string|null $confianza
 * @property EstadoParteDivision $estado
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $hash
 * @property int|null $size
 * @property int|null $employee_document_id
 * @property int|null $asignado_por
 * @property Carbon|null $asignado_en
 * @property-read DivisionExpedienteHistorico $division
 * @property-read DocumentType|null $tipo
 * @property-read EmployeeDocument|null $documento
 */
class ParteDivisionHistorico extends Model
{
    protected $table = 'partes_division_historico';

    protected $fillable = [
        'division_id', 'document_type_id', 'orden', 'paginas', 'confianza', 'estado', 'disk', 'path', 'hash', 'size',
        'employee_document_id', 'asignado_por', 'asignado_en',
    ];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'paginas' => 'array',
            'estado' => EstadoParteDivision::class,
            'orden' => 'integer',
            'size' => 'integer',
            'asignado_en' => 'datetime',
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
     * @return BelongsTo<DocumentType, $this>
     */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    /**
     * @return BelongsTo<EmployeeDocument, $this>
     */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id')->withTrashed();
    }

    /**
     * «1-3, 7» para mostrar las páginas del original que trae esta parte.
     */
    public function rangoPaginas(): string
    {
        return self::formatearRango($this->paginas);
    }

    /**
     * @param  list<int>  $paginas
     */
    public static function formatearRango(array $paginas): string
    {
        sort($paginas);
        $tramos = [];
        $inicio = null;
        $anterior = null;

        foreach ($paginas as $pagina) {
            if ($inicio === null) {
                $inicio = $anterior = $pagina;

                continue;
            }

            if ($pagina === $anterior + 1) {
                $anterior = $pagina;

                continue;
            }

            $tramos[] = $inicio === $anterior ? (string) $inicio : sprintf('%d-%d', $inicio, $anterior);
            $inicio = $anterior = $pagina;
        }

        if ($inicio !== null) {
            $tramos[] = $inicio === $anterior ? (string) $inicio : sprintf('%d-%d', $inicio, $anterior);
        }

        return implode(', ', $tramos);
    }
}
