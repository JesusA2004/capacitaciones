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
        /** Foto oficial del expediente (índice web, URL protegida); null → iniciales. */
        foto_url?: string | null;
    };
    fecha: string;
    hora: string | null;
    lugar: string | null;
    negativa_firma: boolean;
    documento_id: number | null;
    /** 'circunstanciada' = formato vigente; null = acta histórica. */
    formato: string | null;
    cerrada_en: string | null;
};

export type ActaDetalle = ActaResumen & {
    hechos: string;
    responsable_id: number | null;
    testigos: TestigoActa[];
    declaraciones: DeclaracionActa[];
    motivo_negativa: string | null;
    circunstanciada: ActaCircunstanciada | null;
    jefe_sugerido: { id: number; nombre: string; puesto: string | null } | null;
    seguimiento: { fecha: string; nota: string; user_id: number | null }[];
    anexos: {
        id: number;
        nombre: string;
        descripcion: string | null;
        fecha_identificacion: string | null;
        relacion_hechos: string | null;
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
    /** Siempre exactamente dos: el formato oficial exige dos testigos elegidos de un buscador, nunca texto libre. */
    testigos: {
        colaborador_id: number | null;
        nombre: string;
        puesto: string;
    }[];
    declaraciones: DeclaracionActa[];
};

export type OpcionActa = { value: string; label: string };

/** ActaService::catalogos(): catálogos cerrados del acta circunstanciada. */
export type CatalogosActa = {
    incidencias: OpcionActa[];
    caracteres: OpcionActa[];
    relaciones: OpcionActa[];
};

/** Ficha de una persona tal como está en PEOPLE (snapshot del backend). */
export type PersonaActa = {
    colaborador_id: number | null;
    nombre: string | null;
    puesto: string | null;
    area: string | null;
};

/** Lo guardado del acta administrativa circunstanciada (ActaService). */
export type ActaCircunstanciada = {
    disposiciones: string | null;
    incidencia: {
        tipo: string | null;
        otro: string | null;
        fecha_desde?: string | null;
        fecha_hasta?: string | null;
        hora_desde?: string | null;
        hora_hasta?: string | null;
        fecha_hechos: string | null;
        hora_hechos: string | null;
        lugar_hechos: string | null;
    };
    responsable: PersonaActa & {
        caracter: string | null;
        manifestacion: string | null;
    };
    jefe: PersonaActa & { participa: boolean; manifestacion: string | null };
    testigos_hechos: (PersonaActa & {
        relacion: string | null;
        relacion_otro: string | null;
        declaro: boolean;
        manifestacion: string | null;
    })[];
    testigos_asistencia: (PersonaActa & { manifestacion: string | null })[];
    trabajador: {
        manifesto: boolean | null;
        manifestacion: string | null;
        elementos: string | null;
    };
    incidencias_levantamiento: string | null;
    cierre: { fecha: string | null; hora: string | null };
    entrega: {
        estado: 'pendiente' | 'entregada' | 'no_posible';
        motivo?: string | null;
        por?: string | null;
        en?: string | null;
    };
    control: { fecha_integracion: string; integrado_por: string } | null;
};

/** Testigo en captura: id para el backend; nombre/puesto solo para mostrar. */
export type TestigoCaptura = {
    colaborador_id: number | null;
    nombre: string;
    puesto: string;
    manifestacion: string;
};

/** Captura por pasos del acta circunstanciada (ActaCircunstanciadaCaptura.vue). */
export type FormularioCircunstanciada = {
    fecha: string;
    hora: string;
    lugar: string;
    hechos: string;
    circunstanciada: {
        disposiciones: string;
        incidencia: {
            tipo: string;
            otro: string;
            fecha_desde: string;
            fecha_hasta: string;
            hora_desde: string;
            hora_hasta: string;
            lugar_hechos: string;
        };
        responsable: { caracter: string; manifestacion: string };
        jefe: {
            participa: boolean;
            colaborador_id: number | null;
            nombre: string;
            puesto: string;
            manifestacion: string;
        };
        testigos_hechos: (TestigoCaptura & {
            relacion: string;
            relacion_otro: string;
        })[];
        testigos_asistencia: TestigoCaptura[];
        trabajador: {
            manifesto: boolean | null;
            manifestacion: string;
            elementos: string;
        };
        incidencias_levantamiento: string;
        cierre: { fecha: string; hora: string };
    };
};

/** Qué puede hacer esta cuenta con el acta (ActaService::permisosDe, misma policy que cada endpoint). */
export type PermisosActa = {
    gestionar: boolean;
    editar: boolean;
    subir_anexo: boolean;
    registrar_firma: boolean;
    registrar_entrega: boolean;
    seguimiento: boolean;
    cerrar: boolean;
    descargar: boolean;
    descargar_anexo: boolean;
    generar_documento: boolean;
};
