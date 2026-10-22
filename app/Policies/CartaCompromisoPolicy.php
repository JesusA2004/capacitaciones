<?php

namespace App\Policies;

use App\Enums\EstadoCartaCompromiso;
use App\Models\CartaCompromiso;
use App\Models\Colaborador;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\Expedientes\GestionCartasCompromisoService;

/**
 * Módulo «Cartas compromiso»: RH, el gerente (de sucursal) y la gerente
 * regional, con el alcance del módulo `cartas_compromiso` de Roles y
 * permisos (GestionCartasCompromisoService::motivoFueraDeAlcance). Nunca
 * sobre la propia carta. Las reglas de A QUIÉN se le puede generar (nuevo
 * ingreso, máximo de documentos, una sola carta abierta) son de negocio y
 * responden 422 con su motivo desde el servicio.
 */
class CartaCompromisoPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(private readonly GestionCartasCompromisoService $gestion) {}

    public function viewAny(User $usuario): bool
    {
        return $usuario->can(GestionCartasCompromisoService::PERMISO_VER);
    }

    public function view(User $usuario, CartaCompromiso $carta): bool
    {
        return $usuario->can(GestionCartasCompromisoService::PERMISO_VER) && $this->alcanza($usuario, $carta);
    }

    /** Generar (y ver la vista previa de) la carta de esta persona. */
    public function generar(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->can(GestionCartasCompromisoService::PERMISO_GENERAR) && $this->gestion->alcanza($usuario, $colaborador);
    }

    public function subirFirmada(User $usuario, CartaCompromiso $carta): bool
    {
        return $usuario->can(GestionCartasCompromisoService::PERMISO_SUBIR_FIRMADA)
            && ! $carta->firmada()
            && $carta->estado !== EstadoCartaCompromiso::Sustituida
            && $this->alcanza($usuario, $carta);
    }

    /** PDF de la carta (para imprimirla y firmarla) y su escaneo firmado. */
    public function descargar(User $usuario, CartaCompromiso $carta): bool
    {
        return $usuario->can(GestionCartasCompromisoService::PERMISO_DESCARGAR) && $this->view($usuario, $carta);
    }

    private function alcanza(User $usuario, CartaCompromiso $carta): bool
    {
        $colaborador = $carta->colaborador()->withTrashed()->first();

        return $colaborador !== null && $this->gestion->alcanza($usuario, $colaborador);
    }
}
