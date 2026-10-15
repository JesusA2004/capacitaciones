<script setup lang="ts" generic="T extends Record<string, unknown>">
import { Loader2 } from '@lucide/vue';
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

withDefaults(
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
                class="hidden overflow-x-auto rounded-2xl border border-border/60 shadow-sm sm:block"
            >
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
                            <TableHead v-if="$slots.acciones" class="text-right"
                                >Acciones</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="(fila, indice) in datos.data"
                            :key="indice"
                            :class="[
                                'transition-colors duration-150',
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
                                class="text-right"
                                @click.stop
                            >
                                <slot name="acciones" :fila="fila" />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <!-- Mobile: cada fila se muestra como tarjeta en vez de tabla
                 horizontal, nunca se sale de los bordes de la pantalla. Si
                 la página no define una tarjeta personalizada (#mobile-card),
                 se arma una genérica a partir de las mismas columnas. -->
            <div
                v-if="$slots['mobile-card']"
                class="flex flex-col gap-3 sm:hidden"
            >
                <template v-for="(fila, indice) in datos.data" :key="indice">
                    <slot name="mobile-card" :fila="fila" />
                </template>
            </div>
            <div v-else class="flex flex-col gap-3 sm:hidden">
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
