/** Actas administrativas (PRO-ADP-08) — payload de ActaService::aArray(). */
export type EstadoActa =
    'borrador' | 'generada' | 'firmada' | 'cerrada' | 'cancelada';

export type TestigoActa = {
    nombre: string;
    puesto?: string | null;
    colaborador_id?: number | null;
};

export type DeclaracionActa = { persona: string; declaracion: string };

export type ActaResumen = {
    id: number;
    folio: string | null;
    tipo: string;
    tipo_etiqueta: string;
    motivo: string | null;
    estado: EstadoActa;
    estado_etiqueta: string;
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
    };
    fecha: string;
    hora: string | null;
    lugar: string | null;
    negativa_firma: boolean;
    documento_id: number | null;
    cerrada_en: string | null;
};

export type ActaDetalle = ActaResumen & {
    hechos: string;
    responsable_id: number | null;
    testigos: TestigoActa[];
    declaraciones: DeclaracionActa[];
    motivo_negativa: string | null;
    seguimiento: { fecha: string; nota: string; user_id: number | null }[];
    anexos: {
        id: number;
        nombre: string;
        descripcion: string | null;
        mime: string | null;
        size: number | null;
    }[];
};

/** Campos que se capturan al levantar/editar un acta (ActaCampos.vue). */
export type FormularioActa = {
    motivo: string;
    fecha: string;
    hora: string;
    lugar: string;
    hechos: string;
    testigos: { nombre: string; puesto: string }[];
    declaraciones: DeclaracionActa[];
};
