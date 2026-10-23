<script setup lang="ts">
import { Info, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import TarjetaPregunta from '@/components/formularios-evaluacion/editor/TarjetaPregunta.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { claveNueva, duplicarEn, moverEn } from '@/lib/formulariosEvaluacion';
import type { DefinicionPeriodoPrueba, ReactivoPeriodoPrueba } from '@/types';

/**
 * Editor de la evaluación de periodo de prueba (FO-RH-EPP-001): criterios
 * (escala fija 4–1, se pueden renombrar los valores), preguntas filtro
 * Sí/No y reglas de renovación.
 */
const props = defineProps<{ soloLectura?: boolean }>();
const d = defineModel<DefinicionPeriodoPrueba>({ required: true });

const activa = ref<string | null>(null);
const maximo = computed(() => d.value.criterios.length * 4);

function nivelesVacios(): Record<string, string> {
    return { '4': '', '3': '', '2': '', '1': '' };
}

// Los criterios editan la descripción oficial de cada valor (4/3/2/1) y si
// capturan Meta/Logró/%: garantiza que esos campos existan aunque el borrador
// venga de una versión anterior que no los tenía.
watch(
    () => d.value.criterios,
    (criterios) => {
        criterios.forEach((c) => {
            if (!c.niveles) {
                c.niveles = nivelesVacios();
            }
            if (c.captura_meta === undefined) {
                c.captura_meta = false;
            }
        });
    },
    { immediate: true, deep: true },
);

function agregar(
    lista: ReactivoPeriodoPrueba[],
    prefijo: 'criterio' | 'filtro',
) {
    lista.push(
        prefijo === 'criterio'
            ? {
                  clave: claveNueva(prefijo),
                  etiqueta: '',
                  descripcion: '',
                  captura_meta: false,
                  niveles: nivelesVacios(),
              }
            : { clave: claveNueva(prefijo), etiqueta: '', descripcion: '' },
    );
    activa.value = `${prefijo}-${lista.length - 1}`;
}

function eliminar(lista: ReactivoPeriodoPrueba[], i: number, prefijo: string) {
    lista.splice(i, 1);
    activa.value = lista.length ? `${prefijo}-${Math.max(0, i - 1)}` : null;
}

function mover(
    lista: ReactivoPeriodoPrueba[],
    i: number,
    delta: number,
    prefijo: string,
) {
    activa.value = `${prefijo}-${moverEn(lista, i, delta)}`;
}

function duplicar(
    lista: ReactivoPeriodoPrueba[],
    i: number,
    prefijo: 'criterio' | 'filtro',
) {
    activa.value = `${prefijo}-${duplicarEn(lista, i, (c) => ({ ...c, clave: claveNueva(prefijo) }))}`;
}

const OPCIONES_COMENTARIO = [
    { valor: 0, texto: 'Nunca por la calificación' },
    { valor: 1, texto: 'Si algún criterio queda en 1' },
    { valor: 2, texto: 'Si algún criterio queda en 2 o 1' },
    { valor: 3, texto: 'Si algún criterio queda en 3 o menos' },
];
</script>

<template>
    <div class="flex flex-col gap-8">
        <!-- Reglas -->
        <section
            class="flex flex-col gap-4 rounded-2xl border border-border bg-card p-5 shadow-xs"
        >
            <h3 class="font-semibold">Reglas de renovación</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="minimo">Total mínimo para renovar</Label>
                    <Input
                        id="minimo"
                        v-model.number="d.total_minimo_renovar"
                        type="number"
                        :min="d.criterios.length"
                        :max="maximo"
                        :disabled="props.soloLectura"
                    />
                    <p class="text-xs text-muted-foreground">
                        De {{ maximo }} puntos posibles ({{
                            d.criterios.length
                        }}
                        criterios × 4).
                    </p>
                </div>
                <div class="grid gap-1.5">
                    <Label>El comentario del jefe es obligatorio…</Label>
                    <div class="flex flex-col gap-1">
                        <label
                            v-for="o in OPCIONES_COMENTARIO"
                            :key="o.valor"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                v-model="d.comentario_obligatorio_hasta"
                                type="radio"
                                :value="o.valor"
                                name="comentario_hasta"
                                class="accent-[var(--primary)]"
                                :disabled="props.soloLectura"
                            />
                            {{ o.texto }}
                        </label>
                    </div>
                </div>
            </div>
            <label class="flex items-start gap-2 text-sm">
                <Checkbox
                    :model-value="d.un_uno_no_renueva"
                    :disabled="props.soloLectura"
                    class="mt-0.5"
                    @update:model-value="
                        (v) => (d.un_uno_no_renueva = v === true)
                    "
                />
                <span
                    >Un solo criterio en <strong>1</strong> significa NO SE
                    RENUEVA, aunque el total alcance el mínimo.</span
                >
            </label>
            <label class="flex items-start gap-2 text-sm">
                <Checkbox
                    :model-value="d.comentario_si_filtro"
                    :disabled="props.soloLectura"
                    class="mt-0.5"
                    @update:model-value="
                        (v) => (d.comentario_si_filtro = v === true)
                    "
                />
                <span
                    >Pedir comentario cuando una pregunta filtro se conteste
                    «Sí».</span
                >
            </label>
            <p class="flex items-start gap-1.5 text-xs text-muted-foreground">
                <Info class="mt-0.5 size-3.5 shrink-0" />
                Un «Sí» en cualquier pregunta filtro siempre significa NO SE
                RENUEVA. Recursos Humanos puede pedir una excepción a Dirección
                General.
            </p>
        </section>

        <!-- Escala -->
        <section
            class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-5 shadow-xs"
        >
            <h3 class="font-semibold">Escala de calificación</h3>
            <p class="text-xs text-muted-foreground">
                La escala siempre es de 4 a 1 (así la usan el formato impreso y
                la app); aquí cambias cómo se llama cada valor.
            </p>
            <div class="grid gap-3 sm:grid-cols-4">
                <div v-for="n in [4, 3, 2, 1]" :key="n" class="grid gap-1.5">
                    <Label :for="`escala-${n}`">Valor {{ n }}</Label>
                    <Input
                        :id="`escala-${n}`"
                        v-model="d.escala_etiquetas[String(n)]"
                        :disabled="props.soloLectura"
                    />
                </div>
            </div>
        </section>

        <!-- Criterios -->
        <section class="flex flex-col gap-3">
            <div class="px-1">
                <h3 class="font-semibold">Paso 1 · Criterios a calificar</h3>
                <p class="text-xs text-muted-foreground">
                    El jefe inmediato evalúa el desempeño observado del
                    colaborador con la escala de 4 a 1, eligiendo en cada
                    criterio la descripción que mejor lo describe. Agrega, quita
                    o reordena los que necesites (máximo 20).
                </p>
            </div>
            <TarjetaPregunta
                v-for="(c, i) in d.criterios"
                :key="c.clave"
                :activa="activa === `criterio-${i}`"
                :etiqueta="`Criterio ${i + 1}`"
                :solo-lectura="props.soloLectura"
                :puede-subir="i > 0"
                :puede-bajar="i < d.criterios.length - 1"
                :puede-eliminar="d.criterios.length > 1"
                @activar="activa = `criterio-${i}`"
                @subir="mover(d.criterios, i, -1, 'criterio')"
                @bajar="mover(d.criterios, i, 1, 'criterio')"
                @duplicar="duplicar(d.criterios, i, 'criterio')"
                @eliminar="eliminar(d.criterios, i, 'criterio')"
            >
                <template #resumen>
                    <p class="text-[15px] font-medium">
                        {{ c.etiqueta || 'Criterio sin texto' }}
                    </p>
                    <p
                        v-if="c.descripcion"
                        class="text-xs text-muted-foreground"
                    >
                        {{ c.descripcion }}
                    </p>
                </template>
                <Input
                    v-model="c.etiqueta"
                    placeholder="Ej. Conoce y hace bien su trabajo"
                    :disabled="props.soloLectura"
                />
                <Input
                    v-model="c.descripcion"
                    placeholder="Ayuda para el jefe (opcional)"
                    :disabled="props.soloLectura"
                />
                <div v-if="c.niveles" class="grid gap-2 sm:grid-cols-2">
                    <div v-for="n in [4, 3, 2, 1]" :key="n" class="grid gap-1">
                        <Label :for="`nivel-${c.clave}-${n}`" class="text-xs">
                            Descripción del {{ n }} ·
                            {{ d.escala_etiquetas[String(n)] }}
                        </Label>
                        <Input
                            :id="`nivel-${c.clave}-${n}`"
                            v-model="c.niveles[String(n)]"
                            :placeholder="`Qué se ve cuando merece un ${n}`"
                            :disabled="props.soloLectura"
                        />
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="c.captura_meta === true"
                        :disabled="props.soloLectura"
                        @update:model-value="
                            (v) => (c.captura_meta = v === true)
                        "
                    />
                    <span
                        >Capturar <strong>Meta / Logró / %</strong> en este
                        criterio (como «Cumple sus metas»).</span
                    >
                </label>
            </TarjetaPregunta>
            <Button
                v-if="!props.soloLectura && d.criterios.length < 20"
                type="button"
                variant="outline"
                class="w-fit border-dashed"
                @click="agregar(d.criterios, 'criterio')"
            >
                <Plus class="size-4" /> Agregar criterio
            </Button>
        </section>

        <!-- Filtros -->
        <section class="flex flex-col gap-3">
            <div class="px-1">
                <h3 class="font-semibold">
                    Paso 2 · Preguntas filtro (Sí / No)
                </h3>
                <p class="text-xs text-muted-foreground">
                    Conductas graves: un solo «Sí» bloquea la renovación.
                </p>
            </div>
            <TarjetaPregunta
                v-for="(f, i) in d.filtros"
                :key="f.clave"
                :activa="activa === `filtro-${i}`"
                :etiqueta="`Pregunta filtro ${i + 1}`"
                :solo-lectura="props.soloLectura"
                :puede-subir="i > 0"
                :puede-bajar="i < d.filtros.length - 1"
                @activar="activa = `filtro-${i}`"
                @subir="mover(d.filtros, i, -1, 'filtro')"
                @bajar="mover(d.filtros, i, 1, 'filtro')"
                @duplicar="duplicar(d.filtros, i, 'filtro')"
                @eliminar="eliminar(d.filtros, i, 'filtro')"
            >
                <template #resumen>
                    <p class="text-[15px] font-medium">
                        {{ f.etiqueta || 'Pregunta sin texto' }}
                    </p>
                </template>
                <Input
                    v-model="f.etiqueta"
                    placeholder="Ej. Faltantes de dinero no aclarados"
                    :disabled="props.soloLectura"
                />
                <Input
                    v-model="f.descripcion"
                    placeholder="Ayuda para el jefe (opcional)"
                    :disabled="props.soloLectura"
                />
            </TarjetaPregunta>
            <Button
                v-if="!props.soloLectura && d.filtros.length < 10"
                type="button"
                variant="outline"
                class="w-fit border-dashed"
                @click="agregar(d.filtros, 'filtro')"
            >
                <Plus class="size-4" /> Agregar pregunta filtro
            </Button>
        </section>
    </div>
</template>
