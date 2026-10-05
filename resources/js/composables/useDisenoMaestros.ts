import { solicitar } from '@/composables/useDocumentosMaestros';
import { show, update } from '@/routes/rh/documentos-maestros/diseno';
import type { DisenoFamilia, LayoutDocumento } from '@/types';

/**
 * Cliente JSON del «Diseño de página» de una familia de documentos maestros.
 */
export function useDisenoMaestros() {
    return {
        disenoFamilia: (familia: string) =>
            solicitar<{ data: DisenoFamilia }>('GET', show.url(familia)).then(
                (r) => r.data,
            ),

        guardarDiseno: (
            familia: string,
            cuerpo: {
                preset_id: number | null;
                overrides: Partial<LayoutDocumento> | null;
            },
        ) =>
            solicitar<{ data: DisenoFamilia }>(
                'PUT',
                update.url(familia),
                cuerpo,
            ).then((r) => r.data),
    };
}
