export type TipoCampoReporte = 'texto' | 'numero' | 'moneda' | 'fecha';

export type CampoReporte = {
    clave: string;
    etiqueta: string;
    categoria: string;
    tipo: TipoCampoReporte;
    restringido: boolean;
};

export type FuenteReporte = {
    clave: string;
    etiqueta: string;
    campos: CampoReporte[];
};

export type FiltroReporte = {
    campo: string;
    operador: string;
    valor: string | string[] | null;
};

export type OrdenReporte = {
    campo: string;
    direccion: 'asc' | 'desc';
};

export type DisenoReporte = {
    titulo: string;
    subtitulo: string;
    orientacion: 'landscape' | 'portrait';
    tamano: 'letter' | 'a4' | 'legal';
    color: string;
    encabezado: boolean;
    mostrar_filtros: boolean;
    totales: boolean;
    ajuste: 'compacto' | 'normal' | 'amplio';
    grafica: {
        activa: boolean;
        tipo: 'barras' | 'pastel' | 'lineas';
        campo: string;
        valor: string;
    };
};

export type DefinicionReporte = {
    fuente: string;
    columnas: string[];
    filtros: FiltroReporte[];
    orden: OrdenReporte[];
    diseno: DisenoReporte;
};

export type ReporteGuardadoResumen = {
    id: number;
    nombre: string;
    fuente: string;
    updated_at: string | null;
};

export type ReporteGuardadoDetalle = {
    id: number;
    nombre: string;
    fuente: string;
    definicion: DefinicionReporte;
};

export type ColorReporte = { clave: string; etiqueta: string; hex: string };

export type VistaPreviaReporte = {
    titulo: string;
    columnas: { clave: string; etiqueta: string; tipo: TipoCampoReporte }[];
    filas: (string | number | null)[][];
    total: number;
    limite: number;
    totales: (string | number | null)[] | null;
    grafica: {
        tipo: string;
        titulo: string;
        nombre: string;
        categorias: string[];
        valores: number[];
    } | null;
    omitidas: string[];
    avisos: string[];
    filtros_texto: string[];
};
