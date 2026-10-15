<?php

namespace App\Policies;

use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;

/**
 * No usa los verbos CRUD por defecto de Laravel a proposito: "documentos
 * laborales" no es un CRUD simetrico (un colaborador puede subir pero nunca
 * aprobar/rechazar su propio documento, ni editarlo una vez aprobado). Cada
 * metodo representa una accion real del flujo de revision documental.
 * super_admin revisa/aprueba incluso el documento de su propio colaborador
 * vinculado, ver ConSuperAdminOperativo.
 */
class EmployeeDocumentPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function verExpediente(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->can('documentos.ver') && $this->alcance->puedeVerExpediente($usuario, $colaborador);
    }

    public function subir(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->can('documentos.subir') && $this->alcance->puedeVerExpediente($usuario, $colaborador);
    }

    public function descargar(User $usuario, EmployeeDocument $documento): bool
    {
        return $usuario->can('documentos.descargar') && $this->alcance->puedeVerExpediente($usuario, $documento->colaborador);
    }

    /**
     * Puede aprobar/rechazar/pedir correccion: siempre alguien distinto al
     * propio colaborador (nadie revisa su propio documento).
     */
    public function revisar(User $usuario, EmployeeDocument $documento): bool
    {
        return $usuario->can('documentos.revisar')
            && $usuario->colaborador_id !== $documento->colaborador_id
            && $this->alcance->puedeVerExpediente($usuario, $documento->colaborador);
    }

    /**
     * Criterio único web + app móvil: vale el permiso web
     * (documentos.revisar + documentos.aprobar) o cualquiera de los permisos
     * móviles históricos (rh.expedientes.documentos.aprobar de la ficha del
     * expediente, rh.documentos.aprobar de la bandeja de documentos). En
     * todos los casos: nunca el propio documento y siempre con alcance.
     */
    public function aprobar(User $usuario, EmployeeDocument $documento): bool
    {
        return $this->decide($usuario, $documento, 'documentos.aprobar', ['rh.expedientes.documentos.aprobar', 'rh.documentos.aprobar']);
    }

    public function rechazar(User $usuario, EmployeeDocument $documento): bool
    {
        return $this->decide($usuario, $documento, 'documentos.rechazar', ['rh.expedientes.documentos.rechazar', 'rh.documentos.rechazar']);
    }

    /**
     * @param  list<string>  $permisosApp
     */
    private function decide(User $usuario, EmployeeDocument $documento, string $permisoWeb, array $permisosApp): bool
    {
        $conPermiso = ($usuario->can('documentos.revisar') && $usuario->can($permisoWeb))
            || collect($permisosApp)->contains(fn (string $permiso): bool => $usuario->can($permiso));

        return $conPermiso
            && $documento->colaborador !== null
            && $usuario->colaborador_id !== $documento->colaborador_id
            && $this->alcance->puedeVerExpediente($usuario, $documento->colaborador);
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
        return $usuario->can('documentos.versiones') && $this->alcance->puedeVerExpediente($usuario, $documento->colaborador);
    }
}
