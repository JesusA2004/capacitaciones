<?php

namespace App\Policies;

use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * No usa los verbos CRUD por defecto de Laravel a proposito: "documentos
 * laborales" no es un CRUD simetrico (un colaborador puede subir pero nunca
 * aprobar/rechazar su propio documento, ni editarlo una vez aprobado). Cada
 * metodo representa una accion real del flujo de revision documental.
 */
class EmployeeDocumentPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function verExpediente(User $usuario, User $colaborador): bool
    {
        return $usuario->can('documentos.ver') && $this->alcance->puedeVerExpediente($usuario, $colaborador);
    }

    public function subir(User $usuario, User $colaborador): bool
    {
        return $usuario->can('documentos.subir') && $this->alcance->puedeVerExpediente($usuario, $colaborador);
    }

    public function descargar(User $usuario, EmployeeDocument $documento): bool
    {
        return $usuario->can('documentos.descargar') && $this->alcance->puedeVerExpediente($usuario, $documento->usuario);
    }

    /**
     * Puede aprobar/rechazar/pedir correccion: siempre alguien distinto al
     * propio colaborador (nadie revisa su propio documento).
     */
    public function revisar(User $usuario, EmployeeDocument $documento): bool
    {
        return $usuario->can('documentos.revisar')
            && ! $usuario->is($documento->usuario)
            && $this->alcance->puedeVerExpediente($usuario, $documento->usuario);
    }

    public function aprobar(User $usuario, EmployeeDocument $documento): bool
    {
        return $usuario->can('documentos.aprobar') && $this->revisar($usuario, $documento);
    }

    public function rechazar(User $usuario, EmployeeDocument $documento): bool
    {
        return $usuario->can('documentos.rechazar') && $this->revisar($usuario, $documento);
    }

    /**
     * Pedir correccion es una accion de revision mas suave que rechazar
     * (el documento vuelve al colaborador con comentarios, no queda
     * marcado como rechazado): solo exige el permiso base de revision.
     */
    public function pedirCorreccion(User $usuario, EmployeeDocument $documento): bool
    {
        return $this->revisar($usuario, $documento);
    }

    public function verVersiones(User $usuario, EmployeeDocument $documento): bool
    {
        return $usuario->can('documentos.versiones') && $this->alcance->puedeVerExpediente($usuario, $documento->usuario);
    }
}
