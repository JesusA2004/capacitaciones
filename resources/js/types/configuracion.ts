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
    /** 'graficas' para los colores de cada gráfica del tablero. */
    seccion?: string | null;
    actualizado_por: string | null;
    actualizado_en: string | null;
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
