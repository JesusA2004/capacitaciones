<script setup lang="ts" generic="T extends Record<string, unknown>">
import { Loader2 } from '@lucide/vue';
import { useEventListener, useResizeObserver } from '@vueuse/core';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { RespuestaPaginada } from '@/types';

export type ColumnaDataTable = {
    clave: string;
    etiqueta: string;
    clase?: string;
};

const props = withDefaults(
    defineProps<{
        columnas: ColumnaDataTable[];
        datos: RespuestaPaginada<T>;
        cargando?: boolean;
        mensajeVacio?: string;
        /** Si se pasa, cada fila/tarjeta abre la acción principal al
         * tocarla, en vez de obligar a usar el menú de acciones. */
        filasClicables?: boolean;
    }>(),
    { filasClicables: false },
);

const emit = defineEmits<{ 'click-fila': [fila: T] }>();

/*
 * Tabla más ancha que la pantalla (5+ columnas en 768–1100 px): el
 * contenedor hace scroll horizontal, y para que se NOTE se dibuja una
 * sombra en el borde hacia donde hay más contenido. La columna «Acciones»
 * queda fija a la derecha (sticky), así nunca se esconde tras el scroll.
 */
const marco = useTemplateRef<HTMLElement>('marco');
const scroller = computed<HTMLElement | null>(
    () => marco.value?.querySelector('table')?.parentElement ?? null,
);
const masIzquierda = ref(false);
const masDerecha = ref(false);

function medirScroll(): void {
    const el = scroller.value;

    if (!el) {
        masIzquierda.value = false;
        masDerecha.value = false;

        return;
    }

    const restante = el.scrollWidth - el.clientWidth - el.scrollLeft;

    masIzquierda.value = el.scrollLeft > 1;
    masDerecha.value = restante > 1;
}

useEventListener(scroller, 'scroll', medirScroll, { passive: true });
useResizeObserver(scroller, medirScroll);
watch(
    () => props.datos.data,
    () => nextTick(medirScroll),
);

function valorCelda(fila: T, clave: string): unknown {
    return clave.split('.').reduce<unknown>((acumulado, parte) => {
        if (acumulado && typeof acumulado === 'object' && parte in acumulado) {
            return (acumulado as Record<string, unknown>)[parte];
        }

        return undefined;
    }, fila);
}
</script>

<template>
    <div data-tour="tabla" class="space-y-3">
        <div
            v-if="cargando"
            class="flex items-center justify-center rounded-2xl border border-border/60 p-16"
        >
            <Loader2 class="size-5 animate-spin text-muted-foreground" />
        </div>

        <slot v-else-if="datos.data.length === 0" name="vacio">
            <EmptyState
                :descripcion="mensajeVacio ?? 'No hay registros para mostrar.'"
            />
        </slot>

        <template v-else>
            <div
                ref="marco"
                class="relative hidden overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm md:block"
            >
                <!-- Sombras de «hay más columnas» (solo si hay scroll). -->
                <span
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-y-0 left-0 z-20 w-6 bg-gradient-to-r from-foreground/10 to-transparent transition-opacity duration-200"
                    :class="masIzquierda ? 'opacity-100' : 'opacity-0'"
                />
                <span
                    v-if="!$slots.acciones"
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-y-0 right-0 z-20 w-6 bg-gradient-to-l from-foreground/10 to-transparent transition-opacity duration-200"
                    :class="masDerecha ? 'opacity-100' : 'opacity-0'"
                />
                <Table>
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead
                                v-for="columna in columnas"
                                :key="columna.clave"
                                :class="columna.clase"
                            >
                                {{ columna.etiqueta }}
                            </TableHead>
                            <TableHead
                                v-if="$slots.acciones"
                                class="sticky right-0 z-10 bg-[color-mix(in_oklab,var(--pastel-crema)_45%,var(--card))] text-right transition-shadow"
                                :class="
                                    masDerecha &&
                                    'shadow-[-10px_0_12px_-10px_color-mix(in_oklab,var(--foreground)_35%,transparent)]'
                                "
                                >Acciones</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="(fila, indice) in datos.data"
                            :key="indice"
                            :class="[
                                'group transition-colors duration-150',
                                filasClicables
                                    ? 'cursor-pointer hover:bg-salvia/40 hover:shadow-[inset_3px_0_0_var(--pastel-oro)]'
                                    : '',
                            ]"
                            @click="filasClicables && emit('click-fila', fila)"
                        >
                            <TableCell
                                v-for="columna in columnas"
                                :key="columna.clave"
                                :class="columna.clase"
                            >
                                <slot
                                    :name="`celda-${columna.clave}`"
                                    :fila="fila"
                                >
                                    {{ valorCelda(fila, columna.clave) }}
                                </slot>
                            </TableCell>
                            <TableCell
                                v-if="$slots.acciones"
                                class="sticky right-0 z-10 bg-card text-right transition-[background-color,box-shadow] group-hover:bg-[color-mix(in_oklab,var(--pastel-menta)_50%,var(--card))]"
                                :class="
                                    masDerecha &&
                                    'shadow-[-10px_0_12px_-10px_color-mix(in_oklab,var(--foreground)_35%,transparent)]'
                                "
                                @click.stop
                            >
                                <slot name="acciones" :fila="fila" />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <!-- Móvil y tableta vertical (< md): cada fila se muestra como tarjeta en vez de tabla
                 horizontal, nunca se sale de los bordes de la pantalla. Si
                 la página no define una tarjeta personalizada (#mobile-card),
                 se arma una genérica a partir de las mismas columnas. -->
            <div
                v-if="$slots['mobile-card']"
                class="flex flex-col gap-3 md:hidden"
            >
                <template v-for="(fila, indice) in datos.data" :key="indice">
                    <slot name="mobile-card" :fila="fila" />
                </template>
            </div>
            <div v-else class="flex flex-col gap-3 md:hidden">
                <div
                    v-for="(fila, indice) in datos.data"
                    :key="indice"
                    :class="[
                        'flex flex-col gap-2.5 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-colors',
                        filasClicables
                            ? 'tarjeta-interactiva active:bg-muted/40'
                            : '',
                    ]"
                    @click="filasClicables && emit('click-fila', fila)"
                >
                    <dl class="flex flex-col gap-2 text-sm">
                        <div
                            v-for="columna in columnas"
                            :key="columna.clave"
                            class="flex items-start justify-between gap-3"
                        >
                            <dt
                                class="shrink-0 text-xs font-medium text-muted-foreground"
                            >
                                {{ columna.etiqueta }}
                            </dt>
                            <dd class="min-w-0 text-right break-words">
                                <slot
                                    :name="`celda-${columna.clave}`"
                                    :fila="fila"
                                >
                                    {{ valorCelda(fila, columna.clave) }}
                                </slot>
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-if="$slots.acciones"
                        class="flex items-center justify-end gap-1 border-t border-border/60 pt-2"
                        @click.stop
                    >
                        <slot name="acciones" :fila="fila" />
                    </div>
                </div>
            </div>
        </template>

        <PaginacionTabla :datos="datos" />
    </div>
</template>
