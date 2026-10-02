<script setup lang="ts">
import { formatearFecha } from '@/lib/fechas';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Download,
    Paperclip,
    Pencil,
    QrCode,
    Upload,
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
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import CandidatoFormDialog from '@/components/Rh/CandidatoFormDialog.vue';
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
import { dashboard } from '@/routes';
import {
    autorizar,
    contratacion,
    cv,
    descartar,
    devolver,
    entrevista,
    index,
    perfil,
    preautorizar,
    rechazar,
    socioeconomico,
} from '@/routes/rh/candidatos';
import { descargar as descargarCv } from '@/routes/rh/candidatos/cv';
import { descargar as descargarEvidencia } from '@/routes/rh/candidatos/evidencias';
import psicometricas from '@/routes/rh/candidatos/psicometricas';
import referencias from '@/routes/rh/candidatos/referencias';
import { show as verInvitacion } from '@/routes/rh/incorporacion/invitaciones';
import type {
    AccionCiclo,
    CandidatoFicha,
    CandidatoItem,
    EstadoCiclo,
    FormularioAccion,
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
}>();

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
        }) as unknown as CandidatoItem,
);

const form = useForm<FormularioAccion>({});

const hoy = new Date().toISOString().slice(0, 10);
const camposPorAccion: Record<string, () => FormularioAccion> = {
    evaluar_perfil: () => ({ viable: true, observaciones: '' }),
    registrar_entrevista: () => ({
        realizada_en: `${hoy}T10:00`,
        resultado: 'viable',
        observaciones: '',
    }),
    enviar_psicometricas: () => ({ link: '' }),
    registrar_resultados_psicometricas: () => ({
        resumen: '',
        archivos: [] as File[],
    }),
    revisar_psicometricas: () => ({ viable: true, observaciones: '' }),
    registrar_socioeconomico: () => ({
        fecha_visita: hoy,
        direccion: '',
        checklist: {
            vivienda_en_orden: false,
            vive_con_familia: false,
            arraigo_anios: 0,
            resguardo_motocicleta: false,
        },
        riesgos: '',
        observaciones: '',
        resultado: 'viable',
        evidencias: [] as File[],
    }),
    registrar_referencia: () => ({
        empresa: '',
        contacto: '',
        telefono: '',
        relacion_puesto: '',
        resultado: 'positiva',
        observaciones: '',
    }),
    concluir_referencias: () => ({ viable: true, observaciones: '' }),
    preautorizar: () => ({ comentario: '' }),
    autorizar_rh: () => ({ comentario: '' }),
    devolver_rh: () => ({ motivo: '' }),
    rechazar_rh: () => ({ motivo: '' }),
    descartar: () => ({
        estado: props.opciones.salidas[0]?.value ?? 'no_viable',
        motivo: '',
    }),
    iniciar_contratacion: () => ({
        sueldo_mensual: '',
        fecha_ingreso: hoy,
        tipo_contratacion: 'periodo_prueba',
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
            registrar_referencia: referencias.store.url(id),
            concluir_referencias: referencias.concluir.url(id),
            preautorizar: preautorizar.url(id),
            autorizar_rh: autorizar.url(id),
            devolver_rh: devolver.url(id),
            rechazar_rh: rechazar.url(id),
            descartar: descartar.url(id),
            iniciar_contratacion: contratacion.url(id),
        } as Record<string, string>
    )[clave];
}

function enviar() {
    const accion = accionActiva.value;

    if (!accion) {
        return;
    }

    form.post(url(accion.clave), {
        preserveScroll: true,
        forceFormData: [
            'registrar_resultados_psicometricas',
            'registrar_socioeconomico',
        ].includes(accion.clave),
        onSuccess: () => (accionActiva.value = null),
    });
}

const formCv = useForm({ cv: null as File | null });

function subirCv(event: Event) {
    const input = event.target as HTMLInputElement;
    formCv.cv = input.files?.[0] ?? null;

    if (formCv.cv) {
        formCv.post(cv.url(props.candidato.id), {
            preserveScroll: true,
            forceFormData: true,
        });
    }
}

const esDecisionViable = computed(() =>
    [
        'evaluar_perfil',
        'revisar_psicometricas',
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

const claseBoton: Record<string, 'default' | 'secondary' | 'destructive'> = {
    primaria: 'default',
    secundaria: 'secondary',
    peligro: 'destructive',
};
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
            <Button variant="secondary" @click="editar = true">
                <Pencil class="size-4" />
                Editar datos
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
                        v-for="accion in ciclo.acciones_permitidas"
                        :key="accion.clave"
                        :variant="claseBoton[accion.tipo] ?? 'secondary'"
                        @click="abrir(accion)"
                    >
                        {{ accion.etiqueta }}
                    </Button>
                </section>

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
                        <div class="sm:col-span-2">
                            <dt class="text-muted-foreground">Currículum</dt>
                            <dd class="flex items-center gap-3">
                                <a
                                    v-if="candidato.tiene_cv"
                                    :href="descargarCv.url(candidato.id)"
                                    class="inline-flex items-center gap-1 text-[var(--mrl-petroleo)] hover:underline"
                                >
                                    <Download class="size-4" /> Descargar CV
                                </a>
                                <span v-else class="text-muted-foreground"
                                    >Sin CV.</span
                                >
                                <label
                                    class="ml-auto inline-flex cursor-pointer items-center gap-1 text-muted-foreground hover:text-foreground"
                                >
                                    <Upload class="size-4" />
                                    {{
                                        candidato.tiene_cv
                                            ? 'Reemplazar'
                                            : 'Subir CV'
                                    }}
                                    <input
                                        type="file"
                                        accept=".pdf,.doc,.docx"
                                        class="hidden"
                                        @change="subirCv"
                                    />
                                </label>
                            </dd>
                        </div>
                    </dl>
                </section>

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
                        <ul
                            v-if="item.evidencias.length"
                            class="mt-2 flex flex-wrap gap-2"
                        >
                            <li v-for="ev in item.evidencias" :key="ev.id">
                                <a
                                    :href="
                                        descargarEvidencia.url({
                                            candidato: candidato.id,
                                            evidencia: ev.id,
                                        })
                                    "
                                    target="_blank"
                                    class="inline-flex items-center gap-1 text-xs text-[var(--mrl-petroleo)] hover:underline"
                                >
                                    <Paperclip class="size-3" /> {{ ev.nombre }}
                                </a>
                            </li>
                        </ul>
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
                                v-for="(valor, clave) in item.checklist"
                                :key="clave"
                            >
                                {{ String(clave).replaceAll('_', ' ') }}:
                                <strong>{{
                                    valor === true
                                        ? 'sí'
                                        : valor === false
                                          ? 'no'
                                          : valor
                                }}</strong>
                            </li>
                        </ul>
                        <p v-if="item.riesgos" class="mt-1">
                            Riesgos: {{ item.riesgos }}
                        </p>
                        <p v-if="item.observaciones" class="mt-1">
                            {{ item.observaciones }}
                        </p>
                        <ul
                            v-if="item.evidencias.length"
                            class="mt-2 flex flex-wrap gap-2"
                        >
                            <li v-for="ev in item.evidencias" :key="ev.id">
                                <a
                                    :href="
                                        descargarEvidencia.url({
                                            candidato: candidato.id,
                                            evidencia: ev.id,
                                        })
                                    "
                                    target="_blank"
                                    class="inline-flex items-center gap-1 text-xs text-[var(--mrl-petroleo)] hover:underline"
                                >
                                    <Paperclip class="size-3" />
                                    {{ ev.tipo_etiqueta }}: {{ ev.nombre }}
                                </a>
                            </li>
                        </ul>
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
                    <div class="grid gap-1.5">
                        <Label>Observaciones</Label
                        ><Textarea
                            v-model="form.observaciones as string"
                            rows="3"
                        /><InputError :message="form.errors.observaciones" />
                    </div>
                </template>

                <template
                    v-else-if="accionActiva?.clave === 'enviar_psicometricas'"
                >
                    <div class="grid gap-1.5">
                        <Label>Link de las pruebas</Label
                        ><Input
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
                    <div class="grid gap-1.5">
                        <Label>Resumen de resultados</Label
                        ><Textarea
                            v-model="form.resumen as string"
                            rows="4"
                        /><InputError :message="form.errors.resumen" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Reportes (PDF/imagen)</Label
                        ><PeopleFileDropzone
                            :model-value="(form.archivos as File[]) ?? []"
                            multiple
                            accept=".pdf,.jpg,.jpeg,.png"
                            label="Arrastra los archivos o haz clic"
                            @update:model-value="(f) => (form.archivos = f)"
                        />
                    </div>
                </template>

                <template
                    v-else-if="
                        accionActiva?.clave === 'registrar_socioeconomico'
                    "
                >
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label>Fecha de visita</Label
                            ><DatePicker
                                v-model="form.fecha_visita as string"
                            /><InputError :message="form.errors.fecha_visita" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Arraigo (años)</Label
                            ><Input
                                v-model.number="
                                    (form.checklist as Record<string, number>)
                                        .arraigo_anios
                                "
                                type="number"
                                min="0"
                            />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Dirección visitada</Label
                        ><Input v-model="form.direccion as string" /><InputError
                            :message="form.errors.direccion"
                        />
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
                        <Label>Riesgos o inconsistencias</Label
                        ><Textarea v-model="form.riesgos as string" rows="2" />
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
                            >Viable</Button
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
                    <div class="grid gap-1.5">
                        <Label>Observaciones</Label
                        ><Textarea
                            v-model="form.observaciones as string"
                            rows="2"
                        /><InputError :message="form.errors.observaciones" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label
                            >Fotografías / video / PDF (evidencia
                            privada)</Label
                        ><PeopleFileDropzone
                            :model-value="(form.evidencias as File[]) ?? []"
                            multiple
                            accept=".jpg,.jpeg,.png,.pdf,.mp4,.mov"
                            :max-size-mb="100"
                            label="Arrastra fotos, PDF o video, o haz clic"
                            @update:model-value="(f) => (form.evidencias = f)"
                        />
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
                </template>

                <template
                    v-else-if="
                        ['preautorizar', 'autorizar_rh'].includes(
                            accionActiva?.clave ?? '',
                        )
                    "
                >
                    <p class="text-sm text-muted-foreground">
                        {{
                            accionActiva?.clave === 'preautorizar'
                                ? 'Tu preautorización NO contrata: RH dará la autorización final.'
                                : 'Autorización final: después podrás generar el QR de contratación.'
                        }}
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

                <template
                    v-else-if="
                        ['devolver_rh', 'rechazar_rh'].includes(
                            accionActiva?.clave ?? '',
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
                        <Label>Motivo (obligatorio)</Label
                        ><Textarea
                            v-model="form.motivo as string"
                            rows="3"
                        /><InputError :message="form.errors.motivo" />
                    </div>
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
                        :disabled="form.processing"
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
</template>
