/**
 * Responsivas de las tres familias oficiales (bien mueble, equipo electrónico,
 * vehículo). Ver App\Http\Controllers\Rh\ResponsivaController.
 */
export type EstadoResponsiva =
    'generada' | 'firmada' | 'devuelta' | 'cancelada';

export type OpcionResponsiva = { value: string; label: string };

export type ResponsivaItem = {
    id: number;
    folio: string | null;
    tipo: string;
    tipo_etiqueta: string;
    fecha_entrega: string;
    lugar: string;
    estado: EstadoResponsiva;
    estado_etiqueta: string;
    /** Responsable de entrega: quien realmente entregó el bien. */
    entregado_por: string;
    entregado_por_puesto: string | null;
    /** Quien capturó la responsiva en PEOPLE (puede diferir del anterior). */
    registrado_por: string | null;
    observaciones: string | null;
    /** Chips descriptivos de lo entregado (según la familia). */
    resumen: string[];
    tiene_pdf: boolean;
    /** LEGACY: escaneo firmado histórico (solo lectura, ya no se sube). */
    tiene_firmada: boolean;
    firmada_subida_en: string | null;
    /** Firma física sin archivo (flujo vigente). */
    firmada_fisicamente_en: string | null;
    devuelta_en: string | null;
    observaciones_devolucion: string | null;
    motivo_cancelacion: string | null;
    puede_operar: boolean;
    puede_registrar_firma: boolean;
    puede_registrar_devolucion: boolean;
    puede_descargar: boolean;
    puede_cancelar: boolean;
};

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
        area: string | null;
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

/** Catálogos (enums) que alimentan los selectores por familia. */
export type CatalogosResponsiva = {
    estados_fisicos: OpcionResponsiva[];
    tipos_mueble: OpcionResponsiva[];
    tipos_equipo: OpcionResponsiva[];
    accesorios_equipo: OpcionResponsiva[];
    niveles_combustible: OpcionResponsiva[];
    estados_conservacion: OpcionResponsiva[];
    accesorios_vehiculo: OpcionResponsiva[];
};

export type ResponsivasColaboradorRespuesta = {
    responsivas: ResponsivaItem[];
    puede_generar: boolean;
    valores_iniciales: ValoresInicialesResponsiva | null;
    tipos: OpcionResponsiva[];
    catalogos: CatalogosResponsiva;
};

/**
 * Campos capturables de las tres familias en un solo objeto (el formulario
 * limpia y reinicia este bloque al cambiar de familia; el backend solo valida
 * y guarda las claves de la familia elegida).
 */
export type DatosResponsivaForm = {
    // Bien mueble
    tipo_mueble: string;
    tipo_mueble_otro: string;
    numero_inventario: string;
    material: string;
    tamano: string;
    ubicacion_lugar: string;
    ubicacion_area: string;
    // Equipo electrónico
    tipo_equipo: string;
    tipo_equipo_otro: string;
    imei1: string;
    imei2: string;
    numero_telefonico: string;
    // Vehículo
    submarca: string;
    modelo_anio: string;
    niv: string;
    numero_motor: string;
    placas: string;
    kilometraje: string;
    combustible: string;
    estado_exterior: string;
    estado_interior: string;
    // Compartidos
    marca: string;
    modelo: string;
    numero_serie: string;
    color: string;
    estado: string;
    danos: string;
    accesorios: string[];
    accesorios_otro: string;
};

export type ResponsivaForm = {
    tipo: string;
    lugar: string;
    fecha_entrega: string;
    responsable_entrega_id: number;
    observaciones: string;
    datos: DatosResponsivaForm;
};
