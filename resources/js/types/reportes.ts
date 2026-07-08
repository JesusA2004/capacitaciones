export type SesionProximaItem = {
    id: number;
    titulo: string;
    fecha_inicio: string;
    duracion_minutos: number;
    leccion: { id: number; titulo: string } | null;
};

export type ResumenCumplimiento = {
    total_asignaciones: number;
    completadas: number;
    vencidas: number;
    porcentaje_cumplimiento: number;
};

export type CumplimientoSucursalItem = {
    sucursal_id: number;
    sucursal: string;
    total: number;
    completadas: number;
    porcentaje: number;
};

export type ColaboradorCumplimientoItem = {
    id: number;
    name: string;
    apellidos: string | null;
    sucursal_principal: { id: number; nombre: string } | null;
    departamento: { id: number; nombre: string } | null;
    asignaciones_total: number;
    asignaciones_completadas: number;
    asignaciones_vencidas: number;
};

export type GraficaCursosPorEstado = {
    completados: number;
    en_progreso: number;
    pendientes: number;
};

export type GraficaCumplimientoDepartamentoItem = {
    departamento_id: number;
    departamento: string;
    total: number;
    completadas: number;
    porcentaje: number;
};

/** Conteo por valor crudo de EstadoAsistencia (presente, ausente, ...). */
export type GraficaAsistenciaSesiones = Record<string, number>;

export type GraficaVideosCompletados = {
    completados: number;
    total: number;
};

export type GraficaCuestionarios = {
    aprobados: number;
    reprobados: number;
};

export type GraficaActividadesPendientes = {
    recientes: number;
    atrasadas: number;
    criticas: number;
};

export type GraficaEvolucionMensualItem = {
    mes: string;
    completados: number;
};

export type GraficaTopCursoItem = {
    curso_id: number;
    curso: string;
    porcentaje: number;
};

export type GraficaUsuarioPendienteCriticoItem = {
    id: number;
    nombre: string;
    vencidas: number;
};

/** Conjunto completo de gráficas del dashboard Global/Sucursal. */
export type GraficasOrganizacion = {
    cursosPorEstado: GraficaCursosPorEstado;
    cumplimientoPorDepartamento: GraficaCumplimientoDepartamentoItem[];
    colaboradoresActivos: number;
    calificacionPromedio: number;
    asistenciaSesiones: GraficaAsistenciaSesiones;
    videosCompletados: GraficaVideosCompletados;
    cuestionarios: GraficaCuestionarios;
    actividadesPendientes: GraficaActividadesPendientes;
    evolucionMensual: GraficaEvolucionMensualItem[];
    topCursosAvance: GraficaTopCursoItem[];
    cursosMayorAbandono: GraficaTopCursoItem[];
    usuariosPendientesCriticos: GraficaUsuarioPendienteCriticoItem[];
};

/** Subconjunto personal de gráficas del dashboard de Colaborador. */
export type GraficasColaborador = {
    cursosPorEstado: GraficaCursosPorEstado;
    calificacionPromedio: number;
    asistenciaSesiones: GraficaAsistenciaSesiones;
    videosCompletados: GraficaVideosCompletados;
};
