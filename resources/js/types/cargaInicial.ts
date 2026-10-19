/**
 * Carga inicial PEOPLE (Configuración → Importar datos → Carga inicial).
 * Espejo de las props de `Administracion/ImportarDatos/CargaInicial`.
 */
export type CargaInicialEstado =
    | 'analizado'
    | 'en_cola'
    | 'aplicando'
    | 'completado'
    | 'completado_con_errores'
    | 'fallido';

export type CargaInicialPreview = {
    colaboradores: {
        total: number;
        crear: number;
        actualizar: number;
        sin_cambios: number;
        pendientes: number;
        conflictos: number;
        omitidos: number;
    };
    catalogos: {
        departamentos_reconocidos: string[];
        departamentos_crear: string[];
        puestos_reconocidos: string[];
        puestos_crear: { puesto: string; departamento: string | null }[];
        /** Se resuelven por sucursal/región (Coordinadora Administrativa…). */
        puestos_contexto: string[];
        aliases: { origen: string; destino: string }[];
        conflictos: string[];
    };
    salarios: {
        vinculados: number;
        cambios: number;
        pendientes: {
            clave: string | null;
            nombre: string | null;
            motivo: string;
        }[];
    };
    headcount: {
        registros: number;
        total_autorizado: number;
        fecha_corte: string | null;
        conflictos: string[];
    };
    rotacion: { periodos: number; sucursales: number; conflictos: string[] };
    nas: {
        origen_existe: boolean;
        ruta: string;
        carpetas: number;
        match_exacto: number;
        match_alto: number;
        revision_manual: number;
        sin_match: number;
        historicos: number;
        pendientes_vincular: number;
    };
    /** Filas en revisión (p. ej. sin puesto): no se aplican. */
    pendientes_rh: {
        fila: number;
        clave: string | null;
        nombre: string | null;
        motivo: string;
    }[];
    conflictos_filas: {
        fila: number;
        clave: string | null;
        nombre: string | null;
        motivos: string[];
    }[];
    /** Hoja PENDIENTES (PENDIENTE_ACTUAL / EXCLUIDO / EXCLUIDO_HEADCOUNT). */
    informativos: {
        tipo: string;
        clave: string | null;
        nombre: string | null;
        detalle: string | null;
    }[];
    advertencias: string[];
};

export type CargaInicialResultado = {
    colaboradores: {
        creados: number;
        actualizados: number;
        sin_cambios: number;
        en_revision: number;
        conflictos: number;
        errores: number;
    };
    catalogos: { departamentos_creados: number; puestos_creados: number };
    salarios: { aplicados: number; sin_cambios: number; pendientes: number };
    vacaciones: { aplicados: number; sin_cambios: number };
    headcount: { creados: number; actualizados: number; sin_cambios: number };
    vacantes: { abiertas: number; cerradas: number; actualizadas: number };
    rotacion: { nuevos: number; reemplazados: number; sin_cambios: number };
    nas: {
        pdfs_registrados: number;
        pendientes_vincular: number;
        revision_pendiente: number;
    };
    cuentas_creadas: number;
    errores: string[];
};

export type CargaInicialCorrida = {
    id: number;
    archivo: string;
    estado: CargaInicialEstado | string;
    etapa: string | null;
    progreso: number;
    progreso_total: number;
    error: string | null;
    usuario: string | null;
    creada_en: string | null;
    terminada_en: string | null;
    /** Solo en index con corrida. */
    preview: CargaInicialPreview | null;
    /** Plan dañado / ilegible. */
    preview_error: string | null;
    resultado: CargaInicialResultado | null;
};

export type CargaInicialHistorialItem = {
    id: number;
    archivo: string;
    estado: string;
    usuario: string | null;
    creada_en: string | null;
};

export type CargaInicialPageProps = {
    corrida: CargaInicialCorrida | null;
    historial: CargaInicialHistorialItem[];
};
