<script setup lang="ts">
import {
    AlertTriangle,
    ArrowRight,
    Eye,
    Info,
    Loader2,
    Save,
} from '@lucide/vue';
import { ref } from 'vue';
import VisorPdf from '@/components/documentos/VisorPdf.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { describirOrden } from '@/lib/editorHojas';
import type {
    AccionDocumentoReorganizado,
    DocumentoResultanteHojas,
    VistaPreviaEditor,
} from '@/types';

/**
 * Vista previa del resultado del editor de hojas ANTES de guardar: los
 * documentos que saldrán (tipo, hojas, nº de páginas, qué pasará en el
 * expediente), el PDF de cada uno, las advertencias y la lista de hojas que
 * se movieron. Nada se escribe hasta «Confirmar y guardar».
 */
defineProps<{
    abierto: boolean;
    previa: VistaPreviaEditor | null;
    cargando: boolean;
    error: string | null;
    puedeGuardar: boolean;
    guardando: boolean;
}>();

const emit = defineEmits<{ cerrar: []; confirmar: [] }>();

const documentoPdf = ref<DocumentoResultanteHojas | null>(null);

const VARIANTE: Record<
    AccionDocumentoReorganizado,
    'success' | 'info' | 'warning' | 'secondary' | 'outline'
> = {
    sin_cambios: 'outline',
    nueva_version: 'success',
    carga_nueva: 'success',
    ya_cubierta: 'info',
    duplicado: 'warning',
    sin_clasificar: 'warning',
};
</script>

<template>
    <Dialog :open="abierto" @update:open="(v: boolean) => !v && emit('cerrar')">
        <DialogContent
            class="flex max-h-[calc(100svh-2rem)] max-w-[calc(100%-1rem)] flex-col gap-4 sm:max-w-4xl"
        >
            <DialogHeader>
                <DialogTitle>Vista previa del resultado</DialogTitle>
                <DialogDescription>
                    Así quedará el PDF histórico dividido. Todavía no se guarda
                    nada en el expediente.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="cargando"
                class="flex items-center justify-center gap-2 py-12 text-sm text-muted-foreground"
            >
                <Loader2 class="size-4 animate-spin" /> Revisando el plan…
            </div>
            <p
                v-else-if="error"
                class="rounded-xl border border-destructive/30 bg-danger-soft p-4 text-sm text-destructive"
                role="alert"
            >
                {{ error }}
            </p>

            <div
                v-else-if="previa"
                class="flex min-h-0 flex-col gap-4 overflow-y-auto"
            >
                <ul
                    v-if="previa.advertencias.length"
                    class="flex flex-col gap-1.5"
                >
                    <li
                        v-for="(a, i) in previa.advertencias"
                        :key="i"
                        class="flex items-start gap-2 rounded-xl border p-3 text-sm"
                        :class="
                            a.nivel === 'aviso'
                                ? 'border-warning/40 bg-warning-soft'
                                : 'border-info/30 bg-info-soft'
                        "
                    >
                        <AlertTriangle
                            v-if="a.nivel === 'aviso'"
                            class="mt-0.5 size-4 shrink-0 text-warning"
                        />
                        <Info v-else class="mt-0.5 size-4 shrink-0 text-info" />
                        <span>{{ a.mensaje }}</span>
                    </li>
                </ul>

                <section class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold">
                        Documentos resultantes ({{ previa.documentos.length }})
                    </h3>
                    <ul class="flex flex-col divide-y rounded-xl border">
                        <li
                            v-for="d in previa.documentos"
                            :key="d.orden"
                            class="flex flex-wrap items-center gap-x-3 gap-y-1.5 p-3 text-sm"
                        >
                            <span
                                class="w-8 shrink-0 text-xs text-muted-foreground tabular-nums"
                                >#{{ d.orden }}</span
                            >
                            <span class="min-w-0 flex-1">
                                <span class="font-medium">{{
                                    d.tipo ?? 'Sin clasificar'
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    Hojas {{ describirOrden(d.paginas) }} ·
                                    {{ d.total_paginas }}
                                    {{
                                        d.total_paginas === 1
                                            ? 'página'
                                            : 'páginas'
                                    }}
                                </span>
                            </span>
                            <Badge :variant="VARIANTE[d.accion]">{{
                                d.accion_etiqueta
                            }}</Badge>
                            <Button
                                size="sm"
                                variant="outline"
                                @click="documentoPdf = d"
                            >
                                <Eye class="size-4" /> Ver PDF
                            </Button>
                        </li>
                    </ul>
                </section>

                <details
                    v-if="previa.cambios.length"
                    class="rounded-xl border p-3 text-sm"
                    open
                >
                    <summary class="cursor-pointer font-medium">
                        Hojas que se mueven ({{ previa.cambios.length }})
                    </summary>
                    <ul class="mt-2 flex flex-col gap-1">
                        <li
                            v-for="c in previa.cambios"
                            :key="c.pagina"
                            class="flex flex-wrap items-center gap-x-2 border-t pt-1"
                        >
                            <span class="w-16 font-medium"
                                >Hoja {{ c.pagina }}</span
                            >
                            <span class="text-muted-foreground">{{
                                c.de
                            }}</span>
                            <ArrowRight
                                class="size-3.5 text-muted-foreground"
                            />
                            <span>{{ c.a }}</span>
                        </li>
                    </ul>
                </details>
            </div>

            <DialogFooter class="gap-2">
                <Button variant="outline" @click="emit('cerrar')"
                    >Seguir editando</Button
                >
                <Button
                    v-if="puedeGuardar"
                    :disabled="
                        !previa || previa.sin_cambios || guardando || cargando
                    "
                    @click="emit('confirmar')"
                >
                    <Loader2 v-if="guardando" class="size-4 animate-spin" />
                    <Save v-else class="size-4" />
                    Confirmar y guardar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="documentoPdf !== null"
        @update:open="(v: boolean) => !v && (documentoPdf = null)"
    >
        <DialogContent
            class="flex h-[calc(100svh-2rem)] max-w-[calc(100%-1rem)] flex-col p-3 sm:max-w-5xl"
        >
            <DialogHeader class="sr-only">
                <DialogTitle>PDF del documento</DialogTitle>
                <DialogDescription
                    >Vista previa, aún no guardada.</DialogDescription
                >
            </DialogHeader>
            <VisorPdf
                v-if="documentoPdf"
                :key="documentoPdf.pdf_url"
                :url="documentoPdf.pdf_url"
                :titulo="`${documentoPdf.tipo ?? 'Sin clasificar'} — hojas ${describirOrden(documentoPdf.paginas)} (vista previa)`"
            />
        </DialogContent>
    </Dialog>
</template>
