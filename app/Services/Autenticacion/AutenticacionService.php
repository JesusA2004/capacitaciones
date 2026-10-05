<?php

namespace App\Services\Autenticacion;

use App\Enums\EstadoUsuario;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Reglas de inicio de sesión compartidas por el login web (Fortify,
 * App\Providers\FortifyServiceProvider) y la app móvil
 * (Api\V1\AuthController) — docs/AUTENTICACION.md:
 *
 *  - Usuario: `username` sin distinguir mayúsculas/acentos, con espacios
 *    recortados y colapsados («  jesus   ARIZMENDI » = «Jesus Arizmendi»).
 *    Compatibilidad temporal: si lo escrito es un correo registrado,
 *    también identifica la cuenta (el correo ya no es requisito).
 *  - Contraseña: SOLO se recortan espacios al inicio/fin; mayúsculas,
 *    espacios internos y especiales se respetan tal cual.
 */
class AutenticacionService
{
    public function __construct(private readonly NombreUsuarioService $nombres) {}

    /**
     * Cuenta cuyas credenciales coinciden, o null. No revisa el estatus
     * (ver puedeIniciarSesion()).
     */
    public function verificar(string $usuario, string $contrasena): ?User
    {
        $cuenta = $this->nombres->buscar($usuario) ?? $this->porCorreo($usuario);

        return $cuenta !== null && $this->contrasenaCoincide($cuenta, $contrasena) ? $cuenta : null;
    }

    /**
     * Tolera espacios accidentales al inicio/fin. Si la contraseña real
     * tuviera esos espacios (anterior a esta regla) también se acepta.
     */
    public function contrasenaCoincide(User $cuenta, string $contrasena): bool
    {
        $limpia = trim($contrasena);

        if ($limpia !== '' && Hash::check($limpia, $cuenta->password)) {
            return true;
        }

        return $limpia !== $contrasena && Hash::check($contrasena, $cuenta->password);
    }

    /**
     * Activo o en incorporación (este último solo ve su checklist) y sin
     * el acceso revocado. Una cuenta sin colaborador no entra.
     */
    public function puedeIniciarSesion(User $cuenta): bool
    {
        $estatus = $cuenta->colaborador?->estatus;

        return $estatus !== null
            && in_array($estatus, [EstadoUsuario::Activo, EstadoUsuario::EnIncorporacion], true)
            && $cuenta->acceso_bloqueado_en === null;
    }

    /** Único punto de escritura de `ultimo_acceso` (web y app). */
    public function registrarAcceso(User $cuenta): void
    {
        $cuenta->forceFill(['ultimo_acceso' => now()])->save();
    }

    /**
     * Cambio de contraseña hecho por la propia persona: guarda solo el hash
     * y libera el cambio obligatorio de la contraseña temporal.
     */
    public function cambiarContrasena(User $cuenta, string $nueva): void
    {
        $cuenta->forceFill([
            'password' => Hash::make($nueva),
            'debe_cambiar_contrasena' => false,
        ])->save();
    }

    /** Llave de los límites de intentos: la misma para cualquier variante escrita. */
    public function llaveLimite(mixed $usuario, ?string $ip): string
    {
        return sprintf('%s|%s', $this->nombres->clave(is_scalar($usuario) ? (string) $usuario : ''), (string) $ip);
    }

    private function porCorreo(string $entrada): ?User
    {
        $correo = Str::lower(trim($entrada));

        return str_contains($correo, '@') ? User::query()->whereRaw('LOWER(email) = ?', [$correo])->first() : null;
    }
}
