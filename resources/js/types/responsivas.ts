/**
 * Responsivas de equipo (App\Http\Controllers\Rh\ResponsivaController).
 */
export type EstadoResponsiva =
    'generada' | 'firmada' | 'devuelta' | 'cancelada';

export type ResponsivaActivoResumen = {
    descripcion: string;
    detalle: string;
    cantidad: number;
    estado: string;
};

export type ResponsivaItem = {
    id: number;
    folio: string | null;
    tipo: string;
    tipo_etiqueta: string;
    fecha_entrega: string;
    lugar: string;
    estado: EstadoResponsiva;
    estado_etiqueta: string;
    /** Responsable de entrega: quien realmente entregó el equipo. */
    entregado_por: string;
    entregado_por_puesto: string | null;
    /** Quien capturó la responsiva en PEOPLE (puede diferir del anterior). */
    registrado_por: string | null;
    observaciones: string | null;
    activos: ResponsivaActivoResumen[];
    tiene_pdf: boolean;
    /** LEGACY: escaneo firmado histórico (solo lectura). */
    tiene_firmada: boolean;
    firmada_subida_en: string | null;
    /** Firma física sin archivo (flujo vigente). */
    firmada_fisicamente_en: string | null;
    devuelta_en: string | null;
    observaciones_devolucion: string | null;
    motivo_cancelacion: string | null;
    puede_operar: boolean;
    puede_cancelar: boolean;
};

export type OpcionResponsiva = { value: string; label: string };

export type ResponsableEntrega = {
    id: number;
    nombre: string;
    puesto: string | null;
};

export type ValoresInicialesResponsiva = {
    lugar: string;
    fecha_entrega: string;
    receptor: {
        nombre: string;
        numero_empleado: string | null;
        puesto: string | null;
        sucursal: string | null;
    };
    otorgante: { nombre: string; puesto: string | null };
    empresa: string | null;
    /** Responsable de entrega por defecto (el capturador). */
    responsable_entrega_id: number;
    /** Candidatos a responsable de entrega dentro del alcance del capturador. */
    responsables: ResponsableEntrega[];
    /** true si hay más de un candidato (p.ej. RH captura por otro). */
    puede_elegir_responsable: boolean;
};

export type ResponsivasColaboradorRespuesta = {
    responsivas: ResponsivaItem[];
    puede_generar: boolean;
    valores_iniciales: ValoresInicialesResponsiva | null;
    tipos: OpcionResponsiva[];
    condiciones: OpcionResponsiva[];
};

export type ActivoResponsivaForm = {
    descripcion: string;
    marca: string;
    modelo: string;
    numero_serie: string;
    color: string;
    cantidad: number;
    estado: string;
    caracteristicas: string;
    accesorios: string;
};

export type ResponsivaForm = {
    tipo: string;
    lugar: string;
    fecha_entrega: string;
    responsable_entrega_id: number;
    observaciones: string;
    activos: ActivoResponsivaForm[];
};
