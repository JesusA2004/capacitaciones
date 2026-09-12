<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ClipboardList,
    Download,
    Eye,
    FileStack,
    Paperclip,
    Upload,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import FiniquitoPanel from '@/components/Rh/FiniquitoPanel.vue';
import GenerarFormatoDialog from '@/components/Rh/GenerarFormatoDialog.vue';
import SubirFormatoFirmadoDialog from '@/components/Rh/SubirFormatoFirmadoDialog.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { descargar } from '@/routes/rh/formatos';
import {
    aprobar,
    cerrar,
    index,
    rechazar,
    requerirCorreccion,
    revisar,
} from '@/routes/rh/solicitudes';
import { ver as verDocumento } from '@/routes/rh/solicitudes/documentos';
import { store as subirDocumentoSolicitud } from '@/routes/solicitudes/documentos';
import type { FiniquitoPermisos, SolicitudInternaItem } from '@/types';

const props = defineProps<{
    solicitud: SolicitudInternaItem;
    puedeGenerarFormato: boolean;
    plantillasSugeridas: { id: number; nombre: string; tipo: string }[];
    tiposDocumentoExpediente: { id: number; nombre: string }[];
    finiquitoPermisos: FiniquitoPermisos;
}>();

const TIPO_PLANTILLA_SUGERIDO: Record<string, string> = {
    permiso_con_goce: 'formato_permiso',
    permiso_sin_goce: 'formato_permiso',
    incapacidad: 'formato_incapacidad',
    constancia_laboral: 'constancia_laboral',
    actualizacion_datos: 'actualizacion_datos',
    actualizacion_bancaria: 'actualizacion_datos',
    reposicion_documental: 'reposicion_documental',
    general: 'solicitud_general',
};
const tipoSugerido = computed(
    () => TIPO_PLANTILLA_SUGERIDO[props.solicitud.tipo] ?? null,
);

const dialogoGenerarAbierto = ref(false);
const documentoFirmando = ref<number | null>(null);

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

// Evidencia/firma del gerente: obligatoria antes de poder aprobar una baja
// de colaborador (ver App\Services\Solicitudes\SolicitudesService::cambiarEstado()).
const esBaja = computed(() => props.solicitud.tipo === 'baja_colaborador');
const sinEvidencia = computed(
    () => esBaja.value && (props.solicitud.documentos?.length ?? 0) === 0,
);

const finiquitoNoRevisado = computed(
    () =>
        esBaja.value &&
        !props.finiquitoPermisos.puedeOmitirRevision &&
        props.solicitud.finiquitoCalculo?.estado !== 'revisado' &&
        props.solicitud.finiquitoCalculo?.estado !== 'aprobado',
);

const formEvidencia = useForm({ archivo: null as File | null });

function subirEvidencia(event: Event) {
    const input = event.target as HTMLInputElement;
    formEvidencia.archivo = input.files?.[0] ?? null;

    if (!formEvidencia.archivo) {
        return;
    }

    formEvidencia.post(subirDocumentoSolicitud.url(props.solicitud.id), {
        preserveScroll: true,
        onSuccess: () => {
            formEvidencia.reset();
            input.value = '';
        },
    });
}

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
                            solicitud.usuario?.sucursal_principal?.nombre ?? '—'
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
                        Colaborador a dar de baja
                    </p>
                    <p class="text-sm font-medium">
                        {{ solicitud.colaborador_objetivo.name }}
                        {{ solicitud.colaborador_objetivo.apellidos ?? '' }}
                    </p>
                </div>
                <div v-if="solicitud.fecha_efectiva">
                    <p class="text-xs text-muted-foreground">
                        Fecha efectiva de baja
                    </p>
                    <p class="text-sm font-medium">
                        {{ solicitud.fecha_efectiva }}
                    </p>
                </div>
                <div v-if="solicitud.tipo_baja">
                    <p class="text-xs text-muted-foreground">Tipo de baja</p>
                    <p class="text-sm font-medium capitalize">
                        {{ solicitud.tipo_baja.replace(/_/g, ' ') }}
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

        <div
            v-if="puedeGenerarFormato"
            class="rounded-2xl border border-border/60 bg-card p-5"
        >
            <div class="mb-3 flex items-center justify-between gap-2">
                <h3 class="text-sm font-semibold">Formatos generados</h3>
                <Button size="sm" @click="dialogoGenerarAbierto = true">
                    <FileStack class="size-4" />
                    Generar formato
                </Button>
            </div>

            <ul
                v-if="solicitud.documentos_generados?.length"
                class="flex flex-col gap-2"
            >
                <li
                    v-for="doc in solicitud.documentos_generados"
                    :key="doc.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border/60 p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">{{ doc.generated_name }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ doc.plantilla?.nombre ?? '—' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <EstadoBadge :estado="doc.status" />
                        <a
                            :href="descargar.url(doc.id)"
                            class="inline-flex items-center gap-1 text-[var(--brand-primary)] hover:underline"
                            ><Download class="size-4" /> Descargar</a
                        >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground"
                            @click="documentoFirmando = doc.id"
                        >
                            <Upload class="size-4" /> Subir firmado
                        </button>
                    </div>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                Todavía no se ha generado ningún formato para esta solicitud.
            </p>
        </div>

        <div class="rounded-2xl border border-border/60 bg-card p-5">
            <h3 class="mb-3 text-sm font-semibold">
                {{ esBaja ? 'Evidencia / firma del gerente' : 'Documentos adjuntos' }}
            </h3>

            <div
                v-if="sinEvidencia"
                class="mb-3 flex items-center gap-2 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
            >
                <AlertTriangle class="size-4 shrink-0" />
                Esta baja no se puede aprobar sin evidencia (formato firmado, carta o autorización del gerente).
            </div>

            <ul v-if="solicitud.documentos?.length" class="mb-3 flex flex-col gap-2">
                <li
                    v-for="doc in solicitud.documentos"
                    :key="doc.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border/60 p-3 text-sm"
                >
                    <div class="flex items-center gap-2">
                        <Paperclip class="size-4 text-muted-foreground" />
                        <div>
                            <p>{{ doc.original_name }}</p>
                            <p v-if="doc.subido_por" class="text-xs text-muted-foreground">
                                Subido por {{ doc.subido_por.name }} {{ doc.subido_por.apellidos }}
                            </p>
                        </div>
                    </div>
                    <a
                        :href="verDocumento.url([solicitud.id, doc.id])"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 text-[var(--brand-primary)] hover:underline"
                    >
                        <Eye class="size-4" /> Ver
                    </a>
                </li>
            </ul>
            <p v-else class="mb-3 text-sm text-muted-foreground">
                Sin documentos adjuntos.
            </p>

            <label
                class="flex w-fit cursor-pointer items-center gap-2 rounded-lg border border-dashed px-3 py-2 text-sm text-muted-foreground hover:bg-accent"
            >
                <Upload class="size-4" />
                {{ esBaja ? 'Adjuntar evidencia' : 'Adjuntar documento' }}
                <input
                    type="file"
                    class="hidden"
                    accept=".pdf,.jpg,.jpeg,.png,.webp"
                    :disabled="formEvidencia.processing"
                    @change="subirEvidencia"
                />
            </label>
        </div>

        <FiniquitoPanel
            v-if="esBaja"
            :solicitud-id="solicitud.id"
            :finiquito="solicitud.finiquitoCalculo ?? null"
            :permisos="finiquitoPermisos"
        />

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
                    :disabled="formAccion.processing || sinEvidencia || finiquitoNoRevisado"
                    :title="
                        sinEvidencia
                            ? 'Adjunta la evidencia/firma del gerente antes de aprobar.'
                            : finiquitoNoRevisado
                              ? 'Calcula y marca como revisado el finiquito antes de aprobar esta baja.'
                              : undefined
                    "
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

    <GenerarFormatoDialog
        v-if="puedeGenerarFormato"
        v-model:open="dialogoGenerarAbierto"
        :solicitud-id="solicitud.id"
        :tipo-sugerido="tipoSugerido"
        :plantillas="plantillasSugeridas"
    />

    <SubirFormatoFirmadoDialog
        v-if="documentoFirmando !== null"
        :open="documentoFirmando !== null"
        :documento-id="documentoFirmando"
        :tipos-documento="tiposDocumentoExpediente"
        @update:open="(valor) => (valor ? null : (documentoFirmando = null))"
    />
</template>
