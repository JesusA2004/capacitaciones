<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cliente de integración servidor-a-servidor (no es una cuenta de `users`).
 * El token vive sólo como SHA-256 en `token_hash`; ver
 * App\Services\Integraciones\TokenIntegracionService.
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property string|null $descripcion
 * @property string|null $token_prefijo
 * @property string|null $token_hash
 * @property list<string> $habilidades
 * @property bool $todas_las_empresas
 * @property list<int>|null $empresas_permitidas
 * @property int|null $limite_por_minuto null = el de config/integraciones.php (sistemas.limite_por_minuto)
 * @property bool $activo
 * @property Carbon|null $emitido_en
 * @property Carbon|null $revocado_en
 * @property Carbon|null $ultimo_uso_en
 * @property string|null $ultimo_uso_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClienteIntegracion extends Model
{
    protected $table = 'clientes_integracion';

    protected $fillable = [
        'clave', 'nombre', 'descripcion', 'token_prefijo', 'token_hash', 'habilidades', 'todas_las_empresas',
        'empresas_permitidas', 'limite_por_minuto', 'activo', 'emitido_en', 'revocado_en', 'ultimo_uso_en', 'ultimo_uso_ip',
    ];

    /**
     * El hash tampoco viaja nunca al serializar.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'habilidades' => 'array',
            'todas_las_empresas' => 'boolean',
            'empresas_permitidas' => 'array',
            'limite_por_minuto' => 'integer',
            'activo' => 'boolean',
            'emitido_en' => 'datetime',
            'revocado_en' => 'datetime',
            'ultimo_uso_en' => 'datetime',
        ];
    }

    public function puede(string $habilidad): bool
    {
        return in_array($habilidad, $this->habilidades, true);
    }

    /**
     * Solicitudes por minuto de este cliente: la suya, acotada al máximo de
     * config, o la general de config si no tiene una propia.
     */
    public function limitePorMinuto(): int
    {
        $general = max(1, (int) config('integraciones.sistemas.limite_por_minuto', 600));
        $maximo = max($general, (int) config('integraciones.sistemas.limite_maximo_por_minuto', 6000));

        return $this->limite_por_minuto !== null && $this->limite_por_minuto > 0 ? min($this->limite_por_minuto, $maximo) : $general;
    }

    /**
     * @return HasMany<ConsultaIntegracion, $this>
     */
    public function consultas(): HasMany
    {
        return $this->hasMany(ConsultaIntegracion::class, 'cliente_integracion_id');
    }

    /**
     * Ids de empresa que este cliente puede leer; null = todas (sólo si se
     * emitió explícitamente con «todas las empresas»).
     *
     * @return list<int>|null
     */
    public function empresasPermitidas(): ?array
    {
        if ($this->todas_las_empresas) {
            return null;
        }

        return array_map('intval', $this->empresas_permitidas ?? []);
    }

    public function puedeVerEmpresa(?int $empresaId): bool
    {
        $permitidas = $this->empresasPermitidas();

        if ($permitidas === null) {
            return true;
        }

        return $empresaId !== null && in_array($empresaId, $permitidas, true);
    }
}
