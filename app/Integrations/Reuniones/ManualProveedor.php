<?php

namespace App\Integrations\Reuniones;

use App\Models\SesionEnVivo;

/**
 * El instructor escribe el enlace de la reunion directamente en el
 * formulario (por ejemplo, un enlace de Teams/Meet/Zoom creado a mano fuera
 * del sistema); esta integracion no llama a ninguna API externa.
 */
class ManualProveedor implements ProveedorSesionEnVivo
{
    public function estaDisponible(): bool
    {
        return true;
    }

    public function crearReunion(SesionEnVivo $sesion): void
    {
        // El enlace ya se guardo tal cual lo escribio el instructor.
    }

    public function cancelarReunion(SesionEnVivo $sesion): void
    {
        // Nada que cancelar en un proveedor externo.
    }
}
