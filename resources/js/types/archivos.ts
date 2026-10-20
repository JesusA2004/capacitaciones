/**
 * Un archivo que el visor embebido (DocumentPreviewDialog) sabe mostrar.
 * Las URLs siempre son endpoints protegidos del backend (Policy + alcance),
 * nunca rutas físicas ni enlaces directos al NAS.
 */
export type ArchivoPrevisualizable = {
    /** Identificador estable dentro del grupo (p. ej. "cv" o "ev-12"). */
    clave: string;
    nombre: string;
    /** Endpoint que sirve el archivo inline (Content-Disposition: inline). */
    previewUrl: string;
    /** Endpoint que fuerza la descarga; si falta se usa previewUrl con `download`. */
    downloadUrl?: string | null;
    mime?: string | null;
    /** Extensión o tipo explícito ("pdf", "png"…), si el nombre no la trae. */
    tipo?: string | null;
    tamano?: number | null;
    /** Contexto corto: "Currículum", "Psicométrico", "Socioeconómico"… */
    etiqueta?: string | null;
    fecha?: string | null;
    version?: number | null;
    estado?: string | null;
    subidoPor?: string | null;
};

/** Cómo se dibuja el archivo dentro del visor. */
export type ClaseArchivoVisor =
    'pdf' | 'imagen' | 'video' | 'audio' | 'texto' | 'office' | 'otro';
