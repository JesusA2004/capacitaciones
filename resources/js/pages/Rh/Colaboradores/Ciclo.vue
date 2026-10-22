<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Circle,
    FileSearch,
    FileText,
    FolderOpen,
    UserRound,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import CamposBaja from '@/components/bajas/CamposBaja.vue';
import VistaPreviaBaja from '@/components/bajas/VistaPreviaBaja.vue';
import AccionesCiclo from '@/components/ciclo/AccionesCiclo.vue';
import AprobacionesResumen from '@/components/ciclo/AprobacionesResumen.vue';
import CicloEstadoPanel from '@/components/ciclo/CicloEstadoPanel.vue';
import CicloStepper from '@/components/ciclo/CicloStepper.vue';
import CicloTimeline from '@/components/ciclo/CicloTimeline.vue';
import FiniquitoCierreDialog from '@/components/ciclo/FiniquitoCierreDialog.vue';
import Casilla from '@/components/Common/Casilla.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DocumentosProceso from '@/components/documentos/DocumentosProceso.vue';
import VistaPreviaFlujo from '@/components/documentos/VistaPreviaFlujo.vue';
import FormularioPeriodoPrueba from '@/components/formularios-evaluacion/FormularioPeriodoPrueba.vue';
import InputError from '@/components/InputError.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
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
import { formatearFecha } from '@/lib/fechas';
import { vistaPreviaPost } from '@/lib/http';
import { dashboard } from '@/routes';
import rutasBajas from '@/routes/rh/bajas';
import cierres from '@/routes/rh/cierres';
import documentosLaborales from '@/routes/rh/documentos-laborales';
import evaluaciones from '@/routes/rh/evaluaciones';
import evaluacionesExcepcion from '@/routes/rh/evaluaciones/excepcion';
import { show as verExpediente } from '@/routes/rh/expedientes';
import onboardingRutas from '@/routes/rh/onboarding';
import { index as reingresos } from '@/routes/rh/reingresos';
import type {
    AccionCiclo,
    CamposBaja as CamposBajaDatos,
    EstadoCiclo,
    EvaluacionPeriodoPruebaResumen,
    FiniquitoCierre,
    FormaSalidaOpcion,
    FormularioAccion,
    LimitesBaja,
    OnboardingDetalle,
    TipoBajaOpcion,
} from '@/types';

type DocumentoLaboral = {
    id: number;
    titulo: string | null;
    clave: string | null;
    estado: string | null;
    estado_etiqueta: string | null;
    requiere_huella: boolean;
    requiere_testigos: boolean;
    acciones: string[];
};

const props = defineProps<{
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        puesto: string | null;
        sucursal: string | null;
    };
    ciclo: EstadoCiclo;
    onboarding: OnboardingDetalle | null;
    documentos: DocumentoLaboral[];
    evaluacion: EvaluacionPeriodoPruebaResumen | null;
    cierre: Record<string, unknown> | null;
    puede_solicitar_excepcion: boolean;
    puede_resolver_excepcion: boolean;
    puede_devolver_evaluacion?: boolean;
    opciones: {
        // Formas de salida (renuncia / no renovación / rescisión) con sus
        // causales activas, ayuda y reglas (módulo de Bajas).
        causas: TipoBajaOpcion[];
        recontratable: { value: string; label: string }[];
        entregas: { value: string; label: string }[];
        limites_baja: LimitesBaja;
        es_rh_bajas: boolean;
        criterios: string[];
    };
}>();

defineOptions({
    layout: (pageProps: { colaborador: { nombre: string } }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Ciclo laboral', href: '' },
            { title: pageProps.colaborador.nombre, href: '' },
        ],
    }),
});

const accion = ref<AccionCiclo | null>(null);
const form = useForm<FormularioAccion>({});
const hoy = new Date().toISOString().slice(0, 10);

// «Iniciar baja» (gerente o RH): el MISMO registro directo de «Bajas»
// (BajaService::registrar, rh.bajas.store) con los mismos campos
// (CamposBaja) y la vista previa de la renuncia / formato y del finiquito
// antes de confirmar.
const formasAtajo = computed<FormaSalidaOpcion[]>(() =>
    props.opciones.causas.map((c) => ({
        value: c.forma,
        etiqueta: c.etiqueta,
        resumen: c.resumen,
        descripcion: c.descripcion,
        tipo_baja: c.value,
        token: c.token,
        causales: c.causales,
    })),
);

const camposVacios = (): CamposBajaDatos => ({
    forma_salida: null,
    causal_rotacion_id: null,
    fecha_baja: hoy,
    recontratable: '',
    comentarios: '',
    fecha_conocimiento_falta: '',
    entrega_aviso_rescision: '',
    fecha_entrega_aviso: '',
});
const camposAtajo = ref<CamposBajaDatos>(camposVacios());
const tipoBajaAtajo = computed(
    () =>
        formasAtajo.value.find(
            (f) => f.value === camposAtajo.value.forma_salida,
        )?.tipo_baja ?? '',
);

function volcarAtajo() {
    const c = camposAtajo.value;
    Object.assign(form, {
        colaborador_id: props.colaborador.id,
        forma_salida: c.forma_salida,
        causal_rotacion_id: c.causal_rotacion_id,
        fecha_baja: c.fecha_baja,
        comentarios: c.comentarios,
        recontratable: c.recontratable || null,
        fecha_conocimiento_falta: c.fecha_conocimiento_falta || null,
        entrega_aviso_rescision: c.entrega_aviso_rescision || null,
        fecha_entrega_aviso: c.fecha_entrega_aviso || null,
        volver: 'ciclo',
    });
}

const plantillas: Record<string, () => FormularioAccion> = {
    entregar_activos: () => ({
        tipo_activo_id:
            props.onboarding?.activos.find((a) => !a.entregado)
                ?.tipo_activo_id ?? null,
        identificador: '',
        observaciones: '',
    }),
    completar_onboarding: () => ({}),
    retroalimentar_onboarding: () => ({
        avance_id:
            props.onboarding?.modulos.find((m) => m.puede_retroalimentar)
                ?.avance_id ?? null,
        retroalimentacion: '',
    }),
    capturar_evaluacion: () => ({
        criterios: (props.evaluacion?.criterios_oficiales ?? []).map((c) => ({
            clave: c.clave,
            calificacion: 4,
        })),
        filtros: (props.evaluacion?.filtros_oficiales ?? []).map((f) => ({
            clave: f.clave,
            respuesta: false,
        })),
        observaciones: '',
    }),
    autorizar_evaluacion: () => ({
        renovar: Boolean(props.evaluacion?.recomienda_renovar ?? true),
        comentario: '',
        motivo_no_renovacion: '',
    }),
    devolver_evaluacion: () => ({ motivo: '' }),
    solicitar_baja: () => {
        // Forma y causal se eligen siempre a conciencia: nunca se preseleccionan.
        camposAtajo.value = camposVacios();

        return {
            colaborador_id: props.colaborador.id,
            forma_salida: null,
            causal_rotacion_id: null,
            comentarios: '',
            fecha_baja: hoy,
            volver: 'ciclo',
        };
    },
    registrar_aviso: () => ({ archivo: null }),
    // Prellenado con el sueldo del cálculo anterior o el salario diario
    // registrado × 30: «Calcular» nunca exige capturar a mano lo que ya existe.
    calcular_finiquito: () => ({
        sueldo_mensual: sueldoMensualInicial(),
        sueldo_pendiente: finiquitoCierre.value?.sueldo_pendiente ?? '0',
    }),
    cerrar: () => ({}),
    cancelar: () => ({ motivo: '' }),
};

const conArchivos = ['registrar_aviso'];

// Acciones propias de esta pantalla que se suman a las del proceso.
const accionesExtra = computed<AccionCiclo[]>(() => [
    ...(props.evaluacion &&
    props.puede_devolver_evaluacion &&
    props.ciclo.acciones_permitidas.some(
        (a) => a.clave === 'autorizar_evaluacion',
    )
        ? [
              {
                  clave: 'devolver_evaluacion',
                  etiqueta: 'Devolver evaluación',
                  tipo: 'secundaria',
              },
          ]
        : []),
    ...(props.evaluacion?.es_oficial &&
    !evaluacionEsElegible.value &&
    !excepcionVigente.value &&
    !excepcionPendiente.value &&
    props.puede_solicitar_excepcion
        ? [
              {
                  clave: 'solicitar_excepcion',
                  etiqueta: 'Solicitar excepción a Dirección General',
                  tipo: 'peligro',
              },
          ]
        : []),
]);

function alElegirAccion(a: AccionCiclo) {
    if (a.clave === 'solicitar_excepcion') {
        dialogoExcepcionAbierto.value = true;

        return;
    }

    abrir(a);
}

// «Iniciar baja» desde el expediente llega con ?accion=solicitar_baja: abre
// el formulario directo, solo si la acción realmente está permitida.
onMounted(() => {
    const pedida = new URLSearchParams(window.location.search).get('accion');
    const disponible = props.ciclo.acciones_permitidas.find(
        (a) => a.clave === pedida,
    );

    if (disponible) {
        abrir(disponible);
    }
});

function abrir(a: AccionCiclo) {
    // Ver / editar el finiquito y registrar su entrega viven en su propio
    // diálogo (desglose por concepto, ajustes y entrega que cierra el cierre).
    if (
        a.clave === 'ver_finiquito' ||
        a.clave === 'registrar_entrega_finiquito'
    ) {
        abrirFiniquito(a.clave === 'registrar_entrega_finiquito');

        return;
    }

    if (a.clave === 'solicitar_reingreso' || a.clave === 'decidir_reingreso') {
        window.location.href = reingresos.url({
            query: { colaborador: props.colaborador.id },
        });

        return;
    }

    form.clearErrors();
    const inicial = plantillas[a.clave]?.() ?? {};
    form.defaults(inicial);
    form.reset();
    Object.assign(form, inicial);
    accion.value = a;
}

const cierreId = computed(() => Number(props.cierre?.id ?? 0));
const evaluacionId = computed(() => Number(props.evaluacion?.id ?? 0));

// ───── Finiquito del cierre (vista previa + edición + entrega) ─────
const finiquitoCierre = computed(
    () => (props.cierre?.finiquito ?? null) as FiniquitoCierre | null,
);
const finiquitoAbierto = ref(false);
const finiquitoEntregaInicial = ref(false);
const clavesAcciones = computed(() =>
    props.ciclo.acciones_permitidas.map((a) => a.clave),
);
const puedeEditarFiniquito = computed(
    () =>
        clavesAcciones.value.includes('ver_finiquito') ||
        clavesAcciones.value.includes('calcular_finiquito'),
);
const puedeEntregarFiniquito = computed(() =>
    clavesAcciones.value.includes('registrar_entrega_finiquito'),
);

function sueldoMensualInicial(): string {
    const previo = Number(finiquitoCierre.value?.sueldo_mensual ?? 0);
    const sugerido = Number(props.cierre?.sueldo_mensual_sugerido ?? 0);
    const valor = previo > 0 ? previo : sugerido;

    return valor > 0 ? valor.toFixed(2) : '';
}

function abrirFiniquito(entrega = false) {
    finiquitoEntregaInicial.value = entrega;
    finiquitoAbierto.value = true;
}

function calcularDesdeDocumentos() {
    const calcular = props.ciclo.acciones_permitidas.find(
        (a) => a.clave === 'calcular_finiquito',
    );

    if (calcular) {
        abrir(calcular);
    }
}

// Evaluación oficial FO-RH-EPP-001 (version >= 2): 9 criterios (escala 4-1)
// + 5 preguntas filtro. Legacy (version null/1) sigue usando criterios
// libres (opciones.criterios) — nunca se mezclan los dos formatos.
const criteriosForm = computed(
    () => (form.criterios ?? []) as { clave: string; calificacion: number }[],
);
const filtrosForm = computed(
    () => (form.filtros ?? []) as { clave: string; respuesta: boolean }[],
);

// Elegibilidad real (regla canónica FO-RH-EPP-001) de la evaluación YA
// capturada, para decidir si mostrar la excepción de Dirección General.
const evaluacionEsElegible = computed(
    () => props.evaluacion?.recomienda_renovar === true,
);
const excepcionVigente = computed(() =>
    props.evaluacion?.excepcion?.estado === 'aprobada'
        ? props.evaluacion.excepcion
        : null,
);
const excepcionPendiente = computed(() =>
    props.evaluacion?.excepcion?.estado === 'pendiente'
        ? props.evaluacion.excepcion
        : null,
);

const excepcionForm = useForm<{
    motivo: string;
    documento_soporte: File | null;
}>({
    motivo: '',
    documento_soporte: null,
});
const dialogoExcepcionAbierto = ref(false);

function solicitarExcepcion() {
    excepcionForm.post(
        evaluacionesExcepcion.solicitar.url(evaluacionId.value),
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                dialogoExcepcionAbierto.value = false;
                excepcionForm.reset();
            },
        },
    );
}

const resolverExcepcionForm = useForm<{
    aprobar: boolean;
    comentario: string;
}>({ aprobar: true, comentario: '' });

function resolverExcepcion(aprobar: boolean) {
    const excepcionId = props.evaluacion?.excepcion?.id;

    if (!excepcionId) {
        return;
    }

    resolverExcepcionForm.aprobar = aprobar;
    resolverExcepcionForm.post(
        evaluacionesExcepcion.resolver.url(excepcionId),
        {
            preserveScroll: true,
            onSuccess: () => resolverExcepcionForm.reset(),
        },
    );
}

function destino(clave: string): string {
    const procesoId = props.onboarding?.id ?? 0;

    switch (clave) {
        case 'entregar_activos':
            return onboardingRutas.activos.url(procesoId);
        case 'completar_onboarding':
            return onboardingRutas.completar.url(procesoId);
        case 'retroalimentar_onboarding':
            return onboardingRutas.retroalimentar.url(
                Number(form.avance_id ?? 0),
            );
        case 'capturar_evaluacion':
            return evaluaciones.capturar.url(evaluacionId.value);
        case 'autorizar_evaluacion':
            return evaluaciones.autorizar.url(evaluacionId.value);
        case 'devolver_evaluacion':
            return evaluaciones.devolver.url(evaluacionId.value);
        case 'solicitar_baja':
            return rutasBajas.store.url();
        default:
            return (
                cierres as unknown as Record<
                    string,
                    { url: (id: number) => string }
                >
            )[aliasCierre[clave] ?? clave].url(cierreId.value);
    }
}

// ───── Entrega de activos: vista previa de la carta responsiva ─────
// Antes de confirmar la entrega, RH ve la responsiva con el activo y la
// serie capturados (el backend no registra la entrega ni archiva nada).
const vistaPreviaResponsivaUrl = ref<string | null>(null);
const vistaPreviaResponsivaAbierta = ref(false);
const cargandoVistaPreviaResponsiva = ref(false);
const errorVistaPreviaResponsiva = ref<string | null>(null);

function limpiarVistaPreviaResponsiva() {
    if (vistaPreviaResponsivaUrl.value) {
        URL.revokeObjectURL(vistaPreviaResponsivaUrl.value);
        vistaPreviaResponsivaUrl.value = null;
    }
}

async function verVistaPreviaResponsiva() {
    if (!props.onboarding) {
        return;
    }

    cargandoVistaPreviaResponsiva.value = true;
    errorVistaPreviaResponsiva.value = null;

    try {
        const url = await vistaPreviaPost(
            onboardingRutas.responsiva.vistaPrevia.url(props.onboarding.id),
            {
                tipo_activo_id: form.tipo_activo_id,
                identificador: form.identificador,
            },
        );
        limpiarVistaPreviaResponsiva();
        vistaPreviaResponsivaUrl.value = url;
        vistaPreviaResponsivaAbierta.value = true;
    } catch (e) {
        errorVistaPreviaResponsiva.value =
            e instanceof Error
                ? e.message
                : 'No se pudo generar la vista previa de la responsiva.';
    } finally {
        cargandoVistaPreviaResponsiva.value = false;
    }
}

watch(vistaPreviaResponsivaAbierta, (abierta) => {
    if (!abierta) {
        limpiarVistaPreviaResponsiva();
    }
});

watch(accion, () => {
    errorVistaPreviaResponsiva.value = null;
});

const aliasCierre: Record<string, string> = {
    registrar_aviso: 'aviso',
    calcular_finiquito: 'calcularFiniquito',
};

function enviar() {
    if (!accion.value) {
        return;
    }

    const clave = accion.value.clave;

    if (clave === 'solicitar_baja') {
        volcarAtajo();
    }

    form.post(destino(clave), {
        preserveScroll: true,
        forceFormData: conArchivos.includes(clave),
        onSuccess: () => {
            accion.value = null;

            // Calcular SIEMPRE termina mostrando el desglose recién calculado.
            if (clave === 'calcular_finiquito') {
                abrirFiniquito(false);
            }
        },
    });
}

// Control del original físico: cada paso pide los datos reales que exige
// (paquetería/guía/fecha del envío, fecha y huella/testigos de la firma,
// fecha de recepción, escaneo del original) — no basta cambiar un estado.
type FormDocumento = {
    fecha: string;
    observaciones: string;
    huella_registrada: boolean;
    testigos: { nombre: string; puesto: string }[];
    paqueteria: string;
    numero_guia: string;
    comprobante: File | null;
    archivo: File | null;
};

const formDocumento = useForm<FormDocumento>({
    fecha: hoy,
    observaciones: '',
    huella_registrada: false,
    testigos: [],
    paqueteria: '',
    numero_guia: '',
    comprobante: null,
    archivo: null,
});
const pasoDocumento = ref<{ documento: DocumentoLaboral; paso: string } | null>(
    null,
);

function operarDocumento(documento: DocumentoLaboral, paso: string) {
    formDocumento.reset();
    formDocumento.clearErrors();
    formDocumento.testigos = documento.requiere_testigos
        ? [{ nombre: '', puesto: '' }]
        : [];
    pasoDocumento.value = { documento, paso };
}

const rutasDocumento: Record<string, (id: number) => string> = {
    imprimir: (id) => documentosLaborales.imprimir.url(id),
    firma_fisica: (id) => documentosLaborales.firmaFisica.url(id),
    envio: (id) => documentosLaborales.envio.url(id),
    recepcion: (id) => documentosLaborales.recepcion.url(id),
    escaneo: (id) => documentosLaborales.escaneo.url(id),
    archivar: (id) => documentosLaborales.archivar.url(id),
};

function enviarPasoDocumento() {
    if (!pasoDocumento.value) {
        return;
    }

    const { documento, paso } = pasoDocumento.value;

    formDocumento
        .transform((datos) => {
            const base = {
                fecha: datos.fecha,
                observaciones: datos.observaciones,
            };

            switch (paso) {
                case 'firma_fisica':
                    return {
                        ...base,
                        huella_registrada: datos.huella_registrada,
                        testigos: datos.testigos.filter(
                            (t) => t.nombre.trim() !== '',
                        ),
                    };
                case 'envio':
                    return {
                        ...base,
                        paqueteria: datos.paqueteria,
                        numero_guia: datos.numero_guia,
                        comprobante: datos.comprobante,
                    };
                case 'escaneo':
                    return {
                        observaciones: datos.observaciones,
                        archivo: datos.archivo,
                    };
                case 'archivar':
                    return { observaciones: datos.observaciones };
                default:
                    return base;
            }
        })
        .post(rutasDocumento[paso](documento.id), {
            preserveScroll: true,
            forceFormData: paso === 'envio' || paso === 'escaneo',
            onSuccess: () => {
                pasoDocumento.value = null;
                // Las tarjetas de documentos se recargan con el nuevo estado.
                versionDocumentos.value++;
            },
        });
}

/** Sube al operar un documento desde el historial: las tarjetas se vuelven a cargar. */
const versionDocumentos = ref(0);

// El finiquito cambió (calculado, ajustado, entregado): la tarjeta
// «Finiquito» de «Documentos de la baja» vuelve a cargar su estado.
watch(
    () => [finiquitoCierre.value?.estado, finiquitoCierre.value?.neto],
    () => versionDocumentos.value++,
);

/** Una tarjeta cambió un documento: el historial y el estado del ciclo se refrescan. */
function refrescarDocumentos() {
    router.reload({ only: ['documentos', 'ciclo', 'cierre'] });
}

// Mismas etiquetas que las tarjetas de «Documentos de la baja».
const etiquetaPaso: Record<string, string> = {
    imprimir: 'Imprimir',
    firma_fisica: 'Marcar como firmado físicamente',
    envio: 'Registrar envío',
    recepcion: 'Registrar recepción',
    escaneo: 'Subir escaneo',
    archivar: 'Archivar original',
};

const etiquetaFecha: Record<string, string> = {
    imprimir: 'Fecha de impresión',
    firma_fisica: 'Fecha de firma',
    envio: 'Fecha de envío',
    recepcion: 'Fecha de recepción',
};
</script>

<template>
    <Head :title="colaborador.nombre" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            detalle
            :titulo="colaborador.nombre"
            :descripcion="`${colaborador.numero_empleado ?? ''} · ${colaborador.puesto ?? 'Sin puesto'} · ${colaborador.sucursal ?? 'Sin sucursal'}`"
            :icono="UserRound"
        >
            <Button as-child variant="secondary">
                <Link :href="verExpediente.url(colaborador.id)"
                    ><FolderOpen class="size-4" /> Expediente</Link
                >
            </Button>
        </CrudPageHeader>

        <CicloStepper :pasos="ciclo.pasos" />

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="flex flex-col gap-5 lg:col-span-2">
                <CicloEstadoPanel :ciclo="ciclo">
                    <AccionesCiclo
                        :acciones="ciclo.acciones_permitidas"
                        :siguiente="ciclo.siguiente_accion?.clave"
                        :extras="accionesExtra"
                        @accion="alElegirAccion"
                    />
                </CicloEstadoPanel>

                <section
                    v-if="excepcionPendiente"
                    class="flex flex-col gap-2 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                >
                    <p class="text-sm font-semibold">
                        Excepción de Dirección General pendiente
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ excepcionPendiente.motivo }}
                    </p>
                    <div v-if="puede_resolver_excepcion" class="flex gap-2">
                        <Button type="button" @click="resolverExcepcion(true)"
                            >Aprobar excepción</Button
                        >
                        <Button
                            type="button"
                            variant="destructive"
                            @click="resolverExcepcion(false)"
                            >Rechazar excepción</Button
                        >
                    </div>
                    <p v-else class="text-xs text-muted-foreground">
                        Solo Dirección General puede resolverla.
                    </p>
                </section>

                <section
                    v-if="onboarding"
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                    aria-label="Onboarding"
                >
                    <h2 class="mb-3 text-sm font-semibold">
                        Onboarding · {{ onboarding.estado_etiqueta }}
                    </h2>
                    <ul class="mb-4 grid gap-2 sm:grid-cols-2">
                        <li
                            v-for="item in onboarding.checklist"
                            :key="item.clave"
                            class="flex items-center gap-2 text-sm"
                        >
                            <CheckCircle2
                                v-if="item.completado"
                                class="size-4 text-[var(--mrl-verde)]"
                            />
                            <Circle
                                v-else
                                class="size-4 text-[var(--mrl-gris-verdoso)]"
                            />
                            {{ item.etiqueta }}
                        </li>
                    </ul>
                    <div class="flex flex-col gap-2">
                        <article
                            v-for="m in onboarding.modulos"
                            :key="m.avance_id"
                            class="rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <p class="font-medium">
                                    {{ m.tipo_etiqueta }} · {{ m.titulo }}
                                </p>
                                <span
                                    class="text-xs text-[var(--mrl-texto-suave)]"
                                    >{{ m.estado_etiqueta }}</span
                                >
                            </div>
                            <p class="text-xs text-[var(--mrl-texto-suave)]">
                                Mínimo {{ m.calificacion_minima }} · intentos:
                                {{
                                    m.intentos
                                        .map((i) => i.calificacion.toFixed(1))
                                        .join(', ') || 'ninguno'
                                }}
                            </p>
                            <p v-if="m.retroalimentacion" class="mt-1 text-xs">
                                Retroalimentación RH: “{{
                                    m.retroalimentacion
                                }}”
                            </p>
                        </article>
                    </div>
                    <div v-if="onboarding.activos.length" class="mt-4">
                        <h3
                            class="mb-2 text-xs font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                        >
                            Activos y responsivas
                        </h3>
                        <ul class="flex flex-col gap-1 text-sm">
                            <li
                                v-for="activo in onboarding.activos"
                                :key="activo.tipo_activo_id"
                                class="flex items-center gap-2"
                            >
                                <CheckCircle2
                                    v-if="activo.entregado"
                                    class="size-4 text-[var(--mrl-verde)]"
                                />
                                <Circle
                                    v-else
                                    class="size-4 text-[var(--mrl-gris-verdoso)]"
                                />
                                {{ activo.nombre }}
                                <span
                                    v-if="activo.entrega"
                                    class="text-xs text-[var(--mrl-texto-suave)]"
                                >
                                    {{
                                        activo.entrega.identificador
                                            ? `#${activo.entrega.identificador} · `
                                            : ''
                                    }}{{
                                        formatearFecha(
                                            activo.entrega.entregado_en,
                                        )
                                    }}
                                    · responsiva
                                    {{
                                        activo.entrega.responsiva_estado ??
                                        'pendiente'
                                    }}
                                </span>
                            </li>
                        </ul>
                    </div>
                </section>

                <DocumentosProceso
                    :key="`colaborador-${versionDocumentos}`"
                    tipo="colaborador"
                    :id="colaborador.id"
                    @actualizado="refrescarDocumentos"
                />
                <DocumentosProceso
                    v-if="evaluacion && !cierre"
                    :key="`evaluacion-${versionDocumentos}`"
                    tipo="evaluacion"
                    :id="evaluacionId"
                    @actualizado="refrescarDocumentos"
                />
                <DocumentosProceso
                    v-if="cierre"
                    :key="`cierre-${versionDocumentos}`"
                    tipo="cierre"
                    :id="cierreId"
                    @actualizado="refrescarDocumentos"
                    @ver-finiquito="abrirFiniquito(false)"
                    @calcular-finiquito="calcularDesdeDocumentos"
                />

                <section
                    v-if="documentos.length"
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                    aria-label="Documentos laborales"
                >
                    <h2 class="mb-3 text-sm font-semibold">
                        Historial de documentos laborales
                    </h2>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="d in documentos"
                            :key="d.id"
                            class="flex flex-wrap items-center gap-2 rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm"
                        >
                            <FileText
                                class="size-4 text-[var(--mrl-petroleo)]"
                            />
                            <a
                                :href="documentosLaborales.descargar.url(d.id)"
                                target="_blank"
                                class="font-medium hover:underline"
                                >{{ d.titulo ?? d.clave }}</a
                            >
                            <span
                                class="text-xs text-[var(--mrl-texto-suave)]"
                                >{{ d.estado_etiqueta }}</span
                            >
                            <span class="ml-auto flex flex-wrap gap-1">
                                <Button
                                    v-for="paso in d.acciones"
                                    :key="paso"
                                    size="sm"
                                    variant="outline"
                                    :disabled="formDocumento.processing"
                                    @click="operarDocumento(d, paso)"
                                    >{{ etiquetaPaso[paso] ?? paso }}</Button
                                >
                            </span>
                        </li>
                    </ul>
                </section>
            </div>

            <div class="flex flex-col gap-5">
                <AprobacionesResumen
                    v-if="ciclo.aprobaciones"
                    :aprobaciones="ciclo.aprobaciones"
                />
                <CicloTimeline :items="ciclo.timeline" />
            </div>
        </div>
    </div>

    <Dialog
        :open="pasoDocumento !== null"
        @update:open="(abierto: boolean) => !abierto && (pasoDocumento = null)"
    >
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    pasoDocumento ? etiquetaPaso[pasoDocumento.paso] : ''
                }}</DialogTitle>
                <DialogDescription
                    >{{
                        pasoDocumento?.documento.titulo ??
                        pasoDocumento?.documento.clave
                    }}
                    · {{ colaborador.nombre }}</DialogDescription
                >
            </DialogHeader>

            <form
                v-if="pasoDocumento"
                class="flex flex-col gap-4"
                @submit.prevent="enviarPasoDocumento"
            >
                <template v-if="pasoDocumento.paso === 'envio'">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="doc-paqueteria">Paquetería</Label>
                            <Input
                                id="doc-paqueteria"
                                v-model="formDocumento.paqueteria"
                                required
                            />
                            <InputError
                                :message="formDocumento.errors.paqueteria"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="doc-guia">Número de guía</Label>
                            <Input
                                id="doc-guia"
                                v-model="formDocumento.numero_guia"
                                required
                            />
                            <InputError
                                :message="formDocumento.errors.numero_guia"
                            />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="doc-comprobante"
                            >Comprobante de envío (opcional)</Label
                        >
                        <PeopleFileDropzone
                            :model-value="
                                formDocumento.comprobante
                                    ? [formDocumento.comprobante]
                                    : []
                            "
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            label="Arrastra el comprobante aquí"
                            @update:model-value="
                                (f) =>
                                    (formDocumento.comprobante = f[0] ?? null)
                            "
                        />
                        <InputError
                            :message="formDocumento.errors.comprobante"
                        />
                    </div>
                </template>

                <template v-if="pasoDocumento.paso === 'firma_fisica'">
                    <label class="flex items-center gap-2 text-sm">
                        <Casilla v-model="formDocumento.huella_registrada" />
                        Se recabó la huella{{
                            pasoDocumento.documento.requiere_huella
                                ? ' (obligatoria en esta plantilla)'
                                : ''
                        }}
                    </label>
                    <InputError
                        :message="formDocumento.errors.huella_registrada"
                    />
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium">
                            Testigos{{
                                pasoDocumento.documento.requiere_testigos
                                    ? ' (obligatorios)'
                                    : ' (si aplica)'
                            }}
                        </legend>
                        <div
                            v-for="(t, i) in formDocumento.testigos"
                            :key="i"
                            class="grid gap-2 sm:grid-cols-2"
                        >
                            <Input
                                v-model="t.nombre"
                                placeholder="Nombre completo"
                            />
                            <Input v-model="t.puesto" placeholder="Puesto" />
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="w-fit"
                            @click="
                                formDocumento.testigos.push({
                                    nombre: '',
                                    puesto: '',
                                })
                            "
                            >Agregar testigo</Button
                        >
                        <InputError :message="formDocumento.errors.testigos" />
                    </fieldset>
                </template>

                <div
                    v-if="pasoDocumento.paso === 'escaneo'"
                    class="grid gap-1.5"
                >
                    <Label for="doc-archivo"
                        >Escaneo del original firmado</Label
                    >
                    <PeopleFileDropzone
                        :model-value="
                            formDocumento.archivo ? [formDocumento.archivo] : []
                        "
                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                        label="Arrastra el escaneo aquí"
                        @update:model-value="
                            (f) => (formDocumento.archivo = f[0] ?? null)
                        "
                    />
                    <InputError :message="formDocumento.errors.archivo" />
                </div>

                <div
                    v-if="etiquetaFecha[pasoDocumento.paso]"
                    class="grid gap-1.5"
                >
                    <Label for="doc-fecha">{{
                        etiquetaFecha[pasoDocumento.paso]
                    }}</Label>
                    <DatePicker
                        v-model="formDocumento.fecha"
                        id="doc-fecha"
                        :max-value="hoy"
                    />
                    <p
                        v-if="pasoDocumento.paso === 'recepcion'"
                        class="text-xs text-[var(--mrl-texto-suave)]"
                    >
                        Queda registrado que tú recibiste el original en
                        corporativo.
                    </p>
                    <InputError :message="formDocumento.errors.fecha" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="doc-obs">Observaciones</Label>
                    <Textarea
                        id="doc-obs"
                        v-model="formDocumento.observaciones"
                        rows="2"
                    />
                    <InputError :message="formDocumento.errors.observaciones" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="pasoDocumento = null"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="formDocumento.processing"
                        >Guardar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="accion !== null"
        @update:open="(abierto: boolean) => !abierto && (accion = null)"
    >
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ accion?.etiqueta }}</DialogTitle>
                <DialogDescription
                    >{{ colaborador.nombre }} ·
                    {{ ciclo.estado.etiqueta }}</DialogDescription
                >
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="enviar">
                <template v-if="accion?.clave === 'entregar_activos'">
                    <div class="grid gap-1.5">
                        <Label>Activo</Label>
                        <SelectSimple
                            :model-value="
                                form.tipo_activo_id
                                    ? Number(form.tipo_activo_id)
                                    : null
                            "
                            @update:model-value="
                                (v) => (form.tipo_activo_id = v)
                            "
                            numerico
                            :opciones="
                                (
                                    onboarding?.activos.filter(
                                        (x) => !x.entregado,
                                    ) ?? []
                                ).map((a) => ({
                                    value: a.tipo_activo_id,
                                    label: a.nombre,
                                }))
                            "
                            placeholder="Elige el activo"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Serie / identificador</Label
                        ><Input
                            v-model="form.identificador as string"
                        /><InputError :message="form.errors.identificador" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Observaciones</Label
                        ><Textarea
                            v-model="form.observaciones as string"
                            rows="2"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Button
                            type="button"
                            variant="outline"
                            class="self-start"
                            :disabled="
                                !form.tipo_activo_id ||
                                cargandoVistaPreviaResponsiva
                            "
                            @click="verVistaPreviaResponsiva"
                        >
                            <FileSearch class="size-4" />
                            {{
                                cargandoVistaPreviaResponsiva
                                    ? 'Preparando vista previa…'
                                    : 'Vista previa de la responsiva'
                            }}
                        </Button>
                        <InputError
                            :message="errorVistaPreviaResponsiva ?? ''"
                        />
                    </div>
                </template>

                <template
                    v-else-if="accion?.clave === 'retroalimentar_onboarding'"
                >
                    <div class="grid gap-1.5">
                        <Label>Sección de capacitación</Label>
                        <SelectSimple
                            :model-value="
                                form.avance_id ? Number(form.avance_id) : null
                            "
                            @update:model-value="(v) => (form.avance_id = v)"
                            numerico
                            :opciones="
                                (
                                    onboarding?.modulos.filter(
                                        (x) => x.puede_retroalimentar,
                                    ) ?? []
                                ).map((m) => ({
                                    value: m.avance_id,
                                    label: `${m.titulo} (${m.ultima_calificacion})`,
                                }))
                            "
                            placeholder="Elige el módulo"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Retroalimentación / refuerzo</Label
                        ><Textarea
                            v-model="form.retroalimentacion as string"
                            rows="4"
                        /><InputError
                            :message="form.errors.retroalimentacion"
                        />
                    </div>
                </template>

                <template v-else-if="accion?.clave === 'capturar_evaluacion'">
                    <!-- Mismo componente que la vista previa del módulo
                    «Formularios de evaluación»: criterios, filtros, escala y
                    reglas de la versión del formulario de esta evaluación. -->
                    <FormularioPeriodoPrueba
                        v-if="evaluacion?.formulario"
                        :contenido="evaluacion.formulario"
                        :criterios="criteriosForm"
                        :filtros="filtrosForm"
                        :observaciones="String(form.observaciones ?? '')"
                        :errores="form.errors"
                        compacto
                        @update:criterios="(v) => (form.criterios = v)"
                        @update:filtros="(v) => (form.filtros = v)"
                        @update:observaciones="(v) => (form.observaciones = v)"
                    />
                </template>

                <template v-else-if="accion?.clave === 'autorizar_evaluacion'">
                    <p class="text-sm">
                        El jefe recomienda
                        <strong>{{
                            evaluacion?.recomienda_renovar
                                ? 'renovar'
                                : 'NO renovar'
                        }}</strong>
                        (total {{ evaluacion?.calificacion
                        }}<template v-if="evaluacion?.es_oficial">
                            /{{ evaluacion?.total_maximo }}</template
                        >).
                    </p>
                    <p
                        v-if="
                            evaluacion?.es_oficial &&
                            !evaluacionEsElegible &&
                            !excepcionVigente
                        "
                        class="rounded-lg bg-destructive/10 p-2.5 text-xs text-destructive"
                    >
                        No alcanza la regla de renovación del FO-RH-EPP-001. No
                        se puede "forzar aprobado": solicita la excepción de
                        Dirección General para poder autorizar la renovación.
                    </p>
                    <p
                        v-if="excepcionVigente"
                        class="rounded-lg bg-success/10 p-2.5 text-xs text-success"
                    >
                        Dirección General aprobó la excepción el
                        {{ formatearFecha(excepcionVigente.resuelto_en) }}: ya
                        puedes autorizar la renovación.
                    </p>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            :disabled="
                                evaluacion?.es_oficial &&
                                !evaluacionEsElegible &&
                                !excepcionVigente &&
                                !form.renovar
                            "
                            :variant="form.renovar ? 'default' : 'outline'"
                            @click="form.renovar = true"
                            >Autorizar renovación</Button
                        >
                        <Button
                            type="button"
                            :variant="!form.renovar ? 'destructive' : 'outline'"
                            @click="form.renovar = false"
                            >Autorizar no renovación</Button
                        >
                    </div>
                    <div class="grid gap-1.5">
                        <Label
                            >Comentario
                            {{
                                Boolean(form.renovar) !==
                                Boolean(evaluacion?.recomienda_renovar)
                                    ? '(obligatorio: decides distinto al jefe)'
                                    : '(opcional)'
                            }}</Label
                        ><Textarea
                            v-model="form.comentario as string"
                            rows="2"
                        /><InputError :message="form.errors.comentario" />
                    </div>
                    <div v-if="!form.renovar" class="grid gap-1.5">
                        <Label>Motivo de la no renovación</Label
                        ><Textarea
                            v-model="form.motivo_no_renovacion as string"
                            rows="2"
                        />
                    </div>
                    <!-- Lo que se genera al autorizar, visto ANTES de confirmar. -->
                    <VistaPreviaFlujo
                        v-if="evaluacionId"
                        :flujo="form.renovar ? 'renovacion' : 'no_renovacion'"
                        :id="evaluacionId"
                        :datos="
                            form.renovar
                                ? {}
                                : {
                                      motivo: form.motivo_no_renovacion as string,
                                      fecha_efectiva:
                                          form.fecha_efectiva as string,
                                  }
                        "
                        :disabled="form.processing"
                    />
                    <InputError :message="form.errors.aprobacion" />
                    <InputError :message="form.errors.renovar" />
                </template>

                <template v-else-if="accion?.clave === 'solicitar_baja'">
                    <!-- Registro directo (mismo que «Bajas»): queda aplicada
                         al confirmar; sus documentos se completan después en
                         el expediente de la baja. -->
                    <CamposBaja
                        v-model="camposAtajo"
                        :formas="formasAtajo"
                        :recontratable="opciones.recontratable"
                        :entregas="opciones.entregas"
                        :limites="opciones.limites_baja"
                        :errores="form.errors"
                        :es-rh="opciones.es_rh_bajas"
                        etiqueta-fecha="Fecha efectiva (último día laborado)"
                    />
                    <p class="text-xs text-muted-foreground">
                        Se aplica al confirmar: sus accesos se suspenden, se
                        generan sus documentos y el finiquito propuesto.
                    </p>
                    <!-- Renuncia / formato de la causal y finiquito, vistos
                         ANTES de registrar (no se guarda nada). -->
                    <VistaPreviaBaja
                        :colaborador-id="colaborador.id"
                        :campos="camposAtajo"
                        :tipo-baja="tipoBajaAtajo"
                        :disabled="form.processing"
                    />
                    <InputError :message="form.errors.colaborador" />
                    <InputError :message="form.errors.colaborador_id" />
                </template>

                <template
                    v-else-if="
                        ['cancelar', 'devolver_evaluacion'].includes(
                            accion?.clave ?? '',
                        )
                    "
                >
                    <div class="grid gap-1.5">
                        <Label>Motivo (obligatorio)</Label
                        ><Textarea
                            v-model="form.motivo as string"
                            rows="3"
                        /><InputError :message="form.errors.motivo" />
                    </div>
                </template>

                <template v-else-if="accion?.clave === 'registrar_aviso'">
                    <div class="grid gap-1.5">
                        <Label>Renuncia / aviso firmado (PDF o imagen)</Label
                        ><PeopleFileDropzone
                            :model-value="
                                form.archivo ? [form.archivo as File] : []
                            "
                            accept=".pdf,.jpg,.jpeg,.png"
                            label="Arrastra el archivo o haz clic"
                            @update:model-value="
                                (f) => (form.archivo = f[0] ?? null)
                            "
                        /><InputError :message="form.errors.archivo" />
                    </div>
                </template>

                <template v-else-if="accion?.clave === 'calcular_finiquito'">
                    <p class="text-sm text-muted-foreground">
                        Se calcula con el salario registrado y la fecha efectiva
                        de la baja. Al terminar verás el desglose por concepto
                        para revisarlo y ajustar montos.
                        <template v-if="finiquitoCierre">
                            Los ajustes que ya hiciste se conservan.</template
                        >
                    </p>
                    <div class="grid gap-1.5">
                        <Label for="fin-sueldo">Sueldo mensual base</Label
                        ><Input
                            id="fin-sueldo"
                            v-model="form.sueldo_mensual as string"
                            type="number"
                            min="0"
                            step="0.01"
                        />
                        <p
                            v-if="Number(form.sueldo_mensual) > 0"
                            class="text-xs text-muted-foreground"
                        >
                            Salario diario: ${{
                                (
                                    Number(form.sueldo_mensual) / 30
                                ).toLocaleString('es-MX', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2,
                                })
                            }}
                            (sueldo mensual ÷ 30)
                        </p>
                        <InputError :message="form.errors.sueldo_mensual" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="fin-pendiente"
                            >Sueldo pendiente por pagar</Label
                        ><Input
                            id="fin-pendiente"
                            v-model="form.sueldo_pendiente as string"
                            type="number"
                            min="0"
                            step="0.01"
                        /><InputError :message="form.errors.sueldo_pendiente" />
                    </div>
                </template>

                <p v-else class="text-sm text-muted-foreground">
                    ¿Confirmas «{{ accion?.etiqueta }}»?
                </p>

                <InputError
                    :message="
                        form.errors.estado ??
                        form.errors.onboarding ??
                        form.errors.finiquito ??
                        form.errors.fecha_efectiva
                    "
                />

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="accion = null"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        :variant="
                            accion?.tipo === 'peligro'
                                ? 'destructive'
                                : 'default'
                        "
                        >Confirmar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <DocumentPreviewDialog
        :open="vistaPreviaResponsivaAbierta"
        :preview-url="vistaPreviaResponsivaUrl"
        nombre="Carta responsiva (vista previa).pdf"
        mime="application/pdf"
        estado="Vista previa — la entrega aún no se registra"
        @update:open="(v) => (vistaPreviaResponsivaAbierta = v)"
    />

    <FiniquitoCierreDialog
        v-if="cierre"
        v-model:open="finiquitoAbierto"
        :cierre-id="cierreId"
        :colaborador="colaborador.nombre"
        :finiquito="finiquitoCierre"
        :puede-editar="puedeEditarFiniquito"
        :puede-entregar="puedeEntregarFiniquito"
        :entrega-inicial="finiquitoEntregaInicial"
    />

    <Dialog
        :open="dialogoExcepcionAbierto"
        @update:open="
            (abierto: boolean) => !abierto && (dialogoExcepcionAbierto = false)
        "
    >
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle
                    >Solicitar excepción a Dirección General</DialogTitle
                >
                <DialogDescription>
                    {{ colaborador.nombre }} — la evaluación no alcanza la regla
                    de renovación del FO-RH-EPP-001. Toda excepción requiere
                    autorización escrita de Dirección General.
                </DialogDescription>
            </DialogHeader>

            <form
                class="flex flex-col gap-4"
                @submit.prevent="solicitarExcepcion"
            >
                <div class="grid gap-1.5">
                    <Label>Motivo</Label>
                    <Textarea v-model="excepcionForm.motivo" rows="4" />
                    <InputError :message="excepcionForm.errors.motivo" />
                </div>
                <div class="grid gap-1.5">
                    <Label>Documento soporte (opcional)</Label>
                    <input
                        type="file"
                        class="text-sm"
                        @change="
                            (e: Event) =>
                                (excepcionForm.documento_soporte =
                                    (e.target as HTMLInputElement).files?.[0] ??
                                    null)
                        "
                    />
                    <InputError
                        :message="excepcionForm.errors.documento_soporte"
                    />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="dialogoExcepcionAbierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="excepcionForm.processing"
                        >Enviar a Dirección General</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
