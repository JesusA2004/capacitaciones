/**
 * Editor visual NO-CODE de documentos maestros (Documentos maestros →
 * Editar). Contrato con App\Services\DocumentosMaestros\EditorMaestroService:
 * el backend detecta los espacios, RH asigna/confirma qué dato va en cada
 * uno, y el backend compila el mapa, prueba y activa. Nada de esto es JSON
 * visible para el usuario.
 */

export type ZonaCampo = 'dato' | 'firma' | 'huella' | 'testigo' | 'ignorar';

export type EstadoCampo = 'confirmado' | 'sugerido' | 'sin_asignar';

export type TransformacionCampo = 'mayusculas' | 'minusculas' | 'titulo';

export type PiezaExpresion =
    | {
          tipo: 'variable';
          clave: string;
          prefijo?: string;
          sufijo?: string;
          opcional?: boolean;
          transformar?: TransformacionCampo;
          formato?: 'moneda' | 'numero_letra';
      }
    | { tipo: 'texto'; valor: string; separador?: boolean };

export type ExpresionCampo = {
    partes: PiezaExpresion[];
    transformar?: TransformacionCampo;
};

export type SugerenciaVariable = {
    clave: string;
    etiqueta: string;
    confianza: number;
};

export type CampoEditor = {
    id: number | null;
    field_key: string;
    zona: ZonaCampo;
    estado: EstadoCampo;
    source_key: string | null;
    expression: ExpresionCampo | null;
    transform: TransformacionCampo | null;
    label: string | null;
    type: 'texto' | 'casilla';
    page: number | null;
    x: number | null;
    y: number | null;
    width: number | null;
    height: number | null;
    font_size: number | null;
    font_size_min: number | null;
    font_weight: 'bold' | null;
    text_align: 'L' | 'C' | 'R' | null;
    line_height: number | null;
    multiline: boolean;
    required: boolean;
    background_color: string | null;
    selector: Record<string, unknown> | null;
    /** Texto del documento junto al espacio («NOMBRE DEL TRABAJADOR:»). */
    contexto: string | null;
    sugerencias: SugerenciaVariable[];
    detection_method: string | null;
    /** Por qué volvió a «por confirmar» (p. ej. cambió el layout). */
    motivo: string | null;
    /** Razón por la que el dato puede quedar en blanco (vacío intencional). */
    motivo_blanco: string | null;
    bloqueado: boolean;
};

/** Marcatexto del original de Jurídico (resaltado o sombreado de texto). */
export type MarcaEditorial = {
    tipo: 'resaltado' | 'sombreado';
    color: string;
    texto: string;
    parte: string;
};

export type ProblemaCobertura = {
    field_key: string | null;
    etiqueta: string;
    contexto: string | null;
    mensaje: string;
};

/** Zonas del documento contra lo configurado (nada queda sin destino). */
export type CoberturaZonas = {
    zonas: number;
    listas: number;
    dinamicas: number;
    manuales: number;
    firmas: number;
    intencionales: number;
    sin_clasificar: number;
    problemas: ProblemaCobertura[];
    marcas: { detectadas: number; retiradas: number; decision: 'limpiar' | 'conservar' | null };
    mensaje: string;
};

export type VariableEditor = {
    clave: string;
    etiqueta: string;
    grupo: string;
    ejemplo: string;
    legal: boolean;
    tipo: string;
};

export type GrupoVariablesEditor = {
    clave: string;
    etiqueta: string;
    variables: VariableEditor[];
};

export type SegmentoDocx =
    | { tipo: 'texto'; texto: string }
    | { tipo: 'campo'; field_key: string; original: string }
    | { tipo: 'firma'; original: string };

export type ParrafoDocx = {
    referencia: string;
    segmentos: SegmentoDocx[];
    en_tabla: boolean;
};

export type ChequeoPreparacion = {
    clave: 'campos' | 'datos' | 'fuentes' | 'diseno' | 'prueba';
    etiqueta: string;
    ok: boolean;
    detalle: string | null;
};

export type PreparacionVersion = {
    porcentaje: number;
    chequeos: ChequeoPreparacion[];
    /** Lo que falta, en lenguaje humano («Falta: • fuente Aptos»). */
    faltan: string[];
    puede_activar: boolean;
    activo: boolean;
    cobertura: CoberturaZonas;
};

export type PaginaEditor = { numero: number; ancho: number; alto: number };

export type EditorMaestro = {
    master: {
        id: number;
        nombre: string;
        familia: string;
        version: number;
        motor: 'docx' | 'pdf_overlay';
        activo: boolean;
        editable: boolean;
        motivo_no_editable: string | null;
        aplica_a: string;
        proceso_etiqueta: string | null;
        tipo_archivo: 'docx' | 'pdf_texto' | 'pdf_escaneado' | null;
        aviso_deteccion: string | null;
        marcas: {
            detectadas: number;
            retiradas: number;
            decision: 'limpiar' | 'conservar' | null;
            lista: MarcaEditorial[];
        };
    };
    paginas: PaginaEditor[];
    url_fondo: string | null;
    parrafos: ParrafoDocx[];
    campos: CampoEditor[];
    variables: GrupoVariablesEditor[];
    preparacion: PreparacionVersion;
    versiones: {
        id: number;
        version: number;
        activo: boolean;
        estado_etiqueta: string;
    }[];
};

export type ResultadoDatosLargos = {
    ok: boolean;
    mensaje: string;
    campos: { field_key: string | null; etiqueta: string; razon: string }[];
    url_pdf: string | null;
};

/** Centro de salud documental (encabezado de Documentos maestros). */
export type SaludDocumental = {
    formatos: { total: number; listos: number; revision: number; bloqueados: number };
    infraestructura: {
        clave: string;
        etiqueta: string;
        estado: 'ok' | 'advertencia' | 'error' | 'no_instalado';
        detalle: string | null;
    }[];
};
