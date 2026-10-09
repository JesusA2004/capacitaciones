<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Circle,
    FileText,
    FolderOpen,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AprobacionesResumen from '@/components/ciclo/AprobacionesResumen.vue';
import CicloEstadoPanel from '@/components/ciclo/CicloEstadoPanel.vue';
import CicloStepper from '@/components/ciclo/CicloStepper.vue';
import CicloTimeline from '@/components/ciclo/CicloTimeline.vue';
import Casilla from '@/components/Common/Casilla.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import DateTimePicker from '@/components/Common/DateTimePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DocumentosProceso from '@/components/documentos/DocumentosProceso.vue';
import InputError from '@/components/InputError.vue';
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
import { dashboard } from '@/routes';
import cierres from '@/routes/rh/cierres';
import colaboradores from '@/routes/rh/colaboradores';
import documentosLaborales from '@/routes/rh/documentos-laborales';
import evaluaciones from '@/routes/rh/evaluaciones';
import { show as verExpediente } from '@/routes/rh/expedientes';
import onboardingRutas from '@/routes/rh/onboarding';
import { index as reingresos } from '@/routes/rh/reingresos';
import type {
    AccionCiclo,
    EstadoCiclo,
    FormularioAccion,
    OnboardingDetalle,
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
    evaluacion: Record<string, unknown> | null;
    cierre: Record<string, unknown> | null;
    opciones: {
        causas: { value: string; etiqueta: string }[];
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
        criterios: props.opciones.criterios.map((criterio) => ({
            criterio,
            calificacion: 8,
        })),
        recomienda_renovar: true,
        observaciones: '',
    }),
    autorizar_evaluacion: () => ({
        renovar: Boolean(props.evaluacion?.recomienda_renovar ?? true),
        comentario: '',
        motivo_no_renovacion: '',
    }),
    devolver_evaluacion: () => ({ motivo: '' }),
    solicitar_baja: () => ({
        tipo_baja: props.opciones.causas[0]?.value ?? 'renuncia',
        motivo: '',
        fecha_efectiva: hoy,
        evidencias: [] as File[],
    }),
    preautorizar: () => ({ comentario: '' }),
    autorizar_rh: () => ({ comentario: '' }),
    rechazar: () => ({ motivo: '' }),
    devolver: () => ({ motivo: '' }),
    registrar_aviso: () => ({ archivo: null }),
    calcular_finiquito: () => ({ sueldo_mensual: '', sueldo_pendiente: 0 }),
    autorizar_finiquito: () => ({}),
    programar_pago: () => ({
        fecha: hoy,
        monto:
            (props.cierre?.finiquito as { neto?: string } | null)?.neto ?? '',
        metodo: 'transferencia',
        observaciones: '',
    }),
    registrar_cita: () => ({ fecha: `${hoy}T10:00` }),
    finiquito_firmado: () => ({ archivo: null }),
    confirmar_pago: () => ({ referencia: '' }),
    cerrar: () => ({}),
    cancelar: () => ({ motivo: '' }),
};

const conArchivos = ['solicitar_baja', 'registrar_aviso', 'finiquito_firmado'];

function abrir(a: AccionCiclo) {
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
const criteriosForm = computed(
    () =>
        (form.criterios ?? []) as { criterio: string; calificacion: number }[],
);

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
            return colaboradores.cierres.store.url(props.colaborador.id);
        default:
            return (
                cierres as unknown as Record<
                    string,
                    { url: (id: number) => string }
                >
            )[aliasCierre[clave] ?? clave].url(cierreId.value);
    }
}

const aliasCierre: Record<string, string> = {
    autorizar_rh: 'autorizar',
    registrar_aviso: 'aviso',
    calcular_finiquito: 'calcularFiniquito',
    autorizar_finiquito: 'autorizarFiniquito',
    programar_pago: 'programarPago',
    registrar_cita: 'cita',
    finiquito_firmado: 'finiquitoFirmado',
    confirmar_pago: 'confirmarPago',
};

function enviar() {
    if (!accion.value) {
        return;
    }

    form.post(destino(accion.value.clave), {
        preserveScroll: true,
        forceFormData: conArchivos.includes(accion.value.clave),
        onSuccess: () => (accion.value = null),
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
            onSuccess: () => (pasoDocumento.value = null),
        });
}

const etiquetaPaso: Record<string, string> = {
    imprimir: 'Marcar impreso',
    firma_fisica: 'Registrar firma',
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

const variante: Record<string, 'default' | 'secondary' | 'destructive'> = {
    primaria: 'default',
    secundaria: 'secondary',
    peligro: 'destructive',
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
                <CicloEstadoPanel :ciclo="ciclo" />

                <section
                    v-if="ciclo.acciones_permitidas.length"
                    class="flex flex-wrap gap-2 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                    aria-label="Acciones disponibles"
                >
                    <Button
                        v-for="a in ciclo.acciones_permitidas"
                        :key="a.clave"
                        :variant="variante[a.tipo] ?? 'secondary'"
                        @click="abrir(a)"
                        >{{ a.etiqueta }}</Button
                    >
                    <Button
                        v-if="
                            evaluacion &&
                            ciclo.acciones_permitidas.some(
                                (a) => a.clave === 'autorizar_evaluacion',
                            )
                        "
                        variant="secondary"
                        @click="
                            abrir({
                                clave: 'devolver_evaluacion',
                                etiqueta: 'Devolver evaluación',
                                tipo: 'secundaria',
                            })
                        "
                        >Devolver evaluación</Button
                    >
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

                <DocumentosProceso tipo="colaborador" :id="colaborador.id" />
                <DocumentosProceso
                    v-if="evaluacion && !cierre"
                    tipo="evaluacion"
                    :id="evaluacionId"
                />
                <DocumentosProceso v-if="cierre" tipo="cierre" :id="cierreId" />

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
                <AprobacionesResumen :aprobaciones="ciclo.aprobaciones" />
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
                    <div
                        v-for="(c, i) in criteriosForm"
                        :key="i"
                        class="grid grid-cols-[1fr_80px] items-center gap-2"
                    >
                        <Label>{{ c.criterio }}</Label>
                        <Input
                            v-model.number="c.calificacion"
                            type="number"
                            min="0"
                            max="10"
                            step="0.5"
                        />
                    </div>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            :variant="
                                form.recomienda_renovar ? 'default' : 'outline'
                            "
                            @click="form.recomienda_renovar = true"
                            >Recomiendo renovar</Button
                        >
                        <Button
                            type="button"
                            :variant="
                                !form.recomienda_renovar
                                    ? 'destructive'
                                    : 'outline'
                            "
                            @click="form.recomienda_renovar = false"
                            >No renovar</Button
                        >
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Tu recomendación es una preautorización: RH da la
                        autorización final.
                    </p>
                    <div class="grid gap-1.5">
                        <Label>Observaciones</Label
                        ><Textarea
                            v-model="form.observaciones as string"
                            rows="3"
                        />
                    </div>
                </template>

                <template v-else-if="accion?.clave === 'autorizar_evaluacion'">
                    <p class="text-sm">
                        El jefe recomienda
                        <strong>{{
                            evaluacion?.recomienda_renovar
                                ? 'renovar'
                                : 'NO renovar'
                        }}</strong>
                        (promedio {{ evaluacion?.calificacion }}).
                    </p>
                    <div class="flex gap-2">
                        <Button
                            type="button"
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
                    <InputError :message="form.errors.aprobacion" />
                </template>

                <template v-else-if="accion?.clave === 'solicitar_baja'">
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="c in opciones.causas"
                            :key="c.value"
                            type="button"
                            :variant="
                                form.tipo_baja === c.value
                                    ? 'default'
                                    : 'outline'
                            "
                            @click="form.tipo_baja = c.value"
                            >{{ c.etiqueta }}</Button
                        >
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Fecha efectiva</Label
                        ><DatePicker
                            v-model="form.fecha_efectiva as string"
                        /><InputError :message="form.errors.fecha_efectiva" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Motivo</Label
                        ><Textarea
                            v-model="form.motivo as string"
                            rows="3"
                        /><InputError :message="form.errors.motivo" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Evidencia (renuncia firmada, reportes…)</Label
                        ><PeopleFileDropzone
                            :model-value="(form.evidencias as File[]) ?? []"
                            multiple
                            accept=".pdf,.jpg,.jpeg,.png"
                            label="Arrastra la evidencia o haz clic"
                            @update:model-value="(f) => (form.evidencias = f)"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Nada se desactiva hasta la autorización de RH y la fecha
                        efectiva.
                    </p>
                </template>

                <template
                    v-else-if="
                        ['preautorizar', 'autorizar_rh'].includes(
                            accion?.clave ?? '',
                        )
                    "
                >
                    <div class="grid gap-1.5">
                        <Label>Comentario (opcional)</Label
                        ><Textarea
                            v-model="form.comentario as string"
                            rows="2"
                        />
                    </div>
                    <InputError :message="form.errors.aprobacion" />
                </template>

                <template
                    v-else-if="
                        [
                            'rechazar',
                            'devolver',
                            'cancelar',
                            'devolver_evaluacion',
                        ].includes(accion?.clave ?? '')
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

                <template
                    v-else-if="
                        ['registrar_aviso', 'finiquito_firmado'].includes(
                            accion?.clave ?? '',
                        )
                    "
                >
                    <div class="grid gap-1.5">
                        <Label
                            >{{
                                accion?.clave === 'registrar_aviso'
                                    ? 'Renuncia / aviso firmado'
                                    : 'Finiquito firmado con huella'
                            }}
                            (PDF o imagen)</Label
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
                    <div class="grid gap-1.5">
                        <Label>Sueldo mensual base</Label
                        ><Input
                            v-model="form.sueldo_mensual as string"
                            type="number"
                            min="0"
                            step="0.01"
                        /><InputError :message="form.errors.sueldo_mensual" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Sueldo pendiente por pagar</Label
                        ><Input
                            v-model="form.sueldo_pendiente as string"
                            type="number"
                            min="0"
                            step="0.01"
                        />
                    </div>
                </template>

                <template v-else-if="accion?.clave === 'programar_pago'">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label>Fecha de pago</Label
                            ><DatePicker
                                v-model="form.fecha as string"
                            /><InputError :message="form.errors.fecha" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Monto final</Label
                            ><Input
                                v-model="form.monto as string"
                                type="number"
                                min="0"
                                step="0.01"
                            />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Método</Label
                        ><Input
                            v-model="form.metodo as string"
                            placeholder="Transferencia, efectivo…"
                        /><InputError :message="form.errors.metodo" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Observaciones</Label
                        ><Textarea
                            v-model="form.observaciones as string"
                            rows="2"
                        />
                    </div>
                </template>

                <template v-else-if="accion?.clave === 'registrar_cita'">
                    <div class="grid gap-1.5">
                        <Label>Fecha y hora de la cita</Label
                        ><DateTimePicker
                            v-model="form.fecha as string"
                        /><InputError :message="form.errors.fecha" />
                    </div>
                </template>

                <template v-else-if="accion?.clave === 'confirmar_pago'">
                    <div class="grid gap-1.5">
                        <Label>Referencia del pago</Label
                        ><Input
                            v-model="form.referencia as string"
                        /><InputError :message="form.errors.referencia" />
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
</template>
