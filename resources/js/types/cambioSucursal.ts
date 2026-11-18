/**
 * «Cambio de sucursal» (CambioSucursalService): opciones, resumen antes de
 * confirmar y resultado.
 */
export type OpcionSucursalCambio = {
    id: number;
    nombre: string;
    es_corporativo: boolean;
    autorizadas: number;
    ocupadas: number;
    libres: number;
    /** Alguna de las personas ya está registrada aquí. */
    actual: boolean;
};

export type PersonaCambioSucursal = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
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

export type ResumenCambioSucursal = {
    personas: PersonaCambioSucursal[];
    plaza_destino: {
        sucursal_id: number;
        sucursal: string;
        puesto: string;
        autorizadas: number;
        ocupadas: number;
        libres: number;
        necesarias: number;
        faltan: number;
    };
    plazas_origen: { tono: string; texto: string }[];
    requiere_autorizar: boolean;
    puede_autorizar: boolean;
    bloqueo: string | null;
    avisos: string[];
    fecha: string;
    cambia_puesto: boolean;
};

export type VistaPreviaCambioSucursal = {
    puesto: { id: number; nombre: string };
    sucursales: OpcionSucursalCambio[];
    resumen: ResumenCambioSucursal | null;
};

export type ResultadoCambioSucursal = {
    sucursal: string;
    puesto: string;
    fecha: string;
    movidos: {
        id: number;
        nombre: string;
        de: string;
        a: string;
        jefe_nuevo: string | null;
    }[];
    plazas_autorizadas: number;
    rutas_liberadas: number;
};
