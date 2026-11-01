/** Contrato de App\Services\Flujos\Dto\SiguientePaso (web y API). */
export type EnlaceFlujo = {
    clave: string;
    etiqueta: string;
    href: string;
    descarga: boolean;
};

export type AccionFlujo = {
    clave: string;
    etiqueta: string;
    modo: 'navegar' | 'dialogo';
    href: string | null;
    solo_web: boolean;
    descarga: boolean;
};

export type SiguientePaso = {
    flujo: 'contratacion' | 'permanencia' | 'baja' | 'sueldo';
    modo: 'lineal' | 'hub';
    estado: { clave: string; etiqueta: string };
    tipo: 'accion' | 'espera' | 'terminado';
    accion: AccionFlujo | null;
    mensaje: string;
    responsable: { rol: string; nombre: string | null };
    progreso: number | null;
    pasos: {
        clave: string;
        etiqueta: string;
        estado: 'completado' | 'actual' | 'pendiente' | 'detenido';
    }[];
    bloqueos: string[];
    relacionadas: EnlaceFlujo[];
};
