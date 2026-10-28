<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Brain,
    FileText,
    FolderOpen,
    Home,
    Paperclip,
    Pencil,
    QrCode,
    ShieldAlert,
    UserRound,
    Video,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    decidir,
    solicitar as solicitarIntervencion,
} from '@/actions/App/Http/Controllers/Rh/IntervencionCandidatoController';
import AccionesCiclo from '@/components/ciclo/AccionesCiclo.vue';
import AprobacionesResumen from '@/components/ciclo/AprobacionesResumen.vue';
import CicloEstadoPanel from '@/components/ciclo/CicloEstadoPanel.vue';
import CicloStepper from '@/components/ciclo/CicloStepper.vue';
import CicloTimeline from '@/components/ciclo/CicloTimeline.vue';
import Casilla from '@/components/Common/Casilla.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import DateTimePicker from '@/components/Common/DateTimePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import CandidatoExpedientePanel from '@/components/Rh/CandidatoExpedientePanel.vue';
import CandidatoFormDialog from '@/components/Rh/CandidatoFormDialog.vue';
import CandidatoPerfilPuesto from '@/components/Rh/CandidatoPerfilPuesto.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    archivoCvCandidato,
    archivosDelExpediente,
    claveEvidencia,
} from '@/lib/archivosCandidato';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import {
    autorizar,
    contratacion,
    descartar,
    devolver,
    entrevista,
    index,
    perfil,
    rechazar,
    socioeconomico,
} from '@/routes/rh/candidatos';
import psicometricas from '@/routes/rh/candidatos/psicometricas';
import referencias from '@/routes/rh/candidatos/referencias';
import socioeconomicoRutas from '@/routes/rh/candidatos/socioeconomico';
import { show as verInvitacion } from '@/routes/rh/incorporacion/invitaciones';
import type {
    AccionCiclo,
    AntecedenteBaja,
    CandidatoFicha,
    CandidatoItem,
    EstadoCadenaContratacion,
    EstadoCiclo,
    EvidenciaCandidato,
    FormularioAccion,
    LimitesArchivosCandidato,
    PerfilPuestoCandidato,
    OpcionesReclutamiento,
} from '@/types';

const props = defineProps<{
    candidato: CandidatoFicha;
    ciclo: EstadoCiclo;
    opciones: OpcionesReclutamiento & {
        salidas: { value: string; etiqueta: string }[];
        resultados: { value: string; etiqueta: string }[];
        resultadosReferencia: { value: string; etiqueta: string }[];
        tiposContratacion: { value: string; etiqueta: string }[];
    };
    /** Intervención pendiente que el usuario actual tiene autoridad para decidir (null si no aplica). */
    intervencionPendienteId: number | null;
    /** Puede agregar archivos al mini expediente (CandidatoPolicy::adjuntar). */
    puedeAdjuntar: boolean;
    limitesArchivos: LimitesArchivosCandidato;
    perfilPuesto: PerfilPuestoCandidato;
    /** RN-09: baja previa «No recontratable» / «Con reservas». */
    antecedenteBaja?: AntecedenteBaja | null;
    /** Candidato → contratación → expediente (null = aún no seleccionado). */
    cadenaContratacion?: EstadoCadenaContratacion | null;
}>();

/** El paso del proceso ya es esta ficha: no se ofrece un enlace a sí misma. */
const procesoEsEstaFicha = computed(
    () =>
        !!props.cadenaContratacion?.proceso_url &&
        props.cadenaContratacion.proceso_url.includes(
            `/rh/candidatos/${props.candidato.id}`,
        ),
);

const ACEPTA_DOCUMENTOS = '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx';
const ACEPTA_SOCIOECONOMICO =
    '.mp4,.mov,.m4v,.3gp,.webm,.jpg,.jpeg,.png,.webp,.pdf';

defineOptions({
    layout: (pageProps: { candidato: CandidatoFicha }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Candidatos', href: index.url() },
            { title: pageProps.candidato.nombre_completo, href: '' },
        ],
    }),
});

const editar = ref(false);
const accionActiva = ref<AccionCiclo | null>(null);
const evidenciaActiva = ref<EvidenciaCandidato | null>(null);

const inicialesCandidato = computed(() => {
    const partes = props.candidato.nombre_completo.split(/\s+/).filter(Boolean);

    return (
        (partes[0]?.[0] ?? '') + (partes[1]?.[0] ?? partes[0]?.[1] ?? '')
    ).toUpperCase();
});

// El formulario de edición del candidato espera la forma del listado.
const candidatoFormulario = computed(
    () =>
        ({
            ...props.candidato,
            empresa: props.candidato.empresa_id
                ? {
                      id: props.candidato.empresa_id,
                      nombre: props.candidato.empresa ?? '',
                  }
                : null,
            sucursal: props.candidato.sucursal_id
                ? {
                      id: props.candidato.sucursal_id,
                      nombre: props.candidato.sucursal ?? '',
                  }
                : null,
            departamento: props.candidato.departamento_id
                ? {
                      id: props.candidato.departamento_id,
                      nombre: props.candidato.departamento ?? '',
                  }
                : null,
            puesto_objetivo: props.candidato.puesto_objetivo_id
                ? {
                      id: props.candidato.puesto_objetivo_id,
                      nombre: props.candidato.puesto ?? '',
                  }
                : null,
            vacante: props.candidato.vacante_id
                ? {
                      id: props.candidato.vacante_id,
                      puesto_id: props.candidato.puesto_objetivo_id,
                  }
                : null,
            campana_reclutamiento_id:
                props.candidato.campana_reclutamiento_id ?? null,
        }) as unknown as CandidatoItem,
);

const form = useForm<FormularioAccion>({});

const hoy = new Date().toISOString().slice(0, 10);
/** Rechazo en cualquier fase: motivo del catálogo + «¿puede considerarse nuevamente?». */
const rechazoInicial = () => ({ motivo_rechazo_id: null, recontratable: true });
const camposPorAccion: Record<string, () => FormularioAccion> = {
    evaluar_perfil: () => ({
        viable: true,
        observaciones: '',
        ...rechazoInicial(),
    }),
    registrar_entrevista: () => ({
        realizada_en: `${hoy}T10:00`,
        resultado: 'viable',
        observaciones: '',
        ...rechazoInicial(),
    }),
    enviar_psicometricas: () => ({ link: '' }),
    registrar_resultados_psicometricas: () => ({
        resumen: '',
        archivos: [] as File[],
    }),
    revisar_psicometricas: () => ({
        viable: true,
        observaciones: '',
        ...rechazoInicial(),
    }),
    registrar_socioeconomico: () => ({
        fecha_visita: hoy,
        direccion: '',
        checklist: {
            vivienda_en_orden: false,
            vive_con_familia: false,
            arraigo_anios: 0,
            arraigo_meses: 0,
            resguardo_motocicleta: false,
        },
        riesgos: '',
        observaciones: '',
        evidencias: [] as File[],
    }),
    revisar_socioeconomico: () => ({
        viable: true,
        observaciones: '',
        ...rechazoInicial(),
    }),
    registrar_referencia: () => ({
        empresa: '',
        contacto: '',
        telefono: '',
        relacion_puesto: '',
        resultado: 'positiva',
        observaciones: '',
        archivos: [] as File[],
    }),
    concluir_referencias: () => ({
        viable: true,
        observaciones: '',
        ...rechazoInicial(),
    }),
    autorizar_rh: () => ({ comentario: '' }),
    devolver_rh: () => ({ motivo: '' }),
    rechazar_rh: () => ({
        motivo: '',
        motivo_rechazo_id: null,
        recontratable: true,
    }),
    solicitar_intervencion: () => ({ motivo: '' }),
    descartar: () => ({
        estado: props.opciones.salidas[0]?.value ?? 'no_viable',
        motivo: '',
        motivo_rechazo_id: null,
        recontratable: true,
    }),
    iniciar_contratacion: () => ({
        sueldo_mensual: '',
        fecha_ingreso: hoy,
        tipo_contratacion: 'capacitacion_inicial',
        fecha_fin_contrato: '',
        duracion_horas: 24,
    }),
};

function abrir(accion: AccionCiclo) {
    form.clearErrors();
    const inicial = camposPorAccion[accion.clave]?.() ?? {};
    form.defaults(inicial);
    form.reset();
    Object.assign(form, inicial);
    accionActiva.value = accion;
}

function url(clave: string): string {
    const id = props.candidato.id;

    return (
        {
            evaluar_perfil: perfil.url(id),
            registrar_entrevista: entrevista.url(id),
            enviar_psicometricas: psicometricas.link.url(id),
            registrar_resultados_psicometricas:
                psicometricas.resultados.url(id),
            revisar_psicometricas: psicometricas.revision.url(id),
            registrar_socioeconomico: socioeconomico.url(id),
            revisar_socioeconomico: socioeconomicoRutas.revision.url(id),
            registrar_referencia: referencias.store.url(id),
            concluir_referencias: referencias.concluir.url(id),
            autorizar_rh: autorizar.url(id),
            devolver_rh: devolver.url(id),
            rechazar_rh: rechazar.url(id),
            solicitar_intervencion: solicitarIntervencion.url(id),
            descartar: descartar.url(id),
            iniciar_contratacion: contratacion.url(id),
        } as Record<string, string>
    )[clave];
}

/** Error de un campo de archivos múltiples: el general o el del primer archivo (`archivos.0`). */
function errorDeArchivos(campo: string): string | undefined {
    const errores = form.errors as Record<string, string | undefined>;

    return (
        errores[campo] ??
        Object.entries(errores).find(([clave]) =>
            clave.startsWith(`${campo}.`),
        )?.[1]
    );
}

function alElegirMotivoRechazoForm(id: string) {
    const motivoId = id ? Number(id) : null;
    form.motivo_rechazo_id = motivoId;
    const motivo = props.opciones.motivosRechazo?.find(
        (m) => m.id === motivoId,
    );
    form.recontratable = motivo ? !motivo.no_recontratable_por_defecto : true;
}

function enviar() {
    const accion = accionActiva.value;

    if (!accion) {
        return;
    }

    // El comentario es opcional en pantalla; si RH no escribió nada, el
    // motivo del catálogo (obligatorio) sirve de texto para el histórico.
    const catalogo = props.opciones.motivosRechazo?.find(
        (m) => m.id === form.motivo_rechazo_id,
    );

    if (['rechazar_rh', 'descartar'].includes(accion.clave)) {
        const motivoTexto = String(form.motivo ?? '').trim();

        if (motivoTexto === '') {
            form.motivo = catalogo?.nombre ?? '';
        }
    }

    // Rechazo en una fase (no viable): sin observaciones escritas, el
    // motivo del catálogo queda como nota del histórico.
    const rechazaFase = form.viable === false || form.resultado === 'no_viable';

    if (
        rechazaFase &&
        catalogo &&
        String(form.observaciones ?? '').trim() === ''
    ) {
        form.observaciones = catalogo.nombre;
    }

    form.post(url(accion.clave), {
        preserveScroll: true,
        forceFormData: [
            'registrar_resultados_psicometricas',
            'registrar_socioeconomico',
            'registrar_referencia',
        ].includes(accion.clave),
        onSuccess: () => (accionActiva.value = null),
    });
}

const cvAbierto = ref(false);
const accesos = computed(() => props.candidato.accesos_rapidos);

/**
 * Todo el mini expediente (CV + evidencias de cada fase) como una sola
 * galería del visor: se abre en el archivo que se tocó y se puede pasar al
 * siguiente sin cerrar.
 */
const archivosVisor = computed(() => {
    const c = props.candidato;
    const extras = [
        ...c.psicometricas.flatMap((p) => p.evidencias),
        ...c.socioeconomicos.flatMap((s) => s.evidencias),
        ...c.referencias.flatMap((r) => r.evidencias),
        ...[
            accesos.value?.psicometrico,
            accesos.value?.socioeconomico,
            accesos.value?.video,
        ].filter((ev): ev is EvidenciaCandidato => Boolean(ev)),
    ];
    const archivos = archivosDelExpediente(c.id, c.expediente, extras);

    if (c.tiene_cv && !archivos.some((a) => a.clave === 'cv')) {
        archivos.unshift(
            archivoCvCandidato(c.id, { nombre: c.expediente.cv?.nombre }),
        );
    }

    return archivos;
});

const visorAbierto = computed(
    () => cvAbierto.value || evidenciaActiva.value !== null,
);

const indiceVisor = computed(() => {
    const clave = evidenciaActiva.value
        ? claveEvidencia(evidenciaActiva.value)
        : 'cv';

    return Math.max(
        archivosVisor.value.findIndex((a) => a.clave === clave),
        0,
    );
});

function cerrarVisor(abierto: boolean) {
    if (!abierto) {
        cvAbierto.value = false;
        evidenciaActiva.value = null;
    }
}

/** Evidencia de la etapa que corresponde revisar en este momento. */
const evidenciaDeLaRevision = computed(() => {
    const clave = accionActiva.value?.clave;

    if (clave === 'revisar_psicometricas') {
        return accesos.value?.psicometrico ?? null;
    }

    if (clave === 'revisar_socioeconomico') {
        return accesos.value?.socioeconomico ?? accesos.value?.video ?? null;
    }

    return null;
});

const decisionIntervencion = ref<{ aprueba: boolean } | null>(null);
const formDecision = useForm({ comentario: '' });

function abrirDecision(aprueba: boolean) {
    formDecision.reset();
    formDecision.comentario = '';
    decisionIntervencion.value = { aprueba };
}

function enviarDecision() {
    const pendiente = decisionIntervencion.value;

    if (!pendiente || !props.intervencionPendienteId) {
        return;
    }

    formDecision
        .transform((datos) => ({ ...datos, aprueba: pendiente.aprueba }))
        .post(decidir.url(props.intervencionPendienteId), {
            preserveScroll: true,
            onSuccess: () => (decisionIntervencion.value = null),
        });
}

const accionRegistrarReferencia = computed(() =>
    props.ciclo.acciones_permitidas.find(
        (a) => a.clave === 'registrar_referencia',
    ),
);

const esDecisionViable = computed(() =>
    [
        'evaluar_perfil',
        'revisar_psicometricas',
        'revisar_socioeconomico',
        'concluir_referencias',
    ].includes(accionActiva.value?.clave ?? ''),
);

function fecha(valor: string | null): string {
    return valor
        ? new Date(valor).toLocaleString('es-MX', {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '—';
}
</script>

<template>
    <Head :title="candidato.nombre_completo" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            detalle
            :titulo="candidato.nombre_completo"
            :descripcion="`${candidato.puesto ?? 'Sin puesto objetivo'} · ${candidato.sucursal ?? 'Sin sucursal'}`"
            :icono="UserRound"
        >
            <template #icono>
                <!-- Foto oficial del expediente si ya hay colaborador
                     vinculado; si no (o si falla), iniciales. -->
                <Avatar class="size-11 shrink-0 rounded-2xl shadow-sm">
                    <AvatarImage
                        v-if="candidato.foto_url"
                        :src="candidato.foto_url"
                        :alt="`Fotografía de ${candidato.nombre_completo}`"
                        class="object-cover"
                    />
                    <AvatarFallback
                        class="rounded-2xl bg-primary/10 text-sm font-semibold text-primary"
                        >{{ inicialesCandidato }}</AvatarFallback
                    >
                </Avatar>
            </template>
            <Button variant="secondary" @click="editar = true">
                <Pencil class="size-4" />
                Editar datos
            </Button>
        </CrudPageHeader>

        <!-- RN-09: baja previa no recontratable (módulo de Bajas). -->
        <p
            v-if="antecedenteBaja"
            role="alert"
            :class="[
                'rounded-2xl px-4 py-3 text-sm',
                antecedenteBaja.recontratable === 'no'
                    ? 'bg-danger-soft text-destructive'
                    : 'bg-warning-soft text-warning',
            ]"
        >
            <strong
                >Baja previa «{{ antecedenteBaja.recontratable_etiqueta }}»
                recontratable:</strong
            >
            {{ antecedenteBaja.nombre }} · {{ antecedenteBaja.folio }} · baja
            {{ antecedenteBaja.fecha_baja ?? '—' }}
            <template v-if="antecedenteBaja.sucursal">
                · {{ antecedenteBaja.sucursal }}</template
            ><template v-if="antecedenteBaja.causal">
                · {{ antecedenteBaja.causal }}</template
            >
            (coincide por {{ antecedenteBaja.coincidencia }}). Consulta con RH
            antes de continuar.
        </p>

        <!-- Seleccionado → contratación → expediente: el candidato no
             desaparece al avanzar y lleva directo al proceso. -->
        <section
            v-if="cadenaContratacion"
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-crema/60 p-4 sm:flex-row sm:items-center sm:justify-between"
            aria-label="Estado de contratación"
            data-cadena-contratacion
        >
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <ChipEstado
                    :etiqueta="cadenaContratacion.etiqueta"
                    :tono="cadenaContratacion.tono"
                    tamano="md"
                />
                <span
                    v-if="cadenaContratacion.detalle"
                    class="text-sm text-muted-foreground"
                    >{{ cadenaContratacion.detalle }}</span
                >
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="cadenaContratacion.expediente_url"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="cadenaContratacion.expediente_url"
                        data-accion="ver-expediente"
                    >
                        <FolderOpen class="size-4" />
                        {{ cadenaContratacion.expediente_etiqueta }}
                    </Link>
                </Button>
                <Button
                    v-if="cadenaContratacion.proceso_url && !procesoEsEstaFicha"
                    size="sm"
                    variant="outline"
                    as-child
                >
                    <Link :href="cadenaContratacion.proceso_url">
                        {{ cadenaContratacion.proceso_etiqueta }}
                        <ArrowRight class="size-4" />
                    </Link>
                </Button>
            </div>
        </section>

        <!-- Resumen ejecutivo: quién, a qué puesto, en qué etapa, quién sigue -->
        <section
            class="grid gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5 lg:grid-cols-[1fr_auto]"
            aria-label="Resumen del candidato"
        >
            <dl
                class="grid gap-x-6 gap-y-3 text-base sm:grid-cols-2 xl:grid-cols-3"
            >
                <div>
                    <dt class="text-xs text-muted-foreground">Puesto</dt>
                    <dd class="font-semibold">{{ candidato.puesto ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Sucursal</dt>
                    <dd class="font-semibold">
                        {{ candidato.sucursal ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Etapa</dt>
                    <dd>
                        <span
                            class="inline-flex rounded-full bg-salvia px-2.5 py-0.5 text-sm font-medium text-[var(--mrl-petroleo)]"
                            >{{ candidato.estado_etiqueta }}</span
                        >
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Responsable</dt>
                    <dd class="font-semibold">
                        {{
                            ciclo.responsable_actual.nombre ??
                            ciclo.responsable_actual.rol
                        }}
                        <span
                            v-if="ciclo.responsable_actual.nombre"
                            class="block text-xs font-normal text-muted-foreground"
                            >{{ ciclo.responsable_actual.rol }}</span
                        >
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">
                        Siguiente acción
                    </dt>
                    <dd class="font-semibold">
                        {{
                            ciclo.siguiente_accion?.etiqueta ??
                            'Sin acciones pendientes'
                        }}
                    </dd>
                </div>
            </dl>

            <div
                class="flex flex-wrap content-start gap-2 lg:max-w-xs lg:justify-end"
                aria-label="Documentos clave"
            >
                <Button
                    v-if="accesos?.psicometrico"
                    size="sm"
                    variant="outline"
                    @click="evidenciaActiva = accesos.psicometrico"
                >
                    <Brain class="size-4" /> Ver psicométrico
                </Button>
                <Button
                    v-if="accesos?.socioeconomico"
                    size="sm"
                    variant="outline"
                    @click="evidenciaActiva = accesos.socioeconomico"
                >
                    <Home class="size-4" /> Ver socioeconómico
                </Button>
                <Button
                    v-if="accesos?.video"
                    size="sm"
                    variant="outline"
                    @click="evidenciaActiva = accesos.video"
                >
                    <Video class="size-4" /> Ver video
                </Button>
                <Button
                    v-if="candidato.tiene_cv"
                    size="sm"
                    variant="outline"
                    @click="cvAbierto = true"
                >
                    <FileText class="size-4" /> Ver CV
                </Button>
            </div>
        </section>

        <CicloStepper data-tour="candidato-pasos" :pasos="ciclo.pasos" />

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="flex flex-col gap-5 lg:col-span-2">
                <CicloEstadoPanel data-tour="candidato-estado" :ciclo="ciclo">
                    <AccionesCiclo
                        data-tour="candidato-acciones"
                        :acciones="ciclo.acciones_permitidas"
                        :siguiente="ciclo.siguiente_accion?.clave"
                        @accion="abrir"
                    />
                </CicloEstadoPanel>

                <section
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <h2 class="mb-3 text-sm font-semibold">
                        Datos del candidato
                    </h2>
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-muted-foreground">Teléfono</dt>
                            <dd>{{ candidato.telefono ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Correo</dt>
                            <dd>{{ candidato.correo ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Fuente</dt>
                            <dd>{{ candidato.fuente_etiqueta ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Campaña</dt>
                            <dd>{{ candidato.campana ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Reclutamiento</dt>
                            <dd>{{ candidato.responsable_rh ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Gerente</dt>
                            <dd>{{ candidato.gerente ?? '—' }}</dd>
                        </div>
                        <div
                            v-if="candidato.motivo_rechazo"
                            class="sm:col-span-2"
                        >
                            <dt class="text-muted-foreground">
                                ¿Por qué ya no continuó?
                            </dt>
                            <dd>
                                {{ candidato.motivo_rechazo }}
                                <span
                                    v-if="candidato.motivo_salida"
                                    class="block text-xs text-muted-foreground"
                                    >{{ candidato.motivo_salida }}</span
                                >
                            </dd>
                        </div>
                        <div
                            v-if="candidato.respuestas_filtro.length"
                            class="sm:col-span-2"
                        >
                            <dt class="text-muted-foreground">
                                Requisitos del puesto
                            </dt>
                            <dd class="mt-1 flex flex-col gap-1">
                                <span
                                    v-for="(
                                        r, i
                                    ) in candidato.respuestas_filtro"
                                    :key="i"
                                    class="flex items-center justify-between gap-3 rounded-md px-2 py-1"
                                    :class="
                                        r.cumple
                                            ? 'bg-success-soft'
                                            : 'bg-danger-soft'
                                    "
                                >
                                    <span>{{ r.pregunta }}</span>
                                    <span class="font-medium">{{
                                        r.respuesta ? 'Sí' : 'No'
                                    }}</span>
                                </span>
                            </dd>
                        </div>
                    </dl>
                </section>

                <CandidatoPerfilPuesto
                    v-if="perfilPuesto"
                    :perfil="perfilPuesto"
                />

                <CandidatoExpedientePanel
                    :candidato-id="candidato.id"
                    :expediente="candidato.expediente"
                    :puede-adjuntar="puedeAdjuntar"
                    :limites="limitesArchivos"
                    :socioeconomico-registrado="
                        candidato.socioeconomicos.length > 0
                    "
                    :hay-referencias="candidato.referencias.length > 0"
                    @ver="(ev) => (evidenciaActiva = ev)"
                    @ver-cv="cvAbierto = true"
                />

                <section
                    v-if="
                        candidato.entrevistas.length ||
                        candidato.psicometricas.length ||
                        candidato.socioeconomicos.length ||
                        candidato.referencias.length
                    "
                    class="flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                    aria-label="Evaluaciones del reclutamiento"
                >
                    <h2 class="text-sm font-semibold">
                        Evaluaciones registradas
                    </h2>

                    <article
                        v-for="item in candidato.entrevistas"
                        :key="`e${item.id}`"
                        class="rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm"
                    >
                        <p class="font-medium">
                            Entrevista · {{ item.resultado_etiqueta }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ fecha(item.realizada_en) }} ·
                            {{ item.entrevistador ?? '—' }}
                        </p>
                        <p v-if="item.observaciones" class="mt-1">
                            {{ item.observaciones }}
                        </p>
                    </article>

                    <article
                        v-for="item in candidato.psicometricas"
                        :key="`p${item.id}`"
                        class="rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm"
                    >
                        <p class="font-medium">
                            Psicométricas<template
                                v-if="item.revision_resultado"
                            >
                                · revisión:
                                {{
                                    item.revision_resultado === 'viable'
                                        ? 'en perfil'
                                        : 'fuera de perfil'
                                }}</template
                            >
                        </p>
                        <p
                            v-if="item.link"
                            class="text-xs text-muted-foreground"
                        >
                            Link enviado {{ fecha(item.enviada_en) }}
                        </p>
                        <p v-if="item.resumen_resultados" class="mt-1">
                            {{ item.resumen_resultados }}
                        </p>
                        <div
                            v-if="item.evidencias.length"
                            class="mt-2 flex flex-wrap gap-2"
                        >
                            <Button
                                v-for="ev in item.evidencias"
                                :key="ev.id"
                                size="sm"
                                variant="outline"
                                @click="evidenciaActiva = ev"
                            >
                                <Brain class="size-4" /> Psicométrico ·
                                {{ ev.nombre }}
                            </Button>
                        </div>
                    </article>

                    <article
                        v-for="item in candidato.socioeconomicos"
                        :key="`s${item.id}`"
                        class="rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm"
                    >
                        <p class="font-medium">
                            Estudio socioeconómico ·
                            {{ item.resultado_etiqueta }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatearFecha(item.fecha_visita) }} ·
                            {{ item.visitador ?? '—' }} · {{ item.direccion }}
                        </p>
                        <ul class="mt-1 flex flex-wrap gap-x-4 text-xs">
                            <li
                                v-for="criterio in item.criterios"
                                :key="criterio.clave"
                            >
                                {{ criterio.etiqueta }}:
                                <strong>{{ criterio.valor }}</strong>
                            </li>
                        </ul>
                        <p v-if="item.riesgos" class="mt-1">
                            Riesgos: {{ item.riesgos }}
                        </p>
                        <p v-if="item.observaciones" class="mt-1">
                            {{ item.observaciones }}
                        </p>
                        <div
                            v-if="item.evidencias.length"
                            class="mt-2 flex flex-wrap gap-2"
                        >
                            <Button
                                v-for="ev in item.evidencias"
                                :key="ev.id"
                                size="sm"
                                variant="outline"
                                @click="evidenciaActiva = ev"
                            >
                                <component
                                    :is="ev.tipo === 'video' ? Video : Home"
                                    class="size-4"
                                />
                                Socioeconómico · {{ ev.nombre }}
                            </Button>
                        </div>
                    </article>

                    <article
                        v-for="item in candidato.referencias"
                        :key="`r${item.id}`"
                        class="rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm"
                    >
                        <p class="font-medium">
                            Referencia · {{ item.empresa }} ·
                            {{ item.resultado_etiqueta }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ item.contacto
                            }}<template v-if="item.relacion_puesto">
                                ({{ item.relacion_puesto }})</template
                            >
                            · {{ item.telefono ?? 'sin teléfono' }} · validó
                            {{ item.validada_por ?? '—' }} el
                            {{ formatearFecha(item.fecha_validacion) }}
                        </p>
                        <p v-if="item.observaciones" class="mt-1">
                            {{ item.observaciones }}
                        </p>
                        <div
                            v-if="item.evidencias.length"
                            class="mt-2 flex flex-wrap gap-2"
                        >
                            <Button
                                v-for="ev in item.evidencias"
                                :key="ev.id"
                                size="sm"
                                variant="outline"
                                @click="evidenciaActiva = ev"
                            >
                                <Paperclip class="size-4" /> Soporte ·
                                {{ ev.nombre }}
                            </Button>
                        </div>
                    </article>
                </section>

                <section
                    v-if="candidato.intervenciones.length"
                    class="rounded-2xl border border-warning/40 bg-warning/5 p-5 text-sm"
                    aria-label="Intervención sobre el rechazo de RH"
                >
                    <h2
                        class="mb-3 flex items-center gap-2 text-sm font-semibold text-warning"
                    >
                        <ShieldAlert class="size-4" /> Intervención sobre el
                        rechazo de RH
                    </h2>

                    <article
                        v-for="item in candidato.intervenciones"
                        :key="item.id"
                        class="mb-3 rounded-xl bg-[var(--mrl-fondo)] p-3 last:mb-0"
                    >
                        <p>
                            <strong>RH rechazó</strong>
                            <template v-if="item.rechazo_rh_por">
                                · {{ item.rechazo_rh_por }}</template
                            >
                            <template v-if="item.rechazo_rh_en">
                                · {{ fecha(item.rechazo_rh_en) }}</template
                            >
                        </p>
                        <p
                            v-if="item.rechazo_rh_motivo"
                            class="text-muted-foreground"
                        >
                            {{ item.rechazo_rh_motivo }}
                        </p>

                        <p class="mt-2">
                            <strong>Intervención solicitada por</strong>
                            {{ item.gerente_solicitante ?? '—' }} ·
                            {{ fecha(item.solicitada_en) }} ·
                            {{ item.ruta_etiqueta }}
                        </p>
                        <p class="text-muted-foreground">
                            {{ item.motivo_solicitud }}
                        </p>

                        <p class="mt-2">
                            <strong>{{ item.estado_etiqueta }}</strong>
                            <template v-if="item.aprobador">
                                · {{ item.aprobador }} ·
                                {{ fecha(item.decidida_en) }}</template
                            >
                        </p>
                        <p
                            v-if="item.comentario_decision"
                            class="text-muted-foreground"
                        >
                            {{ item.comentario_decision }}
                        </p>

                        <div
                            v-if="intervencionPendienteId === item.id"
                            class="mt-3 flex gap-2"
                        >
                            <Button size="sm" @click="abrirDecision(true)"
                                >Aprobar intervención</Button
                            >
                            <Button
                                size="sm"
                                variant="destructive"
                                @click="abrirDecision(false)"
                                >Confirmar rechazo</Button
                            >
                        </div>
                    </article>
                </section>

                <section
                    v-if="candidato.invitacion"
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5 text-sm"
                >
                    <h2 class="mb-2 text-sm font-semibold">
                        QR de contratación
                    </h2>
                    <p>
                        Estado:
                        <strong>{{
                            candidato.invitacion.estado_etiqueta
                        }}</strong>
                        · vence {{ fecha(candidato.invitacion.expira_en) }}
                    </p>
                    <Button as-child size="sm" variant="outline" class="mt-3">
                        <Link :href="verInvitacion.url(candidato.invitacion.id)"
                            ><QrCode class="size-4" /> Ver / regenerar QR</Link
                        >
                    </Button>
                </section>
            </div>

            <div class="flex flex-col gap-5">
                <AprobacionesResumen :aprobaciones="ciclo.aprobaciones" />
                <CicloTimeline :items="ciclo.timeline" />
            </div>
        </div>
    </div>

    <Dialog
        :open="accionActiva !== null"
        @update:open="(abierto: boolean) => !abierto && (accionActiva = null)"
    >
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ accionActiva?.etiqueta }}</DialogTitle>
                <DialogDescription
                    >{{ candidato.nombre_completo }} ·
                    {{ ciclo.estado.etiqueta }}</DialogDescription
                >
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="enviar">
                <!-- Concluir referencias sin ninguna registrada: se dice ANTES
                     de enviar (antes el 422 «referencias» no se pintaba y el
                     botón parecía no servir). -->
                <div
                    v-if="
                        accionActiva?.clave === 'concluir_referencias' &&
                        candidato.referencias.length === 0
                    "
                    class="flex flex-col gap-2 rounded-xl border border-warning/40 bg-warning-soft/60 p-3 text-sm"
                    role="alert"
                >
                    <p class="font-medium">
                        Primero registra al menos una referencia laboral.
                    </p>
                    <Button
                        v-if="accionRegistrarReferencia"
                        type="button"
                        size="sm"
                        variant="outline"
                        class="self-start"
                        @click="abrir(accionRegistrarReferencia)"
                    >
                        Registrar referencia
                    </Button>
                </div>
                <Button
                    v-if="evidenciaDeLaRevision"
                    type="button"
                    variant="outline"
                    class="self-start"
                    @click="evidenciaActiva = evidenciaDeLaRevision"
                >
                    <FileText class="size-4" />
                    {{
                        accionActiva?.clave === 'revisar_psicometricas'
                            ? 'Ver psicométrico'
                            : 'Ver socioeconómico'
                    }}
                </Button>
                <template v-if="esDecisionViable">
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            :variant="form.viable ? 'default' : 'outline'"
                            @click="form.viable = true"
                            >Viable</Button
                        >
                        <Button
                            type="button"
                            :variant="!form.viable ? 'destructive' : 'outline'"
                            @click="form.viable = false"
                            >No viable</Button
                        >
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="obs"
                            >Observaciones
                            {{
                                form.viable
                                    ? '(opcional)'
                                    : '(motivo obligatorio)'
                            }}</Label
                        >
                        <Textarea
                            id="obs"
                            v-model="form.observaciones as string"
                            rows="3"
                        />
                        <InputError :message="form.errors.observaciones" />
                    </div>
                    <template v-if="!form.viable">
                        <div class="grid gap-1.5">
                            <Label>Motivo del rechazo *</Label>
                            <SelectSimple
                                :model-value="
                                    form.motivo_rechazo_id
                                        ? String(form.motivo_rechazo_id)
                                        : ''
                                "
                                @update:model-value="alElegirMotivoRechazoForm"
                                :opciones="
                                    (opciones.motivosRechazo ?? []).map(
                                        (m) => ({
                                            value: String(m.id),
                                            label: m.etiqueta,
                                        }),
                                    )
                                "
                                placeholder="Elige un motivo"
                            />
                            <InputError
                                :message="form.errors.motivo_rechazo_id"
                            />
                        </div>
                        <label class="flex min-h-11 items-center gap-2 text-sm">
                            <Casilla v-model="form.recontratable as boolean" />
                            ¿Puede considerarse nuevamente?
                        </label>
                    </template>
                </template>

                <template
                    v-else-if="accionActiva?.clave === 'registrar_entrevista'"
                >
                    <div class="grid gap-1.5">
                        <Label>Fecha y hora</Label
                        ><DateTimePicker
                            v-model="form.realizada_en as string"
                        /><InputError :message="form.errors.realizada_en" />
                    </div>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            :variant="
                                form.resultado === 'viable'
                                    ? 'default'
                                    : 'outline'
                            "
                            @click="form.resultado = 'viable'"
                            >Sigue viable</Button
                        >
                        <Button
                            type="button"
                            :variant="
                                form.resultado === 'no_viable'
                                    ? 'destructive'
                                    : 'outline'
                            "
                            @click="form.resultado = 'no_viable'"
                            >No viable</Button
                        >
                    </div>
                    <InputError :message="form.errors.resultado" />
                    <div class="grid gap-1.5">
                        <Label
                            >Observaciones
                            {{
                                form.resultado === 'no_viable'
                                    ? '(motivo obligatorio)'
                                    : '(opcional)'
                            }}</Label
                        ><Textarea
                            v-model="form.observaciones as string"
                            rows="3"
                        /><InputError :message="form.errors.observaciones" />
                    </div>
                    <template v-if="form.resultado === 'no_viable'">
                        <div class="grid gap-1.5">
                            <Label>Motivo del rechazo *</Label>
                            <SelectSimple
                                :model-value="
                                    form.motivo_rechazo_id
                                        ? String(form.motivo_rechazo_id)
                                        : ''
                                "
                                @update:model-value="alElegirMotivoRechazoForm"
                                :opciones="
                                    (opciones.motivosRechazo ?? []).map(
                                        (m) => ({
                                            value: String(m.id),
                                            label: m.etiqueta,
                                        }),
                                    )
                                "
                                placeholder="Elige un motivo"
                            />
                            <InputError
                                :message="form.errors.motivo_rechazo_id"
                            />
                        </div>
                        <label class="flex min-h-11 items-center gap-2 text-sm">
                            <Casilla v-model="form.recontratable as boolean" />
                            ¿Puede considerarse nuevamente?
                        </label>
                    </template>
                </template>

                <template
                    v-else-if="accionActiva?.clave === 'enviar_psicometricas'"
                >
                    <div class="grid gap-1.5">
                        <Label for="link-psicometricas"
                            >Link de las pruebas</Label
                        ><Input
                            id="link-psicometricas"
                            v-model="form.link as string"
                            type="url"
                            placeholder="https://"
                        /><InputError :message="form.errors.link" />
                    </div>
                </template>

                <template
                    v-else-if="
                        accionActiva?.clave ===
                        'registrar_resultados_psicometricas'
                    "
                >
                    <p
                        class="rounded-lg bg-salvia/50 p-2 text-xs text-muted-foreground"
                    >
                        Cargar los resultados no los aprueba: quedan pendientes
                        de revisión y el responsable decide si el candidato está
                        en perfil.
                    </p>
                    <div class="grid gap-1.5">
                        <Label for="resumen-psicometricas"
                            >Resumen de resultados</Label
                        ><Textarea
                            id="resumen-psicometricas"
                            v-model="form.resumen as string"
                            rows="4"
                        /><InputError :message="form.errors.resumen" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label
                            >Reportes (PDF, imagen, Word o Excel; puedes subir
                            varios)</Label
                        ><PeopleFileDropzone
                            :model-value="(form.archivos as File[]) ?? []"
                            multiple
                            :accept="ACEPTA_DOCUMENTOS"
                            :max-size-mb="limitesArchivos.max_documento_mb"
                            label="Arrastra los archivos"
                            @update:model-value="(f) => (form.archivos = f)"
                        />
                        <InputError :message="errorDeArchivos('archivos')" />
                    </div>
                </template>

                <template
                    v-else-if="
                        accionActiva?.clave === 'registrar_socioeconomico'
                    "
                >
                    <p
                        class="rounded-lg bg-salvia/50 p-2 text-xs text-muted-foreground"
                    >
                        Cargar el estudio no lo aprueba: queda pendiente de
                        revisión y el responsable decide si es viable.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label>Fecha de visita</Label
                            ><DatePicker
                                v-model="form.fecha_visita as string"
                            /><InputError :message="form.errors.fecha_visita" />
                        </div>
                        <fieldset class="grid gap-1.5">
                            <legend class="mb-1.5 text-sm font-medium">
                                Tiempo en la vivienda (arraigo)
                            </legend>
                            <div class="grid grid-cols-2 gap-2">
                                <div class="grid gap-1">
                                    <Label
                                        for="arraigo-anios"
                                        class="text-xs text-muted-foreground"
                                        >Años</Label
                                    ><Input
                                        id="arraigo-anios"
                                        v-model.number="
                                            (
                                                form.checklist as Record<
                                                    string,
                                                    number
                                                >
                                            ).arraigo_anios
                                        "
                                        type="number"
                                        min="0"
                                        max="80"
                                        step="1"
                                    />
                                </div>
                                <div class="grid gap-1">
                                    <Label
                                        for="arraigo-meses"
                                        class="text-xs text-muted-foreground"
                                        >Meses</Label
                                    ><Input
                                        id="arraigo-meses"
                                        v-model.number="
                                            (
                                                form.checklist as Record<
                                                    string,
                                                    number
                                                >
                                            ).arraigo_meses
                                        "
                                        type="number"
                                        min="0"
                                        max="11"
                                        step="1"
                                    />
                                </div>
                            </div>
                            <InputError
                                :message="
                                    (
                                        form.errors as Record<
                                            string,
                                            string | undefined
                                        >
                                    )['checklist.arraigo_anios'] ??
                                    (
                                        form.errors as Record<
                                            string,
                                            string | undefined
                                        >
                                    )['checklist.arraigo_meses']
                                "
                            />
                        </fieldset>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="direccion-visita">Dirección visitada</Label
                        ><Input
                            id="direccion-visita"
                            v-model="form.direccion as string"
                        /><InputError :message="form.errors.direccion" />
                    </div>
                    <fieldset class="grid gap-2 text-sm">
                        <legend class="mb-1 text-sm font-medium">
                            Criterios (informativos)
                        </legend>
                        <label class="flex items-center gap-2"
                            ><Casilla
                                v-model="
                                    (form.checklist as Record<string, boolean>)
                                        .vivienda_en_orden
                                "
                            />
                            Vivienda en orden</label
                        >
                        <label class="flex items-center gap-2"
                            ><Casilla
                                v-model="
                                    (form.checklist as Record<string, boolean>)
                                        .vive_con_familia
                                "
                            />
                            Vive con familia</label
                        >
                        <label class="flex items-center gap-2"
                            ><Casilla
                                v-model="
                                    (form.checklist as Record<string, boolean>)
                                        .resguardo_motocicleta
                                "
                            />
                            Espacio para resguardar la motocicleta</label
                        >
                    </fieldset>
                    <div class="grid gap-1.5">
                        <Label for="riesgos">Riesgos o inconsistencias</Label
                        ><Textarea
                            id="riesgos"
                            v-model="form.riesgos as string"
                            rows="2"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="obs-socioeconomico">Observaciones</Label
                        ><Textarea
                            id="obs-socioeconomico"
                            v-model="form.observaciones as string"
                            rows="2"
                        /><InputError :message="form.errors.observaciones" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label
                            >Videos, fotografías o PDF (puedes subir varios; se
                            guardan en el NAS)</Label
                        ><PeopleFileDropzone
                            :model-value="(form.evidencias as File[]) ?? []"
                            multiple
                            :accept="ACEPTA_SOCIOECONOMICO"
                            :max-size-mb="limitesArchivos.max_video_mb"
                            label="Arrastra videos, fotos o PDF"
                            @update:model-value="(f) => (form.evidencias = f)"
                        />
                        <InputError :message="errorDeArchivos('evidencias')" />
                    </div>
                </template>

                <template
                    v-else-if="accionActiva?.clave === 'registrar_referencia'"
                >
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label>Empresa</Label
                            ><Input
                                v-model="form.empresa as string"
                            /><InputError :message="form.errors.empresa" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Contacto</Label
                            ><Input
                                v-model="form.contacto as string"
                            /><InputError :message="form.errors.contacto" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Teléfono</Label
                            ><Input v-model="form.telefono as string" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Relación / puesto</Label
                            ><Input v-model="form.relacion_puesto as string" />
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="r in opciones.resultadosReferencia"
                            :key="r.value"
                            type="button"
                            :variant="
                                form.resultado === r.value
                                    ? 'default'
                                    : 'outline'
                            "
                            @click="form.resultado = r.value"
                            >{{ r.etiqueta }}</Button
                        >
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Observaciones</Label
                        ><Textarea
                            v-model="form.observaciones as string"
                            rows="2"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label
                            >Soporte de la referencia (opcional: constancia,
                            carta o captura)</Label
                        ><PeopleFileDropzone
                            :model-value="(form.archivos as File[]) ?? []"
                            multiple
                            :accept="ACEPTA_DOCUMENTOS"
                            :max-size-mb="limitesArchivos.max_documento_mb"
                            label="Arrastra los archivos"
                            @update:model-value="(f) => (form.archivos = f)"
                        />
                        <InputError :message="errorDeArchivos('archivos')" />
                    </div>
                </template>

                <template v-else-if="accionActiva?.clave === 'autorizar_rh'">
                    <p class="text-sm text-muted-foreground">
                        Autorización de RH: después podrás generar el QR de
                        contratación.
                    </p>
                    <div class="grid gap-1.5">
                        <Label>Comentario (opcional)</Label
                        ><Textarea
                            v-model="form.comentario as string"
                            rows="2"
                        />
                    </div>
                    <InputError :message="form.errors.aprobacion" />
                </template>

                <template v-else-if="accionActiva?.clave === 'devolver_rh'">
                    <div class="grid gap-1.5">
                        <Label>Motivo (obligatorio)</Label
                        ><Textarea
                            v-model="form.motivo as string"
                            rows="3"
                        /><InputError :message="form.errors.motivo" />
                    </div>
                </template>

                <template v-else-if="accionActiva?.clave === 'rechazar_rh'">
                    <div class="grid gap-1.5">
                        <Label>Motivo *</Label>
                        <SelectSimple
                            :model-value="
                                form.motivo_rechazo_id
                                    ? String(form.motivo_rechazo_id)
                                    : ''
                            "
                            @update:model-value="alElegirMotivoRechazoForm"
                            :opciones="
                                (opciones.motivosRechazo ?? []).map((m) => ({
                                    value: String(m.id),
                                    label: m.etiqueta,
                                }))
                            "
                            placeholder="Elige un motivo"
                        />
                        <InputError :message="form.errors.motivo_rechazo_id" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Comentario (opcional)</Label
                        ><Textarea
                            v-model="form.motivo as string"
                            rows="3"
                        /><InputError :message="form.errors.motivo" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <Casilla v-model="form.recontratable as boolean" />
                        ¿Puede ser considerado de nuevo?
                    </label>
                </template>

                <template
                    v-else-if="accionActiva?.clave === 'solicitar_intervencion'"
                >
                    <p class="text-sm text-muted-foreground">
                        Pide revisar este rechazo de RH. Según el puesto, la
                        decisión la toma la Gerencia Regional (Gestor/Volante) o
                        Dirección Comercial (cualquier otro puesto) — nadie se
                        entera si no envías esta solicitud.
                    </p>
                    <div class="grid gap-1.5">
                        <Label>Motivo (obligatorio)</Label
                        ><Textarea
                            v-model="form.motivo as string"
                            rows="3"
                            placeholder="Explica por qué debería reconsiderarse este rechazo..."
                        /><InputError :message="form.errors.motivo" />
                    </div>
                </template>

                <template v-else-if="accionActiva?.clave === 'descartar'">
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="s in opciones.salidas"
                            :key="s.value"
                            type="button"
                            :variant="
                                form.estado === s.value
                                    ? 'destructive'
                                    : 'outline'
                            "
                            @click="form.estado = s.value"
                            >{{ s.etiqueta }}</Button
                        >
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Motivo *</Label>
                        <SelectSimple
                            :model-value="
                                form.motivo_rechazo_id
                                    ? String(form.motivo_rechazo_id)
                                    : ''
                            "
                            @update:model-value="alElegirMotivoRechazoForm"
                            :opciones="
                                (opciones.motivosRechazo ?? []).map((m) => ({
                                    value: String(m.id),
                                    label: m.etiqueta,
                                }))
                            "
                            placeholder="Elige un motivo"
                        />
                        <InputError :message="form.errors.motivo_rechazo_id" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Comentario (opcional)</Label
                        ><Textarea
                            v-model="form.motivo as string"
                            rows="3"
                        /><InputError :message="form.errors.motivo" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <Casilla v-model="form.recontratable as boolean" />
                        ¿Puede ser considerado de nuevo?
                    </label>
                </template>

                <template
                    v-else-if="accionActiva?.clave === 'iniciar_contratacion'"
                >
                    <p class="text-sm text-muted-foreground">
                        Se crea a la persona en contratación (sin duplicarla) y
                        se genera su QR de registro, temporal y de un solo uso.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label>Sueldo mensual</Label
                            ><Input
                                v-model="form.sueldo_mensual as string"
                                type="number"
                                min="0"
                                step="0.01"
                            /><InputError
                                :message="form.errors.sueldo_mensual"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Fecha de ingreso</Label
                            ><DatePicker
                                v-model="form.fecha_ingreso as string"
                            /><InputError
                                :message="form.errors.fecha_ingreso"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Modalidad</Label>
                            <SelectSimple
                                :model-value="
                                    String(form.tipo_contratacion ?? '')
                                "
                                @update:model-value="
                                    (v) => (form.tipo_contratacion = v)
                                "
                                :opciones="
                                    opciones.tiposContratacion.map((t) => ({
                                        value: t.value,
                                        label: t.etiqueta,
                                    }))
                                "
                                placeholder="Tipo de contratación"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Fin del periodo (opcional)</Label
                            ><DatePicker
                                v-model="form.fecha_fin_contrato as string"
                            />
                            <p class="text-xs text-muted-foreground">
                                Vacío = según la duración del puesto.
                            </p>
                        </div>
                    </div>
                    <InputError :message="form.errors.candidato" />
                </template>

                <InputError :message="form.errors.referencias" />
                <InputError :message="form.errors.estado" />

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="accionActiva = null"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            (['rechazar_rh', 'descartar'].includes(
                                accionActiva?.clave ?? '',
                            ) &&
                                !form.motivo_rechazo_id)
                        "
                        :variant="
                            accionActiva?.tipo === 'peligro'
                                ? 'destructive'
                                : 'default'
                        "
                        >Confirmar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <CandidatoFormDialog
        v-if="editar"
        v-model:open="editar"
        :candidato="candidatoFormulario"
        :opciones="opciones"
    />

    <Dialog
        :open="decisionIntervencion !== null"
        @update:open="
            (abierto: boolean) => !abierto && (decisionIntervencion = null)
        "
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    decisionIntervencion?.aprueba
                        ? 'Aprobar intervención'
                        : 'Confirmar rechazo'
                }}</DialogTitle>
                <DialogDescription>
                    {{ candidato.nombre_completo }} ·
                    {{
                        decisionIntervencion?.aprueba
                            ? 'El candidato pasará a contratación.'
                            : 'El candidato queda rechazado de forma definitiva.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-1.5">
                <Label>Comentario (opcional)</Label>
                <Textarea v-model="formDecision.comentario" rows="3" />
            </div>
            <DialogFooter>
                <Button variant="ghost" @click="decisionIntervencion = null"
                    >Cancelar</Button
                >
                <Button
                    :variant="
                        decisionIntervencion?.aprueba
                            ? 'default'
                            : 'destructive'
                    "
                    :disabled="formDecision.processing"
                    @click="enviarDecision"
                    >Confirmar</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Visor único del expediente: CV, psicométricos, socioeconómico, referencias. -->
    <DocumentPreviewDialog
        :open="visorAbierto"
        :archivos="archivosVisor"
        :indice="indiceVisor"
        @update:open="cerrarVisor"
    />
</template>
