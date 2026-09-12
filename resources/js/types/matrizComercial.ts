export type CoberturaNodoComercial =
    | 'no_aplica'
    | 'inactiva'
    | 'cubierta'
    | 'sin_cubrir';

export type NodoComercialArbol = {
    id: number;
    tipo: 'matriz' | 'region' | 'zona' | 'ruta' | 'sucursal' | 'gerencia' | 'subgerencia';
    nombre: string;
    activa: boolean;
    region: string | null;
    sucursal: { id: number; nombre: string } | null;
    responsable: { id: number; nombre: string } | null;
    estado_operativo: 'vencidos' | 'castigo' | null;
    cobertura: CoberturaNodoComercial;
    apoyos: { id: number; nombre: string }[];
    volantes: { id: number; nombre: string }[];
    hijos: NodoComercialArbol[];
};

export type ResumenMatrizComercial = {
    total_rutas: number;
    activas: number;
    inactivas: number;
    cubiertas: number;
    sin_cubrir: number;
    porcentaje_cobertura: number;
};

export type GestorDisponible = {
    id: number;
    name: string;
    apellidos: string | null;
};
