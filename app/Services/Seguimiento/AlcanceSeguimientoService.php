<?php

namespace App\Services\Seguimiento;

use App\Enums\AlcanceDatos;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Permisos\AlcanceModuloService;

/**
 * Qué sucursales de PEOPLE ve cada usuario en Seguimiento. Reutiliza el
 * alcance del módulo «seguimiento» de Roles y permisos (AlcanceModuloService,
 * el mismo mecanismo que usa AlcanceOrganizacionalService::limitarPorSucursal):
 * Todos → toda la empresa; Región → las sucursales de su región (gerencia
 * regional = todas las operativas); Sucursal → sus sucursales (principal +
 * adicionales); Equipo/Propios → ninguna (Seguimiento es una vista de
 * operación, no de autoservicio).
 */
class AlcanceSeguimientoService
{
    public const MODULO = 'seguimiento';

    public function __construct(private readonly AlcanceModuloService $alcanceModulo) {}

    /**
     * null = todas las sucursales (sin restricción).
     *
     * @return list<int>|null
     */
    public function sucursalesVisibles(User $usuario): ?array
    {
        $nivel = $this->alcanceModulo->nivel($usuario, self::MODULO);

        return match ($nivel) {
            AlcanceDatos::Todos => null,
            AlcanceDatos::Region, AlcanceDatos::Sucursal => array_values(array_unique(array_map(
                fn ($id): int => (int) $id,
                $this->alcanceModulo->sucursalesPara($usuario, $nivel)->all(),
            ))),
            default => [],
        };
    }

    public function veTodo(User $usuario): bool
    {
        return $this->sucursalesVisibles($usuario) === null;
    }

    /**
     * @param  list<int>|null  $visibles
     */
    public function alcanzaSucursal(?array $visibles, ?int $sucursalId): bool
    {
        if ($visibles === null) {
            return true;
        }

        return $sucursalId !== null && in_array($sucursalId, $visibles, true);
    }

    public function alcanzaColaborador(User $usuario, Colaborador $colaborador): bool
    {
        return $this->alcanceModulo->alcanzaColaborador($usuario, $colaborador, self::MODULO);
    }
}
