<?php

namespace App\Services\Notificaciones;

use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «Avisos por sucursal»: una cuenta con alcance amplio (RH, Sistemas,
 * Dirección Comercial, Regional…) elige de qué sucursales NO quiere recibir
 * avisos (campana y push). Lo decide cada quien para sí mismo, con el
 * permiso `notificaciones.silenciar_sucursales` (Roles y permisos).
 *
 * Silenciar ≠ ocultar: lo pendiente sigue en «Mis pendientes» y en las
 * bandejas; solo deja de llegar el aviso. Nunca se silencia:
 *  - la confirmación de lo que la persona misma hizo;
 *  - lo que es sobre ella misma;
 *  - lo de las personas de las que es jefe directo o gerente (organigrama):
 *    eso le toca atenderlo a ella.
 * Lo aplica NotificacionEventosService::entregar() (capa única de avisos).
 */
class SucursalesSilenciadasService
{
    public const PERMISO = 'notificaciones.silenciar_sucursales';

    public function __construct(private readonly AuditoriaService $auditoria) {}

    public function puedeConfigurar(User $usuario): bool
    {
        return $usuario->can(self::PERMISO);
    }

    /**
     * Sucursales activas con la marca de si están silenciadas para la cuenta.
     *
     * @return list<array{id: int, nombre: string, es_corporativo: bool, silenciada: bool}>
     */
    public function opciones(User $usuario): array
    {
        $silenciadas = $this->silenciadas($usuario);
        $opciones = [];

        foreach (Sucursal::query()->where('activo', true)->orderByDesc('es_corporativo')->orderBy('nombre')->get(['id', 'nombre', 'es_corporativo']) as $sucursal) {
            $opciones[] = [
                'id' => (int) $sucursal->id,
                'nombre' => (string) $sucursal->nombre,
                'es_corporativo' => (bool) $sucursal->es_corporativo,
                'silenciada' => in_array((int) $sucursal->id, $silenciadas, true),
            ];
        }

        return $opciones;
    }

    /**
     * @param  list<int>  $sucursalIds  Sucursales de las que NO quiere avisos (vacío = recibir de todas).
     */
    public function guardar(User $usuario, array $sucursalIds): void
    {
        if (! $this->puedeConfigurar($usuario)) {
            throw new AuthorizationException('Tu rol no permite elegir de qué sucursales recibir avisos.');
        }

        $ids = array_values(array_unique(array_map('intval', $sucursalIds)));
        $existentes = Sucursal::query()->whereIn('id', $ids === [] ? [0] : $ids)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if (count($existentes) !== count($ids)) {
            throw ValidationException::withMessages(['sucursales' => 'Alguna de las sucursales elegidas ya no existe. Recarga la página.']);
        }

        $antes = $this->silenciadas($usuario);
        $usuario->sucursalesSilenciadas()->sync($ids);

        $this->auditoria->registrar('notificaciones_sucursales_silenciadas', null, $usuario, [
            'antes' => $antes,
            'despues' => $ids,
        ]);
    }

    /**
     * true si el aviso sobre este recurso NO debe llegarle a la cuenta (lo
     * llama la capa de entrega; las confirmaciones ni siquiera pasan por
     * aquí). Ante cualquier duda, no silencia. $colaboradorId se resuelve
     * solo si la cuenta tiene sucursales silenciadas.
     *
     * @param  Closure(): ?int  $colaboradorId
     */
    public function silencia(User $usuario, ?Model $recurso, Closure $colaboradorId): bool
    {
        $silenciadas = $this->silenciadas($usuario);

        if ($silenciadas === [] || ! $this->puedeConfigurar($usuario)) {
            return false;
        }

        $colaborador = $recurso instanceof Colaborador
            ? $recurso
            : (($id = $colaboradorId()) !== null ? Colaborador::query()->withTrashed()->where('id', $id)->first(['id', 'sucursal_principal_id', 'jefe_id', 'gerente_id']) : null);

        if ($colaborador !== null) {
            // Su persona, su equipo directo o la gente que gerencia: le toca a él.
            $propio = $usuario->colaborador_id;

            if ($propio !== null && in_array($propio, [$colaborador->id, $colaborador->jefe_id, $colaborador->gerente_id], true)) {
                return false;
            }

            $sucursalId = $colaborador->sucursal_principal_id;
        } else {
            $sucursalId = $recurso?->getAttribute('sucursal_id');
        }

        return is_numeric($sucursalId) && in_array((int) $sucursalId, $silenciadas, true);
    }

    /**
     * @return list<int>
     */
    private function silenciadas(User $usuario): array
    {
        try {
            return array_values(DB::table('notificacion_sucursales_silenciadas')
                ->where('user_id', $usuario->id)
                ->pluck('sucursal_id')
                ->map(fn ($id): int => (int) $id)
                ->all());
        } catch (Throwable) {
            // Tabla aún no migrada: nunca se bloquea un aviso por esto.
            return [];
        }
    }
}
