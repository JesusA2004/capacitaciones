<?php

namespace App\Services\Incorporacion;

use App\Enums\EstadoInvitacionIncorporacion;
use App\Enums\EstadoUsuario;
use App\Exceptions\Incorporacion\InvitacionInvalidaException;
use App\Models\IncorporacionInvitacion;
use App\Models\User;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Unica fuente de las reglas de negocio de las invitaciones de incorporacion
 * por QR temporal: un colaborador no puede registrarse libremente desde la
 * app, solo si RH genero antes una de estas invitaciones. Genera/hashea el
 * token, valida su vigencia, crea al colaborador en estatus en_incorporacion
 * y arma el QR (SVG/PNG, con bacon/bacon-qr-code — ya viene con
 * laravel/fortify, no es una dependencia nueva). Ver docs/API_MOVIL.md,
 * sección "Registro por QR temporal".
 */
class IncorporacionInvitacionService
{
    /** Tamaño en px del lado del QR renderizado. */
    private const QR_TAMANO = 320;

    private const QR_MARGEN = 8;

    /**
     * @param  array<string, mixed>  $datos
     * @return array{invitacion: IncorporacionInvitacion, token: string}
     */
    public function crear(array $datos, User $creadoPor): array
    {
        $token = $this->generarToken();

        $invitacion = IncorporacionInvitacion::create([
            'uuid' => (string) Str::uuid(),
            'token_hash' => $this->hashToken($token),
            'codigo_legible' => $this->generarCodigoLegible(),
            'email' => $datos['email'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'nombre_prellenado' => $datos['nombre_prellenado'] ?? null,
            'empresa_id' => $datos['empresa_id'] ?? null,
            'sucursal_id' => $datos['sucursal_id'] ?? null,
            'departamento_id' => $datos['departamento_id'] ?? null,
            'puesto_id' => $datos['puesto_id'] ?? null,
            'candidato_id' => $datos['candidato_id'] ?? null,
            'creado_por_id' => $creadoPor->id,
            'expires_at' => $this->resolverExpiracion($datos),
            'max_usos' => $datos['max_usos'] ?? 1,
            'estado' => EstadoInvitacionIncorporacion::Activo->value,
            'metadata' => array_filter(['observaciones' => $datos['observaciones'] ?? null]) ?: null,
        ]);

        return ['invitacion' => $invitacion, 'token' => $token];
    }

    /**
     * Revoca la invitacion vigente (si aplica) y crea una nueva con los
     * mismos datos prellenados, enlazada via regenerated_from_id. Usado
     * cuando el QR vencio o RH simplemente quiere invalidar el anterior.
     *
     * @return array{invitacion: IncorporacionInvitacion, token: string}
     */
    public function regenerar(IncorporacionInvitacion $invitacion, User $creadoPor): array
    {
        $resultado = $this->crear([
            'email' => $invitacion->email,
            'telefono' => $invitacion->telefono,
            'nombre_prellenado' => $invitacion->nombre_prellenado,
            'empresa_id' => $invitacion->empresa_id,
            'sucursal_id' => $invitacion->sucursal_id,
            'departamento_id' => $invitacion->departamento_id,
            'puesto_id' => $invitacion->puesto_id,
            'candidato_id' => $invitacion->candidato_id,
            'max_usos' => $invitacion->max_usos,
            'observaciones' => $invitacion->metadata['observaciones'] ?? null,
        ], $creadoPor);

        $resultado['invitacion']->update(['regenerated_from_id' => $invitacion->id]);

        if ($invitacion->estado === EstadoInvitacionIncorporacion::Activo) {
            $invitacion->update(['estado' => EstadoInvitacionIncorporacion::Revocado->value, 'revoked_at' => now()]);
        }

        return $resultado;
    }

    public function revocar(IncorporacionInvitacion $invitacion): void
    {
        if (in_array($invitacion->estado, [EstadoInvitacionIncorporacion::Usado, EstadoInvitacionIncorporacion::Revocado], true)) {
            throw new RuntimeException('Esta invitación ya no se puede revocar.');
        }

        $invitacion->update(['estado' => EstadoInvitacionIncorporacion::Revocado->value, 'revoked_at' => now()]);
    }

    public function generarToken(): string
    {
        return Str::random(64);
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function generarCodigoLegible(): string
    {
        return Str::upper(Str::random(8));
    }

    /**
     * Tipado por CarbonInterface (no Illuminate\Support\Carbon) porque
     * AppServiceProvider fuerza `Date::use(CarbonImmutable::class)`
     * globalmente: `Carbon::parse()`/`now()` devuelven CarbonImmutable en
     * runtime, no la subclase mutable.
     *
     * @param  array<string, mixed>  $datos
     */
    private function resolverExpiracion(array $datos): CarbonInterface
    {
        if (! empty($datos['expires_at'])) {
            return Carbon::parse($datos['expires_at']);
        }

        $horas = (int) ($datos['duracion_horas'] ?? config('incorporacion.qr_ttl_horas'));

        return now()->addHours($horas);
    }

    public function buscarPorToken(string $token): ?IncorporacionInvitacion
    {
        return IncorporacionInvitacion::query()->where('token_hash', $this->hashToken($token))->first();
    }

    /**
     * Valida hash, expiracion, estado y revocacion. Lanza
     * InvitacionInvalidaException con el motivo exacto si no es utilizable
     * (nunca deja pasar un registro con un QR que no este activo).
     */
    public function validar(string $token): IncorporacionInvitacion
    {
        $invitacion = $this->buscarPorToken($token);

        if ($invitacion === null) {
            throw InvitacionInvalidaException::invalido();
        }

        $this->sincronizarVencimiento($invitacion);

        return match ($invitacion->estado) {
            EstadoInvitacionIncorporacion::Revocado => throw InvitacionInvalidaException::revocado(),
            EstadoInvitacionIncorporacion::Vencido => throw InvitacionInvalidaException::vencido(),
            EstadoInvitacionIncorporacion::Usado => throw InvitacionInvalidaException::usado(),
            EstadoInvitacionIncorporacion::Activo => $invitacion,
        };
    }

    /**
     * Una invitacion activa cuya fecha de expiracion ya paso se marca
     * vencida en cuanto se consulta (en vez de depender de un cron): así
     * `estado` en la respuesta de /validar siempre refleja la realidad.
     */
    private function sincronizarVencimiento(IncorporacionInvitacion $invitacion): void
    {
        if ($invitacion->estado === EstadoInvitacionIncorporacion::Activo && $invitacion->expires_at->isPast()) {
            $invitacion->update(['estado' => EstadoInvitacionIncorporacion::Vencido->value]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadValidacion(IncorporacionInvitacion $invitacion): array
    {
        $invitacion->loadMissing(['empresa:id,nombre', 'sucursal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre']);

        return [
            'valida' => true,
            'estado' => $invitacion->estado->value,
            'expires_at' => $invitacion->expires_at->toIso8601String(),
            'datos_prellenados' => [
                'nombre' => $invitacion->nombre_prellenado,
                'email' => $invitacion->email,
                'telefono' => $invitacion->telefono,
                'empresa' => $invitacion->empresa?->nombre,
                'sucursal' => $invitacion->sucursal?->nombre,
                'departamento' => $invitacion->departamento?->nombre,
                'puesto' => $invitacion->puesto?->nombre,
            ],
            'fases' => $this->fases(),
        ];
    }

    /**
     * Hoja de ruta que ve la app tras escanear el QR (fases 2 y 3 del flujo
     * completo: "QR validado" y "Aprobado/Rechazado" son estados de arranque
     * y cierre, no pasos que el colaborador recorra dentro del formulario).
     *
     * @return array<int, array{clave: string, nombre: string, orden: int}>
     */
    public function fases(): array
    {
        return [
            ['clave' => 'datos_personales', 'nombre' => 'Datos personales', 'orden' => 1],
            ['clave' => 'documentos', 'nombre' => 'Documentos', 'orden' => 2],
            ['clave' => 'revision', 'nombre' => 'Revisión RH', 'orden' => 3],
        ];
    }

    /**
     * Crea al colaborador desde una invitacion ya validada (estatus
     * en_incorporacion, rol colaborador, datos organizacionales heredados
     * de la invitacion) y la marca como usada. Nunca activa al usuario: eso
     * solo lo hace RH aprobando la incorporacion completa (ver
     * App\Services\Incorporacion\IncorporacionService::aprobarIncorporacion).
     *
     * @param  array<string, mixed>  $datos
     */
    public function registrarUsuario(IncorporacionInvitacion $invitacion, array $datos): User
    {
        if (! $invitacion->tieneUsosDisponibles()) {
            throw InvitacionInvalidaException::usado();
        }

        if ($invitacion->email !== null && mb_strtolower($invitacion->email) !== mb_strtolower($datos['email'])) {
            throw InvitacionInvalidaException::correoNoCoincide();
        }

        return DB::transaction(function () use ($invitacion, $datos) {
            $usuario = User::create([
                'name' => $datos['name'],
                'apellidos' => $datos['apellidos'] ?? null,
                'email' => $datos['email'],
                'password' => Hash::make($datos['password']),
                'telefono' => $datos['telefono'] ?? $invitacion->telefono,
                'curp' => $datos['curp'] ?? null,
                'rfc' => $datos['rfc'] ?? null,
                'nss' => $datos['nss'] ?? null,
                'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
                'domicilio' => $datos['direccion'] ?? null,
                'contacto_emergencia_nombre' => $datos['contacto_emergencia_nombre'] ?? null,
                'contacto_emergencia_telefono' => $datos['contacto_emergencia_telefono'] ?? null,
                'sucursal_principal_id' => $invitacion->sucursal_id,
                'departamento_id' => $invitacion->departamento_id,
                'puesto_id' => $invitacion->puesto_id,
                'estatus' => EstadoUsuario::EnIncorporacion->value,
            ]);

            $usuario->assignRole('colaborador');

            $this->marcarUsada($invitacion, $usuario);

            return $usuario;
        });
    }

    public function marcarUsada(IncorporacionInvitacion $invitacion, User $usuario): void
    {
        $usosCount = $invitacion->usos_count + 1;

        $invitacion->update([
            'usos_count' => $usosCount,
            'used_at' => $invitacion->used_at ?? now(),
            'usado_por_id' => $usuario->id,
            'user_id' => $invitacion->user_id ?? $usuario->id,
            'estado' => $usosCount >= $invitacion->max_usos
                ? EstadoInvitacionIncorporacion::Usado->value
                : $invitacion->estado->value,
        ]);
    }

    public function urlQr(string $tokenPlano): string
    {
        return rtrim((string) config('incorporacion.qr_url_base'), '/').'/'.$tokenPlano;
    }

    /**
     * SVG del QR (sin dependencias de extensiones de PHP): usado para
     * mostrarlo embebido en la vista de RH justo despues de crear/regenerar
     * la invitacion (única vez que el token plano existe en memoria).
     */
    public function qrSvg(string $tokenPlano): string
    {
        $renderer = new ImageRenderer(new RendererStyle(self::QR_TAMANO, self::QR_MARGEN), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($this->urlQr($tokenPlano));
    }

    /**
     * PNG del QR via GD (disponible en cualquier instalación estándar de
     * PHP, a diferencia del backend Imagick de bacon-qr-code).
     */
    public function qrPng(string $tokenPlano): string
    {
        $renderer = new GDLibRenderer(self::QR_TAMANO, self::QR_MARGEN, 'png');

        return (new Writer($renderer))->writeString($this->urlQr($tokenPlano));
    }
}
