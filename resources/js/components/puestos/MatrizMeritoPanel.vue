<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Gift, History, Loader2, Pencil, Sparkles } from '@lucide/vue';
import { reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { guardar } from '@/routes/salarios/matriz';
import type {
    BandaSalarial,
    MatrizMeritoCeldas,
    SalariosModulo,
} from '@/types';

/**
 * Matriz de mérito: incremento sugerido según desempeño (filas) y posición
 * en el tabulador (columnas). Son sugerencias: ningún aumento se aplica
 * solo. Cada celda es un porcentaje o «Bono» (para quien ya pasó el máximo).
 */
const props = defineProps<{
    matriz: MatrizMeritoCeldas | null;
    historial: SalariosModulo['historial_matriz'];
    puedeEditar: boolean;
}>();

const NIVELES = [
    {
        clave: 'sobresaliente',
        etiqueta: 'Sobresaliente',
        clase: 'bg-success-soft text-success',
    },
    {
        clave: 'satisfactorio',
        etiqueta: 'Satisfactorio',
        clase: 'bg-info-soft text-info',
    },
    {
        clave: 'bajo',
        etiqueta: 'Bajo',
        clase: 'bg-danger-soft text-destructive',
    },
];
const BANDAS: { clave: BandaSalarial; etiqueta: string }[] = [
    { clave: 'bajo_minimo', etiqueta: 'Bajo mínimo' },
    { clave: 'minimo_a_medio', etiqueta: 'Mínimo a medio' },
    { clave: 'medio_a_maximo', etiqueta: 'Medio a máximo' },
    { clave: 'sobre_maximo', etiqueta: 'Sobre máximo' },
];

const editando = ref(false);
const enviando = ref(false);
const verHistorial = ref(false);
const celdas = reactive<
    Record<string, Record<string, { bono: boolean; valor: string }>>
>({});
const motivo = ref('');
const error = ref<string | null>(null);

function valor(nivel: string, banda: string): number | string | undefined {
    return props.matriz?.[nivel]?.[banda];
}

function abrir(): void {
    for (const nivel of NIVELES) {
        celdas[nivel.clave] = {};

        for (const banda of BANDAS) {
            const actual = valor(nivel.clave, banda.clave);
            celdas[nivel.clave][banda.clave] = {
                bono: actual === 'bono',
                valor:
                    actual === 'bono' || actual === undefined
                        ? '0'
                        : String(actual),
            };
        }
    }

    error.value = null;
    editando.value = true;
}

function guardarMatriz(): void {
    const payload: Record<string, Record<string, string>> = {};

    for (const nivel of NIVELES) {
        payload[nivel.clave] = {};

        for (const banda of BANDAS) {
            const celda = celdas[nivel.clave][banda.clave];
            payload[nivel.clave][banda.clave] = celda.bono
                ? 'bono'
                : celda.valor.trim();
        }
    }

    enviando.value = true;
    router.put(
        guardar.url(),
        { celdas: payload, motivo: motivo.value.trim() || null },
        {
            preserveScroll: true,
            onError: (errores) => {
                error.value =
                    errores.celdas ??
                    'Revisa los valores: de 0 a 100 o «Bono».';
            },
            onSuccess: () => {
                editando.value = false;
                motivo.value = '';
            },
            onFinish: () => {
                enviando.value = false;
            },
        },
    );
}

function intensidad(celda: number | string | undefined): string {
    if (celda === 'bono') {
        return 'bg-oro/15 text-bronce';
    }

    const numero = typeof celda === 'number' ? celda : Number(celda ?? 0);

    if (numero >= 8) {
        return 'bg-success-soft text-success';
    }

    if (numero > 0) {
        return 'bg-menta text-primary';
    }

    return 'bg-muted/60 text-muted-foreground';
}
</script>

<template>
    <section class="flex flex-col gap-4 rounded-3xl border bg-card p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <span
                    class="grid size-10 shrink-0 place-items-center rounded-2xl bg-oro/15 text-bronce"
                >
                    <Sparkles class="size-5" />
                </span>
                <div>
                    <h2 class="font-semibold">Matriz de mérito</h2>
                    <p class="text-sm text-muted-foreground">
                        Incremento sugerido según desempeño y posición en el
                        tabulador. Son sugerencias: ningún aumento se aplica
                        solo.
                    </p>
                </div>
            </div>
            <Button
                v-if="puedeEditar && !editando"
                variant="outline"
                @click="abrir"
            >
                <Pencil class="size-4" /> Editar matriz
            </Button>
        </div>

        <div class="tabla-scroll">
            <table
                class="w-full min-w-[560px] border-separate border-spacing-1.5 text-sm"
            >
                <thead>
                    <tr>
                        <th
                            class="w-36 px-2 text-left text-xs font-medium text-muted-foreground"
                        >
                            Desempeño ↓ · Posición →
                        </th>
                        <th
                            v-for="banda in BANDAS"
                            :key="banda.clave"
                            class="rounded-xl bg-crema/60 px-2 py-2 text-xs font-semibold"
                        >
                            {{ banda.etiqueta }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="nivel in NIVELES" :key="nivel.clave">
                        <th class="text-left">
                            <span
                                :class="
                                    cn(
                                        'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                                        nivel.clase,
                                    )
                                "
                            >
                                {{ nivel.etiqueta }}
                            </span>
                        </th>
                        <td
                            v-for="banda in BANDAS"
                            :key="banda.clave"
                            class="p-0"
                        >
                            <div
                                v-if="editando"
                                class="flex h-14 items-center gap-1 rounded-xl border bg-background/70 px-1.5"
                            >
                                <template
                                    v-if="
                                        !celdas[nivel.clave][banda.clave].bono
                                    "
                                >
                                    <Input
                                        v-model="
                                            celdas[nivel.clave][banda.clave]
                                                .valor
                                        "
                                        inputmode="decimal"
                                        class="h-9 border-0 text-center font-semibold tabular-nums shadow-none focus-visible:ring-0"
                                        :aria-label="`${nivel.etiqueta}, ${banda.etiqueta}`"
                                    />
                                    <span class="text-xs text-muted-foreground"
                                        >%</span
                                    >
                                </template>
                                <span
                                    v-else
                                    class="flex-1 text-center text-sm font-semibold text-bronce"
                                    >Bono</span
                                >
                                <button
                                    type="button"
                                    :aria-pressed="
                                        celdas[nivel.clave][banda.clave].bono
                                    "
                                    :title="
                                        celdas[nivel.clave][banda.clave].bono
                                            ? 'Cambiar a porcentaje'
                                            : 'Cambiar a bono'
                                    "
                                    class="grid size-8 shrink-0 place-items-center rounded-lg transition-colors"
                                    :class="
                                        celdas[nivel.clave][banda.clave].bono
                                            ? 'bg-oro/20 text-bronce'
                                            : 'text-muted-foreground hover:bg-crema/60'
                                    "
                                    @click="
                                        celdas[nivel.clave][banda.clave].bono =
                                            !celdas[nivel.clave][banda.clave]
                                                .bono
                                    "
                                >
                                    <Gift class="size-4" />
                                </button>
                            </div>
                            <div
                                v-else
                                :class="
                                    cn(
                                        'grid h-14 place-items-center rounded-xl text-base font-semibold tabular-nums',
                                        intensidad(
                                            valor(nivel.clave, banda.clave),
                                        ),
                                    )
                                "
                            >
                                {{
                                    valor(nivel.clave, banda.clave) ===
                                    undefined
                                        ? '—'
                                        : valor(nivel.clave, banda.clave) ===
                                            'bono'
                                          ? 'Bono'
                                          : `${valor(nivel.clave, banda.clave)}%`
                                }}
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="editando"
            class="flex flex-col gap-3 rounded-2xl bg-crema/30 p-3"
        >
            <Input
                v-model="motivo"
                class="h-11 rounded-xl"
                placeholder="Motivo del cambio (opcional)"
            />
            <InputError :message="error ?? undefined" />
            <div class="flex justify-end gap-2">
                <Button variant="ghost" @click="editando = false"
                    >Cancelar</Button
                >
                <Button :disabled="enviando" @click="guardarMatriz">
                    <Loader2 v-if="enviando" class="size-4 animate-spin" />
                    <Check v-else class="size-4" />
                    Guardar matriz
                </Button>
            </div>
        </div>

        <div v-if="historial.length" class="border-t pt-3">
            <Button
                variant="ghost"
                size="sm"
                @click="verHistorial = !verHistorial"
            >
                <History class="size-4" />
                {{
                    verHistorial
                        ? 'Ocultar historial'
                        : `Historial (${historial.length})`
                }}
            </Button>
            <ol v-if="verHistorial" class="mt-2 grid gap-1.5 text-xs">
                <li
                    v-for="version in historial"
                    :key="version.id"
                    class="flex flex-wrap items-center gap-x-2 gap-y-0.5 rounded-xl bg-background/60 px-3 py-2"
                >
                    <span class="font-medium tabular-nums">{{
                        version.vigente_desde
                    }}</span>
                    <span class="text-muted-foreground">
                        {{
                            version.vigente_hasta
                                ? `a ${version.vigente_hasta}`
                                : '· vigente'
                        }}
                    </span>
                    <span class="text-muted-foreground"
                        >· {{ version.usuario ?? 'Sistema' }}</span
                    >
                    <span v-if="version.motivo" class="text-muted-foreground"
                        >· {{ version.motivo }}</span
                    >
                </li>
            </ol>
        </div>
    </section>
</template>
