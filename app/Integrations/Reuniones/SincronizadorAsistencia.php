<?php

namespace App\Integrations\Reuniones;

use App\Integrations\Reuniones\DTO\RegistroSesionExterno;
use App\Models\SesionEnVivo;

/**
 * Contrato para recuperar la asistencia real de una sesión desde el
 * proveedor externo (Google Meet, Zoom). Separado de
 * `ProveedorSesionEnVivo` (creación/cancelación de la reunión) porque usa
 * credenciales/scopes distintos y tiene un ciclo de vida propio: se llama
 * repetidamente después de que la sesión terminó, no una sola vez al
 * crearla. Ver docs/AUDITORIA_CUMPLIMIENTO.md sección 1.
 */
interface SincronizadorAsistencia
{
    public function estaDisponible(): bool;

    /**
     * @throws \RuntimeException si el registro de conferencia todavía no
     *                           está disponible o la reunión no puede
     *                           identificarse en el proveedor
     */
    public function obtenerDatosAsistencia(SesionEnVivo $sesion): RegistroSesionExterno;
}
