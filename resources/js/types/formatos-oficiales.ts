export type FormatoOficialItem = {
    id: number;
    slug: string;
    nombre: string;
    descripcion: string | null;
    tipo: string;
    tipo_etiqueta: string;
    aplica_a: 'colaborador' | 'candidato';
    empresa: string | null;
    file_type: string;
    lista: boolean;
    archivado: boolean;
    version_vigente: {
        id: number;
        numero: number;
        publicada_en: string | null;
        fidelidad: string;
        campos: number;
    } | null;
    borrador: number | null;
    veces_generado: number;
    ultimo_generado_at: string | null;
};

export type OpcionCatalogo = { value: string; etiqueta: string };

export type TipoCampoFormato = 'variable' | 'manual' | 'texto' | 'imagen';

export type CampoFormato = {
    id: string;
    tipo: TipoCampoFormato;
    variable: string | null;
    placeholder?: string | null;
    etiqueta: string | null;
    texto: string | null;
    pagina: number;
    x: number;
    y: number;
    ancho: number;
    alto: number;
    font_size: number;
    align: 'left' | 'center' | 'right';
    negrita: boolean;
    color: string;
    formato: string | null;
    max_caracteres: number | null;
    multilinea: boolean;
    requerido: boolean;
};

export type VariableFormato = {
    clave: string;
    etiqueta: string;
    grupo: string;
    tipo: 'texto' | 'fecha' | 'moneda' | 'numero' | 'imagen';
    contexto: string;
    sensible: boolean;
    sinonimos: string[];
    ejemplo: string;
    completar: string | null;
};

export type GrupoVariables = {
    clave: string;
    etiqueta: string;
    variables: VariableFormato[];
};

export type SugerenciaCampo = {
    id: string;
    etiqueta_detectada: string;
    variable: string;
    etiqueta_variable: string;
    pagina: number;
    x: number;
    y: number;
    ancho: number;
    alto: number;
    font_size: number;
    confianza: number;
    estado: 'seguro' | 'dudoso';
};

export type AnalisisFormato = {
    metodo: string | null;
    sugerencias: SugerenciaCampo[];
    placeholders: { placeholder: string; variable: string | null }[];
    mensajes: string[];
    posiciones_aproximadas: boolean;
    total_bloques: number;
    analizado_en: string | null;
};

export type PaginaFormato = { numero: number; ancho: number; alto: number };

export type VersionFormatoEditor = {
    id: number;
    numero: number;
    estado: 'borrador' | 'publicada' | 'retirada';
    estado_etiqueta: string;
    editable: boolean;
    estrategia: 'overlay' | 'docx_variables';
    file_type: string;
    fidelidad: string;
    paginas: PaginaFormato[];
    campos: CampoFormato[];
    analisis: AnalisisFormato;
    archivo_url: string;
    original_filename: string;
    hash: string | null;
    notas: string | null;
};

export type VersionFormatoResumen = {
    id: number;
    numero: number;
    estado: 'borrador' | 'publicada' | 'retirada';
    estado_etiqueta: string;
    creada_por: string | null;
    creada_en: string | null;
    publicada_por: string | null;
    publicada_en: string | null;
    archivo: string;
    hash: string | null;
    generaciones: number;
    notas: string | null;
};

export type PreparacionFormato = {
    version: number;
    puede_generar: boolean;
    motivo: string | null;
    faltantes: { variable: string; etiqueta: string; completar_url: string | null }[];
    manuales: { clave: string; etiqueta: string; requerido: boolean; valor: string }[];
    contextos_faltantes: string[];
    datos: { etiqueta: string; valor: string }[];
    contextos?: {
        usados: string[];
        opciones: Record<string, { id: number; label: string }[]>;
    };
    puede_guardar_en_expediente?: boolean;
    pdf_base64?: string;
};

export type GeneracionFormatoItem = {
    id: number;
    formato: string;
    categoria: string;
    version: number | null;
    persona: string | null;
    tipo_persona: 'colaborador' | 'candidato';
    colaborador_id: number | null;
    solicitud_folio: string | null;
    generado_por: string | null;
    generado_en: string | null;
    estado: string;
    en_expediente: boolean;
    checksum: string | null;
    ver_url: string;
    descargar_url: string;
};

export type PersonaDisponible = {
    id: number;
    name?: string;
    nombre?: string;
    apellidos: string | null;
};
