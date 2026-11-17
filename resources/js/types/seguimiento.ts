/**
 * Seguimiento (Fase 2, docs/SEGUIMIENTO.md): datos duros de Reportes por
 * sucursal y por gestor. Forma que arma App\Services\Seguimiento\*.
 */

export type MesSeguimiento = {
    id: number;
    codigo: string;
    etiqueta: string;
    inicio: string | null;
    fin: string | null;
    /** Reportes ya generó la radiografía de ese mes. */
    radiografia: boolean;
    semanas_ids: number[];
};

export type SemanaSeguimiento = {
    id: number;
    indice: number;
    /** «Semana 1»…«Semana 4» dentro del mes. */
    etiqueta: string;
    /** Nombre de la semana en Reportes («Semana 22 - Mayo 2026»). */
    nombre: string;
    inicio: string | null;
    fin: string | null;
};

export type PeriodoSeguimiento = {
    meses: MesSeguimiento[];
    mes: MesSeguimiento | null;
};

export type ValorSemana = {
    semana_id: number;
    colocacion: number;
    operaciones: number;
    recuperacion: number;
};

export type FilaSucursalSeguimiento = {
    id: number;
    nombre: string;
    branches: string[];
    colocacion: number | null;
    operaciones: number | null;
    recuperacion: number | null;
    cartera: number | null;
    vencida: number | null;
    mora: number | null;
    gestores: number;
    semanas: ValorSemana[];
    mes_oficial: boolean;
};

export type TotalesSeguimiento = {
    sucursales: number;
    colocacion: number | null;
    operaciones: number | null;
    recuperacion: number | null;
    cartera: number | null;
    vencida: number | null;
    mora: number | null;
    gestores: number;
};

export type TableroSucursales = {
    estado: 'ok' | 'sin_datos';
    mensaje: string | null;
    calculado_en: string | null;
    semanas: SemanaSeguimiento[];
    semana_id: number | null;
    totales: TotalesSeguimiento;
    serie_semanal: {
        semana_id: number;
        etiqueta: string;
        colocacion: number;
        recuperacion: number;
    }[];
    sucursales: FilaSucursalSeguimiento[];
    sin_vincular: {
        branches: number;
        nombres: string[];
        colocacion: number;
        recuperacion: number;
    } | null;
};

export type FilaGestorSeguimiento = {
    reportes_id: number;
    nombre: string;
    puesto: string | null;
    colaborador_id: number | null;
    vinculado: boolean;
    sucursal_id: number | null;
    sucursal: string | null;
    branch: string | null;
    colocacion: number | null;
    operaciones: number | null;
    recuperacion: number | null;
    cartera: number | null;
    vencida: number | null;
    mora: number | null;
    semanas: ValorSemana[];
};

export type TableroGestores = {
    estado: 'ok' | 'sin_datos';
    mensaje: string | null;
    calculado_en: string | null;
    semanas: SemanaSeguimiento[];
    semana_id: number | null;
    gestores: FilaGestorSeguimiento[];
    opciones_sucursal: { value: number; label: string }[];
    totales: {
        gestores: number;
        sin_vincular: number;
        colocacion: number;
        recuperacion: number;
        cartera: number;
        vencida: number;
    };
};

export type DetalleGestor = {
    gestor: {
        reportes_id: number;
        nombre: string;
        nombre_reportes: string | null;
        puesto: string | null;
        sucursal: string | null;
        branch: string | null;
        colaborador_id: number | null;
        vinculado: boolean;
    };
    mes: {
        colocacion: number | null;
        operaciones: number | null;
        recuperacion: number | null;
        cartera: number | null;
        vencida: number | null;
        mora: number | null;
    };
    semanal: {
        semana_id: number;
        etiqueta: string;
        mes: string;
        sin_datos: boolean;
        colocacion: number;
        operaciones: number;
        recuperacion: number;
    }[];
    mensual: {
        mes_id: number | null;
        etiqueta: string;
        estado: string;
        colocacion: number | null;
        recuperacion: number | null;
        cartera: number | null;
        vencida: number | null;
        mora: number | null;
    }[];
    con_actividad: boolean;
};

/** Respuesta de una lectura de Reportes: datos o el motivo de la falla. */
export type RespuestaSeguimiento<T> = {
    datos: T | null;
    error: string | null;
};

export type FiltrosSeguimiento = {
    mes: number | null;
    semana: number | null;
};

type DatosVinculo = {
    id: number;
    metodo: string;
    vinculado_por: string | null;
    vinculado_en: string | null;
};

/** Persona de Reportes (todos sus registros/rutas) ↔ colaborador de PEOPLE. */
export type VinculoPersona = {
    reportes_id: number;
    registros: number;
    nombre: string;
    variantes: string[];
    claves: string[];
    puesto: string | null;
    sucursal: string | null;
    sucursal_people: string | null;
    rutas: string[];
    con_datos: boolean;
    vinculo: (DatosVinculo & { colaborador_id: number | null; colaborador: string | null }) | null;
    conflicto: boolean;
    sugerencia: { colaborador_id: number; colaborador: string | null; motivo: string | null } | null;
    homonimos: boolean;
};

/** Sucursal de Reportes (con TODAS sus rutas) ↔ sucursal de PEOPLE. */
export type VinculoSucursalReportes = {
    codigo: string;
    nombre: string;
    sin_sucursal_en_reportes: boolean;
    rutas: { id: number; nombre: string; sucursal_id: number | null; con_datos: boolean }[];
    con_datos: boolean;
    vinculo: (DatosVinculo & { sucursal_id: number; sucursal: string; rutas_vinculadas: number }) | null;
    mixta: boolean;
    sugerencia: { sucursal_id: number; sucursal: string } | null;
};

export type PantallaVinculos = {
    mes: string | null;
    personas: VinculoPersona[];
    no_personas: { reportes_id: number; nombre: string; motivo: string; sucursal: string | null }[];
    sucursales: VinculoSucursalReportes[];
    opciones_sucursales: { value: number; label: string }[];
    con_datos_disponible: boolean;
    actualizado_en: string;
    totales: {
        personas: number;
        personas_vinculadas: number;
        sucursales: number;
        sucursales_vinculadas: number;
        rutas: number;
        sugerencias_personas: number;
        sugerencias_sucursales: number;
        no_personas: number;
    };
};
