export type DocumentoPendienteItem = {
    id: number;
    colaborador: string | null;
    tipo: string;
    status: string;
    creado_en: string | null;
};

export type DashboardColaboradorProps = {
    miExpediente: { porcentaje: number; pendientes: number };
    misDocumentosPendientes: DocumentoPendienteItem[];
    misVacaciones: { dias_disponibles: number };
    misSolicitudes: { pendientes: number };
    avisosPendientes: { disponible: false };
};
