<?php

namespace App\Services\RhMobile;

use App\Models\User;

/**
 * Resumen para GET /api/v1/rh/dashboard: reutiliza RhPendientesService (la
 * misma fuente que la bandeja completa) en vez de recalcular por su cuenta,
 * solo recorta a "urgentes"/"recientes" para no traer el universo completo
 * a la pantalla de inicio. Ver seccion 6 del encargo movil.
 */
class RhDashboardService
{
    private const LIMITE_URGENTES = 5;

    private const LIMITE_RECIENTES = 10;

    public function __construct(private readonly RhPendientesService $pendientes) {}

    /**
     * @return array<string, mixed>
     */
    public function resumen(User $usuario): array
    {
        $conteos = $this->pendientes->resumenConteos($usuario);

        $bandeja = $this->pendientes->bandeja($usuario, ['per_page' => self::LIMITE_RECIENTES]);
        $items = collect($bandeja->items());

        return [
            'resumen' => [
                'pendientes_total' => $conteos['total'],
                'solicitudes' => $conteos['solicitudes'],
                'vacaciones' => $conteos['vacaciones'],
                'documentos' => $conteos['documentos'],
                'incorporaciones' => $conteos['incorporaciones'],
            ],
            'urgentes' => $items->take(self::LIMITE_URGENTES)->values()->all(),
            'recientes' => $items->values()->all(),
        ];
    }
}
