<script setup lang="ts">
import { Download, Eye, History } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

/**
 * Historial de solo lectura de lo que se emitió con el motor anterior de
 * formatos oficiales. Ya no se genera nada desde aquí — todo documento
 * nuevo sale de Documentos maestros (sección "Documentos del proceso") —,
 * pero el expediente conserva lo que ya tenía.
 */
defineProps<{
    documentos: {
        id: number;
        formato: string;
        categoria: string;
        version: number | null;
        solicitud_folio: string | null;
        generado_por: string | null;
        generado_en: string | null;
        estado: string;
        ver_url: string;
        descargar_url: string;
    }[];
    puedeDescargar: boolean;
}>();

function fecha(valor: string | null): string {
    return valor
        ? new Date(valor).toLocaleDateString('es-MX', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';
}
</script>

<template>
    <section v-if="documentos.length > 0" class="rounded-2xl border p-4">
        <h3 class="mb-1 flex items-center gap-2 font-medium">
            <History class="size-4 text-muted-foreground" />Documentos
            anteriores
        </h3>
        <p class="mb-3 text-xs text-muted-foreground">
            Emitidos antes de Documentos maestros. Solo consulta: los documentos
            nuevos se generan arriba, en los documentos del proceso.
        </p>
        <ul class="divide-y">
            <li
                v-for="d in documentos"
                :key="d.id"
                class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
            >
                <div class="min-w-0">
                    <p class="font-medium">{{ d.formato }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ d.categoria }} · v{{ d.version ?? '—' }} ·
                        {{ fecha(d.generado_en) }} ·
                        {{ d.generado_por ?? 'sistema'
                        }}<template v-if="d.solicitud_folio">
                            · {{ d.solicitud_folio }}</template
                        >
                    </p>
                </div>
                <div class="flex items-center gap-1">
                    <Badge
                        :variant="
                            d.estado === 'firmado' ? 'default' : 'outline'
                        "
                        >{{
                            d.estado === 'firmado' ? 'Firmado' : 'Generado'
                        }}</Badge
                    >
                    <Button
                        as-child
                        size="icon"
                        variant="ghost"
                        aria-label="Ver"
                        ><a :href="d.ver_url" target="_blank"
                            ><Eye class="size-4" /></a
                    ></Button>
                    <Button
                        v-if="puedeDescargar"
                        as-child
                        size="icon"
                        variant="ghost"
                        aria-label="Descargar"
                        ><a :href="d.descargar_url"
                            ><Download class="size-4" /></a
                    ></Button>
                </div>
            </li>
        </ul>
    </section>
</template>
