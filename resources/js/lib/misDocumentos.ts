import type { Tono } from '@/lib/tonos';
import type { ContratoPropio, DocumentoLaboralPropio } from '@/types';

/** Tono del chip de un documento laboral propio: por firmar, firmado o en trámite. */
export function tonoDocumentoLaboral(documento: DocumentoLaboralPropio): Tono {
    if (documento.puede_firmar) {
        return 'alerta';
    }

    if (
        documento.firmado_digital_en !== null ||
        documento.estado === 'archivado' ||
        documento.estado === 'firmado_fisicamente' ||
        documento.estado === 'escaneado'
    ) {
        return 'exito';
    }

    return 'info';
}

const ESTADO_CONTRATO: Record<string, { etiqueta: string; tono: Tono }> = {
    vigente: { etiqueta: 'Vigente', tono: 'exito' },
    renovado: { etiqueta: 'Renovado', tono: 'neutro' },
    terminado: { etiqueta: 'Terminado', tono: 'neutro' },
    cancelado: { etiqueta: 'Cancelado', tono: 'neutro' },
};

export function estadoContrato(contrato: ContratoPropio): {
    etiqueta: string;
    tono: Tono;
} {
    return (
        ESTADO_CONTRATO[contrato.estado] ?? {
            etiqueta: contrato.estado,
            tono: 'neutro',
        }
    );
}
