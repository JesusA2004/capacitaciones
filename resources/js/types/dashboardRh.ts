export type TarjetaDisponible = {
    valor: number;
    disponible: false;
};

export type DashboardRhCards = {
    colaboradores_activos: number;
    altas_en_proceso: TarjetaDisponible;
    bajas_del_mes: number;
    expedientes_completos: number;
    expedientes_incompletos: number;
    documentos_pendientes: number;
    solicitudes_pendientes: TarjetaDisponible;
    vacaciones_pendientes: TarjetaDisponible;
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

export type DashboardRhProps = {
    cards: DashboardRhCards;
    graficas: DashboardRhGraficas;
    proximosAniversarios: AniversarioItem[];
    documentosPendientesRevision: DocumentoPendienteItem[];
    alertas: AlertaRh[];
};

export type DashboardColaboradorProps = {
    miExpediente: { porcentaje: number; pendientes: number };
    misDocumentosPendientes: DocumentoPendienteItem[];
    misVacaciones: { disponible: false };
    misSolicitudes: { disponible: false };
    avisosPendientes: { disponible: false };
};
