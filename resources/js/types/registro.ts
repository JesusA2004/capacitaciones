/**
 * Registro por QR en la web (Registro/Asistente): espejo de
 * App\Services\Incorporacion\RegistroAppService::estadoWeb() — el mismo
 * contrato que la app recibe en GET api/v1/colaborador/registro.
 */
export interface CampoRegistro {
    campo: string;
    etiqueta: string;
    /** text | date | tel | email | number | textarea | opciones */
    tipo: string;
    opciones?: { value: string; label: string }[];
    /** Valor vigente (null = vacío). En datos médicos solo indica «registrado». */
    actual: string | null;
    actual_legible: string | null;
    obligatorio: boolean;
    /** Ya lo registró RH: no se cambia aquí (se pide corrección después). */
    bloqueado: boolean;
}

export interface GeneradoPorSistemaRegistro {
    estado: 'escaneo' | 'generado' | 'por_generar';
    cumplido: boolean;
    titulo: string | null;
    mensaje: string | null;
    captura: 'registro' | 'rh';
}

export interface DocumentoRegistro {
    id: number;
    tipo: string;
    nombre: string;
    obligatorio: boolean;
    estado: string;
    mensaje: string | null;
    motivo_rechazo: string | null;
    puede_subir: boolean;
    puede_reemplazar: boolean;
    fecha_subida: string | null;
    par: { id: number; nombre: string; puede_subir: boolean } | null;
    admite_reverso?: boolean;
    generado_por_sistema: GeneradoPorSistemaRegistro | null;
}

export interface SeccionSolicitudRegistro {
    clave: string;
    titulo: string;
    completa: boolean;
    porcentaje: number;
}

export interface EstadoRegistroWeb {
    aplica: boolean;
    registro_completo: boolean;
    datos: {
        completo: boolean;
        faltan: { campo: string; etiqueta: string }[];
        campos: CampoRegistro[];
    };
    datos_medicos: { capturados: boolean };
    foto: {
        obligatoria: boolean;
        completo: boolean;
        url: string | null;
        pendiente_revision: boolean;
        puede_reemplazar: boolean;
        cubre_documento: { id: number; nombre: string } | null;
    };
    documentos: {
        completo: boolean;
        lista: DocumentoRegistro[];
    };
    puede_finalizar: boolean;
    solicitud_empleo: {
        exigida: boolean;
        completo: boolean;
        porcentaje: number;
        secciones: SeccionSolicitudRegistro[];
    } | null;
    max_documentos_carta: number;
    pendientes: string[];
}
