/**
 * Organigrama editable (Administración → Organigrama, sección General):
 * lado Q1/Q3, cobertura temporal y subir/bajar/mover. Ver docs/ORGANIGRAMA.md.
 */

/** Q1 / Q3: regiones de la matriz comercial (nodos_comerciales tipo región). */
export type RegionOrganigrama = {
    id: number;
    nombre: string;
    codigo: string;
};

/**
 * Lado de un Gerente de Sucursal. `por_clasificar`: RH aún no lo clasifica;
 * su jefe sale provisionalmente de la región de su sucursal (`sugerida`).
 */
export type ClasificacionRegionNodo = {
    estado: 'clasificado' | 'por_clasificar';
    region: { id: number; nombre: string } | null;
    sugerida: { id: number; nombre: string } | null;
    clasificada_por: string | null;
    clasificada_en: string | null;
};

export type OpcionPuestoOrganigrama = {
    id: number;
    nombre: string;
    ambito: 'sucursal' | 'region' | 'corporativo' | string;
    clasificable: boolean;
};

export type DestinoMoverBajo = {
    clave: string;
    etiqueta: string;
    puesto_id: number;
    sucursal_id: number | null;
    region_id: number | null;
};

/** GET …/personas/{colaborador}/opciones */
export type OpcionesMovimientoOrganigrama = {
    subir: OpcionPuestoOrganigrama[];
    bajar: OpcionPuestoOrganigrama[];
    mover_bajo: DestinoMoverBajo[];
    sucursales: { id: number; nombre: string }[];
    regiones: RegionOrganigrama[];
    actual: {
        puesto_id: number | null;
        sucursal_id: number | null;
        region_id: number | null;
    };
};

/** POST …/personas/{colaborador}/vista-previa */
export type VistaPreviaMovimientoOrganigrama = {
    descripcion: string;
    de: string;
    a: string;
    jefe_actual: string | null;
    jefe_nuevo: string | null;
    avisos: string[];
    sin_cambios: boolean;
};

export type AccionMovimientoOrganigrama =
    'subir' | 'bajar' | 'mover' | 'traspaso';

/** GET …/jerarquia-puestos/traspaso/opciones («Cambiar de puesto (traspaso)»). */
export type OpcionesTraspasoPuesto = {
    puestos: {
        id: number;
        nombre: string;
        ambito: 'sucursal' | 'region' | 'corporativo' | string;
        departamento: string | null;
    }[];
    sucursales: { id: number; nombre: string; es_corporativo: boolean }[];
    colaboradores: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        puesto_id: number | null;
        puesto: string | null;
        sucursal_id: number | null;
        sucursal: string | null;
        departamento: string | null;
    }[];
    maximo: number;
};

export type PersonaTraspasoPrevia = {
    id: number;
    nombre: string;
    de: string;
    a: string;
    departamento_de: string | null;
    departamento_a: string | null;
    jefe_actual: string | null;
    jefe_nuevo: string | null;
    tipo_movimiento: string;
    sin_cambios: boolean;
    rutas: string[];
};

/** POST …/jerarquia-puestos/traspaso/vista-previa */
export type VistaPreviaTraspasoPuesto = {
    descripcion: string;
    personas: PersonaTraspasoPrevia[];
    headcount: {
        tono: 'vacante' | 'ocupa' | 'fuera' | 'info';
        texto: string;
    }[];
    avisos: string[];
    sin_cambios: boolean;
};

/** Lámina Dirección Comercial: Q1 / Q3 en una línea. */
export type LadosComerciales = {
    regiones: {
        id: number;
        nombre: string;
        regional: string | null;
        cubre: string | null;
        gerentes: number;
    }[];
    por_clasificar: {
        id: number;
        nombre: string;
        sucursal: string | null;
        sugerida: string | null;
    }[];
};
