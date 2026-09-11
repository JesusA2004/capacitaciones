<?php

namespace App\Services\Navigation;

use App\Models\User;

/**
 * Separa la experiencia "modo colaborador" (portal personal: mis
 * solicitudes, mi expediente, mis notificaciones) de la experiencia "modo
 * operativo" (herramientas de RH/gerencia/dirección para operar el
 * sistema). Antes de este servicio, AppSidebar.vue mezclaba ambas: un
 * admin veía "Mi portal"/"Vacaciones" como si fuera colaborador.
 *
 * Basado enteramente en permisos ya sembrados (RolesYPermisosSeeder), no en
 * nombres de rol hardcodeados:
 * - Modo colaborador: gate = permiso `portal.ver` (solo el rol
 *   `colaborador` lo tiene hoy).
 * - Modo operativo: gate = tener `dashboard.global.ver` o
 *   `dashboard.sucursal.ver` (todo rol operativo tiene uno de los dos; el
 *   rol `colaborador` no tiene ninguno).
 *
 * Un usuario puede tener ambos modos si algún día un rol combina ambos
 * bloques de permisos (p. ej. un futuro rol "gerente-colaborador"); ese
 * caso ya está soportado aunque ningún rol sembrado lo use todavía.
 */
class NavigationService
{
    private const COOKIE_MODO = 'experiencia_modo';

    public function esColaborador(User $usuario): bool
    {
        return $usuario->can('portal.ver');
    }

    public function tieneModoColaborador(User $usuario): bool
    {
        return $this->esColaborador($usuario);
    }

    public function tieneModoOperativo(User $usuario): bool
    {
        return $usuario->can('dashboard.global.ver') || $usuario->can('dashboard.sucursal.ver');
    }

    /**
     * @return array<int, 'colaborador'|'operativo'>
     */
    public function modosDisponibles(User $usuario): array
    {
        $modos = [];

        if ($this->tieneModoOperativo($usuario)) {
            $modos[] = 'operativo';
        }

        if ($this->tieneModoColaborador($usuario)) {
            $modos[] = 'colaborador';
        }

        return $modos === [] ? ['colaborador'] : $modos;
    }

    /**
     * Modo actual: respeta la cookie `experiencia_modo` si el usuario tiene
     * ambos modos disponibles y la cookie apunta a uno válido; si solo tiene
     * un modo, ese es el único resultado posible (ignora la cookie). Si
     * tiene ambos y no hay cookie, prioriza operativo (es la herramienta de
     * trabajo principal de quien administra el sistema).
     */
    public function modoActual(User $usuario, ?string $cookieValor): string
    {
        $disponibles = $this->modosDisponibles($usuario);

        if (count($disponibles) === 1) {
            return $disponibles[0];
        }

        if ($cookieValor !== null && in_array($cookieValor, $disponibles, true)) {
            return $cookieValor;
        }

        return in_array('operativo', $disponibles, true) ? 'operativo' : $disponibles[0];
    }

    public static function nombreCookie(): string
    {
        return self::COOKIE_MODO;
    }

    /**
     * @return array{modo_actual: string, modos_disponibles: array<int, string>}
     */
    public function paraCompartir(User $usuario, ?string $cookieValor): array
    {
        return [
            'modo_actual' => $this->modoActual($usuario, $cookieValor),
            'modos_disponibles' => $this->modosDisponibles($usuario),
        ];
    }
}
