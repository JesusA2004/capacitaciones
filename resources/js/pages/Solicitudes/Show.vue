<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ClipboardList, Paperclip, Upload } from '@lucide/vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { cancelar, index } from '@/routes/solicitudes';
import { store as subirDocumentoSolicitud } from '@/routes/solicitudes/documentos';
import type { SolicitudInternaItem } from '@/types';

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

const formArchivo = useForm({ archivo: null as File | null });

function subirDocumento(event: Event) {
    const input = event.target as HTMLInputElement;
    formArchivo.archivo = input.files?.[0] ?? null;

    if (!formArchivo.archivo) {
        return;
    }

    formArchivo.post(subirDocumentoSolicitud.url(props.solicitud.id), {
        preserveScroll: true,
        onSuccess: () => {
            formArchivo.reset();
            input.value = '';
        },
    });
}

const PUEDE_CANCELAR = [
    'creada',
    'enviada',
    'en_revision',
    'requiere_correccion',
];
</script>

<template>
    <Head :title="`Solicitud ${solicitud.folio}`" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <CrudPageHeader
            :titulo="`Solicitud ${solicitud.folio}`"
            :icono="ClipboardList"
        >
            <EstadoBadge :estado="solicitud.estado" />
            <Button
                v-if="PUEDE_CANCELAR.includes(solicitud.estado)"
                variant="outline"
                size="sm"
                @click="cancelarSolicitud"
            >
                Cancelar solicitud
            </Button>
        </CrudPageHeader>

        <div class="grid gap-4 rounded-2xl border border-border/60 bg-card p-5">
            <div class="grid gap-1 sm:grid-cols-2">
                <div>
                    <p class="text-xs text-muted-foreground">Tipo</p>
                    <p class="text-sm font-medium capitalize">
                        {{ solicitud.tipo.replace(/_/g, ' ') }}
                    </p>
                </div>
                <div v-if="solicitud.fecha_inicio">
                    <p class="text-xs text-muted-foreground">Periodo</p>
                    <p class="text-sm font-medium">
                        {{ solicitud.fecha_inicio }} —
                        {{ solicitud.fecha_fin }}
                    </p>
                </div>
            </div>
            <div>
                <p class="text-xs text-muted-foreground">Motivo</p>
                <p class="text-sm">{{ solicitud.motivo }}</p>
            </div>
            <div v-if="solicitud.observaciones">
                <p class="text-xs text-muted-foreground">Observaciones</p>
                <p class="text-sm">{{ solicitud.observaciones }}</p>
            </div>
            <div v-if="solicitud.motivo_rechazo">
                <p class="text-xs text-destructive">Motivo de rechazo</p>
                <p class="text-sm text-destructive">
                    {{ solicitud.motivo_rechazo }}
                </p>
            </div>
        </div>

        <div
            v-if="solicitud.documentos_generados?.length"
            class="rounded-2xl border border-border/60 bg-card p-5"
        >
            <h3 class="mb-3 text-sm font-semibold">Formatos generados</h3>
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
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold">Documentos adjuntos</h3>
                <label
                    class="inline-flex cursor-pointer items-center gap-1 text-xs font-medium text-[var(--brand-primary)] hover:underline"
                >
                    <Upload class="size-3.5" />
                    Adjuntar archivo
                    <input
                        type="file"
                        class="hidden"
                        @change="subirDocumento"
                    />
                </label>
            </div>
            <Spinner v-if="formArchivo.processing" class="size-4" />
            <ul v-if="solicitud.documentos?.length" class="flex flex-col gap-2">
                <li
                    v-for="doc in solicitud.documentos"
                    :key="doc.id"
                    class="flex items-center gap-2 text-sm"
                >
                    <Paperclip class="size-4 text-muted-foreground" />
                    {{ doc.original_name }}
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                Sin documentos adjuntos.
            </p>
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
</template>
