// Bandeja «Pendientes de alta en sistemas»
// (App\Services\Sistemas\PendientesAltaSistemasService::fila).

export type FiltroPendientesAlta = 'pendientes' | 'atendidos' | 'todos';

export type EstadoCorreoAltaSistemas =
    'pendiente' | 'enviado' | 'fallido' | 'sin_enviar' | 'esperando_datos';

export type DatoAltaSistemas = {
    etiqueta: string;
    valor: string;
    falta: boolean;
};

export type SeccionAltaSistemas = {
    clave: 'persona' | 'contacto' | 'puesto';
    titulo: string;
    datos: DatoAltaSistemas[];
};

export type PendienteAltaSistemas = {
    id: number;
    colaborador: {
        id: number;
        nombre: string;
        /** Foto oficial (URL protegida) si existe y está en tu alcance. */
        foto_url: string | null;
        numero_empleado: string | null;
        puesto: string | null;
        sucursal: string | null;
        departamento: string | null;
        fecha_ingreso: string | null;
        url_expediente: string | null;
    };
    origen: string;
    correo: {
        estado: EstadoCorreoAltaSistemas;
        etiqueta: string;
        enviado_en: string | null;
        error: string | null;
    };
    creado_en: string | null;
    dias: number;
    atendido: { en: string | null; por: string; notas: string | null } | null;
    tipo_ingreso: string;
    secciones: SeccionAltaSistemas[];
    url_detalle: string;
    texto_copiar: string;
    puede_atender: boolean;
    puede_reenviar: boolean;
};

export type TotalesPendientesAlta = {
    pendientes: number;
    atendidos: number;
    atrasados: number;
};
