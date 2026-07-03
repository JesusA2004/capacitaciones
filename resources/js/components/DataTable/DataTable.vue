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

const props = defineProps<{
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

const colSpanTotal = () => props.columnas.length + 1;
</script>

<template>
    <div class="space-y-3">
        <div class="rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
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
                    <template v-if="cargando">
                        <TableRow>
                            <TableCell
                                :colspan="colSpanTotal()"
                                class="h-32 text-center"
                            >
                                <Loader2
                                    class="mx-auto size-5 animate-spin text-muted-foreground"
                                />
                            </TableCell>
                        </TableRow>
                    </template>
                    <template v-else-if="datos.data.length === 0">
                        <TableRow>
                            <TableCell :colspan="colSpanTotal()" class="p-0">
                                <EmptyState
                                    :descripcion="
                                        mensajeVacio ??
                                        'No hay registros para mostrar.'
                                    "
                                />
                            </TableCell>
                        </TableRow>
                    </template>
                    <template v-else>
                        <TableRow
                            v-for="(fila, indice) in datos.data"
                            :key="indice"
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
                    </template>
                </TableBody>
            </Table>
        </div>

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
