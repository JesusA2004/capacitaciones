import type { ArchivoPrevisualizable, ClaseArchivoVisor } from '@/types';

const IMAGEN = [
    'png',
    'jpg',
    'jpeg',
    'jpe',
    'jfif',
    'webp',
    'gif',
    'bmp',
    'svg',
    'avif',
    'ico',
];
const VIDEO = ['mp4', 'm4v', 'mov', 'webm', '3gp', 'ogv'];
const AUDIO = ['mp3', 'm4a', 'ogg', 'oga', 'wav', 'aac'];
const TEXTO = ['txt'];
const OFFICE = [
    'doc',
    'docx',
    'xls',
    'xlsx',
    'csv',
    'ppt',
    'pptx',
    'odt',
    'ods',
    'rtf',
];

/** Extensión en minúsculas sin punto ("" si no tiene). */
export function extensionDe(nombre: string | null | undefined): string {
    const limpio = (nombre ?? '').split(/[?#]/)[0];
    const punto = limpio.lastIndexOf('.');

    return punto >= 0 ? limpio.slice(punto + 1).toLowerCase() : '';
}

function claseDeMime(mime: string): ClaseArchivoVisor | null {
    if (mime === 'application/pdf') {
        return 'pdf';
    }

    // HEIC/TIFF no los pinta ningún navegador de escritorio común.
    if (mime.startsWith('image/') && !/heic|heif|tiff/.test(mime)) {
        return 'imagen';
    }

    if (mime.startsWith('video/')) {
        // .mov (quicktime) solo se reproduce en Safari/Chrome con H.264: se
        // intenta igual y, si falla, el visor ofrece la descarga.
        return 'video';
    }

    if (mime.startsWith('audio/')) {
        return 'audio';
    }

    if (mime === 'text/plain') {
        return 'texto';
    }

    if (
        mime.includes('word') ||
        mime.includes('excel') ||
        mime.includes('spreadsheet') ||
        mime.includes('presentation') ||
        mime.includes('powerpoint') ||
        mime.includes('opendocument') ||
        mime === 'text/csv'
    ) {
        return 'office';
    }

    return null;
}

function claseDeExtension(extension: string): ClaseArchivoVisor | null {
    if (extension === 'pdf') {
        return 'pdf';
    }

    if (IMAGEN.includes(extension)) {
        return 'imagen';
    }

    if (VIDEO.includes(extension)) {
        return 'video';
    }

    if (AUDIO.includes(extension)) {
        return 'audio';
    }

    if (TEXTO.includes(extension)) {
        return 'texto';
    }

    if (OFFICE.includes(extension)) {
        return 'office';
    }

    return null;
}

/**
 * Decide cómo mostrar un archivo: mime específico → extensión del nombre →
 * `tipo` explícito (que puede ser extensión, subtipo de mime o el tipo de
 * evidencia "video"/"fotografia").
 */
export function claseDeArchivo(
    archivo: Pick<ArchivoPrevisualizable, 'mime' | 'nombre' | 'tipo'>,
): ClaseArchivoVisor {
    const mime = (archivo.mime ?? '').toLowerCase();

    if (mime && mime !== 'application/octet-stream') {
        const porMime = claseDeMime(mime);

        if (porMime) {
            return porMime;
        }
    }

    const porNombre = claseDeExtension(extensionDe(archivo.nombre));

    if (porNombre) {
        return porNombre;
    }

    const tipo = (archivo.tipo ?? '').toLowerCase();

    if (tipo === 'fotografia') {
        return 'imagen';
    }

    if (tipo === 'video') {
        return 'video';
    }

    return (
        claseDeExtension(tipo.includes('.') ? extensionDe(tipo) : tipo) ??
        'otro'
    );
}

/** Etiqueta corta del formato para el encabezado del visor ("PDF", "JPG"…). */
export function formatoDeArchivo(
    archivo: Pick<ArchivoPrevisualizable, 'mime' | 'nombre' | 'tipo'>,
): string {
    const extension = extensionDe(archivo.nombre);

    if (extension) {
        return extension.toUpperCase();
    }

    const mime = archivo.mime ?? '';

    if (mime.includes('/')) {
        const subtipo = mime.split('/').pop() ?? '';

        return (subtipo.split(/[.+-]/).pop() ?? subtipo).toUpperCase();
    }

    return (archivo.tipo ?? '').toUpperCase();
}

export function formatearTamano(bytes: number | null | undefined): string {
    if (!bytes) {
        return '';
    }

    if (bytes >= 1024 * 1024 * 1024) {
        return `${(bytes / 1024 / 1024 / 1024).toFixed(1)} GB`;
    }

    return bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
