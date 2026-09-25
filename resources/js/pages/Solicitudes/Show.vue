<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    ClipboardList,
    Eye,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { cancelar, index } from '@/routes/solicitudes';
import { store as subirDocumentoSolicitud, ver as verDocumento } from '@/routes/solicitudes/documentos';
import type { SolicitudInternaDocumentoItem, SolicitudInternaItem } from '@/types';

const props = defineProps<{
    solicitud: SolicitudInternaItem;
}>();

// `layout` recibe una función en vez de un objeto estático porque
// `defineOptions()` se compila fuera del scope de setup() y no puede
// referenciar variables locales como `props`; Inertia la invoca con las
// props actuales de la página en cada render (ver @inertiajs/vue3).
defineOptions({
    layout: (pageProps: { solicitud: SolicitudInternaItem }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mis solicitudes', href: index.url() },
            { title: pageProps.solicitud.folio, href: '' },
        ],
    }),
});

const { confirmarEliminacion, mostrarExito } = useAlertas();

async function cancelarSolicitud() {
    const confirmado = await confirmarEliminacion('esta solicitud');

    if (!confirmado) {
        return;
    }

    useForm({}).post(cancelar.url(props.solicitud.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('Solicitud cancelada.'),
    });
}

const archivosAdjuntos = ref<File[]>([]);
const formArchivo = useForm({ archivo: null as File | null });

function subirDocumento(archivos: File[]) {
    archivosAdjuntos.value = archivos;
    formArchivo.archivo = archivos[0] ?? null;

    if (!formArchivo.archivo) {
        return;
    }

    formArchivo.post(subirDocumentoSolicitud.url(props.solicitud.id), {
        preserveScroll: true,
        onSuccess: () => {
            formArchivo.reset();
            archivosAdjuntos.value = [];
        },
    });
}

const PUEDE_CANCELAR = [
    'creada',
    'enviada',
    'en_revision',
    'requiere_correccion',
];

// Progreso simple: solo tiene sentido mientras la solicitud sigue su curso
// normal — un estado terminal (rechazada/cancelada) se muestra aparte, no
// como un paso "incompleto" del mismo avance.
const PASOS = ['enviada', 'en_revision', 'aprobada'] as const;
const esTerminalAtipico = computed(() =>
    ['rechazada', 'cancelada'].includes(props.solicitud.estado),
);
const pasoActualIndice = computed(() => {
    if (props.solicitud.estado === 'cerrada') {
        return PASOS.length - 1;
    }

    if (props.solicitud.estado === 'requiere_correccion') {
        return PASOS.indexOf('en_revision');
    }

    const indice = PASOS.indexOf(
        props.solicitud.estado as (typeof PASOS)[number],
    );

    return indice === -1 ? 0 : indice;
});

const ETIQUETAS_PASO: Record<(typeof PASOS)[number], string> = {
    enviada: 'Enviada',
    en_revision: 'En revisión',
    aprobada: 'Aprobada / Cerrada',
};

const previewAbierto = ref(false);
const previewActivo = ref<{ url: string; nombre: string } | null>(null);

function previsualizar(doc: SolicitudInternaDocumentoItem) {
    previewActivo.value = {
        url: verDocumento.url([props.solicitud.id, doc.id]),
        nombre: doc.original_name,
    };
    previewAbierto.value = true;
}
</script>

<template>
    <Head :title="`Solicitud ${solicitud.folio}`" />

    <div class="flex w-full min-w-0 flex-col gap-6 p-4 sm:p-6">
        <CrudPageHeader
            detalle
            :titulo="`Solicitud ${solicitud.folio}`"
            :icono="ClipboardList"
        >
            <EstadoBadge :estado="solicitud.estado" />
        </CrudPageHeader>

        <div class="grid gap-6 lg:grid-cols-12 lg:items-start">
            <div class="flex flex-col gap-6 lg:col-span-8 xl:col-span-9">
                <!-- Progreso -->
                <div
                    v-if="!esTerminalAtipico"
                    class="rounded-2xl border border-border/60 bg-card p-5"
                >
                    <ol class="flex items-center gap-2">
                        <template v-for="(paso, indice) in PASOS" :key="paso">
                            <li class="flex flex-1 items-center gap-2">
                                <span
                                    :class="
                                        cn(
                                            'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                            indice <= pasoActualIndice
                                                ? 'bg-[var(--brand-primary)] text-white'
                                                : 'bg-muted text-muted-foreground',
                                        )
                                    "
                                >
                                    <Check
                                        v-if="indice < pasoActualIndice"
                                        class="size-3.5"
                                    />
                                    <template v-else>{{ indice + 1 }}</template>
                                </span>
                                <span
                                    :class="
                                        cn(
                                            'text-xs font-medium',
                                            indice <= pasoActualIndice
                                                ? 'text-foreground'
                                                : 'text-muted-foreground',
                                        )
                                    "
                                >
                                    {{ ETIQUETAS_PASO[paso] }}
                                </span>
                            </li>
                            <li
                                v-if="indice < PASOS.length - 1"
                                class="h-px flex-1 bg-border"
                            />
                        </template>
                    </ol>

                    <div
                        v-if="solicitud.estado === 'requiere_correccion'"
                        class="mt-4 flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400"
                    >
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        RH pidió una corrección. Revisa el historial abajo
                        para ver qué falta.
                    </div>
                </div>

                <div
                    v-else
                    class="flex items-start gap-2 rounded-2xl border p-5 text-sm"
                    :class="
                        solicitud.estado === 'rechazada'
                            ? 'border-destructive/40 bg-destructive/5 text-destructive'
                            : 'border-border/60 bg-card text-muted-foreground'
                    "
                >
                    <XCircle class="mt-0.5 size-5 shrink-0" />
                    <div>
                        <p class="font-medium">
                            {{
                                solicitud.estado === 'rechazada'
                                    ? 'Esta solicitud fue rechazada.'
                                    : 'Esta solicitud fue cancelada.'
                            }}
                        </p>
                        <p v-if="solicitud.motivo_rechazo" class="mt-1">
                            {{ solicitud.motivo_rechazo }}
                        </p>
                    </div>
                </div>

                <div
                    class="grid gap-4 rounded-2xl border border-border/60 bg-card p-5"
                >
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <p class="text-xs text-muted-foreground">Tipo</p>
                            <p class="text-sm font-medium capitalize">
                                {{ solicitud.tipo.replace(/_/g, ' ') }}
                            </p>
                        </div>
                        <div v-if="solicitud.fecha_inicio">
                            <p class="text-xs text-muted-foreground">
                                {{ solicitud.fecha_fin ? 'Periodo' : 'Fecha' }}
                            </p>
                            <p class="text-sm font-medium">
                                {{ solicitud.fecha_inicio }}
                                <template v-if="solicitud.fecha_fin">
                                    — {{ solicitud.fecha_fin }}
                                </template>
                            </p>
                        </div>
                        <div v-if="solicitud.dias_solicitados">
                            <p class="text-xs text-muted-foreground">
                                Días solicitados
                            </p>
                            <p class="text-sm font-medium">
                                {{ solicitud.dias_solicitados }}
                            </p>
                        </div>
                        <div v-if="solicitud.monto_solicitado">
                            <p class="text-xs text-muted-foreground">
                                Monto solicitado
                            </p>
                            <p class="text-sm font-medium">
                                ${{ solicitud.monto_solicitado }}
                                <span v-if="solicitud.plazo_meses"
                                    >a {{ solicitud.plazo_meses }} meses</span
                                >
                            </p>
                        </div>
                        <div v-if="solicitud.colaborador_objetivo">
                            <p class="text-xs text-muted-foreground">
                                Colaborador
                            </p>
                            <p class="text-sm font-medium">
                                {{ solicitud.colaborador_objetivo.name }}
                                {{
                                    solicitud.colaborador_objetivo.apellidos ??
                                    ''
                                }}
                            </p>
                        </div>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Motivo</p>
                        <p class="text-sm">{{ solicitud.motivo }}</p>
                    </div>
                    <div v-if="solicitud.observaciones">
                        <p class="text-xs text-muted-foreground">
                            Observaciones
                        </p>
                        <p class="text-sm">{{ solicitud.observaciones }}</p>
                    </div>
                </div>

                <div
                    v-if="solicitud.documentos_generados?.length"
                    class="rounded-2xl border border-border/60 bg-card p-5"
                >
                    <h3 class="mb-3 text-sm font-semibold">
                        Documentos del trámite
                    </h3>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="doc in solicitud.documentos_generados"
                            :key="doc.id"
                            class="flex items-center justify-between gap-2 text-sm"
                        >
                            <span>{{ doc.generated_name }}</span>
                            <EstadoBadge :estado="doc.status" />
                        </li>
                    </ul>
                    <p class="mt-2 text-xs text-muted-foreground">
                        RH te hará llegar el documento para firmar.
                    </p>
                </div>

                <div class="rounded-2xl border border-border/60 bg-card p-5">
                    <h3 class="mb-3 text-sm font-semibold">
                        Documentos adjuntos
                    </h3>
                    <ul
                        v-if="solicitud.documentos?.length"
                        class="mb-3 flex flex-col gap-2"
                    >
                        <li
                            v-for="doc in solicitud.documentos"
                            :key="doc.id"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border/60 p-3 text-sm"
                        >
                            <span class="truncate">{{
                                doc.original_name
                            }}</span>
                            <Button
                                size="sm"
                                variant="outline"
                                @click="previsualizar(doc)"
                            >
                                <Eye class="size-3.5" />
                                Previsualizar
                            </Button>
                        </li>
                    </ul>
                    <p v-else class="mb-3 text-sm text-muted-foreground">
                        Sin documentos adjuntos.
                    </p>

                    <PeopleFileDropzone
                        :model-value="archivosAdjuntos"
                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                        :loading="formArchivo.processing"
                        @update:model-value="subirDocumento"
                    />
                </div>

                <div class="rounded-2xl border border-border/60 bg-card p-5">
                    <h3 class="mb-3 text-sm font-semibold">Historial</h3>
                    <ol
                        class="relative flex flex-col gap-4 border-s border-border/60 ps-4"
                    >
                        <li
                            v-for="evento in solicitud.historial"
                            :key="evento.id"
                            class="relative"
                        >
                            <span
                                class="absolute -start-[21px] mt-1 size-2.5 rounded-full bg-[var(--brand-primary)]"
                            />
                            <p class="text-sm font-medium capitalize">
                                {{ evento.accion.replace(/_/g, ' ') }}
                                <span
                                    v-if="evento.usuario"
                                    class="font-normal text-muted-foreground"
                                >
                                    — {{ evento.usuario.name }}
                                    {{ evento.usuario.apellidos }}
                                </span>
                            </p>
                            <p
                                v-if="evento.comentario"
                                class="text-sm text-muted-foreground"
                            >
                                {{ evento.comentario }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ evento.created_at }}
                            </p>
                        </li>
                    </ol>
                </div>
            </div>

            <div class="flex flex-col gap-4 lg:sticky lg:top-6 lg:col-span-4 xl:col-span-3">
                <div class="rounded-2xl border border-border/60 bg-card p-5">
                    <h3 class="text-sm font-semibold">Estado</h3>
                    <div class="mt-2">
                        <EstadoBadge :estado="solicitud.estado" />
                    </div>
                    <p class="mt-3 text-xs text-muted-foreground">
                        Folio {{ solicitud.folio }} · creada el
                        {{ solicitud.created_at }}
                    </p>
                    <Button
                        v-if="PUEDE_CANCELAR.includes(solicitud.estado)"
                        variant="outline"
                        size="sm"
                        class="mt-4 w-full"
                        @click="cancelarSolicitud"
                    >
                        Cancelar solicitud
                    </Button>
                </div>
            </div>
        </div>
    </div>

    <DocumentPreviewDialog
        :open="previewAbierto"
        :preview-url="previewActivo?.url ?? null"
        :nombre="previewActivo?.nombre ?? ''"
        @update:open="(v) => (previewAbierto = v)"
    />
</template>
