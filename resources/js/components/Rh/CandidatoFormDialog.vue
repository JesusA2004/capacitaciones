<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import BandejaCvSelector from '@/components/Rh/BandejaCvSelector.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/rh/candidatos';
import type { CandidatoItem, OpcionesReclutamiento } from '@/types';

const props = defineProps<{
    open: boolean;
    candidato?: CandidatoItem | null;
    opciones: OpcionesReclutamiento;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

function idComo(valor: { id: number } | null | undefined): string {
    return valor?.id ? String(valor.id) : '';
}

/**
 * «¿Cómo llegó?» es UNA sola pregunta: `c:<id>` = campaña (la fuente sale
 * de su canal) o `f:<fuente>` = medio sin campaña (referido, orgánico…).
 */
function origenInicial(): string {
    if (props.candidato?.campana_reclutamiento_id) {
        return `c:${props.candidato.campana_reclutamiento_id}`;
    }

    return props.candidato?.fuente ? `f:${props.candidato.fuente}` : '';
}

const form = useForm({
    empresa_id: idComo(props.candidato?.empresa),
    sucursal_id: props.candidato?.sucursal_id
        ? String(props.candidato.sucursal_id)
        : '',
    puesto_objetivo_id: idComo(props.candidato?.puesto_objetivo),
    vacante_id: idComo(props.candidato?.vacante),
    origen: origenInicial(),
    espontaneo: props.candidato?.espontaneo ?? false,
    nombre: props.candidato?.nombre ?? '',
    apellidos: props.candidato?.apellidos ?? '',
    telefono: props.candidato?.telefono ?? '',
    correo: props.candidato?.correo ?? '',
    observaciones: props.candidato?.observaciones ?? '',
    // Requisitos indispensables (solo al registrar): pregunta_id → sí/no.
    respuestas_filtro: {} as Record<string, boolean>,
    // CV desde la fase inicial (solo al registrar; después se reemplaza
    // desde el expediente del candidato).
    cv: [] as File[],
    // …o el nombre de un CV de la bandeja (descargas de WhatsApp en el NAS).
    cv_bandeja: '',
});

const errores = computed(
    () => form.errors as Record<string, string | undefined>,
);

// El puesto/sucursal/empresa se derivan SIEMPRE de la vacante elegida (la
// vacante ya nació de un puesto y sucursal concretos: baja, headcount
// nuevo, etc.) — ver Candidato::booted(). Los selects manuales de puesto y
// sucursal solo aparecen para un candidato EXPLÍCITAMENTE espontáneo (sin
// vacante todavía): nunca se vuelve a preguntar lo que la vacante ya trae.
const vacanteSeleccionada = computed(() =>
    props.opciones.vacantes?.find((v) => String(v.id) === form.vacante_id),
);

// Campañas posibles: las de la vacante elegida + las generales (sin
// vacante). El backend valida igual que una campaña de otra vacante no
// se pueda atribuir.
const campanasDisponibles = computed(() =>
    (props.opciones.campanas ?? []).filter(
        (c) =>
            c.vacante_id === null ||
            (!form.espontaneo && String(c.vacante_id) === form.vacante_id),
    ),
);

// Medios que no son campaña: Meta/Indeed/… solo existen como campaña.
const fuentesSinCampana = computed(
    () => props.opciones.fuentesSinCampana ?? [],
);

// Puesto al que aplican las preguntas de filtro.
const puestoActual = computed<number | null>(() => {
    if (form.espontaneo) {
        return form.puesto_objetivo_id ? Number(form.puesto_objetivo_id) : null;
    }

    return vacanteSeleccionada.value?.puesto_id ?? null;
});

const preguntasAplicables = computed(() =>
    (props.opciones.preguntasFiltro ?? []).filter(
        (p) =>
            p.puestos.length === 0 ||
            (puestoActual.value !== null &&
                p.puestos.includes(puestoActual.value)),
    ),
);

// Respuestas que no cumplen un requisito eliminatorio: el candidato SÍ se
// registra (cuenta para su campaña) pero queda «No viable».
const requisitosNoCumplidos = computed(() =>
    preguntasAplicables.value.filter((p) => {
        const respuesta = form.respuestas_filtro[String(p.id)];

        return (
            p.eliminatoria &&
            respuesta !== undefined &&
            respuesta !== p.respuesta_requerida
        );
    }),
);

function responder(preguntaId: number, valor: boolean) {
    form.respuestas_filtro = {
        ...form.respuestas_filtro,
        [String(preguntaId)]: valor,
    };
}

watch(
    () => form.vacante_id,
    () => {
        if (vacanteSeleccionada.value?.puesto_id) {
            form.puesto_objetivo_id = String(
                vacanteSeleccionada.value.puesto_id,
            );
        }

        if (
            form.origen.startsWith('c:') &&
            !campanasDisponibles.value.some((c) => `c:${c.id}` === form.origen)
        ) {
            form.origen = '';
        }
    },
);

watch(
    () => form.espontaneo,
    (esEspontaneo) => {
        if (esEspontaneo) {
            form.vacante_id = '';
        } else {
            form.puesto_objetivo_id = '';
            form.sucursal_id = '';
        }
    },
);

function etiquetaVacante(vacante: {
    id: number;
    puesto?: { nombre: string } | null;
    sucursal?: { nombre: string } | null;
}): string {
    const puesto = vacante.puesto?.nombre ?? `Vacante #${vacante.id}`;

    return vacante.sucursal ? `${puesto} — ${vacante.sucursal.nombre}` : puesto;
}

function enviar() {
    const aplicables = new Set(
        preguntasAplicables.value.map((p) => String(p.id)),
    );

    const transformado = form.transform(({ origen, ...datos }) => ({
        ...datos,
        empresa_id: datos.empresa_id || null,
        sucursal_id: datos.sucursal_id || null,
        puesto_objetivo_id: datos.puesto_objetivo_id || null,
        vacante_id: datos.vacante_id || null,
        campana_reclutamiento_id: origen.startsWith('c:')
            ? origen.slice(2)
            : null,
        // Con campaña, la fuente sale del canal de la campaña (backend).
        fuente: origen.startsWith('f:') ? origen.slice(2) : null,
        respuestas_filtro: props.candidato
            ? undefined
            : Object.fromEntries(
                  Object.entries(datos.respuestas_filtro).filter(([id]) =>
                      aplicables.has(id),
                  ),
              ),
        cv: props.candidato ? undefined : (datos.cv[0] ?? null),
        cv_bandeja:
            props.candidato || datos.cv.length
                ? undefined
                : datos.cv_bandeja || null,
    }));

    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.candidato) {
        transformado.put(update.url(props.candidato.id), opciones);
    } else {
        transformado.post(store.url(), { ...opciones, forceFormData: true });
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{
                    candidato ? 'Editar candidato' : 'Nuevo candidato'
                }}</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="nombre"
                            >Nombre
                            <span class="text-destructive">*</span></Label
                        >
                        <Input id="nombre" v-model="form.nombre" autofocus />
                        <InputError :message="form.errors.nombre" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="apellidos">Apellidos (opcional)</Label>
                        <Input id="apellidos" v-model="form.apellidos" />
                        <InputError :message="form.errors.apellidos" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="telefono">Teléfono (opcional)</Label>
                        <Input id="telefono" v-model="form.telefono" />
                        <InputError :message="form.errors.telefono" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="correo">Correo (opcional)</Label>
                        <Input id="correo" v-model="form.correo" type="email" />
                        <InputError :message="form.errors.correo" />
                    </div>
                </div>

                <!-- Vacante: campo principal del alta. Puesto, sucursal y
                     empresa salen SIEMPRE de ella (Candidato::booted()); no
                     se vuelven a pedir aquí. -->
                <div v-if="!form.espontaneo" class="grid gap-2">
                    <Label
                        >Vacante <span class="text-destructive">*</span></Label
                    >
                    <Select v-model="form.vacante_id">
                        <SelectTrigger class="w-full">
                            <SelectValue
                                placeholder="Elige una vacante disponible"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.vacantes ?? []"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ etiquetaVacante(opcion) }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.vacante_id" />
                    <p class="text-xs text-muted-foreground">
                        Solo se listan vacantes con plaza real disponible. Al
                        elegirla, puesto y sucursal se toman automáticamente de
                        ella.
                    </p>
                </div>

                <div
                    v-if="!form.espontaneo && form.vacante_id"
                    class="grid grid-cols-2 gap-4"
                >
                    <div
                        class="rounded-lg border border-border/60 bg-muted/40 px-3 py-2 text-sm"
                    >
                        <span class="text-muted-foreground">Puesto: </span>
                        <span class="font-medium">{{
                            vacanteSeleccionada?.puesto?.nombre ?? '—'
                        }}</span>
                    </div>
                    <div
                        class="rounded-lg border border-border/60 bg-muted/40 px-3 py-2 text-sm"
                    >
                        <span class="text-muted-foreground">Sucursal: </span>
                        <span class="font-medium">{{
                            vacanteSeleccionada?.sucursal?.nombre ?? '—'
                        }}</span>
                    </div>
                </div>

                <div
                    class="flex items-start gap-2 rounded-lg border border-border/60 px-3 py-2"
                >
                    <Checkbox
                        id="espontaneo"
                        class="mt-0.5"
                        :model-value="form.espontaneo"
                        @update:model-value="(v) => (form.espontaneo = !!v)"
                    />
                    <label for="espontaneo" class="text-sm leading-snug">
                        <span class="font-medium"
                            >Candidato espontáneo / sin vacante actual</span
                        >
                        <p class="text-xs text-muted-foreground">
                            Úsalo solo si todavía no hay una vacante abierta
                            para él. No podrá avanzar a contratación hasta
                            vincularlo a una vacante real disponible.
                        </p>
                    </label>
                </div>

                <!-- Candidato espontáneo: puesto de interés y sucursal son
                     preferencia, no una vacante — se piden a mano porque
                     todavía no existe una apertura concreta. -->
                <div v-if="form.espontaneo" class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Puesto de interés (opcional)</Label>
                        <Select v-model="form.puesto_objetivo_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Sin puesto" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in opciones.puestos"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.nombre }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label>Sucursal preferida (opcional)</Label>
                        <Select v-model="form.sucursal_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Sin sucursal" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in opciones.sucursales"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.nombre }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <!-- «¿Cómo llegó?» — una sola pregunta: la campaña (cuenta
                     en su embudo y costo; la fuente sale de su canal) o, si
                     no fue por campaña, el medio. Meta/Indeed/Computrabajo/
                     LinkedIn solo existen como campaña: nunca se repite. -->
                <div class="grid gap-2">
                    <Label
                        >¿Cómo llegó?
                        <span class="text-destructive">*</span></Label
                    >
                    <Select v-model="form.origen">
                        <SelectTrigger class="w-full">
                            <SelectValue
                                placeholder="Elige la campaña o el medio"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup v-if="campanasDisponibles.length">
                                <SelectLabel>Por una campaña</SelectLabel>
                                <SelectItem
                                    v-for="campana in campanasDisponibles"
                                    :key="campana.id"
                                    :value="`c:${campana.id}`"
                                    >{{ campana.etiqueta }}</SelectItem
                                >
                            </SelectGroup>
                            <SelectGroup>
                                <SelectLabel>Sin campaña</SelectLabel>
                                <SelectItem
                                    v-for="fuente in fuentesSinCampana"
                                    :key="fuente.value"
                                    :value="`f:${fuente.value}`"
                                    >{{ fuente.etiqueta }}</SelectItem
                                >
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <InputError
                        :message="
                            errores.campana_reclutamiento_id ?? errores.fuente
                        "
                    />
                    <p
                        v-if="!campanasDisponibles.length"
                        class="text-xs text-muted-foreground"
                    >
                        Si llegó por una pauta (Meta, Indeed…), registra primero
                        la campaña en Campañas.
                    </p>
                </div>

                <!-- Requisitos indispensables del puesto (p. ej. «¿Sabe
                     manejar moto?» para Gestor). Solo al registrar. -->
                <section
                    v-if="!candidato && preguntasAplicables.length"
                    class="grid gap-3 rounded-lg border border-border/60 p-3"
                >
                    <div>
                        <p class="text-sm font-semibold">
                            Requisitos del puesto
                            <span class="text-destructive">*</span>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Pregúntaselo antes de seguir: si no cumple un
                            requisito indispensable, se registra igual (cuenta
                            para su campaña) pero queda como «No viable».
                        </p>
                    </div>
                    <div
                        v-for="pregunta in preguntasAplicables"
                        :key="pregunta.id"
                        class="grid gap-1.5"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <span class="text-sm">{{ pregunta.pregunta }}</span>
                            <div class="flex gap-1.5">
                                <Button
                                    type="button"
                                    size="sm"
                                    :variant="
                                        form.respuestas_filtro[
                                            String(pregunta.id)
                                        ] === true
                                            ? 'default'
                                            : 'outline'
                                    "
                                    @click="responder(pregunta.id, true)"
                                    >Sí</Button
                                >
                                <Button
                                    type="button"
                                    size="sm"
                                    :variant="
                                        form.respuestas_filtro[
                                            String(pregunta.id)
                                        ] === false
                                            ? 'default'
                                            : 'outline'
                                    "
                                    @click="responder(pregunta.id, false)"
                                    >No</Button
                                >
                            </div>
                        </div>
                        <InputError
                            :message="
                                errores[`respuestas_filtro.${pregunta.id}`]
                            "
                        />
                    </div>
                    <div
                        v-if="requisitosNoCumplidos.length"
                        class="flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2 text-xs"
                    >
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        <span
                            >No cumple:
                            {{
                                requisitosNoCumplidos
                                    .map((p) => p.pregunta)
                                    .join(' · ')
                            }}. Al guardar quedará registrado como «No viable»
                            con el motivo «No cumple un requisito
                            indispensable».</span
                        >
                    </div>
                </section>

                <div class="grid gap-2">
                    <Label for="observaciones">Observaciones (opcional)</Label>
                    <Textarea
                        id="observaciones"
                        v-model="form.observaciones"
                        rows="3"
                    />
                    <InputError :message="form.errors.observaciones" />
                </div>

                <div v-if="!candidato" class="grid gap-2">
                    <Label>Currículum (opcional)</Label>
                    <!-- Primero la bandeja: el CV que se descargó de
                         WhatsApp ya está en el NAS, se elige por nombre. -->
                    <BandejaCvSelector
                        v-if="!form.cv.length"
                        v-model="form.cv_bandeja"
                    />
                    <InputError :message="errores.cv_bandeja" />
                    <PeopleFileDropzone
                        v-if="!form.cv_bandeja"
                        v-model="form.cv"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
                        :max-size-mb="10"
                        label="O arrastra el CV desde tu computadora"
                    />
                    <InputError :message="form.errors.cv" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
