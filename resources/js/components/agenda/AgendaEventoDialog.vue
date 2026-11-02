<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import {
    Ban,
    Briefcase,
    CalendarClock,
    CalendarSync,
    CheckCircle2,
    ExternalLink,
    FileText,
    House,
    MapPin,
    MessagesSquare,
    Phone,
    StickyNote,
    UserX,
    XCircle,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import TimePicker from '@/components/Common/TimePicker.vue';
import BotonCorregir from '@/components/correcciones/BotonCorregir.vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { Tono } from '@/lib/tonos';
import { cn } from '@/lib/utils';
import {
    cancelar as cancelarRuta,
    cv as cvRuta,
    noAsistio as noAsistioRuta,
    reprogramar as reprogramarRuta,
    resultadoEntrevista as resultadoEntrevistaRuta,
    resultadoSocioeconomico as resultadoSocioeconomicoRuta,
} from '@/routes/agenda';
import type { AgendaEvento, EstadoAgenda } from '@/types/agenda';

const props = defineProps<{
    evento: AgendaEvento | null;
    motivos: { value: string; etiqueta: string }[];
}>();

const emit = defineEmits<{ cerrar: [] }>();

type Panel = 'resultado' | 'reprogramar' | 'cancelar' | null;
const panel = ref<Panel>(null);

const resultado = useForm<{
    resultado: string;
    motivo: string;
    comentario: string;
    direccion: string;
    evidencias: File[];
}>({
    resultado: '',
    motivo: '',
    comentario: '',
    direccion: '',
    evidencias: [],
});
const reprogramacion = useForm({ fecha: '', hora: '', motivo: '' });
const cancelacion = useForm({ motivo: '' });
const inasistencia = useForm({ comentario: '' });
const errorArchivos = ref('');

watch(
    () => props.evento?.id,
    () => {
        panel.value = null;
        errorArchivos.value = '';
        resultado.reset();
        resultado.clearErrors();
        reprogramacion.reset();
        reprogramacion.clearErrors();
        cancelacion.reset();
        cancelacion.clearErrors();
    },
);

const esEntrevista = computed(() => props.evento?.tipo === 'entrevista');
const opciones = computed<
    { value: string; etiqueta: string; icono: Component; positivo: boolean }[]
>(() =>
    esEntrevista.value
        ? [
              {
                  value: 'viable',
                  etiqueta: 'Viable',
                  icono: CheckCircle2,
                  positivo: true,
              },
              {
                  value: 'no_viable',
                  etiqueta: 'No viable',
                  icono: XCircle,
                  positivo: false,
              },
          ]
        : [
              {
                  value: 'favorable',
                  etiqueta: 'Favorable',
                  icono: CheckCircle2,
                  positivo: true,
              },
              {
                  value: 'no_favorable',
                  etiqueta: 'No favorable',
                  icono: XCircle,
                  positivo: false,
              },
          ],
);
const puedeCapturar = computed(
    () =>
        props.evento !== null &&
        props.evento.puede.capturar &&
        props.evento.puede.registrar_resultado,
);

const TONO_ESTADO: Record<EstadoAgenda, Tono> = {
    programado: 'info',
    realizado: 'exito',
    no_asistio: 'peligro',
    reprogramado: 'alerta',
    cancelado: 'neutro',
};

const tonoResultado = computed<Tono>(() =>
    props.evento?.resultado === 'viable' ||
    props.evento?.resultado === 'favorable'
        ? 'exito'
        : 'peligro',
);

const cuando = computed(() => {
    if (props.evento === null) {
        return '';
    }

    const fecha = new Date(`${props.evento.fecha}T${props.evento.hora}:00`);
    const dia = new Intl.DateTimeFormat('es-MX', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(fecha);
    const hora = new Intl.DateTimeFormat('es-MX', {
        hour: 'numeric',
        minute: '2-digit',
    }).format(fecha);

    return `${dia.charAt(0).toUpperCase()}${dia.slice(1)} · ${hora}`;
});

const datos = computed(() => {
    const e = props.evento;

    if (e === null) {
        return [];
    }

    return [
        { icono: Briefcase, etiqueta: 'Puesto', valor: e.candidato.puesto },
        { icono: MapPin, etiqueta: 'Sucursal', valor: e.sucursal.nombre },
        { icono: Phone, etiqueta: 'Teléfono', valor: e.candidato.telefono },
        { icono: House, etiqueta: 'Domicilio', valor: e.domicilio },
        {
            icono: CalendarClock,
            etiqueta: 'Agendó',
            valor: [e.agendado_por, e.origen_etiqueta]
                .filter(Boolean)
                .join(' · '),
        },
    ].filter((d) => !!d.valor);
});

const hoy = (() => {
    const d = new Date();

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})();

const opcionesVisita = {
    preserveScroll: true,
    onSuccess: () => emit('cerrar'),
};

function abrirPanel(nuevo: Panel): void {
    panel.value = panel.value === nuevo ? null : nuevo;
}

function guardarResultado(): void {
    if (props.evento === null) {
        return;
    }

    if (esEntrevista.value) {
        resultado.post(
            resultadoEntrevistaRuta.url(props.evento.id),
            opcionesVisita,
        );

        return;
    }

    resultado.post(resultadoSocioeconomicoRuta.url(props.evento.id), {
        ...opcionesVisita,
        forceFormData: true,
    });
}

function marcarNoAsistio(): void {
    if (props.evento !== null) {
        inasistencia.post(noAsistioRuta.url(props.evento.id), opcionesVisita);
    }
}

function guardarReprogramacion(): void {
    if (props.evento !== null) {
        // Sin cerrar: la respuesta abre la cita nueva con su historial.
        reprogramacion.post(reprogramarRuta.url(props.evento.id), {
            preserveScroll: true,
        });
    }
}

function guardarCancelacion(): void {
    if (props.evento !== null) {
        cancelacion.post(cancelarRuta.url(props.evento.id), opcionesVisita);
    }
}

const erroresResultado = computed(
    () => resultado.errors as Record<string, string>,
);
</script>

<template>
    <Dialog
        :open="evento !== null"
        @update:open="(abierto) => !abierto && emit('cerrar')"
    >
        <DialogContent
            v-if="evento"
            class="max-h-[92vh] gap-0 overflow-y-auto p-0 sm:max-w-2xl"
        >
            <DialogHeader class="border-b border-border bg-crema/60 px-6 py-5">
                <div class="flex items-start gap-3">
                    <ColaboradorAvatar
                        :nombre="evento.candidato.nombre"
                        tamano="lg"
                    />
                    <div class="min-w-0 flex-1 text-left">
                        <p
                            class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            <component
                                :is="esEntrevista ? MessagesSquare : House"
                                class="size-3.5"
                            />
                            {{ evento.tipo_etiqueta }}
                        </p>
                        <DialogTitle class="truncate text-lg">
                            {{ evento.candidato.nombre }}
                        </DialogTitle>
                        <DialogDescription class="text-sm text-foreground/80">
                            {{ cuando }}
                        </DialogDescription>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <ChipEstado
                                :etiqueta="evento.estado_etiqueta"
                                :tono="TONO_ESTADO[evento.estado]"
                            />
                            <ChipEstado
                                v-if="evento.resultado_etiqueta"
                                :etiqueta="evento.resultado_etiqueta"
                                :tono="tonoResultado"
                            />
                            <ChipEstado
                                :etiqueta="`Proceso: ${evento.candidato.estado_etiqueta}`"
                                tono="neutro"
                            />
                        </div>
                    </div>
                </div>
            </DialogHeader>

            <div class="grid gap-5 px-6 py-5">
                <dl class="grid gap-2 sm:grid-cols-2">
                    <div
                        v-for="dato in datos"
                        :key="dato.etiqueta"
                        class="flex items-start gap-2.5 rounded-xl bg-muted/40 p-2.5"
                    >
                        <component
                            :is="dato.icono"
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                {{ dato.etiqueta }}
                            </dt>
                            <dd class="text-sm font-medium break-words">
                                {{ dato.valor }}
                            </dd>
                        </div>
                    </div>
                </dl>

                <div
                    v-if="
                        evento.notas ||
                        evento.motivo_no_viable_etiqueta ||
                        evento.comentario_resultado ||
                        evento.motivo_cancelacion
                    "
                    class="grid gap-2 rounded-2xl border border-border p-3 text-sm"
                >
                    <p v-if="evento.notas" class="flex gap-2">
                        <StickyNote
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <span
                            ><span class="font-medium">Notas:</span>
                            {{ evento.notas }}</span
                        >
                    </p>
                    <p v-if="evento.motivo_no_viable_etiqueta">
                        <span class="font-medium">Motivo:</span>
                        {{ evento.motivo_no_viable_etiqueta }}
                    </p>
                    <p v-if="evento.comentario_resultado">
                        <span class="font-medium">Comentario:</span>
                        {{ evento.comentario_resultado }}
                    </p>
                    <p v-if="evento.motivo_cancelacion">
                        <span class="font-medium">Cancelación:</span>
                        {{ evento.motivo_cancelacion }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="evento.candidato.tiene_cv"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <a
                            :href="cvRuta.url(evento.id)"
                            target="_blank"
                            rel="noopener"
                        >
                            <FileText class="size-4" /> Ver CV
                        </a>
                    </Button>
                    <Button
                        v-if="evento.candidato.ficha_url"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="evento.candidato.ficha_url">
                            <ExternalLink class="size-4" /> Ficha del candidato
                        </Link>
                    </Button>
                    <BotonCorregir
                        v-if="evento.puede.gestionar"
                        dominio="agenda"
                        :sujeto-id="evento.id"
                        variante="ghost"
                    />
                </div>

                <div
                    v-if="
                        puedeCapturar ||
                        (evento.puede.gestionar &&
                            (evento.puede.reprogramar || evento.puede.cancelar))
                    "
                    class="grid gap-2 sm:grid-cols-2"
                >
                    <Button
                        v-if="puedeCapturar"
                        :variant="panel === 'resultado' ? 'default' : 'outline'"
                        class="h-11 justify-start"
                        @click="abrirPanel('resultado')"
                    >
                        <CheckCircle2 class="size-4" /> Registrar resultado
                    </Button>
                    <Button
                        v-if="puedeCapturar"
                        variant="outline"
                        class="h-11 justify-start"
                        :disabled="inasistencia.processing"
                        @click="marcarNoAsistio"
                    >
                        <Spinner
                            v-if="inasistencia.processing"
                            class="size-4"
                        />
                        <UserX v-else class="size-4" /> No asistió
                    </Button>
                    <Button
                        v-if="
                            evento.puede.gestionar && evento.puede.reprogramar
                        "
                        :variant="
                            panel === 'reprogramar' ? 'default' : 'outline'
                        "
                        class="h-11 justify-start"
                        @click="abrirPanel('reprogramar')"
                    >
                        <CalendarSync class="size-4" /> Reprogramar
                    </Button>
                    <Button
                        v-if="evento.puede.gestionar && evento.puede.cancelar"
                        :variant="
                            panel === 'cancelar' ? 'destructive' : 'outline'
                        "
                        class="h-11 justify-start"
                        @click="abrirPanel('cancelar')"
                    >
                        <Ban class="size-4" /> Cancelar cita
                    </Button>
                </div>
                <InputError :message="inasistencia.errors.comentario" />

                <form
                    v-if="panel === 'resultado'"
                    class="grid gap-3 rounded-2xl border border-border bg-crema/30 p-4"
                    @submit.prevent="guardarResultado"
                >
                    <p class="text-sm font-semibold">
                        ¿Cómo resultó
                        {{ esEntrevista ? 'la entrevista' : 'el estudio' }}?
                    </p>
                    <div
                        class="grid grid-cols-2 gap-2"
                        role="radiogroup"
                        aria-label="Resultado"
                    >
                        <button
                            v-for="opcion in opciones"
                            :key="opcion.value"
                            type="button"
                            role="radio"
                            :aria-checked="resultado.resultado === opcion.value"
                            :class="
                                cn(
                                    'flex tarjeta-interactiva items-center justify-center gap-2 rounded-xl border bg-card px-3 py-3 text-sm font-semibold',
                                    resultado.resultado === opcion.value
                                        ? opcion.positivo
                                            ? 'border-success bg-success-soft text-success'
                                            : 'border-destructive bg-danger-soft text-destructive'
                                        : 'border-border',
                                )
                            "
                            @click="resultado.resultado = opcion.value"
                        >
                            <component :is="opcion.icono" class="size-4" />
                            {{ opcion.etiqueta }}
                        </button>
                    </div>
                    <InputError :message="resultado.errors.resultado" />

                    <div
                        v-if="
                            esEntrevista && resultado.resultado === 'no_viable'
                        "
                        class="grid gap-1.5"
                    >
                        <p class="text-xs font-medium text-muted-foreground">
                            Motivo
                        </p>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="motivo in motivos"
                                :key="motivo.value"
                                type="button"
                                :aria-pressed="
                                    resultado.motivo === motivo.value
                                "
                                :class="
                                    cn(
                                        'rounded-full border px-3 py-1 text-xs transition-colors',
                                        resultado.motivo === motivo.value
                                            ? 'border-destructive/40 bg-danger-soft font-semibold text-destructive'
                                            : 'border-border bg-card hover:bg-muted',
                                    )
                                "
                                @click="resultado.motivo = motivo.value"
                            >
                                {{ motivo.etiqueta }}
                            </button>
                        </div>
                        <InputError :message="resultado.errors.motivo" />
                    </div>

                    <template v-if="!esEntrevista">
                        <div class="relative">
                            <MapPin
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                v-model="resultado.direccion"
                                class="bg-card pl-9"
                                :placeholder="
                                    evento.domicilio ?? 'Domicilio visitado'
                                "
                                aria-label="Domicilio visitado"
                            />
                        </div>
                        <InputError :message="resultado.errors.direccion" />
                        <PeopleFileDropzone
                            v-model="resultado.evidencias"
                            multiple
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.mp4,.mov,.m4v,.3gp,.webm"
                            :max-size-mb="250"
                            label="Arrastra el formato y las fotografías"
                            hint="PDF, Word, Excel, fotos o video · documentos y fotos hasta 20 MB"
                            @error="(m) => (errorArchivos = m)"
                        />
                        <InputError
                            :message="
                                errorArchivos ||
                                resultado.errors.evidencias ||
                                erroresResultado['evidencias.0']
                            "
                        />
                    </template>

                    <Textarea
                        v-model="resultado.comentario"
                        rows="2"
                        class="bg-card"
                        :placeholder="
                            resultado.resultado === 'no_viable' ||
                            resultado.resultado === 'no_favorable'
                                ? 'Comentario breve (obligatorio)'
                                : 'Comentario breve (opcional)'
                        "
                        aria-label="Comentario"
                    />
                    <InputError :message="resultado.errors.comentario" />
                    <InputError :message="erroresResultado.estado" />
                    <Button
                        type="submit"
                        class="justify-self-end"
                        :disabled="resultado.processing || !resultado.resultado"
                    >
                        <Spinner v-if="resultado.processing" class="size-4" />
                        Guardar resultado
                    </Button>
                </form>

                <form
                    v-if="panel === 'reprogramar'"
                    class="grid gap-3 rounded-2xl border border-border bg-crema/30 p-4"
                    @submit.prevent="guardarReprogramacion"
                >
                    <p class="text-sm font-semibold">Nueva fecha y hora</p>
                    <div class="grid gap-2 sm:grid-cols-[1fr_12rem]">
                        <DatePicker
                            v-model="reprogramacion.fecha"
                            :min-value="hoy"
                            placeholder="Elige el día"
                        />
                        <TimePicker
                            v-model="reprogramacion.hora"
                            :desde="7"
                            :hasta="20"
                        />
                    </div>
                    <InputError :message="reprogramacion.errors.fecha" />
                    <InputError :message="reprogramacion.errors.hora" />
                    <InputError
                        :message="
                            (reprogramacion.errors as Record<string, string>)
                                .estado
                        "
                    />
                    <Input
                        v-model="reprogramacion.motivo"
                        class="bg-card"
                        placeholder="Motivo (opcional)"
                        aria-label="Motivo"
                    />
                    <Button
                        type="submit"
                        class="justify-self-end"
                        :disabled="
                            reprogramacion.processing ||
                            !reprogramacion.fecha ||
                            !reprogramacion.hora
                        "
                    >
                        <Spinner
                            v-if="reprogramacion.processing"
                            class="size-4"
                        />
                        Guardar y avisar
                    </Button>
                </form>

                <form
                    v-if="panel === 'cancelar'"
                    class="grid gap-3 rounded-2xl border border-destructive/30 bg-danger-soft/40 p-4"
                    @submit.prevent="guardarCancelacion"
                >
                    <p class="text-sm font-semibold">
                        ¿Por qué se cancela la cita?
                    </p>
                    <Input
                        v-model="cancelacion.motivo"
                        class="bg-card"
                        placeholder="Motivo de la cancelación"
                        aria-label="Motivo de la cancelación"
                    />
                    <InputError :message="cancelacion.errors.motivo" />
                    <Button
                        type="submit"
                        variant="destructive"
                        class="justify-self-end"
                        :disabled="
                            cancelacion.processing || !cancelacion.motivo.trim()
                        "
                    >
                        <Spinner v-if="cancelacion.processing" class="size-4" />
                        Cancelar cita y avisar
                    </Button>
                </form>

                <section v-if="evento.historial?.length" class="grid gap-2">
                    <h3 class="text-sm font-semibold">Historial</h3>
                    <ol class="relative grid gap-3 border-l border-border pl-4">
                        <li
                            v-for="(linea, i) in evento.historial"
                            :key="i"
                            class="relative text-xs"
                        >
                            <span
                                class="absolute top-1 -left-[1.3rem] size-2.5 rounded-full border-2 border-card bg-primary"
                            />
                            <p class="font-medium text-foreground">
                                {{ linea.estado_nuevo ?? linea.accion }}
                                <span class="font-normal text-muted-foreground"
                                    >· {{ linea.usuario ?? 'Sistema' }}</span
                                >
                            </p>
                            <p class="text-muted-foreground">
                                {{ linea.fecha }}
                                <template v-if="linea.detalle">
                                    — {{ linea.detalle }}</template
                                >
                            </p>
                        </li>
                    </ol>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
