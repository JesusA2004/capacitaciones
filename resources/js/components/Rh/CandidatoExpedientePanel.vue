<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Eye,
    FileText,
    FolderOpen,
    Image,
    Inbox,
    Plus,
    Upload,
    Video,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import BandejaCvSelector from '@/components/Rh/BandejaCvSelector.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    claseDeArchivo,
    formatearTamano,
    formatoDeArchivo,
} from '@/lib/archivos';
import { cv } from '@/routes/rh/candidatos';
import { desdeBandeja } from '@/routes/rh/candidatos/cv';
import { store as guardarEvidencias } from '@/routes/rh/candidatos/evidencias';
import type {
    EvidenciaCandidato,
    ExpedienteCandidato,
    LimitesArchivosCandidato,
} from '@/types';

/**
 * Mini expediente del candidato ANTES de la contratación: CV, resultados
 * psicométricos, videos/fotos del socioeconómico y soporte de referencias.
 * Todo vive en el NAS (carpeta candidatos/{id}/…); aquí solo se listan y se
 * abren con el visor embebido. Permite agregar archivos sin cambiar la fase.
 */
const props = defineProps<{
    candidatoId: number;
    expediente: ExpedienteCandidato;
    puedeAdjuntar: boolean;
    limites: LimitesArchivosCandidato;
    /** Ya hay estudio socioeconómico registrado (se le pueden sumar videos). */
    socioeconomicoRegistrado: boolean;
    /** Ya hay al menos una referencia registrada. */
    hayReferencias: boolean;
}>();

const emit = defineEmits<{
    ver: [evidencia: EvidenciaCandidato];
    verCv: [];
}>();

const ACEPTA: Record<string, string> = {
    socioeconomico: '.mp4,.mov,.m4v,.3gp,.webm,.jpg,.jpeg,.png,.webp,.pdf',
    psicometricas: '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx',
    referencias: '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx',
};

const etapaActiva = ref<{ etapa: string; titulo: string } | null>(null);
const form = useForm({ etapa: '', archivos: [] as File[] });
const formCv = useForm({ cv: null as File | null });

const grupos = computed(() => props.expediente.grupos);

function puedeAgregarA(etapa: string): boolean {
    if (!props.puedeAdjuntar) {
        return false;
    }

    if (etapa === 'socioeconomico') {
        return props.socioeconomicoRegistrado;
    }

    if (etapa === 'referencias') {
        return props.hayReferencias;
    }

    return etapa === 'psicometricas';
}

function abrir(etapa: string, titulo: string) {
    form.reset();
    form.clearErrors();
    form.etapa = etapa;
    etapaActiva.value = { etapa, titulo };
}

function subir() {
    form.post(guardarEvidencias.url(props.candidatoId), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => (etapaActiva.value = null),
    });
}

function subirCv(event: Event) {
    const input = event.target as HTMLInputElement;
    formCv.cv = input.files?.[0] ?? null;

    if (formCv.cv) {
        formCv.post(cv.url(props.candidatoId), {
            preserveScroll: true,
            forceFormData: true,
        });
    }

    input.value = '';
}

const formBandeja = useForm<{ archivo: string; cv_bandeja?: string }>({
    archivo: '',
});

function tomarDeBandeja() {
    formBandeja.post(desdeBandeja.url(props.candidatoId), {
        preserveScroll: true,
        onSuccess: () => formBandeja.reset(),
    });
}

function icono(ev: EvidenciaCandidato) {
    const clase = claseDeArchivo(ev);

    return clase === 'video' ? Video : clase === 'imagen' ? Image : FileText;
}

const errorArchivos = computed(
    () =>
        form.errors.archivos ??
        Object.entries(form.errors).find(([clave]) =>
            clave.startsWith('archivos.'),
        )?.[1] ??
        form.errors.etapa,
);
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        aria-label="Expediente del candidato"
    >
        <div class="flex items-center gap-2">
            <FolderOpen class="size-4 text-[var(--mrl-petroleo)]" />
            <h2 class="text-sm font-semibold">Expediente del candidato</h2>
            <span class="ml-auto text-xs text-muted-foreground"
                >{{ expediente.total }} archivo(s)</span
            >
        </div>
        <p class="-mt-2 text-xs text-muted-foreground">
            Lo que el candidato presentó durante el proceso. Se guarda en el NAS
            y lo pueden consultar RH, Dirección Comercial y el área
            administrativa.
        </p>

        <div class="rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm">
            <div class="flex items-center gap-2">
                <p class="font-medium">Currículum</p>
                <label
                    v-if="puedeAdjuntar"
                    class="ml-auto inline-flex min-h-9 cursor-pointer items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                >
                    <Spinner v-if="formCv.processing" />
                    <Upload v-else class="size-4" />
                    {{ expediente.cv ? 'Reemplazar' : 'Subir CV' }}
                    <input
                        type="file"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
                        class="hidden"
                        @change="subirCv"
                    />
                </label>
            </div>
            <button
                v-if="expediente.cv"
                type="button"
                class="mt-2 flex w-full min-w-0 tarjeta-interactiva items-center gap-2.5 rounded-lg border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-2.5 py-2 text-left"
                :aria-label="`Ver ${expediente.cv.nombre}`"
                @click="emit('verCv')"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-md bg-danger-soft text-danger"
                >
                    <FileText class="size-4" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-xs font-medium">{{
                        expediente.cv.nombre
                    }}</span>
                    <span class="block text-[11px] text-muted-foreground">{{
                        [
                            formatoDeArchivo({ nombre: expediente.cv.nombre }),
                            formatearTamano(expediente.cv.tamano),
                        ]
                            .filter(Boolean)
                            .join(' · ')
                    }}</span>
                </span>
                <span
                    class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-[var(--mrl-petroleo)]"
                >
                    <Eye class="size-3.5" /> Ver
                </span>
            </button>
            <p v-else class="mt-1 text-xs text-muted-foreground">
                Sin CV todavía.
            </p>
            <InputError :message="formCv.errors.cv" />

            <!-- CV descargado de WhatsApp: ya está en la bandeja del NAS. -->
            <div v-if="puedeAdjuntar" class="mt-3 grid gap-2">
                <BandejaCvSelector v-model="formBandeja.archivo" />
                <InputError
                    :message="
                        formBandeja.errors.cv_bandeja ??
                        formBandeja.errors.archivo
                    "
                />
                <Button
                    v-if="formBandeja.archivo"
                    size="sm"
                    class="justify-self-start"
                    :disabled="formBandeja.processing"
                    @click="tomarDeBandeja"
                >
                    <Spinner v-if="formBandeja.processing" />
                    <Inbox v-else class="size-4" />
                    {{
                        expediente.cv
                            ? 'Reemplazar con este CV'
                            : 'Guardar como CV'
                    }}
                </Button>
            </div>
        </div>

        <div
            v-for="grupo in grupos"
            :key="grupo.clave"
            class="rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm"
        >
            <div class="flex items-center gap-2">
                <p class="font-medium">{{ grupo.titulo }}</p>
                <span class="text-xs text-muted-foreground"
                    >({{ grupo.archivos.length }})</span
                >
                <Button
                    v-if="puedeAgregarA(grupo.etapa)"
                    size="sm"
                    variant="ghost"
                    class="ml-auto"
                    @click="abrir(grupo.etapa, grupo.titulo)"
                >
                    <Plus class="size-4" /> Agregar
                </Button>
            </div>
            <ul v-if="grupo.archivos.length" class="mt-1 flex flex-col gap-1">
                <li v-for="ev in grupo.archivos" :key="ev.id">
                    <button
                        type="button"
                        class="group inline-flex min-h-8 max-w-full items-center gap-1.5 rounded-md px-1 text-left text-xs text-[var(--mrl-petroleo)] hover:bg-[var(--mrl-superficie)]"
                        :aria-label="`Ver ${ev.nombre}`"
                        @click="emit('ver', ev)"
                    >
                        <component :is="icono(ev)" class="size-3.5 shrink-0" />
                        <span class="truncate group-hover:underline">{{
                            ev.nombre
                        }}</span>
                        <span class="shrink-0 text-muted-foreground">{{
                            formatearTamano(ev.tamano)
                        }}</span>
                        <Eye
                            class="size-3.5 shrink-0 opacity-60 transition-opacity group-hover:opacity-100"
                        />
                    </button>
                </li>
            </ul>
            <p v-else class="mt-1 text-xs text-muted-foreground">
                Sin archivos.
            </p>
        </div>
    </section>

    <Dialog
        :open="etapaActiva !== null"
        @update:open="(abierto) => !abierto && (etapaActiva = null)"
    >
        <DialogContent class="max-w-lg">
            <DialogHeader>
                <DialogTitle>Agregar a «{{ etapaActiva?.titulo }}»</DialogTitle>
                <DialogDescription>
                    Puedes elegir varios archivos. No cambia la fase del
                    candidato.
                </DialogDescription>
            </DialogHeader>

            <PeopleFileDropzone
                v-model="form.archivos"
                multiple
                :accept="
                    ACEPTA[etapaActiva?.etapa ?? ''] ?? ACEPTA.psicometricas
                "
                :max-size-mb="
                    etapaActiva?.etapa === 'socioeconomico'
                        ? limites.max_video_mb
                        : limites.max_documento_mb
                "
                :loading="form.processing"
                :label="
                    etapaActiva?.etapa === 'socioeconomico'
                        ? 'Arrastra videos, fotos o PDF'
                        : 'Arrastra los archivos'
                "
            />
            <p
                v-if="form.progress"
                class="text-xs text-muted-foreground"
                role="status"
            >
                Subiendo… {{ form.progress.percentage }}%
            </p>
            <InputError :message="errorArchivos" />

            <DialogFooter>
                <Button
                    type="button"
                    variant="secondary"
                    @click="etapaActiva = null"
                    >Cancelar</Button
                >
                <Button
                    :disabled="form.processing || form.archivos.length === 0"
                    @click="subir"
                >
                    <Spinner v-if="form.processing" />
                    Guardar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
