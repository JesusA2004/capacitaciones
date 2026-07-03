<?php

namespace App\Integrations\Reuniones;

use App\Models\SesionEnVivo;

/**
 * Contrato comun para los proveedores de sesiones en vivo (manual, Google
 * Meet, Zoom). `SesionEnVivoService` resuelve la implementacion segun
 * `SesionEnVivo::proveedor` sin conocer los detalles de cada integracion.
 */
interface ProveedorSesionEnVivo
{
    /**
     * Falso cuando la integracion no esta habilitada o le faltan
     * credenciales; en ese caso la sesion se guarda igualmente (con el
     * enlace que haya escrito el instructor, si lo hay) en vez de fallar.
     */
    public function estaDisponible(): bool;

    /**
     * Crea la reunion en el proveedor externo y actualiza en el propio
     * modelo `enlace_reunion`, `id_reunion_externa` y `datos_proveedor`. El
     * proveedor manual no hace nada: el enlace ya viene escrito a mano por
     * el instructor en el formulario.
     */
    public function crearReunion(SesionEnVivo $sesion): void;

    /**
     * Cancela/elimina la reunion en el proveedor externo, si aplica.
     */
    public function cancelarReunion(SesionEnVivo $sesion): void;
}
