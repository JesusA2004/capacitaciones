export type TipoAgenda = 'entrevista' | 'socioeconomico';
export type EstadoAgenda =
    'programado' | 'realizado' | 'no_asistio' | 'reprogramado' | 'cancelado';
export type VistaAgenda = 'semana' | 'mes' | 'lista';

export type AgendaEvento = {
    id: number;
    tipo: TipoAgenda;
    tipo_etiqueta: string;
    fecha_hora: string;
    fecha: string;
    hora: string;
    estado: EstadoAgenda;
    estado_etiqueta: string;
    origen: 'reclutamiento' | 'gerente';
    origen_etiqueta: string;
    agendado_por: string | null;
    sucursal: { id: number; nombre: string };
    candidato: {
        id: number;
        nombre: string;
        puesto: string | null;
        estado: string;
        estado_etiqueta: string;
        telefono: string | null;
        tiene_cv: boolean | null;
    };
    domicilio: string | null;
    notas: string | null;
    resultado: string | null;
    resultado_etiqueta: string | null;
    motivo_no_viable: string | null;
    motivo_no_viable_etiqueta: string | null;
    comentario_resultado: string | null;
    resultado_en: string | null;
    motivo_cancelacion: string | null;
    reprogramado_de_id: number | null;
    reprogramado_a_id: number | null;
    puede: {
        gestionar: boolean;
        registrar_resultado: boolean;
        ver_datos_candidato: boolean;
        reprogramar: boolean;
        cancelar: boolean;
        capturar: boolean;
    };
    historial:
        | {
              accion: string;
              estado_nuevo: string | null;
              detalle: string | null;
              usuario: string | null;
              fecha: string | null;
          }[]
        | null;
};

export type AgendaIndicadores = {
    agendadas: number;
    realizadas: number;
    no_asistio: number;
    viables: number;
    socioeconomicos_agendados: number;
    socioeconomicos_realizados: number;
    pct_realizadas: number | null;
    pct_inasistencia: number | null;
    pct_viabilidad: number | null;
    pct_reclutamiento: number | null;
    agendadas_reclutamiento: number;
    dias_cita_resultado: number | null;
};

export type AgendaRecord = AgendaIndicadores & {
    sucursal_id: number;
    sucursal: string;
    agendadas_mes_anterior: number;
};

export type AgendaFiltros = {
    vista: VistaAgenda;
    ancla: string;
    desde: string;
    hasta: string;
    mes_desde: string;
    mes_hasta: string;
    sucursal_id: number | null;
    tipo: TipoAgenda | null;
    estado: EstadoAgenda | null;
    busqueda: string | null;
};
