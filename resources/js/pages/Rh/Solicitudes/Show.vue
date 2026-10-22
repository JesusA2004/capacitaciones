<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ClipboardList, Eye, FileSearch, Lock } from '@lucide/vue';
import { computed, ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DocumentosProceso from '@/components/documentos/DocumentosProceso.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import FiniquitoPanel from '@/components/Rh/FiniquitoPanel.vue';
import AutorizarPrestamoDialog from '@/components/Solicitudes/AutorizarPrestamoDialog.vue';
import type { NivelVistoBueno } from '@/components/Solicitudes/CadenaAutorizacion.vue';
import ComparativoDatos from '@/components/Solicitudes/ComparativoDatos.vue';
import FlujoAprobacion from '@/components/Solicitudes/FlujoAprobacion.vue';
import PermisoAutorizacionCard from '@/components/Solicitudes/PermisoAutorizacionCard.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
    formatearFecha,
    formatearFechaHora,
    formatearPeriodo,
} from '@/lib/fechas';
import { ciclo as cicloColaborador } from '@/routes/rh/colaboradores';
import {
    aprobar,
    index,
    rechazar,
    requerirCorreccion,
    revisar,
} from '@/routes/rh/solicitudes';
import { permisoPdf, vistaPrevia } from '@/routes/rh/solicitudes';
import { ver as verDocumento } from '@/routes/rh/solicitudes/documentos';
import { store as subirDocumentoSolicitud } from '@/routes/solicitudes/documentos';
import type {
    DatoPropuesto,
    DocumentoOficialEsperado,
    FiniquitoDesgloseItem,
    FiniquitoPermisos,
    FlujoAprobacion as FlujoAprobacionDatos,
    FormatoOficialItem,
    SolicitudInternaDocumentoItem,
    SolicitudInternaItem,
    PermisoResumen,
} from '@/types';

const faltanVistosBuenosCalculo = (niveles: NivelVistoBueno[]) =>
    niveles.some((n) => n.estado !== 'aprobado');

const props = defineProps<{
    solicitud: SolicitudInternaItem;
    tipoEtiqueta: string;
    tipoBajaEtiqueta: string | null;
    permiso: PermisoResumen | null;
    datosPropuestos: DatoPropuesto[] | null;
    puedeGenerarFormato: boolean;
    vistosBuenos: NivelVistoBueno[];
    puedeDarVistoBueno: boolean;
    /** Motor central: pasos, quién sigue y si puedes actuar (y por qué no). */
    flujo: FlujoAprobacionDatos;
    documentoOficial: DocumentoOficialEsperado | null;
    plantillasSugeridas: { id: number; nombre: string; tipo: string }[];
    tiposDocumentoExpediente: { id: number; nombre: string }[];
    finiquitoPermisos: FiniquitoPermisos;
    finiquitoDesglose: FiniquitoDesgloseItem[];
    formatosOficiales: FormatoOficialItem[];
    personaSolicitud: { id: number; nombre: string } | null;
    documentosProceso: { tipo: 'solicitud' | 'prestamo'; id: number }[];
    puedeRevisar: boolean;
    puedePedirCorreccion: boolean;
    puedeDescargarFormato: boolean;
    puedeAprobar: boolean;
    /** Con permiso de aprobar, pero faltan vistos buenos (texto humano). */
    motivoAprobarEnEspera: string | null;
    puedeRechazar: boolean;
    /** Préstamo: solo RH lo autoriza (monto, plazo y datos del contrato). */
    puedeAutorizarPrestamo: boolean;
    vistasPrevias: {
        documento: 'comprobante' | 'formato-oficial';
        titulo: string;
    }[];
    bajaEnCierre: { mensaje: string; colaboradorId: number | null } | null;
}>();

// Sin los vistos buenos (jefe/regional…) RH no puede autorizar todavía.
const faltanVistosBuenos = computed(() =>
    faltanVistosBuenosCalculo(props.vistosBuenos),
);

// Paso de RH en el flujo: por qué todavía no se puede aprobar (texto humano
// del backend, p. ej. «RH podrá aprobar cuando Alberto dé su visto bueno.»).
const motivoEsperaRh = computed(
    () =>
        props.flujo.pasos.find((p) => p.clave === 'rh')?.motivo_no_puede ??
        null,
);
// El botón Aprobar se ve, pero deshabilitado, mientras falten vistos buenos
// y quien mira tenga permiso de aprobar (nunca un botón que parece activo).
const aprobarEnEspera = computed(() => props.motivoAprobarEnEspera !== null);

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

// Una baja (histórica) se consulta aquí en solo lectura: se gestiona en el
// Cierre laboral del colaborador (SolicitudesService::cambiarEstado() la
// rechaza desde la bandeja).
const esBaja = computed(() => props.solicitud.tipo === 'baja_colaborador');

// Aprobada/rechazada/cancelada: ya no acepta cambios (mismo criterio que
// EstadoSolicitudInterna::esFinal() en el backend).
const esFinal = computed(() =>
    ['aprobada', 'rechazada', 'cancelada'].includes(props.solicitud.estado),
);

// Los botones salen de la misma policy que valida cada acción en el backend.
const tieneAcciones = computed(
    () =>
        !esFinal.value &&
        !props.bajaEnCierre &&
        (props.puedeRevisar ||
            props.puedePedirCorreccion ||
            props.puedeAprobar ||
            props.puedeRechazar ||
            props.puedeAutorizarPrestamo),
);

// Un préstamo nunca se aprueba con el botón genérico: RH lo autoriza con el
// diálogo «Autorizar préstamo» (el gerente/regional solo dan visto bueno).
const esPrestamo = computed(() => props.solicitud.tipo === 'prestamo');
const autorizandoPrestamo = ref(false);

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

// Vista previa de documentos (nunca una ruta física del NAS, siempre un
// endpoint protegido — ver resources/js/components/people/DocumentPreviewDialog.vue).
const previewAbierto = ref(false);
const previewActivo = ref<{
    url: string;
    descarga?: string;
    nombre: string;
} | null>(null);

// Documento que se emitirá al aprobar, con los datos reales de esta
// solicitud: RH lo revisa ANTES de autorizar (el backend no guarda nada).
function previsualizarAntesDeAprobar(
    documento: 'comprobante' | 'formato-oficial',
    titulo: string,
) {
    previewActivo.value = {
        url: vistaPrevia.url([props.solicitud.id, documento]),
        nombre: `${titulo} (vista previa).pdf`,
    };
    previewAbierto.value = true;
}

const vistaPreviaPermiso = computed(() =>
    props.permiso &&
    props.vistasPrevias.some((v) => v.documento === 'comprobante')
        ? vistaPrevia.url([props.solicitud.id, 'comprobante'])
        : null,
);

function previsualizarAdjunto(doc: SolicitudInternaDocumentoItem) {
    previewActivo.value = {
        url: verDocumento.url([props.solicitud.id, doc.id]),
        nombre: doc.original_name,
    };
    previewAbierto.value = true;
}
</script>

<template>
    <Head :title="`Solicitud ${solicitud.folio}`" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            detalle
            :titulo="`Solicitud ${solicitud.folio}`"
            :descripcion="`${solicitud.colaborador?.name ?? solicitud.usuario?.name ?? ''} ${solicitud.colaborador?.apellidos ?? solicitud.usuario?.apellidos ?? ''}`"
            :icono="ClipboardList"
        >
            <EstadoBadge :estado="solicitud.estado" />
        </CrudPageHeader>

        <div class="grid gap-6 lg:grid-cols-12 lg:items-start">
            <!-- Columna principal -->
            <div class="flex flex-col gap-6 lg:col-span-8 xl:col-span-9">
                <FlujoAprobacion
                    data-tour="solicitud-cadena"
                    :solicitud-id="solicitud.id"
                    :flujo="flujo"
                    con-acciones
                />
                <PermisoAutorizacionCard
                    v-if="permiso"
                    :permiso="permiso"
                    :pdf-url="
                        puedeDescargarFormato
                            ? permisoPdf.url(solicitud.id)
                            : null
                    "
                    :vista-previa-url="vistaPreviaPermiso"
                />
                <!-- Actualización de datos: al autorizar, lo propuesto pasa al expediente. -->
                <ComparativoDatos
                    v-if="datosPropuestos?.length"
                    :datos="datosPropuestos"
                    :aplicada="solicitud.estado === 'aprobada'"
                />
                <div
                    class="grid gap-4 rounded-2xl border border-border/60 bg-card p-5"
                >
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <p class="text-sm text-muted-foreground">Puesto</p>
                            <p class="text-base font-medium">
                                {{
                                    solicitud.colaborador?.puesto?.nombre ??
                                    solicitud.usuario?.colaborador?.puesto
                                        ?.nombre ??
                                    '—'
                                }}
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-muted-foreground">
                                Sucursal
                            </p>
                            <p class="text-base font-medium">
                                {{
                                    solicitud.colaborador?.sucursal_principal
                                        ?.nombre ??
                                    solicitud.usuario?.colaborador
                                        ?.sucursal_principal?.nombre ??
                                    '—'
                                }}
                            </p>
                        </div>
                        <div>
                            <p class="text-sm text-muted-foreground">Tipo</p>
                            <p class="text-base font-medium">
                                {{ tipoEtiqueta }}
                            </p>
                        </div>
                        <div v-if="solicitud.fecha_inicio">
                            <p class="text-sm text-muted-foreground">
                                {{ solicitud.fecha_fin ? 'Periodo' : 'Fecha' }}
                            </p>
                            <p class="text-base font-medium">
                                {{
                                    formatearPeriodo(
                                        solicitud.fecha_inicio,
                                        solicitud.fecha_fin,
                                    )
                                }}
                            </p>
                        </div>
                        <div v-if="solicitud.dias_solicitados">
                            <p class="text-sm text-muted-foreground">
                                Días solicitados
                            </p>
                            <p class="text-base font-medium">
                                {{ solicitud.dias_solicitados }}
                            </p>
                        </div>
                        <div v-if="solicitud.monto_solicitado">
                            <p class="text-sm text-muted-foreground">
                                Monto solicitado
                            </p>
                            <p class="text-base font-medium">
                                ${{ solicitud.monto_solicitado }}
                                <span v-if="solicitud.plazo_meses"
                                    >a {{ solicitud.plazo_meses }} meses</span
                                >
                            </p>
                        </div>
                        <div
                            v-if="
                                solicitud.objetivo_colaborador ||
                                solicitud.colaborador_objetivo
                            "
                        >
                            <p class="text-sm text-muted-foreground">
                                Colaborador a dar de baja
                            </p>
                            <p class="text-base font-medium">
                                {{
                                    (solicitud.objetivo_colaborador ??
                                        solicitud.colaborador_objetivo)!.name
                                }}
                                {{
                                    (solicitud.objetivo_colaborador ??
                                        solicitud.colaborador_objetivo)!
                                        .apellidos ?? ''
                                }}
                            </p>
                        </div>
                        <div v-if="solicitud.fecha_efectiva">
                            <p class="text-sm text-muted-foreground">
                                Fecha efectiva de baja
                            </p>
                            <p class="text-base font-medium">
                                {{ formatearFecha(solicitud.fecha_efectiva) }}
                            </p>
                        </div>
                        <div v-if="solicitud.tipo_baja">
                            <p class="text-sm text-muted-foreground">
                                Tipo de baja
                            </p>
                            <p class="text-base font-medium">
                                {{ tipoBajaEtiqueta }}
                            </p>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm text-muted-foreground">Motivo</p>
                        <p class="text-sm">{{ solicitud.motivo }}</p>
                    </div>
                    <div v-if="solicitud.observaciones">
                        <p class="text-sm text-muted-foreground">
                            Observaciones
                        </p>
                        <p class="text-sm">{{ solicitud.observaciones }}</p>
                    </div>
                </div>

                <!-- Formato oficial del proceso (permiso / préstamo): lo decide
                     el backend; se genera, imprime y firma aquí mismo. -->
                <DocumentosProceso
                    v-for="d in documentosProceso"
                    :key="`${d.tipo}-${d.id}`"
                    :tipo="d.tipo"
                    :id="d.id"
                />

                <div class="rounded-2xl border border-border/60 bg-card p-5">
                    <h3 class="mb-3 text-sm font-semibold">
                        {{
                            esBaja
                                ? 'Evidencia / firma del gerente'
                                : 'Documentos adjuntos'
                        }}
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
                        v-if="!esFinal && !bajaEnCierre"
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
                    :finiquito="solicitud.finiquito_calculo ?? null"
                    :desglose="finiquitoDesglose"
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
                            <p class="text-base font-medium">
                                {{ evento.accion_etiqueta }}
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
                            <p class="text-sm text-muted-foreground">
                                {{ formatearFechaHora(evento.created_at) }}
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
                    v-if="bajaEnCierre"
                    class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-5"
                >
                    <h3 class="flex items-center gap-2 text-sm font-semibold">
                        <Lock class="size-4" />
                        Solo consulta
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{ bajaEnCierre.mensaje }}
                    </p>
                    <Button
                        v-if="bajaEnCierre.colaboradorId"
                        variant="outline"
                        as-child
                    >
                        <Link
                            :href="
                                cicloColaborador.url(bajaEnCierre.colaboradorId)
                            "
                        >
                            Ir al Cierre laboral
                        </Link>
                    </Button>
                </div>

                <div
                    v-else-if="tieneAcciones"
                    data-tour="solicitud-acciones"
                    class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-5"
                >
                    <h3 class="text-sm font-semibold">Acciones de revisión</h3>
                    <Textarea
                        v-model="comentario"
                        placeholder="Comentario (opcional)"
                        rows="2"
                    />
                    <div class="flex flex-col gap-2">
                        <p
                            v-if="faltanVistosBuenos && flujo.pendiente_de"
                            class="rounded-xl bg-[var(--mrl-accent)]/10 p-3 text-xs text-[var(--mrl-texto)]"
                        >
                            <span class="block font-semibold">{{
                                flujo.pendiente_de.texto
                            }}</span>
                            <span v-if="motivoEsperaRh" class="mt-0.5 block">{{
                                motivoEsperaRh
                            }}</span>
                        </p>
                        <Button
                            v-if="aprobarEnEspera"
                            variant="success"
                            disabled
                            :title="motivoEsperaRh ?? undefined"
                            >{{
                                esPrestamo ? 'Autorizar préstamo' : 'Aprobar'
                            }}</Button
                        >
                        <Button
                            v-if="
                                puedeRevisar &&
                                solicitud.estado === 'enviada' &&
                                !faltanVistosBuenos
                            "
                            variant="secondary"
                            :disabled="formAccion.processing"
                            @click="revisarSolicitud"
                            >Pasar a pendiente de autorizar</Button
                        >
                        <Button
                            v-if="puedePedirCorreccion"
                            class="border border-warning/40 bg-warning/10 text-warning hover:bg-warning/20"
                            variant="ghost"
                            :disabled="formAccion.processing"
                            @click="pedirCorreccion"
                            >Pedir corrección</Button
                        >
                        <div
                            v-if="vistasPrevias.length"
                            class="flex flex-col gap-2 rounded-xl border border-dashed border-border p-3"
                        >
                            <p class="text-xs text-muted-foreground">
                                Revisa el documento que se generará al aprobar:
                            </p>
                            <Button
                                v-for="v in vistasPrevias"
                                :key="v.documento"
                                variant="outline"
                                size="sm"
                                @click="
                                    previsualizarAntesDeAprobar(
                                        v.documento,
                                        v.titulo,
                                    )
                                "
                            >
                                <FileSearch class="size-4" />
                                Vista previa: {{ v.titulo }}
                            </Button>
                        </div>
                        <Button
                            v-if="
                                esPrestamo &&
                                puedeAutorizarPrestamo &&
                                !faltanVistosBuenos
                            "
                            variant="success"
                            @click="autorizandoPrestamo = true"
                            >Autorizar préstamo</Button
                        >
                        <Button
                            v-if="
                                !esPrestamo &&
                                puedeAprobar &&
                                !faltanVistosBuenos
                            "
                            variant="success"
                            :disabled="formAccion.processing"
                            @click="aprobarSolicitud"
                            >Aprobar</Button
                        >
                        <Button
                            v-if="puedeRechazar && !mostrandoRechazo"
                            variant="outline"
                            @click="mostrandoRechazo = true"
                            >Rechazar</Button
                        >
                    </div>

                    <div
                        v-if="puedeRechazar && mostrandoRechazo"
                        class="flex flex-col gap-2 border-t border-border/60 pt-3"
                    >
                        <Textarea
                            v-model="motivoRechazo"
                            placeholder="Motivo del rechazo (obligatorio)"
                            rows="2"
                        />
                        <Button
                            variant="secondary"
                            :disabled="
                                formRechazar.processing || !motivoRechazo
                            "
                            @click="rechazarSolicitud"
                            >Confirmar rechazo</Button
                        >
                    </div>
                </div>

                <div
                    v-else-if="!esFinal"
                    class="rounded-2xl border border-border/60 bg-card p-5"
                >
                    <h3 class="flex items-center gap-2 text-sm font-semibold">
                        <Lock class="size-4" />
                        Solo consulta
                    </h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        No tienes permiso para revisar, aprobar ni rechazar esta
                        solicitud.
                    </p>
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
                        class="mt-3 rounded-lg bg-muted p-3 text-sm text-foreground"
                    >
                        {{ solicitud.motivo_rechazo }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <AutorizarPrestamoDialog
        v-if="esPrestamo && puedeAutorizarPrestamo"
        v-model:open="autorizandoPrestamo"
        :solicitud-id="solicitud.id"
        :monto-solicitado="solicitud.monto_solicitado"
    />

    <DocumentPreviewDialog
        :open="previewAbierto"
        :preview-url="previewActivo?.url ?? null"
        :download-url="previewActivo?.descarga ?? null"
        :nombre="previewActivo?.nombre ?? ''"
        @update:open="(v) => (previewAbierto = v)"
    />
</template>
