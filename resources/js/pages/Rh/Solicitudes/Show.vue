<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardList,
    Download,
    Eye,
    FileStack,
    Settings,
    Upload,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import FiniquitoPanel from '@/components/Rh/FiniquitoPanel.vue';
import GenerarFormatoDialog from '@/components/Rh/GenerarFormatoDialog.vue';
import SubirFormatoFirmadoDialog from '@/components/Rh/SubirFormatoFirmadoDialog.vue';
import SubirFormatoOficialFirmadoDialog from '@/components/Rh/SubirFormatoOficialFirmadoDialog.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { descargar } from '@/routes/rh/formatos';
import {
    descargar as descargarOficial,
    previsualizar as previsualizarOficial,
    show as configurarFormatoOficial,
} from '@/routes/rh/formatos-oficiales';
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
import type {
    DocumentoOficialEsperado,
    FiniquitoPermisos,
    OfficialFormatGenerationItem,
    SolicitudInternaDocumentoItem,
    SolicitudInternaItem,
} from '@/types';

const props = defineProps<{
    solicitud: SolicitudInternaItem;
    puedeGenerarFormato: boolean;
    documentoOficial: DocumentoOficialEsperado | null;
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
const documentoGeneradoFirmando = ref<number | null>(null);
const oficialFirmando = ref<number | null>(null);

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

const archivosEvidencia = ref<File[]>([]);
const formEvidencia = useForm({ archivo: null as File | null });

function subirEvidencia(archivos: File[]) {
    archivosEvidencia.value = archivos;
    formEvidencia.archivo = archivos[0] ?? null;

    if (!formEvidencia.archivo) {
        return;
    }

    formEvidencia.post(subirDocumentoSolicitud.url(props.solicitud.id), {
        preserveScroll: true,
        onSuccess: () => {
            formEvidencia.reset();
            archivosEvidencia.value = [];
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

// Vista previa de documentos (nunca una ruta física del NAS, siempre un
// endpoint protegido — ver resources/js/components/people/DocumentPreviewDialog.vue).
const previewAbierto = ref(false);
const previewActivo = ref<{
    url: string;
    descarga?: string;
    nombre: string;
} | null>(null);

function previsualizarAdjunto(doc: SolicitudInternaDocumentoItem) {
    previewActivo.value = {
        url: verDocumento.url([props.solicitud.id, doc.id]),
        nombre: doc.original_name,
    };
    previewAbierto.value = true;
}

function previsualizarOficialGeneracion(gen: OfficialFormatGenerationItem) {
    previewActivo.value = {
        url: previsualizarOficial.url(gen.id),
        descarga: descargarOficial.url(gen.id),
        nombre: gen.generated_name,
    };
    previewAbierto.value = true;
}

const documentoOficialGeneracion = computed(
    () => props.solicitud.official_format_generations?.[0] ?? null,
);
</script>

<template>
    <Head :title="`Solicitud ${solicitud.folio}`" />

    <div class="flex w-full min-w-0 flex-col gap-6 p-4 sm:p-6">
        <CrudPageHeader
            :titulo="`Solicitud ${solicitud.folio}`"
            :descripcion="`${solicitud.usuario?.name ?? ''} ${solicitud.usuario?.apellidos ?? ''}`"
            :icono="ClipboardList"
        >
            <EstadoBadge :estado="solicitud.estado" />
        </CrudPageHeader>

        <div class="grid gap-6 lg:grid-cols-12 lg:items-start">
            <!-- Columna principal -->
            <div class="flex flex-col gap-6 lg:col-span-8 xl:col-span-9">
                <div
                    class="grid gap-4 rounded-2xl border border-border/60 bg-card p-5"
                >
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <p class="text-xs text-muted-foreground">Puesto</p>
                            <p class="text-sm font-medium">
                                {{ solicitud.usuario?.puesto?.nombre ?? '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Sucursal
                            </p>
                            <p class="text-sm font-medium">
                                {{
                                    solicitud.usuario?.sucursal_principal
                                        ?.nombre ?? '—'
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
                                {{
                                    solicitud.colaborador_objetivo.apellidos ??
                                    ''
                                }}
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
                            <p class="text-xs text-muted-foreground">
                                Tipo de baja
                            </p>
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
                        <p class="text-xs text-muted-foreground">
                            Observaciones
                        </p>
                        <p class="text-sm">{{ solicitud.observaciones }}</p>
                    </div>
                </div>

                <!-- Documento oficial: automático según el tipo de solicitud
                     (config/solicitudes.php) — distinto de los documentos
                     adicionales (plantilla DOCX manual/opcional) de abajo. -->
                <div
                    v-if="documentoOficial"
                    class="rounded-2xl border border-border/60 bg-card p-5"
                >
                    <h3 class="mb-3 text-sm font-semibold">
                        Documento oficial
                    </h3>

                    <div
                        v-if="!documentoOficial.configurado"
                        class="flex flex-col gap-2 rounded-xl border border-warning/40 bg-warning/5 p-3 text-sm"
                    >
                        <p class="flex items-start gap-2 text-warning">
                            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                            Este trámite requiere el formato «{{
                                documentoOficial.nombre
                            }}», pero todavía no está configurado.
                        </p>
                        <Button
                            v-if="
                                documentoOficial.puedeConfigurar &&
                                documentoOficial.id !== null
                            "
                            as-child
                            size="sm"
                            variant="outline"
                            class="w-fit"
                        >
                            <a :href="configurarFormatoOficial.url({ formato: documentoOficial.id })">
                                <Settings class="size-3.5" />
                                Configurar formato
                            </a>
                        </Button>
                        <p v-else class="text-xs text-muted-foreground">
                            Solicita a un administrador que configure este
                            formato.
                        </p>
                    </div>

                    <div
                        v-else-if="documentoOficialGeneracion"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border/60 p-3 text-sm"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ documentoOficialGeneracion.generated_name }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ documentoOficial.nombre }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <EstadoBadge
                                :estado="documentoOficialGeneracion.status"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                @click="
                                    previsualizarOficialGeneracion(
                                        documentoOficialGeneracion,
                                    )
                                "
                            >
                                <Eye class="size-3.5" />
                                Previsualizar
                            </Button>
                            <Button as-child size="sm" variant="outline">
                                <a
                                    :href="
                                        descargarOficial.url(
                                            documentoOficialGeneracion.id,
                                        )
                                    "
                                >
                                    <Download class="size-3.5" />
                                    Descargar
                                </a>
                            </Button>
                            <Button
                                v-if="
                                    documentoOficial.requiereFirma &&
                                    documentoOficialGeneracion.status ===
                                        'generado'
                                "
                                size="sm"
                                @click="
                                    oficialFirmando =
                                        documentoOficialGeneracion.id
                                "
                            >
                                <Upload class="size-3.5" />
                                Subir firmado
                            </Button>
                            <span
                                v-else-if="
                                    documentoOficialGeneracion.status ===
                                    'firmado'
                                "
                                class="inline-flex items-center gap-1 text-xs text-success"
                            >
                                <CheckCircle2 class="size-3.5" />
                                Firmado
                            </span>
                        </div>
                    </div>

                    <p v-else class="text-sm text-muted-foreground">
                        Se generará automáticamente al aprobar la solicitud.
                    </p>
                </div>

                <!-- Documentos adicionales: plantilla DOCX libre y opcional,
                     nunca la acción principal (sección 9 del encargo). -->
                <div
                    v-if="puedeGenerarFormato"
                    class="rounded-2xl border border-border/60 bg-card p-5"
                >
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold">
                            Documentos adicionales
                        </h3>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="dialogoGenerarAbierto = true"
                        >
                            <FileStack class="size-4" />
                            Generar documento adicional
                        </Button>
                    </div>
                    <p class="mb-3 text-xs text-muted-foreground">
                        Genera un documento adicional a partir de una
                        plantilla interna cuando el trámite lo requiera.
                    </p>

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
                                <p class="font-medium">
                                    {{ doc.generated_name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ doc.plantilla?.nombre ?? '—' }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <EstadoBadge :estado="doc.status" />
                                <Button as-child size="sm" variant="outline">
                                    <a :href="descargar.url(doc.id)">
                                        <Download class="size-3.5" />
                                        Descargar
                                    </a>
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="documentoGeneradoFirmando = doc.id"
                                >
                                    <Upload class="size-3.5" />
                                    Subir firmado
                                </Button>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="rounded-2xl border border-border/60 bg-card p-5">
                    <h3 class="mb-3 text-sm font-semibold">
                        {{
                            esBaja
                                ? 'Evidencia / firma del gerente'
                                : 'Documentos adjuntos'
                        }}
                    </h3>
                    <p v-if="esBaja" class="mb-3 text-xs text-muted-foreground">
                        Adjunta la autorización, carta o formato firmado
                        requerido antes de aprobar.
                    </p>

                    <div
                        v-if="sinEvidencia"
                        class="mb-3 flex items-center gap-2 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        <AlertTriangle class="size-4 shrink-0" />
                        Esta baja no se puede aprobar sin evidencia (formato
                        firmado, carta o autorización del gerente).
                    </div>

                    <ul
                        v-if="solicitud.documentos?.length"
                        class="mb-3 flex flex-col gap-2"
                    >
                        <li
                            v-for="doc in solicitud.documentos"
                            :key="doc.id"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border/60 p-3 text-sm"
                        >
                            <div>
                                <p>{{ doc.original_name }}</p>
                                <p
                                    v-if="doc.subido_por"
                                    class="text-xs text-muted-foreground"
                                >
                                    Subido por {{ doc.subido_por.name }}
                                    {{ doc.subido_por.apellidos }}
                                </p>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                @click="previsualizarAdjunto(doc)"
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
                        :model-value="archivosEvidencia"
                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                        :loading="formEvidencia.processing"
                        :label="
                            esBaja
                                ? 'Arrastra la evidencia aquí'
                                : 'Arrastra el documento aquí'
                        "
                        @update:model-value="subirEvidencia"
                    />
                </div>

                <FiniquitoPanel
                    v-if="esBaja"
                    :solicitud-id="solicitud.id"
                    :finiquito="solicitud.finiquitoCalculo ?? null"
                    :permisos="finiquitoPermisos"
                />

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

            <!-- Columna lateral: estado y acciones -->
            <div
                class="flex flex-col gap-4 lg:sticky lg:top-6 lg:col-span-4 xl:col-span-3"
            >
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
                    <div class="flex flex-col gap-2">
                        <Button
                            v-if="solicitud.estado === 'enviada'"
                            variant="secondary"
                            :disabled="formAccion.processing"
                            @click="revisarSolicitud"
                            >Marcar en revisión</Button
                        >
                        <Button
                            class="border border-amber-500/40 bg-amber-500/10 text-amber-600 hover:bg-amber-500/20 dark:text-amber-400"
                            variant="ghost"
                            :disabled="formAccion.processing"
                            @click="pedirCorreccion"
                            >Pedir corrección</Button
                        >
                        <Button
                            variant="success"
                            :disabled="
                                formAccion.processing ||
                                sinEvidencia ||
                                finiquitoNoRevisado
                            "
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
                            variant="destructive"
                            @click="mostrandoRechazo = true"
                            >Rechazar</Button
                        >
                        <Button
                            v-if="solicitud.estado === 'aprobada'"
                            variant="secondary"
                            :disabled="formAccion.processing"
                            @click="cerrarSolicitud"
                            >Cerrar solicitud</Button
                        >
                    </div>

                    <div
                        v-if="mostrandoRechazo"
                        class="flex flex-col gap-2 border-t border-border/60 pt-3"
                    >
                        <Textarea
                            v-model="motivoRechazo"
                            placeholder="Motivo del rechazo (obligatorio)"
                            rows="2"
                        />
                        <Button
                            variant="destructive"
                            :disabled="
                                formRechazar.processing || !motivoRechazo
                            "
                            @click="rechazarSolicitud"
                            >Confirmar rechazo</Button
                        >
                    </div>
                </div>

                <div
                    v-else
                    class="rounded-2xl border border-border/60 bg-card p-5"
                >
                    <h3 class="text-sm font-semibold">Estado final</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Esta solicitud ya no acepta más cambios.
                    </p>
                    <p
                        v-if="solicitud.motivo_rechazo"
                        class="mt-3 rounded-lg bg-destructive/10 p-2.5 text-xs text-destructive"
                    >
                        {{ solicitud.motivo_rechazo }}
                    </p>
                </div>
            </div>
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
        v-if="documentoGeneradoFirmando !== null"
        :open="documentoGeneradoFirmando !== null"
        :documento-id="documentoGeneradoFirmando"
        :tipos-documento="tiposDocumentoExpediente"
        @update:open="
            (valor) => (valor ? null : (documentoGeneradoFirmando = null))
        "
    />

    <SubirFormatoOficialFirmadoDialog
        v-if="oficialFirmando !== null"
        :open="oficialFirmando !== null"
        :generacion-id="oficialFirmando"
        @update:open="(valor) => (valor ? null : (oficialFirmando = null))"
    />

    <DocumentPreviewDialog
        :open="previewAbierto"
        :preview-url="previewActivo?.url ?? null"
        :download-url="previewActivo?.descarga ?? null"
        :nombre="previewActivo?.nombre ?? ''"
        @update:open="(v) => (previewAbierto = v)"
    />
</template>
