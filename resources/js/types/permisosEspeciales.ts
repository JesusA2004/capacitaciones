/**
 * Permisos especiales por persona (App\Services\Permisos\PermisosEspecialesService):
 * conceder o quitar permisos a UNA cuenta sin tocar su rol.
 */

export type TipoAjustePermiso = 'conceder' | 'revocar';

export type AjustePermisoItem = {
    permiso: string;
    tipo: TipoAjustePermiso;
    motivo: string | null;
    vigente_hasta: string | null;
    /** false = ya venció (no cuenta; se retira solo). */
    vigente: boolean;
    otorgado_por: string | null;
    fecha: string | null;
};

export type BitacoraPermisoItem = {
    id: number;
    permiso: string | null;
    accion: string;
    etiqueta: string;
    tipo: TipoAjustePermiso | null;
    motivo: string | null;
    vigente_hasta: string | null;
    actor: string | null;
    fecha: string | null;
};

export type ResumenPermisosEspeciales = {
    concedidos: number;
    quitados: number;
    /** Vencimiento más próximo (ISO) o null si no vencen. */
    vence: string | null;
};

export type PersonaConPermisosEspeciales = ResumenPermisosEspeciales & {
    id: number;
    nombre: string;
    username: string;
    roles: string[];
};

export type UsuarioPermisosEspeciales = {
    id: number;
    nombre: string;
    username: string;
    puesto: string | null;
    sucursal: string | null;
    roles: { nombre: string; activo: boolean }[];
};
