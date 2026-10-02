<script setup lang="ts" generic="T extends Record<string, unknown>">
import { Loader2 } from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { usePaginacion } from '@/composables/usePaginacion';
import type { RespuestaPaginada } from '@/types';

export type ColumnaDataTable = {
    clave: string;
    etiqueta: string;
    clase?: string;
};

defineProps<{
    columnas: ColumnaDataTable[];
    datos: RespuestaPaginada<T>;
    cargando?: boolean;
    mensajeVacio?: string;
}>();

const { irA } = usePaginacion();

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
                            class="transition-colors duration-150"
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
                    class="flex flex-col gap-2.5 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
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
                    >
                        <slot name="acciones" :fila="fila" />
                    </div>
                </div>
            </div>
        </template>

        <div
            v-if="datos.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground"
        >
            <span
                >Mostrando {{ datos.from ?? 0 }}–{{ datos.to ?? 0 }} de
                {{ datos.total }}</span
            >
            <div class="flex flex-wrap gap-1">
                <button
                    v-for="(enlace, indice) in datos.links"
                    :key="indice"
                    type="button"
                    :disabled="!enlace.url"
                    @click="irA(enlace.url)"
                    :class="[
                        'min-w-9 rounded-md border px-3 py-1.5 text-sm transition-colors',
                        enlace.active
                            ? 'border-transparent bg-primary text-primary-foreground'
                            : 'border-border hover:bg-accent',
                        !enlace.url
                            ? 'cursor-not-allowed opacity-50'
                            : 'cursor-pointer',
                    ]"
                    v-html="enlace.label"
                />
            </div>
        </div>
    </div>
</template>
