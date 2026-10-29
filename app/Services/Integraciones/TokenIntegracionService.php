<?php

namespace App\Services\Integraciones;

use App\Models\ClienteIntegracion;
use App\Models\Empresa;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Emisión, rotación, revocación y verificación de tokens de clientes de
 * integración servidor-a-servidor. El token en claro sólo existe en el valor
 * que devuelve emitir()/rotar() (el comando lo muestra UNA vez); en la base
 * queda su SHA-256. Independiente de Sanctum: un token de la app móvil nunca
 * autentica aquí y uno de integración nunca autentica en la API móvil.
 */
class TokenIntegracionService
{
    public const CLAVE_AUDITORIA = 'auditoria';

    public const HABILIDAD_AUDITORIA_LEER = 'auditoria:leer';

    private const PREFIJO = 'ppl_aud_';

    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * Emite (o rota, si ya existe) el token del cliente. Devuelve el token en
     * claro: el llamador lo muestra una vez y no lo guarda.
     *
     * @param  list<int>  $empresas  ids permitidos (ignorado si $todasLasEmpresas)
     * @param  list<string>  $habilidades
     * @return array{cliente: ClienteIntegracion, token: string}
     */
    public function emitir(string $clave, string $nombre, array $habilidades, bool $todasLasEmpresas, array $empresas): array
    {
        if (! $todasLasEmpresas && $empresas === []) {
            throw new InvalidArgumentException('Indica al menos una empresa permitida o «todas las empresas» de forma explícita.');
        }

        $empresas = array_values(array_unique(array_map('intval', $empresas)));
        $existentes = Empresa::withTrashed()->whereIn('id', $empresas)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $faltantes = array_values(array_diff($empresas, $existentes));

        if ($faltantes !== []) {
            throw new InvalidArgumentException(sprintf('No existen las empresas: %s.', implode(', ', $faltantes)));
        }

        $token = sprintf('%s%s', self::PREFIJO, Str::random(48));

        $cliente = DB::transaction(function () use ($clave, $nombre, $habilidades, $todasLasEmpresas, $empresas, $token): ClienteIntegracion {
            $cliente = ClienteIntegracion::query()->where('clave', $clave)->lockForUpdate()->first() ?? new ClienteIntegracion(['clave' => $clave]);
            $rotacion = $cliente->exists;

            $cliente->fill([
                'nombre' => $nombre,
                'token_prefijo' => substr($token, 0, 14),
                'token_hash' => self::hash($token),
                'habilidades' => $habilidades,
                'todas_las_empresas' => $todasLasEmpresas,
                'empresas_permitidas' => $todasLasEmpresas ? null : $empresas,
                'activo' => true,
                'emitido_en' => now(),
                'revocado_en' => null,
            ])->save();

            $this->auditoria->registrar($rotacion ? 'integracion_token_rotado' : 'integracion_token_emitido', $cliente, null, [
                'cliente' => $cliente->clave,
                'habilidades' => $cliente->habilidades,
                'todas_las_empresas' => $cliente->todas_las_empresas,
                'empresas_permitidas' => $cliente->empresas_permitidas,
            ]);

            return $cliente;
        });

        return ['cliente' => $cliente, 'token' => $token];
    }

    public function revocar(string $clave): ?ClienteIntegracion
    {
        return DB::transaction(function () use ($clave): ?ClienteIntegracion {
            $cliente = ClienteIntegracion::query()->where('clave', $clave)->lockForUpdate()->first();

            if ($cliente === null) {
                return null;
            }

            $cliente->forceFill(['activo' => false, 'token_hash' => null, 'revocado_en' => now()])->save();
            $this->auditoria->registrar('integracion_token_revocado', $cliente, null, ['cliente' => $cliente->clave]);

            return $cliente;
        });
    }

    /**
     * Cliente activo dueño del token, o null. Nunca registra el token.
     */
    public function autenticar(?string $token): ?ClienteIntegracion
    {
        if ($token === null || ! str_starts_with($token, self::PREFIJO) || strlen($token) > 200) {
            return null;
        }

        $hash = self::hash($token);
        $cliente = ClienteIntegracion::query()->where('token_hash', $hash)->where('activo', true)->first();

        if ($cliente === null || $cliente->token_hash === null || ! hash_equals($cliente->token_hash, $hash)) {
            return null;
        }

        return $cliente;
    }

    /**
     * Marca de último uso, a lo más una escritura por minuto.
     */
    public function registrarUso(ClienteIntegracion $cliente, ?string $ip): void
    {
        if ($cliente->ultimo_uso_en !== null && $cliente->ultimo_uso_en->gt(now()->subMinute())) {
            return;
        }

        ClienteIntegracion::query()->whereKey($cliente->id)->toBase()->update(['ultimo_uso_en' => now(), 'ultimo_uso_ip' => $ip]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
