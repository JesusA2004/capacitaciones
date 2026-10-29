<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cliente de integración servidor-a-servidor (no es una cuenta de `users`).
 * El token vive sólo como SHA-256 en `token_hash`; ver
 * App\Services\Integraciones\TokenIntegracionService.
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property string|null $token_prefijo
 * @property string|null $token_hash
 * @property list<string> $habilidades
 * @property bool $todas_las_empresas
 * @property list<int>|null $empresas_permitidas
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
        'clave', 'nombre', 'token_prefijo', 'token_hash', 'habilidades', 'todas_las_empresas',
        'empresas_permitidas', 'activo', 'emitido_en', 'revocado_en', 'ultimo_uso_en', 'ultimo_uso_ip',
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
