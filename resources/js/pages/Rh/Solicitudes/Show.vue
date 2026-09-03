<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ClipboardList, Paperclip } from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
    aprobar,
    cerrar,
    index,
    rechazar,
    requerirCorreccion,
    revisar,
} from '@/routes/rh/solicitudes';
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
            { title: 'Solicitudes', href: index.url() },
            { title: pageProps.solicitud.folio, href: '' },
        ],
    }),
});

const comentario = ref('');
const motivoRechazo = ref('');
const mostrandoRechazo = ref(false);

const formAccion = useForm({});

function revisarSolicitud() {
    formAccion
        .transform((d) => ({ ...d, comentario: comentario.value }))
        .post(revisar.url(props.solicitud.id), { preserveScroll: true });
}

function pedirCorreccion() {
    formAccion
        .transform((d) => ({ ...d, comentario: comentario.value }))
        .post(requerirCorreccion.url(props.solicitud.id), {
            preserveScroll: true,
        });
}

function aprobarSolicitud() {
    formAccion
        .transform((d) => ({ ...d, comentario: comentario.value }))
        .post(aprobar.url(props.solicitud.id), { preserveScroll: true });
}

const formRechazar = useForm({ motivo_rechazo: '' });
function rechazarSolicitud() {
    formRechazar.motivo_rechazo = motivoRechazo.value;
    formRechazar.post(rechazar.url(props.solicitud.id), {
        preserveScroll: true,
        onSuccess: () => (mostrandoRechazo.value = false),
    });
}

function cerrarSolicitud() {
    formAccion
        .transform((d) => ({ ...d, comentario: comentario.value }))
        .post(cerrar.url(props.solicitud.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Solicitud ${solicitud.folio}`" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <CrudPageHeader
            :titulo="`Solicitud ${solicitud.folio}`"
            :descripcion="`${solicitud.usuario?.name ?? ''} ${solicitud.usuario?.apellidos ?? ''}`"
            :icono="ClipboardList"
        >
            <EstadoBadge :estado="solicitud.estado" />
        </CrudPageHeader>

        <div class="grid gap-4 rounded-2xl border border-border/60 bg-card p-5">
            <div class="grid gap-1 sm:grid-cols-2">
                <div>
                    <p class="text-xs text-muted-foreground">Puesto</p>
                    <p class="text-sm font-medium">
                        {{ solicitud.usuario?.puesto?.nombre ?? '—' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Sucursal</p>
                    <p class="text-sm font-medium">
                        {{
                            solicitud.usuario?.sucursalPrincipal?.nombre ?? '—'
                        }}
                    </p>
                </div>
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
        </div>

        <div class="rounded-2xl border border-border/60 bg-card p-5">
            <h3 class="mb-3 text-sm font-semibold">Documentos adjuntos</h3>
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

        <div
            v-if="
                !['rechazada', 'cancelada', 'cerrada'].includes(
                    solicitud.estado,
                )
            "
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-5"
        >
            <h3 class="text-sm font-semibold">Acciones de revisión</h3>
            <Textarea
                v-model="comentario"
                placeholder="Comentario (opcional)"
                rows="2"
            />
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="solicitud.estado === 'enviada'"
                    size="sm"
                    variant="outline"
                    :disabled="formAccion.processing"
                    @click="revisarSolicitud"
                    >Marcar en revisión</Button
                >
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="formAccion.processing"
                    @click="pedirCorreccion"
                    >Pedir corrección</Button
                >
                <Button
                    size="sm"
                    :disabled="formAccion.processing"
                    @click="aprobarSolicitud"
                    >Aprobar</Button
                >
                <Button
                    v-if="!mostrandoRechazo"
                    size="sm"
                    variant="destructive"
                    @click="mostrandoRechazo = true"
                    >Rechazar</Button
                >
                <Button
                    v-if="solicitud.estado === 'aprobada'"
                    size="sm"
                    variant="secondary"
                    :disabled="formAccion.processing"
                    @click="cerrarSolicitud"
                    >Cerrar solicitud</Button
                >
            </div>

            <div
                v-if="mostrandoRechazo"
                class="flex flex-wrap items-center gap-2"
            >
                <Textarea
                    v-model="motivoRechazo"
                    placeholder="Motivo del rechazo"
                    class="w-72"
                    rows="1"
                />
                <Button
                    size="sm"
                    variant="destructive"
                    :disabled="formRechazar.processing || !motivoRechazo"
                    @click="rechazarSolicitud"
                    >Confirmar rechazo</Button
                >
            </div>
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
