/**
 * Administración → Configuración (ConfiguracionController).
 */
export type SeccionConfiguracion = { clave: string; titulo: string };

export type ParametroConfiguracion = {
    clave: string;
    etiqueta: string;
    descripcion: string | null;
    tipo: 'color' | 'entero' | 'decimal' | 'lista' | 'booleano' | 'texto';
    valor: string | number | boolean | string[];
    defecto: string | number | boolean | string[];
    personalizado: boolean;
    opciones: { value: string; etiqueta: string }[] | null;
    css: string | null;
    actualizado_por: string | null;
    actualizado_en: string | null;
};

export type PersonaJerarquia = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    puesto: string | null;
    departamento: string | null;
    sucursal: string | null;
    jefe: { id: number; nombre: string; puesto: string | null } | null;
    gerente: { id: number; nombre: string } | null;
    cadena: string[];
    subordinados_directos: number;
    advertencia: string | null;
};

export type PosibleJefe = {
    id: number;
    nombre: string;
    puesto: string | null;
    sucursal: string | null;
};

export type ReglaNotificacion = {
    evento: string;
    etiqueta: string;
    descripcion: string | null;
    destinatarios: string[];
    permiso: string | null;
    usuario_ids: number[];
    fallback: string[];
    activa: boolean;
    personalizada: boolean;
    actualizado_en: string | null;
};
