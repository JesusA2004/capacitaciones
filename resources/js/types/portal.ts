export type PerfilColaborador = {
    id: number;
    nombre: string;
    apellidos: string | null;
    nombre_completo: string;
    correo: string;
    numero_empleado: string | null;
    foto_url: string | null;
    puesto: string | null;
    departamento: string | null;
    sucursal: string | null;
    empresa: string | null;
    jefe_directo: string | null;
    fecha_ingreso: string | null;
    antiguedad_anios: number;
};

export type NotificacionPortalItem = {
    id: string;
    tipo: string | null;
    titulo: string;
    mensaje: string;
    url: string | null;
    leida: boolean;
    creada_en: string | null;
};

export type ResumenNotificaciones = {
    no_leidas: number;
    recientes: NotificacionPortalItem[];
};
