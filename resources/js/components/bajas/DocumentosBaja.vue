<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    CheckCircle2,
    CircleDashed,
    Eye,
    FileUp,
    Loader2,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatearFechaHora } from '@/lib/fechas';
import documentosBaja from '@/routes/rh/bajas/documentos';
import type {
    ArchivoBaja,
    ArchivoPrevisualizable,
    EspacioDocumentoBaja,
    LimitesBaja,
} from '@/types';

/**
 * Bloque 4 de la baja: un espacio por documento requerido (los de la forma
 * + los adicionales de la causal, RN-05), cada uno con su estado y sus
 * adjuntos (PDF o imagen) con vista previa en el visor único
 * (DocumentPreviewDialog). Un espacio también se cumple con lo que el
 * sistema ya lleva en el cierre laboral (renuncia firmada, finiquito
 * entregado). Subir y quitar lo valida el backend (BajaDocumentosService).
 */
const props = withDefaults(
    defineProps<{
        espacios: EspacioDocumentoBaja[];
        bajaId: number | null;
        puedeAdjuntar: boolean;
        limites: LimitesBaja;
        /** Texto cuando no hay espacios (p. ej. baja de un cierre anterior). */
        mensajeVacio?: string;
    }>(),
    {
        mensajeVacio:
            'Elige cómo se va y la causal para ver los documentos que se necesitan.',
    },
);

const subiendo = ref<string | null>(null);
const error = ref<string | null>(null);
const visorAbierto = ref(false);
const indiceVisor = ref(0);

const archivosVisor = computed<ArchivoPrevisualizable[]>(() =>
    props.espacios.flatMap((e) =>
        e.archivos.map((a: ArchivoBaja) => ({
            clave: `doc-${a.id}`,
            nombre: a.nombre_original,
            previewUrl: a.url,
            downloadUrl: a.url_descarga,
            mime: a.mime,
            tamano: a.tamano,
            etiqueta: e.nombre,
            fecha: a.fecha,
        })),
    ),
);

const accept = computed(() =>
    props.limites.mimes.map((m) => `.${m}`).join(','),
);

function claveEspacio(e: EspacioDocumentoBaja): string {
    return String(e.id ?? 'otros');
}

function ver(archivo: ArchivoBaja) {
    const i = archivosVisor.value.findIndex(
        (a) => a.clave === `doc-${archivo.id}`,
    );
    indiceVisor.value = Math.max(0, i);
    visorAbierto.value = true;
}

function adjuntar(espacio: EspacioDocumentoBaja, evento: Event) {
    const entrada = evento.target as HTMLInputElement;
    const archivo = entrada.files?.[0];
    entrada.value = '';

    if (!archivo || props.bajaId === null) {
        return;
    }

    if (archivo.size > props.limites.max_kb * 1024) {
        error.value = `El archivo pesa más de ${Math.round(props.limites.max_kb / 1024)} MB.`;

        return;
    }

    error.value = null;
    subiendo.value = claveEspacio(espacio);
    router.post(
        documentosBaja.store.url(props.bajaId),
        { archivo, documento_requerido_id: espacio.id },
        {
            forceFormData: true,
            preserveScroll: true,
            onError: (e) =>
                (error.value =
                    e.archivo ??
                    e.documento_requerido_id ??
                    'No se pudo subir.'),
            onFinish: () => (subiendo.value = null),
        },
    );
}

function quitar(archivo: ArchivoBaja) {
    if (
        props.bajaId === null ||
        !window.confirm(`¿Quitar «${archivo.nombre_original}» de la baja?`)
    ) {
        return;
    }

    router.delete(
        documentosBaja.destroy.url({
            baja: props.bajaId,
            documento: archivo.id,
        }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="grid gap-3">
        <InputError :message="error ?? ''" />
        <p
            v-if="!espacios.length"
            class="rounded-2xl border border-dashed border-[var(--mrl-borde)] px-4 py-6 text-center text-sm text-[var(--mrl-texto-suave)]"
        >
            {{ mensajeVacio }}
        </p>
        <div
            v-for="e in espacios"
            :key="claveEspacio(e)"
            class="flex flex-col gap-2 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="flex min-w-0 items-start gap-2">
                    <CheckCircle2
                        v-if="e.cumplido"
                        class="mt-0.5 size-5 shrink-0 text-success"
                    />
                    <CircleDashed
                        v-else
                        class="mt-0.5 size-5 shrink-0 text-warning"
                    />
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ e.nombre }}</p>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            <template v-if="e.cumplido_por_sistema">{{
                                e.cumplido_por_sistema
                            }}</template>
                            <template v-else-if="e.cumplido">Adjunto</template>
                            <template v-else
                                >Pendiente{{
                                    e.obligatorio
                                        ? ' · obligatorio para cerrar'
                                        : ''
                                }}</template
                            >
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Badge v-if="e.de_causal" variant="outline"
                        >Adicional de la causal</Badge
                    >
                    <label
                        v-if="puedeAdjuntar && bajaId !== null && e.id !== null"
                        class="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-xl border border-[var(--mrl-borde)] bg-background px-3 text-sm font-medium hover:bg-muted"
                    >
                        <Loader2
                            v-if="subiendo === claveEspacio(e)"
                            class="size-4 animate-spin"
                        />
                        <FileUp v-else class="size-4" />
                        Adjuntar
                        <input
                            type="file"
                            class="sr-only"
                            :accept="accept"
                            :disabled="subiendo !== null"
                            @change="adjuntar(e, $event)"
                        />
                    </label>
                </div>
            </div>
            <ul v-if="e.archivos.length" class="grid gap-1.5">
                <li
                    v-for="a in e.archivos"
                    :key="a.id"
                    class="flex flex-wrap items-center gap-2 rounded-xl bg-muted/40 px-3 py-2 text-sm"
                >
                    <span class="min-w-0 flex-1 truncate">{{
                        a.nombre_original
                    }}</span>
                    <span class="text-xs text-[var(--mrl-texto-suave)]">{{
                        formatearFechaHora(a.fecha)
                    }}</span>
                    <Button
                        size="sm"
                        variant="ghost"
                        type="button"
                        @click="ver(a)"
                        ><Eye class="size-4" />Ver</Button
                    >
                    <Button
                        v-if="puedeAdjuntar"
                        size="icon"
                        variant="ghost"
                        type="button"
                        aria-label="Quitar documento"
                        @click="quitar(a)"
                        ><Trash2 class="size-4"
                    /></Button>
                </li>
            </ul>
        </div>
        <p
            v-if="bajaId === null && espacios.length"
            class="text-xs text-[var(--mrl-texto-suave)]"
        >
            Guarda el borrador para adjuntar los documentos (también puedes
            enviarla y adjuntarlos después; RH no la cierra hasta que estén
            completos).
        </p>
    </div>

    <DocumentPreviewDialog
        v-model:open="visorAbierto"
        :archivos="archivosVisor"
        :indice="indiceVisor"
    />
</template>
