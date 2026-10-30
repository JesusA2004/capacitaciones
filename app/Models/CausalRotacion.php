<?php

namespace App\Models;

use App\Enums\FormaSalida;
use App\Enums\TipoBaja;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Causal de baja («por qué se va»), segundo nivel del catálogo de bajas
 * (cat_causal_baja de la especificación de RH, docs/BAJAS_Y_CAUSALES.md).
 * Pertenece a UNA forma de salida (RV/NR/RE) y su clave inicia con ella
 * (RV-01). `tipo` es el tipo de baja equivalente que usa el cierre laboral.
 *
 * Nunca se borra (RN-15): se desactiva. Las causales del subcatálogo
 * anterior que no son de la especificación quedan con `legado` = true,
 * inactivas y fuera de la administración; sus bajas apuntan a la causal
 * nueva (reemplazada_por_id) y conservan el valor original.
 *
 * @property int $id
 * @property TipoBaja $tipo
 * @property FormaSalida|null $forma_salida
 * @property int|null $causal_grupo_id
 * @property string|null $documento_salida Documento que genera, si difiere del de su forma.
 * @property string|null $clave
 * @property string $nombre
 * @property string|null $descripcion Ayuda «Cuándo usarla».
 * @property bool $requiere_juridico
 * @property bool $comentario_obligatorio
 * @property string|null $alerta
 * @property string|null $fundamento_legal
 * @property bool $excluye_indemnizacion
 * @property bool $implica_indemnizacion
 * @property bool $activo
 * @property bool $legado
 * @property int|null $reemplazada_por_id
 * @property int $orden
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CausalRotacion extends Model
{
    protected $table = 'causales_rotacion';

    protected $fillable = [
        'tipo', 'forma_salida', 'causal_grupo_id', 'documento_salida', 'clave', 'nombre', 'descripcion', 'requiere_juridico', 'comentario_obligatorio',
        'alerta', 'fundamento_legal', 'excluye_indemnizacion', 'implica_indemnizacion', 'activo', 'legado', 'reemplazada_por_id', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoBaja::class,
            'forma_salida' => FormaSalida::class,
            'requiere_juridico' => 'boolean',
            'comentario_obligatorio' => 'boolean',
            'excluye_indemnizacion' => 'boolean',
            'implica_indemnizacion' => 'boolean',
            'activo' => 'boolean',
            'legado' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @param  Builder<CausalRotacion>  $query
     */
    public function scopeActivas(Builder $query): void
    {
        // Un subcatálogo desactivado deja de ofrecer sus causales.
        $query->where('activo', true)->where('legado', false)
            ->where(fn (Builder $q) => $q->whereNull('causal_grupo_id')->orWhereHas('grupo', fn (Builder $g) => $g->where('activo', true)));
    }

    /**
     * Causales del catálogo vigente (activas e inactivas, sin las de legado).
     *
     * @param  Builder<CausalRotacion>  $query
     */
    public function scopeDelCatalogo(Builder $query): void
    {
        $query->where('legado', false);
    }

    /**
     * @param  Builder<CausalRotacion>  $query
     */
    public function scopeOrdenadas(Builder $query): void
    {
        $query->orderBy('orden')->orderBy('nombre');
    }

    /**
     * Forma de salida (la columna o, en registros antiguos, la de su tipo).
     */
    public function forma(): FormaSalida
    {
        return $this->forma_salida ?? FormaSalida::desdeTipoBaja($this->tipo);
    }

    /**
     * «RV-01 · Mejor oferta laboral o salarial» (o solo el nombre).
     */
    public function etiquetaConClave(): string
    {
        return $this->clave !== null && preg_match('/^(RV|NR|RE|OT)-\d{2}$/', $this->clave) === 1
            ? sprintf('%s · %s', $this->clave, $this->nombre)
            : $this->nombre;
    }

    /**
     * @return BelongsTo<CausalGrupo, $this>
     */
    public function grupo(): BelongsTo
    {
        return $this->belongsTo(CausalGrupo::class, 'causal_grupo_id');
    }

    /**
     * @return HasMany<CierreLaboral, $this>
     */
    public function cierres(): HasMany
    {
        return $this->hasMany(CierreLaboral::class, 'causal_rotacion_id');
    }

    /**
     * @return HasMany<SolicitudInterna, $this>
     */
    public function solicitudes(): HasMany
    {
        return $this->hasMany(SolicitudInterna::class, 'causal_rotacion_id');
    }

    /**
     * @return HasMany<Baja, $this>
     */
    public function bajas(): HasMany
    {
        return $this->hasMany(Baja::class, 'causal_rotacion_id');
    }

    /**
     * Documentos adicionales que pide esta causal.
     *
     * @return HasMany<DocumentoRequeridoBaja, $this>
     */
    public function documentosRequeridos(): HasMany
    {
        return $this->hasMany(DocumentoRequeridoBaja::class, 'causal_rotacion_id');
    }

    /**
     * Ya la usa alguna baja (cierre, solicitud o expediente de baja).
     */
    public function enUso(): bool
    {
        return $this->cierres()->exists() || $this->solicitudes()->exists() || $this->bajas()->exists();
    }
}
