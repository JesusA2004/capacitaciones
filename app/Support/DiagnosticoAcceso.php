<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * Bloque «Diagnóstico de acceso» de la página 403 (resources/js/pages/Error.vue).
 * Solo para super_admin y sistemas: ruta, permiso requerido por el
 * middleware, la regla que negó (Gate::after en AppServiceProvider) y sus
 * roles. Nunca trazas, clases PHP completas, consultas ni datos de otras
 * personas. Para cualquier otro usuario devuelve null y la página muestra
 * solo el mensaje genérico.
 */
final class DiagnosticoAcceso
{
    /** @var list<string> */
    private const ROLES_CON_DIAGNOSTICO = ['super_admin', 'sistemas'];

    /**
     * @return array{ruta: string, nombre_ruta: string|null, metodo: string, permiso_requerido: string|null, regla_negada: string|null, motivo: string|null, roles: list<string>, tiene_colaborador: bool}|null
     */
    public static function para(Request $request, Throwable $e): ?array
    {
        $usuario = $request->user();

        if (! $usuario instanceof User || ! $usuario->hasAnyRole(self::ROLES_CON_DIAGNOSTICO)) {
            return null;
        }

        $ruta = $request->route();
        $permisos = [];

        if ($ruta !== null) {
            foreach ($ruta->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/^(?:can|permission):(.+)$/', $middleware, $m) === 1) {
                    $permisos[] = $m[1];
                }
            }
        }

        $negado = $request->attributes->get('acceso_negado');
        $regla = is_array($negado) && is_string($negado['habilidad'] ?? null)
            ? trim(sprintf('%s %s', $negado['habilidad'], is_string($negado['recurso'] ?? null) ? "({$negado['recurso']})" : ''))
            : null;

        $motivo = trim($e->getMessage());

        return [
            'ruta' => '/'.ltrim($request->path(), '/'),
            'nombre_ruta' => $ruta?->getName(),
            'metodo' => $request->method(),
            'permiso_requerido' => $permisos !== [] ? implode(', ', $permisos) : null,
            'regla_negada' => $regla,
            // Mensajes propios (abort(403, '...')); el genérico de Laravel no aporta.
            'motivo' => $motivo !== '' && $motivo !== 'This action is unauthorized.' ? $motivo : null,
            'roles' => array_values($usuario->getRoleNames()->map(fn ($r): string => (string) $r)->all()),
            'tiene_colaborador' => $usuario->colaborador_id !== null,
        ];
    }
}
