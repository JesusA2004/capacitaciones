<?php

namespace App\Services\Navigation;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Separa la experiencia "modo colaborador" (portal personal: mis
 * solicitudes, mi expediente, mis notificaciones) de la experiencia "modo
 * operativo" (herramientas de RH/gerencia/dirección para operar el
 * sistema). Antes de este servicio, AppSidebar.vue mezclaba ambas: un
 * admin veía "Mi portal"/"Vacaciones" como si fuera colaborador.
 *
 * Una misma CUENTA puede representar a la vez a un colaborador real y a
 * alguien con permisos operativos; ninguna de las dos capacidades depende
 * del nombre del rol:
 * - Modo colaborador ("Mi espacio"): puedeUsarModoColaborador() — la
 *   cuenta está enlazada a un Colaborador activo y no tiene el acceso
 *   bloqueado (User::puedeAccederPortal()), o tiene el permiso explícito
 *   `portal.ver` (rol `colaborador`). Un super_admin con Colaborador activo
 *   tiene Mi espacio sin necesitar también el rol `colaborador`.
 * - Modo operativo ("Operación RH"): `dashboard.global.ver` o
 *   `dashboard.sucursal.ver`.
 *
 * Es la ÚNICA definición de la capacidad personal: el Gate
 * `modo-colaborador` (AppServiceProvider) la expone a las rutas personales
 * (mi-portal, mi-perfil, mis-notificaciones, mi-expediente) y a
 * DashboardController. Mi espacio siempre opera sobre el colaborador de la
 * cuenta autenticada — nunca recibe un id de otra persona.
 */
class NavigationService
{
    private const COOKIE_MODO = 'experiencia_modo';

    public const GATE_MODO_COLABORADOR = 'modo-colaborador';

    public function puedeUsarModoColaborador(User $usuario): bool
    {
        if ($usuario->acceso_bloqueado_en !== null) {
            return false;
        }

        return $usuario->can('portal.ver') || $usuario->puedeAccederPortal();
    }

    public function tieneModoColaborador(User $usuario): bool
    {
        return $this->puedeUsarModoColaborador($usuario);
    }

    public function tieneModoOperativo(User $usuario): bool
    {
        return $usuario->can('dashboard.global.ver') || $usuario->can('dashboard.sucursal.ver');
    }

    /**
     * Nunca cae a `['colaborador']` por defecto cuando un usuario no tiene
     * ningún permiso de navegación: eso disfrazaba una cuenta mal
     * configurada (sin `portal.ver` ni `dashboard.*.ver`) de colaborador
     * real, y el usuario terminaba viendo "Mi portal" en el sidebar para
     * luego chocar con un 403 en cada pantalla. Un arreglo vacío es una
     * señal real de configuración inválida — quien la consuma (p. ej.
     * DashboardController) debe responder 403, no adivinar un modo.
     *
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

        return $modos;
    }

    /**
     * Modo actual: respeta la cookie `experiencia_modo` si el usuario tiene
     * ambos modos disponibles y la cookie apunta a uno válido; si solo tiene
     * un modo, ese es el único resultado posible (ignora la cookie). Si
     * tiene ambos y no hay cookie, prioriza operativo (es la herramienta de
     * trabajo principal de quien administra el sistema).
     *
     * Devuelve cadena vacía si el usuario no tiene ningún modo disponible
     * (cuenta mal configurada) — nunca inventa uno.
     */
    public function modoActual(User $usuario, ?string $cookieValor): string
    {
        $disponibles = $this->modosDisponibles($usuario);

        if ($disponibles === []) {
            return '';
        }

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
     * `Request::cookie()` puede devolver `array|string|null` (un mismo
     * nombre de cookie repetido en la petición HTTP se agrupa en arreglo);
     * la cookie de modo siempre es un valor simple, así que cualquier otra
     * forma se trata como "sin cookie".
     */
    public static function cookieDe(Request $request): ?string
    {
        $valor = $request->cookie(self::COOKIE_MODO);

        return is_string($valor) ? $valor : null;
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
