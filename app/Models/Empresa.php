<?php

namespace App\Models;

use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $nombre
 * @property string|null $razon_social
 * @property string|null $rfc
 * @property string|null $logo_path
 * @property bool $activo
 */
class Empresa extends Model
{
    /** @use HasFactory<EmpresaFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'empresas';

    protected $fillable = ['nombre', 'razon_social', 'rfc', 'logo_path', 'activo'];

    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    /**
     * @return HasMany<Sucursal, $this>
     */
    public function sucursales(): HasMany
    {
        return $this->hasMany(Sucursal::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nombre', 'razon_social', 'rfc', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
