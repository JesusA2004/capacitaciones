<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    ClipboardList,
    FileText,
    FolderOpen,
    History,
    LayoutDashboard,
    Route,
    UserSearch,
    UserX,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onMounted, ref } from 'vue';
import {
    decidir,
    solicitar as solicitarIntervencion,
} from '@/actions/App/Http/Controllers/Rh/IntervencionCandidatoController';
import AccionesCiclo from '@/components/ciclo/AccionesCiclo.vue';
import AprobacionesResumen from '@/components/ciclo/AprobacionesResumen.vue';
import CicloStepper from '@/components/ciclo/CicloStepper.vue';
import Casilla from '@/components/Common/Casilla.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import DateTimePicker from '@/components/Common/DateTimePicker.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import SiguientePasoPanel from '@/components/flujos/SiguientePaso.vue';
import InputError from '@/components/InputError.vue';
import BotonReabrir from '@/components/people/BotonReabrir.vue';
import type { ReaperturaDisponible } from '@/components/people/BotonReabrir.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import type { ResumenAvisoSistemas } from '@/components/Rh/AvisoAltaSistemas.vue';
import CandidatoAltaAccesos from '@/components/Rh/Candidato/CandidatoAltaAccesos.vue';
import CandidatoAtajos from '@/components/Rh/Candidato/CandidatoAtajos.vue';
import CandidatoDatos from '@/components/Rh/Candidato/CandidatoDatos.vue';
import CandidatoEvaluaciones from '@/components/Rh/Candidato/CandidatoEvaluaciones.vue';
import CandidatoHero from '@/components/Rh/Candidato/CandidatoHero.vue';
import CandidatoHistorial from '@/components/Rh/Candidato/CandidatoHistorial.vue';
import CandidatoIntervenciones from '@/components/Rh/Candidato/CandidatoIntervenciones.vue';
import CandidatoExpedientePanel from '@/components/Rh/CandidatoExpedientePanel.vue';
import CandidatoFormDialog from '@/components/Rh/CandidatoFormDialog.vue';
import CandidatoPerfilPuesto from '@/components/Rh/CandidatoPerfilPuesto.vue';
import PedirInformacionBoton from '@/components/Rh/PedirInformacionBoton.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
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
import { dashboard } from '@/routes';
import { show as candidatoShow } from '@/routes/rh/candidatos';
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
    SiguientePaso,
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
    /** Decisiones que esta cuenta puede reabrir (ReaperturaService). */
    reaperturas: ReaperturaDisponible[];
    /** Puede agregar archivos al mini expediente (CandidatoPolicy::adjuntar). */
    puedeAdjuntar: boolean;
    limitesArchivos: LimitesArchivosCandidato;
    perfilPuesto: PerfilPuestoCandidato;
    /** RN-09: baja previa «No recontratable» / «Con reservas». */
    antecedenteBaja?: AntecedenteBaja | null;
    /** Candidato → contratación → expediente (null = aún no seleccionado). */
    cadenaContratacion?: EstadoCadenaContratacion | null;
    siguientePaso: SiguientePaso;
    /** Correo «Alta para asignar usuarios» a Sistemas (null = no aplica / sin permiso). */
    avisoSistemas?: ResumenAvisoSistemas | null;
}>();

/** El paso del proceso ya es esta ficha: no se ofrece un enlace a sí misma. */
const procesoEsEstaFicha = computed(
    () =>
        !!props.cadenaContratacion?.proceso_url &&
        props.cadenaContratacion.proceso_url.includes(
            candidatoShow.url(props.candidato.id),
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

/**
 * Pestañas de la ficha: lo de todos los días en «Resumen» y cada tema
 * pesado en la suya, para no hacer una sola fila larga. La activa se recuerda
 * en la URL (`?pestana=`, se puede compartir) y en este navegador.
 */
type Pestana =
    'resumen' | 'evaluaciones' | 'expediente' | 'perfil' | 'historial';

const CLAVE_PESTANA = 'people:ficha-candidato:pestana';
const pestana = ref<Pestana>('resumen');

const totalEvaluaciones = computed(
    () =>
        props.candidato.entrevistas.length +
        props.candidato.psicometricas.length +
        props.candidato.socioeconomicos.length +
        props.candidato.referencias.length,
);

const pestanas = computed(() =>
    (
        [
            {
                clave: 'resumen',
                etiqueta: 'Resumen',
                icono: LayoutDashboard,
                total: null,
            },
            {
                clave: 'evaluaciones',
                etiqueta: 'Evaluaciones',
                icono: ClipboardList,
                total: totalEvaluaciones.value,
            },
            {
                clave: 'expediente',
                etiqueta: 'Expediente y archivos',
                icono: FolderOpen,
                total: props.candidato.expediente.total,
            },
            {
                clave: 'perfil',
                etiqueta: 'Perfil del puesto',
                icono: UserSearch,
                total: null,
            },
            {
                clave: 'historial',
                etiqueta: 'Historial',
                icono: History,
                total: props.ciclo.timeline.length,
            },
        ] satisfies {
            clave: Pestana;
            etiqueta: string;
            icono: Component;
            total: number | null;
        }[]
    ).filter((p) => p.clave !== 'perfil' || !!props.perfilPuesto),
);

function esPestana(valor: string | null): valor is Pestana {
    return pestanas.value.some((p) => p.clave === valor);
}

function irA(clave: Pestana, enfocar = false): void {
    pestana.value = clave;

    const url = new URL(window.location.href);

    if (clave === 'resumen') {
        url.searchParams.delete('pestana');
    } else {
        url.searchParams.set('pestana', clave);
    }

    // Mismo estado de Inertia: solo cambia la dirección visible.
    window.history.replaceState(window.history.state, '', url);

    try {
        window.localStorage.setItem(CLAVE_PESTANA, clave);
    } catch {
        // Navegación privada o almacenamiento bloqueado: la URL basta.
    }

    if (enfocar) {
        document
            .getElementById('ficha-pestanas')
            ?.scrollIntoView({ block: 'start', behavior: 'auto' });
    }
}

/** Flechas ← → entre pestañas (patrón de tablist). */
function moverPestana(evento: KeyboardEvent): void {
    const claves = pestanas.value.map((p) => p.clave);
    const paso = evento.key === 'ArrowRight' ? 1 : -1;
    const siguiente =
        claves[
            (claves.indexOf(pestana.value) + paso + claves.length) %
                claves.length
        ];

    irA(siguiente);
    document.getElementById(`pestana-${siguiente}`)?.focus();
}

onMounted(() => {
    const deUrl = new URLSearchParams(window.location.search).get('pestana');
    let guardada: string | null = null;

    try {
        guardada = window.localStorage.getItem(CLAVE_PESTANA);
    } catch {
        guardada = null;
    }

    if (esPestana(deUrl)) {
        pestana.value = deUrl;
    } else if (esPestana(guardada)) {
        pestana.value = guardada;
    }
});

const editar = ref(false);
const accionActiva = ref<AccionCiclo | null>(null);
const evidenciaActiva = ref<EvidenciaCandidato | null>(null);

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

/** El botón principal del panel no se repite entre las demás acciones. */
const accionesRestantes = computed(() =>
    props.ciclo.acciones_permitidas.filter(
        (a) => a.clave !== props.siguientePaso.accion?.clave,
    ),
);

function abrirPorClave(clave: string | null) {
    const permitida = props.ciclo.acciones_permitidas.find(
        (a) => a.clave === clave,
    );

    if (permitida) {
        abrir(permitida);
    }
}

// ?accion= abre el diálogo solo si la acción realmente está permitida.
onMounted(() =>
    abrirPorClave(new URLSearchParams(window.location.search).get('accion')),
);

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
</script>

<template>
    <Head :title="candidato.nombre_completo" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CandidatoHero
            :candidato="candidato"
            :ciclo="ciclo"
            @editar="editar = true"
            @ver-cv="cvAbierto = true"
            @ver="(ev) => (evidenciaActiva = ev)"
        />

        <!-- RN-09: baja previa no recontratable (módulo de Bajas). -->
        <div
            v-if="antecedenteBaja"
            role="alert"
            :class="[
                'flex aparece-escalonado items-start gap-3 rounded-2xl px-4 py-3 text-sm',
                antecedenteBaja.recontratable === 'no'
                    ? 'bg-danger-soft text-destructive'
                    : 'bg-warning-soft text-warning',
            ]"
            style="--i: 1"
        >
            <UserX class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div class="min-w-0 break-words">
                <p class="font-semibold">
                    Baja previa «{{ antecedenteBaja.recontratable_etiqueta }}»
                    recontratable
                </p>
                <p class="text-foreground">
                    {{ antecedenteBaja.nombre }} · {{ antecedenteBaja.folio }} ·
                    baja {{ antecedenteBaja.fecha_baja ?? '—' }}
                    <template v-if="antecedenteBaja.sucursal">
                        · {{ antecedenteBaja.sucursal }}</template
                    ><template v-if="antecedenteBaja.causal">
                        · {{ antecedenteBaja.causal }}</template
                    >
                    (coincide por {{ antecedenteBaja.coincidencia }}). Consulta
                    con RH antes de continuar.
                </p>
            </div>
        </div>

        <!-- Seleccionado → contratación → expediente: el candidato no
             desaparece al avanzar y lleva directo al proceso. -->
        <section
            v-if="cadenaContratacion"
            class="flex aparece-escalonado flex-col gap-3 rounded-2xl border border-border/60 bg-crema/60 p-4 sm:flex-row sm:items-center sm:justify-between"
            style="--i: 1"
            aria-label="Estado de contratación"
            data-cadena-contratacion
        >
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-[var(--mrl-superficie)] text-bronce"
                    aria-hidden="true"
                >
                    <Route class="size-4" />
                </span>
                <ChipEstado
                    :etiqueta="cadenaContratacion.etiqueta"
                    :tono="cadenaContratacion.tono"
                    tamano="md"
                />
                <span
                    v-if="cadenaContratacion.detalle"
                    class="text-sm break-words text-muted-foreground"
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
                <PedirInformacionBoton
                    v-if="candidato.colaborador_id"
                    :colaborador-id="candidato.colaborador_id"
                    variant="outline"
                />
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

        <div
            class="min-w-0 aparece-escalonado rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3 shadow-sm sm:p-4"
            style="--i: 2"
        >
            <CicloStepper data-tour="candidato-pasos" :pasos="ciclo.pasos" />
        </div>

        <!-- Lo que sigue y quién ya aprobó: visible sin desplazarse. -->
        <div class="grid gap-5 lg:grid-cols-3">
            <div
                class="min-w-0 aparece-escalonado lg:col-span-2 [&>section]:h-full"
                style="--i: 3"
            >
                <SiguientePasoPanel
                    data-tour="candidato-estado"
                    :paso="siguientePaso"
                    @dialogo="abrirPorClave"
                >
                    <div data-tour="candidato-acciones">
                        <AccionesCiclo
                            :acciones="accionesRestantes"
                            :sin-principal="!!siguientePaso.accion"
                            @accion="abrir"
                        />
                    </div>
                    <BotonReabrir :reaperturas="reaperturas" />
                </SiguientePasoPanel>
            </div>
            <!-- En pantallas angostas las aprobaciones bajan al Resumen,
                 para que las pestañas queden cerca del siguiente paso. -->
            <div
                class="hidden min-w-0 aparece-escalonado lg:block [&>section]:h-full"
                style="--i: 3"
            >
                <AprobacionesResumen :aprobaciones="ciclo.aprobaciones" />
            </div>
        </div>

        <!-- Pestañas: cada tema en su lugar en vez de una fila larga. -->
        <div
            id="ficha-pestanas"
            class="-mx-1 scroll-x-limpio flex scroll-mt-20 gap-1.5 px-1 py-1"
            role="tablist"
            aria-label="Secciones de la ficha"
            @keydown.left.prevent="moverPestana"
            @keydown.right.prevent="moverPestana"
        >
            <button
                v-for="p in pestanas"
                :id="`pestana-${p.clave}`"
                :key="p.clave"
                type="button"
                role="tab"
                :aria-selected="pestana === p.clave"
                :aria-controls="`panel-${p.clave}`"
                :tabindex="pestana === p.clave ? 0 : -1"
                class="inline-flex min-h-10 shrink-0 presionable items-center gap-2 rounded-xl border px-3.5 py-2 text-sm font-medium transicion-ui focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="
                    pestana === p.clave
                        ? 'border-primary/30 bg-[var(--mrl-petroleo)] text-[var(--mrl-superficie)] shadow-sm'
                        : 'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] text-muted-foreground hover:-translate-y-0.5 hover:bg-crema/60 hover:text-foreground'
                "
                @click="irA(p.clave)"
            >
                <component :is="p.icono" class="size-4" aria-hidden="true" />
                {{ p.etiqueta }}
                <span
                    v-if="p.total !== null"
                    class="rounded-full px-1.5 text-xs tabular-nums"
                    :class="
                        pestana === p.clave
                            ? 'bg-[var(--mrl-superficie)]/20'
                            : 'bg-crema text-bronce'
                    "
                    >{{ p.total }}</span
                >
            </button>
        </div>

        <CambioSuave>
            <!-- Resumen -->
            <div
                v-if="pestana === 'resumen'"
                id="panel-resumen"
                key="resumen"
                role="tabpanel"
                aria-labelledby="pestana-resumen"
                class="flex min-w-0 flex-col gap-5"
            >
                <CandidatoIntervenciones
                    v-if="candidato.intervenciones.length"
                    :intervenciones="candidato.intervenciones"
                    :intervencion-pendiente-id="intervencionPendienteId"
                    :indice="1"
                    @decidir="abrirDecision"
                />

                <CandidatoAtajos
                    :candidato="candidato"
                    :perfil-puesto="perfilPuesto"
                    @ir="(clave) => irA(clave, true)"
                />

                <div class="grid items-start gap-5 xl:grid-cols-3">
                    <div class="flex min-w-0 flex-col gap-5 xl:col-span-2">
                        <CandidatoDatos :candidato="candidato" :indice="2" />

                        <!-- Alta en sistemas: aviso a Sistemas + QR + credenciales. -->
                        <CandidatoAltaAccesos
                            v-if="
                                avisoSistemas ||
                                candidato.invitacion ||
                                candidato.credenciales
                            "
                            :candidato="candidato"
                            :aviso-sistemas="avisoSistemas"
                            :indice="3"
                        />
                    </div>
                    <CandidatoHistorial
                        :items="ciclo.timeline"
                        modo="resumen"
                        :indice="3"
                        @ver-todo="irA('historial', true)"
                    />
                </div>

                <div class="lg:hidden">
                    <AprobacionesResumen :aprobaciones="ciclo.aprobaciones" />
                </div>
            </div>

            <!-- Evaluaciones -->
            <div
                v-else-if="pestana === 'evaluaciones'"
                id="panel-evaluaciones"
                key="evaluaciones"
                role="tabpanel"
                aria-labelledby="pestana-evaluaciones"
                class="min-w-0"
            >
                <CandidatoEvaluaciones
                    v-if="totalEvaluaciones"
                    :candidato="candidato"
                    @ver="(ev) => (evidenciaActiva = ev)"
                />
                <EmptyState
                    v-else
                    class="rounded-2xl border border-dashed border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
                    :icono="ClipboardList"
                    titulo="Sin evaluaciones"
                    descripcion="Aún no hay evaluaciones registradas para este candidato."
                />
            </div>

            <!-- Expediente y archivos -->
            <div
                v-else-if="pestana === 'expediente'"
                id="panel-expediente"
                key="expediente"
                role="tabpanel"
                aria-labelledby="pestana-expediente"
                class="min-w-0"
            >
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
            </div>

            <!-- Perfil del puesto -->
            <div
                v-else-if="pestana === 'perfil' && perfilPuesto"
                id="panel-perfil"
                key="perfil"
                role="tabpanel"
                aria-labelledby="pestana-perfil"
                class="min-w-0"
            >
                <CandidatoPerfilPuesto :perfil="perfilPuesto" />
            </div>

            <!-- Historial completo -->
            <div
                v-else
                id="panel-historial"
                key="historial"
                role="tabpanel"
                aria-labelledby="pestana-historial"
                class="min-w-0"
            >
                <CandidatoHistorial :items="ciclo.timeline" modo="completo" />
            </div>
        </CambioSuave>
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
                        >{{ accionActiva?.etiqueta ?? 'Guardar' }}</Button
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
                        : 'Rechazar intervención'
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
                    >{{
                        decisionIntervencion?.aprueba
                            ? 'Aprobar intervención'
                            : 'Rechazar intervención'
                    }}</Button
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
