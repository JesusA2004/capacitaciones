/**
 * Plantilla autorizada vista desde el puesto (Puestos y salarios → ficha):
 * plazas por ubicación —sucursales y Corporativo— con ocupadas y vacantes
 * (App\Services\Headcount\PlantillaPuestoService).
 */
export type UbicacionPlantillaPuesto = {
    sucursal_id: number;
    sucursal: string;
    es_corporativo: boolean;
    autorizadas: number;
    ocupadas: number;
    vacantes: number;
    excedentes: number;
};

export type CambioPlantillaPuesto = {
    id: number;
    sucursal: string | null;
    valor_anterior: number | null;
    valor_nuevo: number;
    fuente: string;
    motivo: string | null;
    usuario: string | null;
    fecha: string;
};

export type PlantillaPuesto = {
    ubicaciones: UbicacionPlantillaPuesto[];
    totales: {
        autorizadas: number;
        ocupadas: number;
        vacantes: number;
        excedentes: number;
    };
    es_de_empresa: boolean;
    /** Como se muestra en Vacantes («Gestor fijo», «Gestor volante»…). */
    etiqueta_vacante: string;
    /** Puesto con el que comparte plazas (Gestor ↔ Gestor Volante). */
    comparte_con: string | null;
    ubicaciones_disponibles: {
        id: number;
        nombre: string;
        es_corporativo: boolean;
    }[];
    historial: CambioPlantillaPuesto[];
};

/** Plazas del puesto en el catálogo (todas sus ubicaciones). */
export type PlazasPuestoResumen = {
    autorizadas: number;
    ocupadas: number;
    vacantes: number;
};
