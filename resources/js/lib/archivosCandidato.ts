import cv from '@/routes/rh/candidatos/cv';
import { descargar as rutaEvidencia } from '@/routes/rh/candidatos/evidencias';
import type {
    ArchivoPrevisualizable,
    EvidenciaCandidato,
    ExpedienteCandidato,
} from '@/types';

/**
 * Traduce los archivos del mini expediente del candidato (CV + evidencias)
 * al formato del visor único (DocumentPreviewDialog). Las URLs son siempre
 * los endpoints protegidos de CandidatoController (Policy + alcance).
 */
export function archivoCvCandidato(
    candidatoId: number,
    datos: {
        nombre?: string | null;
        tamano?: number | null;
        subido_en?: string | null;
    } = {},
): ArchivoPrevisualizable {
    return {
        clave: 'cv',
        nombre: datos.nombre || 'CV del candidato',
        previewUrl: cv.previsualizar.url(candidatoId),
        downloadUrl: cv.descargar.url(candidatoId),
        tamano: datos.tamano ?? null,
        etiqueta: 'Currículum',
        fecha: datos.subido_en ?? null,
    };
}

export function claveEvidencia(evidencia: Pick<EvidenciaCandidato, 'id'>) {
    return `ev-${evidencia.id}`;
}

export function archivoEvidenciaCandidato(
    candidatoId: number,
    evidencia: EvidenciaCandidato,
): ArchivoPrevisualizable {
    const args = { candidato: candidatoId, evidencia: evidencia.id };

    return {
        clave: claveEvidencia(evidencia),
        nombre: evidencia.nombre,
        previewUrl: rutaEvidencia.url(args),
        downloadUrl: rutaEvidencia.url(args, { query: { descargar: 1 } }),
        mime: evidencia.mime,
        // "video"/"fotografia" ayudan cuando el mime viene vacío.
        tipo: evidencia.tipo,
        tamano: evidencia.tamano,
        etiqueta: evidencia.tipo_etiqueta,
        fecha: evidencia.subida_en,
    };
}

/**
 * Todo el expediente como una sola galería (CV primero y luego cada grupo
 * en el orden del NAS), para navegar con anterior/siguiente sin cerrar el
 * visor. Las evidencias repetidas se omiten.
 */
export function archivosDelExpediente(
    candidatoId: number,
    expediente: ExpedienteCandidato | null | undefined,
    extras: EvidenciaCandidato[] = [],
): ArchivoPrevisualizable[] {
    const archivos: ArchivoPrevisualizable[] = [];
    const vistos = new Set<number>();

    if (expediente?.cv) {
        archivos.push(archivoCvCandidato(candidatoId, expediente.cv));
    }

    const evidencias = [
        ...(expediente?.grupos.flatMap((grupo) => grupo.archivos) ?? []),
        ...extras,
    ];

    for (const evidencia of evidencias) {
        if (vistos.has(evidencia.id)) {
            continue;
        }

        vistos.add(evidencia.id);
        archivos.push(archivoEvidenciaCandidato(candidatoId, evidencia));
    }

    return archivos;
}
