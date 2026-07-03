<?php

namespace App\Services\Reuniones;

use App\Enums\EstadoSesionEnVivo;
use App\Enums\ProveedorSesion;
use App\Integrations\Reuniones\GoogleMeetProveedor;
use App\Integrations\Reuniones\ManualProveedor;
use App\Integrations\Reuniones\ProveedorSesionEnVivo;
use App\Integrations\Reuniones\ZoomProveedor;
use App\Models\Leccion;
use App\Models\SesionEnVivo;
use App\Models\User;

class SesionEnVivoService
{
    public function __construct(private readonly AsistenciaService $asistencias) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(Leccion $leccion, array $datos, User $creador): SesionEnVivo
    {
        $sesion = SesionEnVivo::create([...$datos, 'leccion_id' => $leccion->id, 'creado_por' => $creador->id]);

        try {
            $this->resolverProveedor($sesion->proveedor)->crearReunion($sesion);
        } catch (\Throwable $excepcion) {
            // No se bloquea la creacion de la sesion por un fallo de la API
            // externa: el instructor puede agregar el enlace a mano despues.
            report($excepcion);
        }

        $sesion = $sesion->fresh();
        $this->asistencias->materializarParaSesion($sesion);

        return $sesion;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(SesionEnVivo $sesion, array $datos): SesionEnVivo
    {
        $sesion->update($datos);

        return $sesion->fresh();
    }

    public function cancelar(SesionEnVivo $sesion): void
    {
        try {
            $this->resolverProveedor($sesion->proveedor)->cancelarReunion($sesion);
        } catch (\Throwable $excepcion) {
            report($excepcion);
        }

        $sesion->update(['estado' => EstadoSesionEnVivo::Cancelada->value]);
    }

    public function resolverProveedor(ProveedorSesion $proveedor): ProveedorSesionEnVivo
    {
        return match ($proveedor) {
            ProveedorSesion::Manual => app(ManualProveedor::class),
            ProveedorSesion::GoogleMeet => app(GoogleMeetProveedor::class),
            ProveedorSesion::Zoom => app(ZoomProveedor::class),
        };
    }
}
