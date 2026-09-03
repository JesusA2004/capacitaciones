<?php

namespace App\Services\Colaboradores;

use App\Models\User;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Vacaciones\VacacionesService;

/**
 * Datos "básicos" del propio colaborador para el portal web (/mi-portal) y
 * la API móvil (Api\V1\ColaboradorController) — Fase 1: nada de expedientes,
 * documentos internos, sueldos ni datos de otros colaboradores (ver
 * docs/PORTAL_COLABORADOR.md y docs/API_MOVIL.md, sección "qué NO ve el
 * colaborador").
 */
class ColaboradorPerfilService
{
    public function __construct(
        private readonly VacacionesService $vacaciones,
        private readonly SolicitudesService $solicitudes,
        private readonly NotificacionesService $notificaciones,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function perfil(User $colaborador): array
    {
        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->name,
            'apellidos' => $colaborador->apellidos,
            'nombre_completo' => $colaborador->nombreCompleto(),
            'correo' => $colaborador->email,
            'numero_empleado' => $colaborador->numero_empleado,
            // Nunca se expone `foto_path` (ruta física en el disco NAS): se
            // resuelve a la misma ruta protegida por policy que usa el
            // expediente (ver Rh\ExpedienteController::descargarFoto). En la
            // API móvil esta URL solo funciona con una sesión web válida —
            // limitación conocida de Fase 1, ver docs/API_MOVIL.md.
            'foto_url' => $colaborador->foto_path !== null ? route('rh.expedientes.foto', $colaborador) : null,
            'puesto' => $colaborador->puesto?->nombre,
            'departamento' => $colaborador->departamento?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'empresa' => $colaborador->empresa()?->nombre,
            'jefe_directo' => $colaborador->jefe?->nombreCompleto(),
            'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
            'antiguedad_anios' => (int) ($colaborador->fecha_ingreso?->diffInYears(now()) ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $colaborador): array
    {
        $saldo = $this->vacaciones->saldo($colaborador);
        $solicitudesRecientes = $this->solicitudes->paraColaborador($colaborador);

        return [
            'perfil' => $this->perfil($colaborador),
            'vacaciones' => $saldo,
            'solicitudes_recientes' => $solicitudesRecientes->items(),
            'notificaciones' => $this->notificaciones->resumen($colaborador, 5),
        ];
    }
}
