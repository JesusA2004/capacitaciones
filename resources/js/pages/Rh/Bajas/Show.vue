<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    FolderOpen,
    History,
    Lock,
    Pencil,
    Scale,
    Undo2,
    UserMinus,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DocumentosBaja from '@/components/bajas/DocumentosBaja.vue';
import Casilla from '@/components/Common/Casilla.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import PeopleConfirmDialog from '@/components/people/PeopleConfirmDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePermisos } from '@/composables/usePermisos';
import { claseForma, estatusBaja } from '@/lib/bajas';
import { formatearFecha, formatearFechaHora } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import rutasBajas from '@/routes/rh/bajas';
import { index as cierresIndex } from '@/routes/rh/cierres';
import { ciclo as cicloColaborador } from '@/routes/rh/colaboradores';
import type { BajaDetalle, FormaSalidaOpcion, LimitesBaja } from '@/types';

/**
 * Expediente de la baja: datos, documentos con vista previa, bitácora y
 * acciones según el rol. La baja ya está APLICADA desde que se registró —
 * RH: completar/corregir datos, cambiar causal con motivo, enviar a
 * Jurídico (si la causal lo requiere), Cerrar, Reabrir con motivo y «No
 * procede»; Jurídico: aprobar o devolver a RH con observaciones; gerente:
 * adjuntar documentos. El backend vuelve a validar cada acción
 * (BajaService).
 */
const props = defineProps<{
    baja: BajaDetalle;
    causales: FormaSalidaOpcion[];
    recontratable: { value: string; label: string }[];
    entregas: { value: string; label: string }[];
    limites: LimitesBaja;
}>();

defineOptions({
    layout: (pageProps: { baja: { folio: string } }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Bajas', href: rutasBajas.index() },
            { title: pageProps.baja.folio, href: '' },
        ],
    }),
});

const { tienePermiso } = usePermisos();
const page = usePage();
// Errores de una acción directa (validar, enviar, cerrar…): el backend
// explica qué falta (documentos, Jurídico, fechas).
const erroresAccion = computed(() =>
    Object.values((page.props.errors ?? {}) as Record<string, string>),
);
const puede = (accion: string) => props.baja.acciones.includes(accion);
const procesando = ref(false);

function accionDirecta(url: string): void {
    procesando.value = true;
    router.post(
        url,
        {},
        { preserveScroll: true, onFinish: () => (procesando.value = false) },
    );
}

const confirmarCierre = ref(false);

function cerrarBaja() {
    confirmarCierre.value = false;
    accionDirecta(rutasBajas.cerrar.url(props.baja.id));
}

// ── Diálogo de motivo (Jurídico devuelve, reabrir, no procede, aprobar) ──
type AccionMotivo =
    'devolver_juridico' | 'reabrir' | 'cancelar' | 'aprobar_juridico';
const accionMotivo = ref<AccionMotivo | null>(null);
const formMotivo = useForm({ motivo: '' });
const textosMotivo: Record<
    AccionMotivo,
    { titulo: string; boton: string; ayuda: string }
> = {
    devolver_juridico: {
        titulo: 'Devolver a RH',
        boton: 'Devolver con observaciones',
        ayuda: 'La baja sigue aplicada: RH corrige y la vuelve a enviar, o la marca «No procede».',
    },
    reabrir: {
        titulo: 'Reabrir la baja',
        boton: 'Reabrir',
        ayuda: 'Regresa a «En trámite». El motivo queda en la bitácora.',
    },
    cancelar: {
        titulo: 'La baja no procede',
        boton: 'Marcar «No procede»',
        ayuda: 'La persona sigue laborando: se cancela el cierre laboral y se le devuelve el acceso. Nada se borra.',
    },
    aprobar_juridico: {
        titulo: 'Revisión de Jurídico',
        boton: 'Aprobar y regresar a RH',
        ayuda: 'Comentario opcional sobre el acta y el aviso.',
    },
};

function abrirMotivo(a: AccionMotivo) {
    formMotivo.reset();
    formMotivo.clearErrors();
    accionMotivo.value = a;
}

function enviarMotivo() {
    const a = accionMotivo.value;

    if (!a) {
        return;
    }

    const url = {
        devolver_juridico: rutasBajas.devolverJuridico.url(props.baja.id),
        reabrir: rutasBajas.reabrir.url(props.baja.id),
        cancelar: rutasBajas.cancelar.url(props.baja.id),
        aprobar_juridico: rutasBajas.aprobarJuridico.url(props.baja.id),
    }[a];

    formMotivo.post(url, {
        preserveScroll: true,
        onSuccess: () => (accionMotivo.value = null),
    });
}

// ── Cambiar causal (RH, con motivo; queda en la bitácora) ──
const causalAbierto = ref(false);
const formCausal = useForm({
    causal_rotacion_id: props.baja.causal_rotacion_id as number | null,
    motivo: '',
});
const opcionesCausal = computed(() =>
    props.causales.flatMap((f) =>
        f.causales.map((c) => ({
            value: c.id,
            label: `${f.etiqueta} · ${c.clave ? `${c.clave} ` : ''}${c.nombre}`,
        })),
    ),
);

function cambiarCausal() {
    formCausal.post(rutasBajas.cambiarCausal.url(props.baja.id), {
        preserveScroll: true,
        onSuccess: () => {
            causalAbierto.value = false;
            formCausal.reset('motivo');
        },
    });
}

// ── Datos que RH completa o corrige ──
const datosAbierto = ref(false);
const formDatos = useForm({
    fecha_baja: props.baja.fecha_baja ?? '',
    comentarios: props.baja.comentarios ?? '',
    recontratable: props.baja.recontratable ?? '',
    fecha_conocimiento_falta: props.baja.fecha_conocimiento_falta ?? '',
    entrega_aviso_rescision: props.baja.entrega_aviso_rescision ?? '',
    fecha_entrega_aviso: props.baja.fecha_entrega_aviso ?? '',
    es_rotacion: props.baja.es_rotacion,
    motivo_cambio: '',
});

function guardarDatos() {
    formDatos
        .transform((d) => ({
            ...d,
            recontratable: d.recontratable || null,
            entrega_aviso_rescision: d.entrega_aviso_rescision || null,
            fecha_conocimiento_falta: d.fecha_conocimiento_falta || null,
            fecha_entrega_aviso: d.fecha_entrega_aviso || null,
        }))
        .patch(rutasBajas.actualizar.url(props.baja.id), {
            preserveScroll: true,
            onSuccess: () => (datosAbierto.value = false),
        });
}

const errores = computed(() => Object.values(props.baja.errores));
const esRescision = computed(() => props.baja.forma === 'RE');

const chipEstatus = computed(() => estatusBaja(props.baja.estatus));

const datos = computed(() => [
    { etiqueta: 'Colaborador', valor: props.baja.colaborador },
    {
        etiqueta: 'Número de empleado',
        valor: props.baja.numero_empleado ?? '—',
    },
    { etiqueta: 'Sucursal', valor: props.baja.sucursal ?? '—' },
    { etiqueta: 'Puesto', valor: props.baja.puesto ?? '—' },
    {
        etiqueta: 'Fecha de ingreso',
        valor: formatearFecha(props.baja.fecha_ingreso),
    },
    {
        etiqueta: 'Último día laborado',
        valor: formatearFecha(props.baja.fecha_baja),
    },
    {
        etiqueta: 'Antigüedad',
        valor:
            props.baja.antiguedad_dias === null
                ? '—'
                : `${props.baja.antiguedad_dias} días`,
    },
    {
        etiqueta: '¿Recontratable?',
        valor: props.baja.recontratable_etiqueta ?? 'Pendiente',
    },
    {
        etiqueta: 'Cuenta en rotación',
        valor: props.baja.es_rotacion ? 'Sí' : 'No (movimiento interno)',
    },
    { etiqueta: 'Registrada por', valor: props.baja.solicitado_por ?? '—' },
    {
        etiqueta: 'Registrada',
        valor: formatearFechaHora(props.baja.enviado_en),
    },
    { etiqueta: 'Origen', valor: props.baja.origen_etiqueta },
]);
</script>

<template>
    <Head :title="`Baja ${baja.folio}`" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="`${baja.folio} · ${baja.colaborador}`"
            :descripcion="`${baja.sucursal ?? 'Sin sucursal'} · ${baja.puesto ?? 'Sin puesto'}`"
            :icono="UserMinus"
        >
            <Button
                v-if="puede('enviar_juridico')"
                variant="outline"
                :disabled="procesando"
                @click="accionDirecta(rutasBajas.enviarJuridico.url(baja.id))"
                ><Scale class="size-4" />Enviar a Jurídico</Button
            >
            <Button
                v-if="puede('aprobar_juridico')"
                :disabled="procesando"
                @click="abrirMotivo('aprobar_juridico')"
                ><CheckCircle2 class="size-4" />Aprobar (Jurídico)</Button
            >
            <Button
                v-if="puede('devolver_juridico')"
                variant="outline"
                :disabled="procesando"
                @click="abrirMotivo('devolver_juridico')"
                ><XCircle class="size-4" />Devolver a RH</Button
            >
            <Button
                v-if="puede('cerrar')"
                :disabled="procesando"
                @click="confirmarCierre = true"
                ><Lock class="size-4" />Cerrar baja</Button
            >
            <Button
                v-if="puede('reabrir')"
                variant="outline"
                @click="abrirMotivo('reabrir')"
                ><Undo2 class="size-4" />Reabrir</Button
            >
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <ChipEstado
                :etiqueta="baja.estatus_etiqueta"
                :tono="chipEstatus.tono"
                :icono="chipEstatus.icono"
                tamano="md"
            />
            <span
                v-if="baja.forma_etiqueta"
                class="inline-flex items-center gap-1.5 text-sm"
            >
                <span
                    :class="
                        cn(
                            'size-2.5 rounded-full',
                            claseForma(baja.forma_token).fondo,
                        )
                    "
                />{{ baja.forma_etiqueta }}
            </span>
            <span class="text-sm text-[var(--mrl-texto-suave)]">
                {{ baja.causal ?? 'Causal pendiente' }}
            </span>
            <Badge v-if="baja.requiere_juridico" variant="outline"
                >Requiere Jurídico{{
                    baja.juridico_en ? ' · aprobada' : ''
                }}</Badge
            >
            <Button
                v-if="puede('cambiar_causal')"
                size="sm"
                variant="ghost"
                @click="causalAbierto = true"
                >Cambiar causal</Button
            >
        </div>

        <div
            v-if="
                erroresAccion.length &&
                accionMotivo === null &&
                !causalAbierto &&
                !datosAbierto
            "
            class="rounded-2xl bg-danger-soft px-4 py-3 text-sm text-destructive"
            role="alert"
        >
            <p v-for="(e, i) in erroresAccion" :key="i">{{ e }}</p>
        </div>

        <p
            v-if="
                baja.estatus !== 'cerrada' &&
                baja.estatus !== 'cancelada' &&
                baja.motivo_rechazo
            "
            class="rounded-2xl bg-warning-soft px-4 py-3 text-sm text-warning"
        >
            <strong
                >{{
                    baja.rechazado_area === 'juridico'
                        ? 'Jurídico la devolvió'
                        : 'Rechazada (flujo anterior)'
                }}:</strong
            >
            {{ baja.motivo_rechazo }}
        </p>
        <p
            v-if="baja.estatus === 'cancelada' && baja.motivo_cancelacion"
            class="rounded-2xl bg-muted px-4 py-3 text-sm"
        >
            <strong>No procede:</strong> {{ baja.motivo_cancelacion }}
        </p>

        <div
            v-if="baja.advertencias.length || errores.length"
            class="grid gap-2"
        >
            <p
                v-for="a in baja.advertencias"
                :key="a.clave"
                class="flex items-start gap-2 rounded-2xl bg-warning-soft px-4 py-3 text-sm text-warning"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />{{ a.mensaje }}
            </p>
            <div
                v-if="errores.length"
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-4 py-3 text-sm"
            >
                <p class="font-medium">Falta para cerrar:</p>
                <ul class="mt-1 list-disc pl-5 text-[var(--mrl-texto-suave)]">
                    <li v-for="(e, i) in errores" :key="i">{{ e }}</li>
                </ul>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-[3fr_2fr]">
            <div class="grid content-start gap-5">
                <section
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h3 class="text-base font-semibold">
                            Datos de la baja
                        </h3>
                        <Button
                            v-if="puede('actualizar')"
                            size="sm"
                            variant="outline"
                            @click="datosAbierto = true"
                            ><Pencil class="size-4" />Completar o
                            corregir</Button
                        >
                    </div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-3">
                        <div v-for="d in datos" :key="d.etiqueta">
                            <dt class="text-xs text-[var(--mrl-texto-suave)]">
                                {{ d.etiqueta }}
                            </dt>
                            <dd class="text-sm font-medium break-words">
                                {{ d.valor }}
                            </dd>
                        </div>
                    </dl>
                    <div v-if="baja.causal_ayuda" class="mt-4 text-sm">
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            Cuándo usar esta causal
                        </p>
                        <p>{{ baja.causal_ayuda }}</p>
                    </div>
                    <div class="mt-4 text-sm">
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            Comentarios
                        </p>
                        <p class="whitespace-pre-line">
                            {{ baja.comentarios ?? '—' }}
                        </p>
                    </div>
                    <div
                        v-if="esRescision"
                        class="mt-4 grid gap-3 rounded-xl bg-crema/40 p-3 sm:grid-cols-3"
                    >
                        <div>
                            <p class="text-xs text-[var(--mrl-texto-suave)]">
                                Se conoció la falta
                            </p>
                            <p class="text-sm font-medium">
                                {{
                                    formatearFecha(
                                        baja.fecha_conocimiento_falta,
                                    )
                                }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-[var(--mrl-texto-suave)]">
                                Entrega del aviso
                            </p>
                            <p class="text-sm font-medium">
                                {{
                                    baja.entrega_aviso_rescision_etiqueta ??
                                    'Pendiente'
                                }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-[var(--mrl-texto-suave)]">
                                Fecha de entrega
                            </p>
                            <p class="text-sm font-medium">
                                {{ formatearFecha(baja.fecha_entrega_aviso) }}
                            </p>
                        </div>
                    </div>
                    <p
                        v-if="baja.motivo_original"
                        class="mt-4 text-xs text-[var(--mrl-texto-suave)]"
                    >
                        Valor original: {{ baja.motivo_original }}
                    </p>
                    <div
                        v-if="baja.cierre"
                        class="mt-4 flex flex-wrap items-center gap-2 rounded-xl bg-menta px-3 py-2 text-sm"
                    >
                        <span
                            >Cierre laboral (finiquito y documentos generados):
                            <strong>{{
                                baja.cierre.estado_etiqueta
                            }}</strong></span
                        >
                        <Button
                            v-if="tienePermiso('cierres.ver')"
                            size="sm"
                            variant="outline"
                            as-child
                        >
                            <Link
                                :href="
                                    cicloColaborador.url(
                                        baja.cierre.colaborador_id,
                                    )
                                "
                                >Ver ciclo laboral</Link
                            >
                        </Button>
                    </div>
                </section>

                <section
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <div
                        class="mb-3 flex flex-wrap items-center justify-between gap-2"
                    >
                        <h3 class="text-base font-semibold">Documentos</h3>
                        <Button
                            v-if="tienePermiso('cierres.ver')"
                            size="sm"
                            variant="outline"
                            as-child
                        >
                            <Link
                                :href="
                                    cierresIndex.url({
                                        query: {
                                            busqueda: baja.folio,
                                            estatus: 'todas',
                                        },
                                    })
                                "
                                ><FolderOpen class="size-4" />Generados y
                                finiquito</Link
                            >
                        </Button>
                    </div>
                    <DocumentosBaja
                        :espacios="baja.documentos"
                        :baja-id="baja.id"
                        :puede-adjuntar="puede('adjuntar')"
                        :mensaje-vacio="
                            baja.origen === 'cierre_previo'
                                ? 'Baja registrada antes del módulo de bajas: no exige documentos para el tablero.'
                                : undefined
                        "
                        :limites="limites"
                    />
                    <p
                        v-if="baja.faltantes.length"
                        class="mt-3 text-xs text-warning"
                    >
                        RH no puede cerrar hasta que estén:
                        {{ baja.faltantes.join(', ') }}.
                    </p>
                </section>

                <div v-if="puede('cancelar')" class="flex justify-end">
                    <Button variant="ghost" @click="abrirMotivo('cancelar')"
                        >La baja no procede…</Button
                    >
                </div>
            </div>

            <section
                class="content-start rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
            >
                <h3
                    class="mb-3 flex items-center gap-2 text-base font-semibold"
                >
                    <History class="size-4" />Bitácora
                </h3>
                <ol class="grid gap-3">
                    <li
                        v-for="b in baja.bitacora"
                        :key="b.id"
                        class="border-l-2 border-[var(--mrl-borde)] pl-3 text-sm"
                    >
                        <p class="font-medium">{{ b.accion_etiqueta }}</p>
                        <p
                            v-if="b.valor_anterior || b.valor_nuevo"
                            class="text-xs"
                        >
                            <span
                                v-if="b.valor_anterior"
                                class="text-[var(--mrl-texto-suave)] line-through"
                                >{{ b.valor_anterior }}</span
                            >
                            <template v-if="b.valor_anterior && b.valor_nuevo">
                                →
                            </template>
                            <span v-if="b.valor_nuevo">{{
                                b.valor_nuevo
                            }}</span>
                        </p>
                        <p v-if="b.motivo" class="text-xs italic">
                            «{{ b.motivo }}»
                        </p>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            {{ b.usuario }} · {{ formatearFechaHora(b.fecha) }}
                        </p>
                    </li>
                </ol>
            </section>
        </div>
    </div>

    <Dialog
        :open="accionMotivo !== null"
        @update:open="(v: boolean) => !v && (accionMotivo = null)"
    >
        <DialogContent class="sm:max-w-lg">
            <DialogHeader v-if="accionMotivo">
                <DialogTitle>{{
                    textosMotivo[accionMotivo].titulo
                }}</DialogTitle>
                <DialogDescription>{{
                    textosMotivo[accionMotivo].ayuda
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="enviarMotivo">
                <Label for="baja-motivo">{{
                    accionMotivo === 'aprobar_juridico'
                        ? 'Comentario (opcional)'
                        : 'Motivo (obligatorio)'
                }}</Label>
                <Textarea
                    id="baja-motivo"
                    v-model="formMotivo.motivo"
                    rows="3"
                />
                <InputError :message="formMotivo.errors.motivo" />
                <InputError
                    :message="
                        (formMotivo.errors as Record<string, string>).estatus
                    "
                />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="accionMotivo = null"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="formMotivo.processing">{{
                        accionMotivo ? textosMotivo[accionMotivo].boton : ''
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <PeopleConfirmDialog
        v-model:open="confirmarCierre"
        titulo="¿Cerrar la baja?"
        descripcion="La persona queda inactiva y su acceso se bloquea de forma definitiva. Nada se borra; RH puede reabrirla con motivo."
        texto-confirmar="Cerrar baja"
        destructivo
        :cargando="procesando"
        @confirm="cerrarBaja"
    />

    <Dialog v-model:open="causalAbierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Cambiar la causal</DialogTitle>
                <DialogDescription
                    >El cambio queda en la bitácora con tu usuario, la fecha, el
                    valor anterior y el nuevo.</DialogDescription
                >
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="cambiarCausal">
                <Label for="baja-nueva-causal">Causal correcta</Label>
                <SelectSimple
                    id="baja-nueva-causal"
                    v-model="formCausal.causal_rotacion_id"
                    :opciones="opcionesCausal"
                    numerico
                />
                <InputError :message="formCausal.errors.causal_rotacion_id" />
                <Label for="baja-motivo-causal">Motivo del cambio</Label>
                <Textarea
                    id="baja-motivo-causal"
                    v-model="formCausal.motivo"
                    rows="3"
                />
                <InputError :message="formCausal.errors.motivo" />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="causalAbierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="formCausal.processing"
                        >Cambiar causal</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="datosAbierto">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Completar o corregir datos</DialogTitle>
                <DialogDescription
                    >Cada cambio queda en la bitácora (valor anterior y
                    nuevo).</DialogDescription
                >
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="guardarDatos">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="rh-fecha">Último día laborado</Label>
                        <DatePicker
                            id="rh-fecha"
                            v-model="formDatos.fecha_baja"
                        />
                        <InputError :message="formDatos.errors.fecha_baja" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="rh-recontratable">¿Recontratable?</Label>
                        <SelectSimple
                            id="rh-recontratable"
                            v-model="formDatos.recontratable"
                            :opciones="recontratable"
                        />
                        <InputError :message="formDatos.errors.recontratable" />
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <Label for="rh-comentarios">Comentarios</Label>
                    <Textarea
                        id="rh-comentarios"
                        v-model="formDatos.comentarios"
                        rows="3"
                        :maxlength="limites.comentario_maximo"
                    />
                    <InputError :message="formDatos.errors.comentarios" />
                </div>
                <div v-if="esRescision" class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <Label for="rh-conocimiento">Se conoció la falta</Label>
                        <DatePicker
                            id="rh-conocimiento"
                            v-model="formDatos.fecha_conocimiento_falta"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="rh-entrega">Entrega del aviso</Label>
                        <SelectSimple
                            id="rh-entrega"
                            v-model="formDatos.entrega_aviso_rescision"
                            :opciones="entregas"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="rh-fecha-aviso">Fecha de entrega</Label>
                        <DatePicker
                            id="rh-fecha-aviso"
                            v-model="formDatos.fecha_entrega_aviso"
                        />
                    </div>
                </div>
                <label class="flex items-start gap-2 text-sm">
                    <Casilla v-model="formDatos.es_rotacion" />
                    <span
                        >Cuenta en la rotación (desmárcalo si es un movimiento
                        interno: cambio de sucursal o razón social, RN-14)</span
                    >
                </label>
                <div class="grid gap-1.5">
                    <Label for="rh-motivo">Motivo del cambio (opcional)</Label>
                    <Textarea
                        id="rh-motivo"
                        v-model="formDatos.motivo_cambio"
                        rows="2"
                    />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="datosAbierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="formDatos.processing"
                        >Guardar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
