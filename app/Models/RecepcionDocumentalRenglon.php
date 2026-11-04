<?php

namespace App\Models;

use App\Enums\EstadoRecepcionDocumento;
use App\Enums\ReglaRecepcionDocumental;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Lo que RH ESPERA recibir en original dentro de un corte mensual: un
 * documento (tipo) de un evento real (origen polimórfico: el colaborador
 * dado de alta, la baja, el recibo emitido, el préstamo…). El snapshot
 * (nombre, número, puesto, sucursal, evento) se fija al materializar y no
 * cambia aunque después se edite al colaborador.
 *
 * @property int $id
 * @property int $corte_id
 * @property ReglaRecepcionDocumental $regla
 * @property string $tipo_documento
 * @property string $tipo_etiqueta
 * @property string $origen_type
 * @property int $origen_id
 * @property int|null $generated_document_id
 * @property int|null $colaborador_id
 * @property int|null $sucursal_id
 * @property string|null $colaborador_nombre
 * @property string|null $numero_empleado
 * @property string|null $puesto_nombre
 * @property string|null $sucursal_nombre
 * @property Carbon|null $fecha_evento
 * @property string|null $evento_descripcion
 * @property int $cantidad_esperada
 * @property int $cantidad_recibida
 * @property EstadoRecepcionDocumento $estado
 * @property string|null $justificacion
 * @property bool $no_aplica_automatico
 * @property Carbon|null $recibido_en
 * @property int|null $recibido_por
 * @property string|null $observaciones
 * @property bool $incidencia
 * @property string|null $evidencia_path
 * @property string|null $evidencia_nombre
 * @property int|null $actualizado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RecepcionDocumentalCorte $corte
 */
class RecepcionDocumentalRenglon extends Model
{
    protected $table = 'recepcion_documental_renglones';

    protected $fillable = [
        'corte_id', 'regla', 'tipo_documento', 'tipo_etiqueta', 'origen_type', 'origen_id',
        'generated_document_id', 'colaborador_id', 'sucursal_id',
        'colaborador_nombre', 'numero_empleado', 'puesto_nombre', 'sucursal_nombre',
        'fecha_evento', 'evento_descripcion', 'cantidad_esperada', 'cantidad_recibida',
        'estado', 'justificacion', 'no_aplica_automatico', 'recibido_en', 'recibido_por',
        'observaciones', 'incidencia', 'evidencia_path', 'evidencia_nombre', 'actualizado_por',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'pendiente',
        'cantidad_esperada' => 1,
        'cantidad_recibida' => 0,
        'no_aplica_automatico' => false,
        'incidencia' => false,
    ];

    protected function casts(): array
    {
        return [
            'regla' => ReglaRecepcionDocumental::class,
            'estado' => EstadoRecepcionDocumento::class,
            'fecha_evento' => 'date',
            'recibido_en' => 'datetime',
            'no_aplica_automatico' => 'boolean',
            'incidencia' => 'boolean',
            'cantidad_esperada' => 'integer',
            'cantidad_recibida' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RecepcionDocumentalCorte, $this>
     */
    public function corte(): BelongsTo
    {
        return $this->belongsTo(RecepcionDocumentalCorte::class, 'corte_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function origen(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recibidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }
}
