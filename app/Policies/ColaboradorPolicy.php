<?php

namespace App\Policies;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * Acciones del ciclo laboral sobre un colaborador (alta, activación,
 * consulta de jerarquía/alta, cierre, documentos, recibos, actas). Siempre
 * permiso Spatie + alcance organizacional (evita IDOR: cambiar el id en la
 * URL no da acceso a colaboradores fuera del alcance). Las acciones
 * administrativas nunca se ejecutan sobre uno mismo.
 */
class ColaboradorPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function darDeAlta(User $usuario): bool
    {
        return $usuario->can('colaboradores.alta');
    }

    public function verAlta(User $usuario, Colaborador $colaborador): bool
    {
        return $this->esPropio($usuario, $colaborador)
            || (($usuario->can('colaboradores.alta') || $usuario->can('expedientes.ver_todos') || $usuario->can('expedientes.ver_sucursal'))
                && $this->alcance->alcanzaColaborador($usuario, $colaborador));
    }

    public function activar(User $usuario, Colaborador $colaborador): bool
    {
        return $this->administrativo($usuario, $colaborador, 'colaboradores.activar');
    }

    public function verJerarquia(User $usuario, Colaborador $colaborador): bool
    {
        return $this->esPropio($usuario, $colaborador)
            || (($usuario->can('organigrama.ver') || $usuario->can('rh.colaboradores.detalle')) && $this->alcance->alcanzaColaborador($usuario, $colaborador));
    }

    public function generarDocumento(User $usuario, Colaborador $colaborador): bool
    {
        return $this->administrativo($usuario, $colaborador, 'documentos_laborales.generar');
    }

    public function verDocumentosLaborales(User $usuario, Colaborador $colaborador): bool
    {
        return $this->esPropio($usuario, $colaborador)
            || ($usuario->can('documentos_laborales.ver') && $this->alcance->alcanzaColaborador($usuario, $colaborador));
    }

    public function verContratos(User $usuario, Colaborador $colaborador): bool
    {
        return $this->esPropio($usuario, $colaborador)
            || ($usuario->can('contratos.ver') && $this->alcance->alcanzaColaborador($usuario, $colaborador));
    }

    public function iniciarCierre(User $usuario, Colaborador $colaborador): bool
    {
        return $this->administrativo($usuario, $colaborador, 'cierres.gestionar');
    }

    public function crearRecibo(User $usuario, Colaborador $colaborador): bool
    {
        return $this->administrativo($usuario, $colaborador, 'nomina.recibos.crear');
    }

    public function crearActa(User $usuario, Colaborador $colaborador): bool
    {
        return $this->administrativo($usuario, $colaborador, 'actas.crear');
    }

    private function esPropio(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->colaborador_id !== null && $usuario->colaborador_id === $colaborador->id;
    }

    private function administrativo(User $usuario, Colaborador $colaborador, string $permiso): bool
    {
        return $usuario->can($permiso)
            && ! $this->esPropio($usuario, $colaborador)
            && $this->alcance->alcanzaColaborador($usuario, $colaborador);
    }
}
