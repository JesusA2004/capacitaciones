/**
 * Capacitación (Fase 3): portada operativa, aula tipo Classroom, Mi
 * capacitación del colaborador y Certificación Mr. Lana. Espejo de lo que
 * devuelven PortadaCapacitacionService, AulaService, MiCapacitacionService y
 * CertificacionMrLanaService.
 */

export type NivelCertificacionClave = 'bronce' | 'plata' | 'oro';

export type NivelCertificacionItem = {
    clave: NivelCertificacionClave;
    etiqueta: string;
    descripcion: string;
    umbral: number;
};

export type ResumenCertificacion = {
    constancias: number;
    nivel: { clave: NivelCertificacionClave; etiqueta: string } | null;
    siguiente: {
        clave: NivelCertificacionClave;
        etiqueta: string;
        umbral: number;
        faltan: number;
    } | null;
    progreso: number;
    niveles: NivelCertificacionItem[];
};

export type CursoTarjeta = {
    id: number;
    titulo: string;
    descripcion: string | null;
    estado: string;
    estado_etiqueta: string;
    etiquetas: string[];
    duracion_estimada_minutos: number | null;
    genera_constancia: boolean;
    modulos: number;
    inscritos: number;
    completaron: number;
    responsable: string | null;
};

export type PortadaCapacitacion = {
    indicadores: {
        cursos_publicados: number;
        cursos_borrador: number;
        participantes: number;
        inscripciones: number;
        completadas: number;
        tasa_finalizacion: number;
        por_calificar: number;
        constancias: number;
        vencen_pronto: number;
    };
    estados: { clave: string; etiqueta: string; total: number }[];
    serie: { mes: string; etiqueta: string; completadas: number }[];
    cursos: CursoTarjeta[];
    proximas_sesiones: {
        id: number;
        titulo: string;
        fecha_inicio: string;
        duracion_minutos: number;
        curso: string | null;
    }[];
};

export type PermisosPortadaCapacitacion = {
    crear_curso: boolean;
    ver_cursos: boolean;
    asignar: boolean;
    calificar: boolean;
    certificacion: boolean;
};

export type EstadoCeldaAula =
    'aprobado' | 'reprobado' | 'por_calificar' | 'completado' | 'sin_entregar';

export type AulaCurso = {
    curso: {
        id: number;
        titulo: string;
        descripcion: string | null;
        objetivo: string | null;
        estado: string;
        estado_etiqueta: string;
        duracion_estimada_minutos: number | null;
        genera_constancia: boolean;
        etiquetas: string[];
        responsable: string | null;
        modulos: number;
        lecciones: number;
    };
    resumen: {
        participantes: number;
        completaron: number;
        por_calificar: number;
        constancias: number;
        progreso_promedio: number;
    };
    tablon: {
        tipo: 'sesion' | 'entrega' | 'cuestionario' | 'constancia';
        titulo: string;
        detalle: string;
        fecha: string;
    }[];
    trabajo: {
        leccion_id: number;
        titulo: string;
        tipo: string;
        tipo_etiqueta: string;
        obligatoria: boolean;
        completaron: number;
        participantes: number;
        por_calificar: number;
        fecha: string | null;
    }[];
    columnas: { leccion_id: number; titulo: string; tipo: string }[];
    calificaciones: {
        user_id: number;
        nombre: string;
        celdas: Record<
            string,
            { estado: EstadoCeldaAula; valor: number | null }
        >;
    }[];
    participantes: {
        user_id: number;
        nombre: string;
        correo: string | null;
        estado: string;
        estado_etiqueta: string;
        progreso: number;
        completado_en: string | null;
        folio: string | null;
    }[];
};

export type MiAula = {
    inscripcion_id: number;
    curso: {
        id: number;
        titulo: string;
        descripcion: string | null;
        duracion_estimada_minutos: number | null;
        genera_constancia: boolean;
        etiquetas: string[];
    };
    estado: string;
    estado_etiqueta: string;
    progreso: number;
    lecciones_total: number;
    lecciones_completadas: number;
    fecha_limite: string | null;
    completado_en: string | null;
    certificado_id: number | null;
};

export type MiPendienteCapacitacion = {
    leccion_id: number;
    titulo: string;
    tipo: string;
    tipo_etiqueta: string;
    obligatoria: boolean;
    curso_id: number;
    curso: string;
    estado: 'por_hacer' | 'en_revision';
    fecha: string | null;
};

export type MiCertificado = {
    id: number;
    folio: string;
    curso: string;
    curso_id: number;
    emitido_en: string | null;
};

export type PanelMiCapacitacion = {
    aulas: MiAula[];
    pendientes: MiPendienteCapacitacion[];
    certificados: MiCertificado[];
    certificacion: ResumenCertificacion;
    resumen: {
        en_curso: number;
        completadas: number;
        pendientes: number;
        constancias: number;
        minutos_completados: number;
        progreso_promedio: number;
    };
};

export type TableroCertificacion = {
    distribucion: {
        clave: NivelCertificacionClave;
        etiqueta: string;
        umbral: number;
        personas: number;
    }[];
    personas: {
        id: number;
        nombre: string;
        constancias: number;
        nivel: { clave: NivelCertificacionClave; etiqueta: string } | null;
        ultima: string | null;
    }[];
    total_constancias: number;
    total_certificados: number;
};
