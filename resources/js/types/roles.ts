/**
 * «Roles y permisos» (App\Services\Permisos\CatalogoPermisosService):
 * etapas del sidebar → módulos → acceso / alcance / acciones.
 */

export type NivelAlcance = 'todos' | 'region' | 'sucursal' | 'equipo';

export type RolItem = {
    id: number;
    nombre: string;
    permisos: string[];
    usuarios_count: number;
    /** super_admin: bloqueado como «todo». */
    es_protegido: boolean;
    /** Rol del seeder: no se renombra ni se elimina. */
    es_del_sistema: boolean;
};

export type AccionPermiso = {
    permiso: string;
    etiqueta: string;
    descripcion: string | null;
};

export type ModuloCatalogo = {
    clave: string;
    etiqueta: string;
    descripcion: string | null;
    acceso: { permiso: string; descripcion: string | null } | null;
    alcances: Partial<Record<NivelAlcance, string>>;
    acciones: AccionPermiso[];
};

export type EtapaCatalogo = {
    clave: string;
    etiqueta: string;
    numero: number | null;
    modulos: ModuloCatalogo[];
};

export type CatalogoPermisos = {
    etapas: EtapaCatalogo[];
    /** Permisos sin módulo, por prefijo: nada queda oculto. */
    otros: Record<string, string[]>;
    niveles: { valor: NivelAlcance | 'propios'; etiqueta: string }[];
};

/**
 * Checklist de avisos por rol (config/notificaciones_catalogo.php). Marcar un
 * rol nunca amplía su alcance; desmarcarlo quita el aviso, no la tarea.
 */
export type EventoNotificacion = {
    clave: string;
    etiqueta: string;
    /** operativa: casilla por rol. personal/asignada: siempre llega a la persona. */
    audiencia: 'operativa' | 'personal' | 'asignada';
};

export type ModuloNotificaciones = {
    clave: string;
    etiqueta: string;
    eventos: EventoNotificacion[];
};

export type ChecklistNotificaciones = {
    modulos: ModuloNotificaciones[];
    marcados: Record<number, string[]>;
    obligatorios: Record<number, string[]>;
    por_defecto: Record<number, string[]>;
    mensaje_obligatoria: string;
};

export type CambioNotificacionRol = {
    id: number;
    evento: string;
    etiqueta: string;
    antes: boolean;
    despues: boolean;
    actor: string | null;
    fecha: string | null;
};
