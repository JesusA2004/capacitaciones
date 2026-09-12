export type DashboardRhCards = {
    colaboradores_activos: number;
    altas_en_proceso: number;
    bajas_del_mes: number;
    expedientes_completos: number;
    expedientes_incompletos: number;
    documentos_pendientes: number;
    solicitudes_pendientes: number;
    vacaciones_pendientes: number;
    vacantes_disponibles: number;
    plazas_automaticas: number;
    candidatos_activos: number;
    rutas_cubiertas: number;
    rutas_sin_cubrir: number;
    cumpleanos_proximos: number;
};

export type PuntoConteo = {
    etiqueta: string;
    valor: number;
};

export type PuntoConteoClave = {
    clave: string;
    etiqueta: string;
    valor: number;
};

export type DashboardRhGraficas = {
    colaboradoresPorEmpresa: PuntoConteo[];
    colaboradoresPorSucursal: PuntoConteo[];
    colaboradoresPorDepartamento: PuntoConteo[];
    colaboradoresPorPuesto: PuntoConteo[];
    expedientesEstado: PuntoConteoClave[];
    documentosPorEstado: PuntoConteoClave[];
    vacantesPorPuesto: PuntoConteo[];
    solicitudesPorEstado: PuntoConteoClave[];
    candidatosPorEtapa: PuntoConteo[];
    coberturaRutas: PuntoConteoClave[];
};

export type AniversarioItem = {
    id: number;
    nombre: string;
    fecha: string;
    dias: number;
    anios: number;
};

export type DocumentoPendienteItem = {
    id: number;
    colaborador: string | null;
    tipo: string;
    status: string;
    creado_en: string | null;
};

export type AlertaRh = {
    tono: 'warning' | 'danger' | 'info';
    mensaje: string;
};

export type EficienciaHeadcount = {
    plantilla_autorizada: number;
    plantilla_actual: number;
    vacantes: number;
    cumplimiento: number;
    sucursales_bajo_cobertura: number;
};

export type TendenciaMesRotacion = {
    mes: string;
    altas: number;
    bajas: number;
};

export type RotacionPersonalData = {
    periodo: { desde: string; hasta: string };
    plantilla_actual: number;
    altas: number;
    bajas: number;
    rotacion_porcentaje: number;
    eficiencia: EficienciaHeadcount;
    genero: PuntoConteo[];
    altasPorSucursal: PuntoConteo[];
    bajasPorSucursal: PuntoConteo[];
    tendenciaMensual: TendenciaMesRotacion[];
    porDepartamento: PuntoConteo[];
};

export type SucursalFiltro = { id: number; nombre: string };
export type DepartamentoFiltro = { id: number; nombre: string };

export type DashboardRhProps = {
    cards: DashboardRhCards;
    graficas: DashboardRhGraficas;
    proximosAniversarios: AniversarioItem[];
    documentosPendientesRevision: DocumentoPendienteItem[];
    alertas: AlertaRh[];
    rotacion: RotacionPersonalData;
    sucursalesFiltro: SucursalFiltro[];
    departamentosFiltro: DepartamentoFiltro[];
};

export type DashboardColaboradorProps = {
    miExpediente: { porcentaje: number; pendientes: number };
    misDocumentosPendientes: DocumentoPendienteItem[];
    misVacaciones: { dias_disponibles: number };
    misSolicitudes: { pendientes: number };
    avisosPendientes: { disponible: false };
};
