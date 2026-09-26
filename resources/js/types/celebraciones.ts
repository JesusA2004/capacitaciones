/**
 * Celebraciones (cumpleaños y aniversarios laborales) — docs/CELEBRACIONES.md.
 *
 * El backend entrega SIEMPRE esta misma forma para ambos tipos
 * (App\Services\Celebraciones\CelebracionService::filasCumpleanos() /
 * filasAniversarios()), así los componentes de
 * resources/js/components/Celebraciones solo presentan y nunca conocen
 * reglas de negocio (edad, 29/feb, años de servicio…).
 */
export type TipoCelebracion = 'cumpleanos' | 'aniversario_laboral';

export type EventoCelebracion = {
    colaborador_id: number;
    nombre: string;
    puesto: string | null;
    sucursal: string | null;
    departamento: string | null;
    foto_url: string | null;
    /** Fecha de la celebración (Y-m-d, America/Mexico_City). */
    fecha: string;
    es_hoy: boolean;
    anios: number | null;
    /** Texto listo para mostrar: «Cumple 34 años» / «6 años en MR. LANA». */
    detalle: string | null;
    celebracion_id: number | null;
    enviada_at: string | null;
    avisada_todos_at: string | null;
};

export type OpcionCelebracion = { id: number; nombre: string };

export type FiltrosCelebracion = {
    busqueda: string;
    sucursal_id: string;
    departamento_id: string;
    empresa_id: string;
    colaborador_id: string;
    estatus: string;
};

export type PermisosCelebracion = {
    /** Generar / regenerar y ver la tarjeta. */
    gestionar: boolean;
    descargar: boolean;
    /** Enviar al colaborador y avisar a todos. */
    enviar: boolean;
    calendario: boolean;
};
