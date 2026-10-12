/**
 * Tipos de la Guía maestra de MR. LANA PEOPLE (página /ayuda y
 * docs/guias/*). Este archivo y los de datos (procesos.ts, articulos.ts,
 * flujo.ts, roles.ts, glosario.ts, preguntas.ts, video.ts) son la ÚNICA
 * fuente del contenido: la web los pinta y `npm run guia:docs` genera los
 * Markdown del repositorio a partir de ellos. Por eso los archivos de datos
 * solo usan `import type` (Node los ejecuta quitando los tipos, sin
 * compilar) y nunca importan componentes ni íconos de Vue: el ícono se
 * guarda como nombre y la pantalla lo resuelve (`iconosGuia.ts`).
 */

/** Roles que reconoce la guía (no son los roles técnicos de Spatie). */
export type RolGuiaId =
    | 'rh'
    | 'gerente'
    | 'regional'
    | 'direccion_comercial'
    | 'sistemas'
    | 'colaborador';

/** Dónde se hace algo: pantalla web, app móvil o ambas. */
export type CanalGuia = 'web' | 'app' | 'web_app' | 'automatico';

/** Enlace real a una pantalla del sistema ("[Ir a Vacantes]"). */
export type AccesoGuia = {
    etiqueta: string;
    /** Ruta GET real (sin parámetros); la prueba valida que exista. */
    ruta: string;
    /** Basta uno para mostrar el botón (sin permisos = cualquiera). */
    permisos?: string[];
    /** Modo de navegación donde vive la pantalla. */
    modo?: 'operativo' | 'colaborador';
};

export type PasoArticulo = {
    titulo: string;
    detalle: string;
    /** Quién hace este paso, si no es el mismo de todo el artículo. */
    quien?: RolGuiaId;
    canal?: CanalGuia;
};

export type Llamado = {
    tono: 'info' | 'aviso' | 'importante';
    texto: string;
};

/**
 * Artículo de la guía: QUÉ ES · QUIÉN LO HACE · ANTES DE EMPEZAR · PASOS ·
 * QUÉ PASA DESPUÉS · ERRORES COMUNES · RELACIONADO CON + accesos reales.
 */
export type ArticuloGuia = {
    id: string;
    titulo: string;
    proceso: ProcesoGuiaId;
    resumen: string;
    queEs: string;
    quien: RolGuiaId[];
    /** Detalle en palabras de quién participa (cuando hay varios). */
    quienDetalle?: string;
    antes: string[];
    pasos: PasoArticulo[];
    despues: string[];
    errores: { problema: string; solucion: string }[];
    relacionados: string[];
    accesos: AccesoGuia[];
    /** Datos que se capturan, tal como los muestra la pantalla real. */
    campos?: { grupo: string; campos: string[] }[];
    /** Recuadros destacados (reglas que no hay que olvidar). */
    llamados?: Llamado[];
    /** Qué notificaciones y avisos dispara. */
    notificaciones?: string[];
    /** Qué documentos se generan. */
    documentos?: string[];
    /** Qué queda registrado (historial, bitácora, expediente). */
    registro?: string[];
    /** Frases con que la gente lo busca: «¿Cómo genero un permiso?». */
    preguntas: string[];
    /** Recorrido interactivo relacionado (id de recorridosProceso). */
    recorrido?: string;
};

export type ProcesoGuiaId =
    | 'reclutamiento'
    | 'contratacion'
    | 'alta'
    | 'expediente'
    | 'contratos'
    | 'onboarding'
    | 'evaluacion'
    | 'solicitudes'
    | 'nomina'
    | 'avisos'
    | 'vida-laboral'
    | 'baja'
    | 'reingresos'
    | 'administracion';

export type ProcesoGuia = {
    id: ProcesoGuiaId;
    numero: number;
    titulo: string;
    /** Nombre de ícono de lucide (ver iconosGuia.ts). */
    icono: string;
    resumen: string;
    /** Cuánto toma normalmente, en palabras. */
    tiempo: string;
    roles: RolGuiaId[];
    /** Artículos en el orden en que se leen. */
    articulos: string[];
};

/** Un bloque del diagrama general (PLANTILLA → … → CONTRATO INDETERMINADO). */
export type BloqueFlujo = {
    id: string;
    titulo: string;
    etapa:
        | 'Reclutamiento'
        | 'Contratación'
        | 'Incorporación'
        | 'Periodo de prueba';
    quien: RolGuiaId[];
    canal: CanalGuia;
    resumen: string;
    articulo: string;
};

export type RolGuia = {
    id: RolGuiaId;
    nombre: string;
    /** Roles técnicos del sistema que caen en esta guía. */
    rolesSistema: string[];
    icono: string;
    resumen: string;
    /** Lo que NO le toca (para evitar confusiones). */
    noLeToca: string[];
    checklist: { texto: string; articulo: string }[];
    /** Dónde trabaja: web, app o ambas. */
    dondeTrabaja: string;
};

export type TerminoGlosario = {
    termino: string;
    definicion: string;
    articulo?: string;
};

export type PreguntaFrecuente = {
    pregunta: string;
    respuesta: string;
    articulo?: string;
};

/** Escena del video tutorial (storyboard + guion + subtítulos). */
export type EscenaVideo = {
    numero: number;
    capitulo: string;
    /** Segundos desde el inicio. */
    inicio: number;
    fin: number;
    pantalla: string;
    /** Ruta real que se graba (sin parámetros o con :parametro). */
    ruta: string | null;
    /** Archivo de captura que la representa (CAPTURAS_NECESARIAS). */
    captura: string;
    accion: string;
    zoom: string;
    textoPantalla: string;
    narracion: string;
    transicion: string;
};

export type MiniVideo = {
    id: string;
    titulo: string;
    duracion: string;
    articulo: string;
    /** `captura`: imagen real de docs/guias/video/capturas con la que se arma. */
    escenas: {
        captura: string;
        pantalla: string;
        accion: string;
        narracion: string;
    }[];
};
