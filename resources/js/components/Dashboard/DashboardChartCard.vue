<script setup lang="ts" generic="Datum extends Record<string, unknown>">
import {
    VisArea,
    VisAxis,
    VisDonut,
    VisGroupedBar,
    VisLine,
    VisSingleContainer,
    VisXYContainer,
} from '@unovis/vue';
import { computed } from 'vue';
import EmptyDashboardState from '@/components/Dashboard/EmptyDashboardState.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Tarjeta-panel que envuelve una gráfica de Unovis (barras, línea, área o
 * dona) con título, descripción, skeleton de carga y estado vacío — para
 * que cada página del dashboard no tenga que repetir el cableado de
 * Unovis ni decidir su propio estado vacío. `xKey`/`yKey` se usan para
 * bar/line/area; `labelKey`/`valueKey` para donut.
 */
type TipoGrafica = 'bar' | 'line' | 'area' | 'donut';

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        type: TipoGrafica;
        data: Datum[];
        xKey?: string;
        yKey?: string;
        labelKey?: string;
        valueKey?: string;
        colors?: string[];
        height?: number;
        loading?: boolean;
        emptyTitle?: string;
        emptyDescription?: string;
    }>(),
    {
        colors: () => [
            'var(--chart-1)',
            'var(--chart-2)',
            'var(--chart-3)',
            'var(--chart-4)',
            'var(--chart-5)',
        ],
        height: 220,
        loading: false,
    },
);

const estaVacio = computed(() => {
    if (!props.data.length) {
        return true;
    }

    if (props.type === 'donut' && props.valueKey) {
        const clave = props.valueKey;

        return props.data.every((d) => Number(d[clave] ?? 0) === 0);
    }

    return false;
});

const accesorIndice = (_d: Datum, i: number) => i;
const accesorY = (d: Datum) => Number(d[props.yKey ?? ''] ?? 0);
const accesorValor = (d: Datum) => Number(d[props.valueKey ?? ''] ?? 0);
const formatoEtiquetaX = (i: number) =>
    String(props.data[i]?.[props.xKey ?? ''] ?? '');
const colorPorIndice = (_d: Datum, i: number) =>
    props.colors[i % props.colors.length];
</script>

<template>
    <Card
        class="gap-4 rounded-2xl border-border/60 py-5 shadow-sm transition-all duration-200 hover:border-primary/40 hover:shadow-lg"
    >
        <CardHeader>
            <CardTitle class="text-base">{{ title }}</CardTitle>
            <CardDescription v-if="description">{{
                description
            }}</CardDescription>
        </CardHeader>
        <CardContent>
            <Skeleton
                v-if="loading"
                class="w-full rounded-xl"
                :style="{ height: `${height}px` }"
            />

            <EmptyDashboardState
                v-else-if="estaVacio"
                :titulo="emptyTitle"
                :descripcion="emptyDescription"
            />

            <template v-else>
                <VisSingleContainer
                    v-if="type === 'donut'"
                    :data="data"
                    :height="height"
                >
                    <VisDonut
                        :value="accesorValor"
                        :color="colorPorIndice"
                        :arc-width="26"
                        :corner-radius="4"
                        :pad-angle="0.02"
                    />
                </VisSingleContainer>

                <VisXYContainer
                    v-else
                    :data="data"
                    :height="height"
                    :margin="{ left: 8, right: 8, top: 8, bottom: 4 }"
                >
                    <VisGroupedBar
                        v-if="type === 'bar'"
                        :x="accesorIndice"
                        :y="accesorY"
                        :color="colorPorIndice"
                        :rounded-corners="6"
                    />
                    <VisLine
                        v-else-if="type === 'line'"
                        :x="accesorIndice"
                        :y="accesorY"
                        :color="() => 'var(--brand-primary)'"
                        :line-width="2.5"
                    />
                    <VisArea
                        v-else-if="type === 'area'"
                        :x="accesorIndice"
                        :y="accesorY"
                        :color="() => 'var(--brand-primary)'"
                    />
                    <VisAxis
                        type="x"
                        :tick-format="formatoEtiquetaX"
                        :num-ticks="data.length"
                    />
                    <VisAxis type="y" :num-ticks="4" />
                </VisXYContainer>

                <div
                    v-if="type === 'donut' && labelKey && valueKey"
                    class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5"
                >
                    <div
                        v-for="(item, i) in data"
                        :key="i"
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :style="{
                                backgroundColor: colors[i % colors.length],
                            }"
                        />
                        {{ item[labelKey] }} · {{ item[valueKey] }}
                    </div>
                </div>
            </template>
        </CardContent>
    </Card>
</template>
