<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleDashed, Hourglass, PencilLine, Undo2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { show as verSolicitud } from '@/routes/solicitudes';
import type { DatoPersonalCaptura } from '@/types';

/**
 * Formulario mixto de datos personales (encargo §21–25):
 * - Campo vacío → «Completar información»: se captura directo.
 * - Campo con valor → «Solicitar cambio de información»: se muestra el
 *   valor actual y el propuesto, solo si la persona decide cambiarlo.
 * - Dato que ya envió y RH aún revisa → «En revisión por RH»: se muestra lo
 *   que mandó y NO se vuelve a pedir (`separarEnRevision`).
 * Cada dato dice si es obligatorio u opcional. Nada se aplica sin la
 * revisión de Recursos Humanos.
 */
const datos = defineModel<Record<string, string>>({ required: true });

const props = defineProps<{
    campos: DatoPersonalCaptura[];
    errores: Partial<Record<string, string>>;
    /**
     * Al crear una solicitud nueva: lo que ya está en revisión se muestra
     * aparte y no se captura. (Al RESPONDER una corrección no: ahí se editan
     * justo los datos de esa solicitud.)
     */
    separarEnRevision?: boolean;
}>();

const revisando = (c: DatoPersonalCaptura): boolean =>
    props.separarEnRevision === true && !!c.en_revision;

const enRevision = computed(() => props.campos.filter(revisando));
const faltantes = computed(() =>
    props.campos.filter((c) => c.actual === null && !revisando(c)),
);
const conValor = computed(() =>
    props.campos.filter((c) => c.actual !== null && !revisando(c)),
);
const obligatoriosFaltantes = computed(
    () => faltantes.value.filter((c) => c.obligatorio).length,
);
const solicitudesEnRevision = computed(() => [
    ...new Set(
        enRevision.value
            .map((c) => c.solicitud_id)
            .filter((id): id is number => typeof id === 'number'),
    ),
]);

/** Campos con valor que la persona decidió cambiar. */
const cambiando = ref<Set<string>>(
    new Set(
        Object.keys(datos.value).filter(
            (k) => props.campos.find((c) => c.campo === k)?.actual !== null,
        ),
    ),
);

function cambiar(campo: string) {
    cambiando.value = new Set([...cambiando.value, campo]);
}

function deshacer(campo: string) {
    const otro = new Set(cambiando.value);
    otro.delete(campo);
    cambiando.value = otro;
    const copia = { ...datos.value };
    delete copia[campo];
    datos.value = copia;
}

function valor(campo: string): string {
    return datos.value[campo] ?? '';
}

function poner(campo: string, v: string | number) {
    datos.value = { ...datos.value, [campo]: String(v) };
}

function tipoInput(tipo: string): string {
    return (
        (
            { tel: 'tel', email: 'email', number: 'text' } as Record<
                string,
                string
            >
        )[tipo] ?? 'text'
    );
}

function error(campo: string): string | undefined {
    return props.errores[`datos.${campo}`];
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <section v-if="faltantes.length" class="flex flex-col gap-3">
            <div>
                <h3 class="flex items-center gap-1.5 text-sm font-semibold">
                    <CircleDashed class="size-4 text-[var(--mrl-petroleo)]" />
                    Completar información
                </h3>
                <p class="text-xs text-muted-foreground">
                    Estos datos no están en tu expediente. Captura los que
                    tengas (no hace falta llenar todo de una vez); Recursos
                    Humanos los revisa antes de guardarlos.
                    <template v-if="obligatoriosFaltantes > 0">
                        <span class="font-semibold text-destructive">*</span>
                        Obligatorio para tus documentos ({{
                            obligatoriosFaltantes
                        }}
                        por completar).
                    </template>
                </p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div
                    v-for="c in faltantes"
                    :key="c.campo"
                    class="flex flex-col gap-1.5"
                >
                    <Label :for="`dato-${c.campo}`"
                        >{{ c.etiqueta }}
                        <span
                            v-if="c.obligatorio"
                            class="text-destructive"
                            title="Obligatorio"
                            >*<span class="sr-only"> (obligatorio)</span></span
                        >
                        <span
                            v-else-if="c.obligatorio === false"
                            class="text-xs font-normal text-muted-foreground"
                            >(opcional)</span
                        ></Label
                    >
                    <SelectSimple
                        v-if="c.tipo === 'opciones'"
                        :id="`dato-${c.campo}`"
                        :model-value="valor(c.campo)"
                        :opciones="c.opciones ?? []"
                        placeholder="Sin capturar"
                        opcion-vacia="Sin capturar"
                        @update:model-value="
                            (v) => poner(c.campo, String(v ?? ''))
                        "
                    />
                    <DatePicker
                        v-else-if="c.tipo === 'date'"
                        :id="`dato-${c.campo}`"
                        :model-value="valor(c.campo)"
                        @update:model-value="(v) => poner(c.campo, v)"
                    />
                    <Input
                        v-else
                        :id="`dato-${c.campo}`"
                        :type="tipoInput(c.tipo)"
                        :maxlength="c.campo === 'domicilio_cp' ? 5 : undefined"
                        :inputmode="
                            c.tipo === 'number' || c.tipo === 'tel'
                                ? 'numeric'
                                : undefined
                        "
                        :model-value="valor(c.campo)"
                        @update:model-value="(v) => poner(c.campo, v)"
                    />
                    <p
                        v-if="error(c.campo)"
                        class="text-xs text-destructive"
                        data-input-error
                    >
                        {{ error(c.campo) }}
                    </p>
                </div>
            </div>
        </section>

        <!-- Lo que ya envió: se ve, no se pide otra vez -->
        <section
            v-if="enRevision.length"
            class="flex flex-col gap-3 rounded-xl bg-info-soft p-3"
        >
            <div>
                <h3 class="flex items-center gap-1.5 text-sm font-semibold">
                    <Hourglass class="size-4 text-[var(--mrl-petroleo)]" />
                    En revisión por Recursos Humanos ({{ enRevision.length }})
                </h3>
                <p class="text-xs text-muted-foreground">
                    Ya los enviaste: no tienes que capturarlos otra vez. Quedan
                    en tu expediente cuando RH los apruebe.
                </p>
            </div>
            <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                <div v-for="c in enRevision" :key="c.campo" class="min-w-0">
                    <dt class="text-xs text-muted-foreground">
                        {{ c.etiqueta }}
                    </dt>
                    <dd class="text-sm break-words">{{ c.en_revision }}</dd>
                </div>
            </dl>
            <p v-if="solicitudesEnRevision.length" class="text-xs">
                <Link
                    v-for="id in solicitudesEnRevision"
                    :key="id"
                    :href="verSolicitud.url(id)"
                    class="mr-3 font-medium text-[var(--mrl-petroleo)] underline underline-offset-2"
                    >Ver lo que envié</Link
                >
            </p>
        </section>

        <section v-if="conValor.length" class="flex flex-col gap-3">
            <div>
                <h3 class="flex items-center gap-1.5 text-sm font-semibold">
                    <PencilLine class="size-4 text-[var(--mrl-petroleo)]" />
                    Solicitar cambio de información
                </h3>
                <p class="text-xs text-muted-foreground">
                    Estos datos ya están en tu expediente. Elige solo los que
                    necesitas corregir y escribe el valor nuevo.
                </p>
            </div>
            <ul
                class="flex flex-col divide-y divide-border/50 rounded-xl border border-border/60"
            >
                <li
                    v-for="c in conValor"
                    :key="c.campo"
                    class="flex flex-col gap-2 px-3 py-2.5 transition-colors hover:bg-crema/40"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium">{{ c.etiqueta }}</p>
                            <p class="truncate text-xs text-muted-foreground">
                                Valor actual: {{ c.actual_legible }}
                            </p>
                        </div>
                        <Button
                            v-if="!cambiando.has(c.campo)"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="cambiar(c.campo)"
                        >
                            Solicitar cambio
                        </Button>
                        <Button
                            v-else
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="deshacer(c.campo)"
                        >
                            <Undo2 class="size-3.5" /> No cambiar
                        </Button>
                    </div>
                    <div
                        v-if="cambiando.has(c.campo)"
                        class="flex entrada-suave flex-col gap-1.5"
                    >
                        <Label :for="`dato-${c.campo}`" class="text-xs"
                            >Valor propuesto</Label
                        >
                        <SelectSimple
                            v-if="c.tipo === 'opciones'"
                            :id="`dato-${c.campo}`"
                            :model-value="valor(c.campo)"
                            :opciones="
                                (c.opciones ?? []).map((op) => ({
                                    value: op.value,
                                    label: op.label,
                                    disabled: op.value === c.actual,
                                }))
                            "
                            placeholder="Elige…"
                            @update:model-value="
                                (v) => poner(c.campo, String(v ?? ''))
                            "
                        />
                        <DatePicker
                            v-else-if="c.tipo === 'date'"
                            :id="`dato-${c.campo}`"
                            :model-value="valor(c.campo)"
                            @update:model-value="(v) => poner(c.campo, v)"
                        />
                        <Input
                            v-else
                            :id="`dato-${c.campo}`"
                            :type="tipoInput(c.tipo)"
                            :maxlength="
                                c.campo === 'domicilio_cp' ? 5 : undefined
                            "
                            :model-value="valor(c.campo)"
                            @update:model-value="(v) => poner(c.campo, v)"
                        />
                        <p
                            v-if="error(c.campo)"
                            class="text-xs text-destructive"
                            data-input-error
                        >
                            {{ error(c.campo) }}
                        </p>
                    </div>
                </li>
            </ul>
        </section>

        <p
            v-if="errores.datos"
            class="text-sm text-destructive"
            data-input-error
        >
            {{ errores.datos }}
        </p>
    </div>
</template>
