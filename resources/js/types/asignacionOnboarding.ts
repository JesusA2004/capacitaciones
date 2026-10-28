/**
 * «Mi inducción» y «Asignar a…» de Onboarding. Ver
 * App\Services\Onboarding\ProgresoInduccionService (DTO de la persona, el
 * mismo en Mi espacio, la app y «Ver como colaborador"),
 * AsignacionInduccionService y DestinatariosOnboardingService.
 */

export type EstadoPasoInduccion =
    'completado' | 'actual' | 'pendiente' | 'detenido' | 'omitido';

export type PasoMiInduccion = {
    clave: 'presentacion' | 'material' | 'evaluacion' | 'resultado';
    titulo: string;
    detalle: string;
    estado: EstadoPasoInduccion;
};

export type EstadoAsignacionInduccion =
    | 'sin_asignar'
    | 'retirada'
    | 'asignada'
    | 'viendo'
    | 'material_visto'
    | 'evaluacion_pendiente'
    | 'en_revision'
    | 'reforzamiento'
    | 'aprobada';

export type MiInduccion = {
    estado:
        | 'sin_asignar'
        | 'disponible'
        | 'evaluacion_pendiente'
        | 'en_revision'
        | 'completada';
    etiqueta: string;
    mensaje: string;
    puede_ver_contenido: boolean;
    puede_contestar_evaluacion: boolean;
    estado_rh: EstadoAsignacionInduccion;
    estado_rh_etiqueta: string;
    es_reinduccion: boolean;
    asignacion: {
        automatica: boolean;
        incluye_presentacion: boolean;
        incluye_material: boolean;
        fecha_limite: string | null;
        vencida: boolean;
        mensaje: string | null;
        asignada_en: string | null;
        asignada_por: string | null;
    } | null;
    presentacion: {
        incluida: boolean;
        total_paginas: number;
        paginas_vistas: number;
        porcentaje: number;
        completada: boolean;
    };
    material: {
        incluido: boolean;
        tipo: 'audio' | 'video';
        tipo_etiqueta: string;
        obligatorio: boolean;
        completado: boolean;
        disponible: boolean;
    } | null;
    evaluacion: {
        id: number;
        estado: string;
        etiqueta: string;
        calificacion: number | null;
        intentos: number;
        fecha_limite_reevaluacion: string | null;
    } | null;
    contenido_visto: boolean;
    pasos: PasoMiInduccion[];
    porcentaje: number;
};

/** Una fila de «Asignaciones» (AsignacionInduccionService::panorama()). */
export type FilaAsignacionInduccion = {
    colaborador: {
        id: number;
        nombre: string;
        /** Foto oficial del expediente (URL protegida); null → iniciales. */
        foto_url?: string | null;
        numero_empleado: string | null;
        puesto: string | null;
        sucursal: string | null;
    };
    induccion: MiInduccion;
    puede_retirar: boolean;
};

export type ModoDestinatarios = 'todos' | 'personas' | 'sucursales';

export type SucursalAsignable = { id: number; nombre: string; total: number };

/** Resumen de confirmación antes de asignar. */
export type ResumenAsignacion = {
    descripcion: string;
    total: number;
    asignar: number;
    omitidos: { id: number; nombre: string; motivo: string }[];
    muestra: string[];
};

/** AcuseDescriptivoPuestoService::aArray(…, true) — lo que ve la persona. */
export type DescriptivoPuestoColaborador = {
    id: number | null;
    colaborador_id: number;
    estado: 'enviado' | 'leido' | 'enterado';
    estado_etiqueta: string;
    enviado_por: string;
    enviado_en: string;
    leido_en: string | null;
    acusado_en: string | null;
    enterado: boolean;
    comentario: string | null;
    enterado_texto?: string | null;
    enterado_en?: string | null;
    conformidad?: string | null;
    texto_casilla_enterado?: string;
    ejemplo_conformidad?: string;
    descriptivo: {
        id: number;
        nombre_puesto: string;
        version: number;
        area?: string | null;
        objetivo?: string | null;
        personal_a_cargo?: string | null;
        fecha?: string | null;
        secciones?: { clave: string; titulo: string; elementos: string[] }[];
    };
};

/** Un capítulo estructurado de la Inducción Mr. Lana (onboarding_modulos.contenido). */
export type ItemCapituloInduccion =
    | string
    | {
          titulo?: string;
          texto?: string;
          nota?: string;
          etiqueta?: string;
          valor?: string;
      };

export type BloqueCapituloInduccion = {
    tipo?: string;
    titulo?: string;
    texto?: string;
    nota?: string;
    vista?: string;
    items?: ItemCapituloInduccion[];
};

export type CapituloInduccion = {
    clave?: string;
    numero?: number;
    titulo?: string;
    subtitulo?: string;
    bloques?: BloqueCapituloInduccion[];
};
