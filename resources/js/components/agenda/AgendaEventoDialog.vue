<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import {
    cancelar as cancelarRuta,
    cv as cvRuta,
    noAsistio as noAsistioRuta,
    reprogramar as reprogramarRuta,
    resultadoEntrevista as resultadoEntrevistaRuta,
    resultadoSocioeconomico as resultadoSocioeconomicoRuta,
} from '@/routes/agenda';
import { show as candidatoRuta } from '@/routes/rh/candidatos';
import type { AgendaEvento } from '@/types/agenda';

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

watch(
    () => props.evento?.id,
    () => {
        panel.value = null;
        resultado.reset();
        resultado.clearErrors();
        reprogramacion.reset();
        reprogramacion.clearErrors();
        cancelacion.reset();
        cancelacion.clearErrors();
    },
);

const esEntrevista = computed(() => props.evento?.tipo === 'entrevista');
const opciones = computed(() =>
    esEntrevista.value
        ? [
              { value: 'viable', etiqueta: 'Viable' },
              { value: 'no_viable', etiqueta: 'No viable' },
          ]
        : [
              { value: 'favorable', etiqueta: 'Favorable' },
              { value: 'no_favorable', etiqueta: 'No favorable' },
          ],
);
const puedeCapturar = computed(
    () =>
        props.evento !== null &&
        props.evento.puede.capturar &&
        props.evento.puede.registrar_resultado,
);

const opcionesVisita = {
    preserveScroll: true,
    onSuccess: () => emit('cerrar'),
};

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

function elegirArchivos(evento: Event): void {
    const entrada = evento.target as HTMLInputElement;
    resultado.evidencias = Array.from(entrada.files ?? []);
}
</script>

<template>
    <Dialog
        :open="evento !== null"
        @update:open="(abierto) => !abierto && emit('cerrar')"
    >
        <DialogContent
            v-if="evento"
            class="max-h-[90vh] overflow-y-auto sm:max-w-xl"
        >
            <DialogHeader>
                <DialogTitle>
                    {{ evento.tipo_etiqueta }} · {{ evento.candidato.nombre }}
                </DialogTitle>
                <DialogDescription>
                    {{ evento.sucursal.nombre }} · {{ evento.fecha }}
                    {{ evento.hora }} · {{ evento.origen_etiqueta }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-wrap items-center gap-2 text-xs">
                <ChipEstado :etiqueta="evento.estado_etiqueta" tono="info" />
                <ChipEstado
                    v-if="evento.resultado_etiqueta"
                    :etiqueta="evento.resultado_etiqueta"
                    tono="exito"
                />
                <a
                    :href="candidatoRuta.url(evento.candidato.id)"
                    class="text-primary underline"
                >
                    {{ evento.candidato.estado_etiqueta }}
                </a>
            </div>

            <dl class="grid gap-1 text-sm">
                <div v-if="evento.candidato.puesto">
                    <dt class="inline font-medium">Puesto:</dt>
                    <dd class="inline">{{ evento.candidato.puesto }}</dd>
                </div>
                <div v-if="evento.candidato.telefono">
                    <dt class="inline font-medium">Teléfono:</dt>
                    <dd class="inline">{{ evento.candidato.telefono }}</dd>
                </div>
                <div v-if="evento.domicilio">
                    <dt class="inline font-medium">Domicilio:</dt>
                    <dd class="inline">{{ evento.domicilio }}</dd>
                </div>
                <div v-if="evento.notas">
                    <dt class="inline font-medium">Notas:</dt>
                    <dd class="inline">{{ evento.notas }}</dd>
                </div>
                <div v-if="evento.motivo_no_viable_etiqueta">
                    <dt class="inline font-medium">Motivo:</dt>
                    <dd class="inline">
                        {{ evento.motivo_no_viable_etiqueta }}
                    </dd>
                </div>
                <div v-if="evento.comentario_resultado">
                    <dt class="inline font-medium">Comentario:</dt>
                    <dd class="inline">{{ evento.comentario_resultado }}</dd>
                </div>
                <div v-if="evento.motivo_cancelacion">
                    <dt class="inline font-medium">Cancelación:</dt>
                    <dd class="inline">{{ evento.motivo_cancelacion }}</dd>
                </div>
                <a
                    v-if="evento.candidato.tiene_cv"
                    :href="cvRuta.url(evento.id)"
                    target="_blank"
                    class="text-primary underline"
                >
                    Ver CV
                </a>
            </dl>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="puedeCapturar"
                    size="sm"
                    @click="panel = 'resultado'"
                >
                    Registrar resultado
                </Button>
                <Button
                    v-if="puedeCapturar"
                    size="sm"
                    variant="outline"
                    :disabled="inasistencia.processing"
                    @click="marcarNoAsistio"
                >
                    No asistió
                </Button>
                <Button
                    v-if="evento.puede.gestionar && evento.puede.reprogramar"
                    size="sm"
                    variant="outline"
                    @click="panel = 'reprogramar'"
                >
                    Reprogramar
                </Button>
                <Button
                    v-if="evento.puede.gestionar && evento.puede.cancelar"
                    size="sm"
                    variant="outline"
                    @click="panel = 'cancelar'"
                >
                    Cancelar cita
                </Button>
            </div>
            <InputError :message="inasistencia.errors.comentario" />

            <form
                v-if="panel === 'resultado'"
                class="grid gap-3 rounded-lg border border-border p-3"
                @submit.prevent="guardarResultado"
            >
                <div class="grid grid-cols-2 gap-2">
                    <button
                        v-for="opcion in opciones"
                        :key="opcion.value"
                        type="button"
                        :aria-pressed="resultado.resultado === opcion.value"
                        :class="
                            cn(
                                'rounded-md border border-border px-3 py-2 text-sm font-medium',
                                resultado.resultado === opcion.value &&
                                    'border-primary bg-primary text-primary-foreground',
                            )
                        "
                        @click="resultado.resultado = opcion.value"
                    >
                        {{ opcion.etiqueta }}
                    </button>
                </div>
                <InputError :message="resultado.errors.resultado" />

                <div
                    v-if="esEntrevista && resultado.resultado === 'no_viable'"
                    class="flex flex-wrap gap-1"
                >
                    <button
                        v-for="motivo in motivos"
                        :key="motivo.value"
                        type="button"
                        :aria-pressed="resultado.motivo === motivo.value"
                        :class="
                            cn(
                                'rounded-full bg-muted px-3 py-1 text-xs',
                                resultado.motivo === motivo.value &&
                                    'bg-danger-soft font-semibold text-destructive',
                            )
                        "
                        @click="resultado.motivo = motivo.value"
                    >
                        {{ motivo.etiqueta }}
                    </button>
                </div>
                <InputError :message="resultado.errors.motivo" />

                <template v-if="!esEntrevista">
                    <Input
                        v-model="resultado.direccion"
                        :placeholder="evento.domicilio ?? 'Domicilio visitado'"
                        aria-label="Domicilio visitado"
                    />
                    <InputError :message="resultado.errors.direccion" />
                    <label class="grid gap-1 text-sm">
                        Formato y fotografías
                        <input
                            type="file"
                            multiple
                            class="text-sm"
                            @change="elegirArchivos"
                        />
                    </label>
                    <InputError :message="resultado.errors.evidencias" />
                </template>

                <Textarea
                    v-model="resultado.comentario"
                    rows="2"
                    placeholder="Comentario breve"
                    aria-label="Comentario"
                />
                <InputError :message="resultado.errors.comentario" />
                <InputError
                    :message="
                        (resultado.errors as Record<string, string>).estado
                    "
                />
                <Button
                    type="submit"
                    :disabled="resultado.processing || !resultado.resultado"
                >
                    Guardar resultado
                </Button>
            </form>

            <form
                v-if="panel === 'reprogramar'"
                class="grid gap-3 rounded-lg border border-border p-3"
                @submit.prevent="guardarReprogramacion"
            >
                <div class="grid grid-cols-2 gap-2">
                    <Input
                        v-model="reprogramacion.fecha"
                        type="date"
                        aria-label="Nueva fecha"
                    />
                    <Input
                        v-model="reprogramacion.hora"
                        type="time"
                        aria-label="Nueva hora"
                    />
                </div>
                <InputError :message="reprogramacion.errors.fecha" />
                <InputError :message="reprogramacion.errors.hora" />
                <InputError
                    :message="
                        (reprogramacion.errors as Record<string, string>).estado
                    "
                />
                <Input
                    v-model="reprogramacion.motivo"
                    placeholder="Motivo (opcional)"
                    aria-label="Motivo"
                />
                <Button type="submit" :disabled="reprogramacion.processing">
                    Guardar y avisar
                </Button>
            </form>

            <form
                v-if="panel === 'cancelar'"
                class="grid gap-3 rounded-lg border border-border p-3"
                @submit.prevent="guardarCancelacion"
            >
                <Input
                    v-model="cancelacion.motivo"
                    placeholder="Motivo de la cancelación"
                    aria-label="Motivo de la cancelación"
                />
                <InputError :message="cancelacion.errors.motivo" />
                <Button
                    type="submit"
                    variant="destructive"
                    :disabled="cancelacion.processing"
                >
                    Cancelar cita y avisar
                </Button>
            </form>

            <section v-if="evento.historial?.length" class="text-xs">
                <h3 class="mb-1 font-semibold">Historial</h3>
                <ul class="grid gap-1 text-muted-foreground">
                    <li v-for="(linea, i) in evento.historial" :key="i">
                        {{ linea.fecha }} · {{ linea.usuario ?? 'Sistema' }} ·
                        {{ linea.estado_nuevo ?? linea.accion }}
                        <span v-if="linea.detalle">— {{ linea.detalle }}</span>
                    </li>
                </ul>
            </section>
        </DialogContent>
    </Dialog>
</template>
