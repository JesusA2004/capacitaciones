<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CalendarPlus,
    FileText,
    FolderOpen,
    History,
    MoreHorizontal,
    PencilLine,
    Receipt,
    UserMinus,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { FilaHistoricoRotacion } from '@/types';

/**
 * Menú de una baja del histórico: solo las acciones que el backend dejó
 * abrir (enlaces ya revalidados por ConstructorEnlaces); nunca un 403.
 */
const props = defineProps<{
    fila: FilaHistoricoRotacion;
    verHistorial: boolean;
}>();

const emit = defineEmits<{
    corregirCausal: [];
    completarFecha: [];
    historial: [];
}>();

const a = computed(() => props.fila.acciones);

const enlaces = computed(() =>
    [
        { href: a.value.ver_baja, etiqueta: 'Ver baja', icono: UserMinus },
        {
            href: a.value.expediente,
            etiqueta: 'Abrir expediente',
            icono: FolderOpen,
        },
        { href: a.value.finiquito, etiqueta: 'Ver finiquito', icono: Receipt },
        {
            href: a.value.cierre,
            etiqueta: 'Ver cierre laboral',
            icono: FileText,
        },
    ].filter((e): e is typeof e & { href: string } => e.href !== null),
);

const hayCorrecciones = computed(
    () => a.value.corregir_causal || a.value.completar_fecha,
);
const conHistorial = computed(
    () => props.verHistorial && a.value.correcciones > 0,
);
const hayAlgo = computed(
    () =>
        enlaces.value.length > 0 || hayCorrecciones.value || conHistorial.value,
);
</script>

<template>
    <DropdownMenu v-if="hayAlgo">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="size-9"
                :aria-label="`Acciones de ${fila.nombre}`"
                @click.stop
            >
                <MoreHorizontal class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuLabel class="truncate text-xs text-muted-foreground">{{
                fila.nombre
            }}</DropdownMenuLabel>
            <DropdownMenuItem v-for="e in enlaces" :key="e.etiqueta" as-child>
                <Link :href="e.href" class="cursor-pointer">
                    <component :is="e.icono" class="size-4" />
                    {{ e.etiqueta }}
                </Link>
            </DropdownMenuItem>
            <DropdownMenuSeparator
                v-if="enlaces.length && (hayCorrecciones || conHistorial)"
            />
            <DropdownMenuItem
                v-if="a.corregir_causal"
                @select="emit('corregirCausal')"
            >
                <PencilLine class="size-4" />
                Corregir causal
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="a.completar_fecha"
                @select="emit('completarFecha')"
            >
                <CalendarPlus class="size-4" />
                Completar fecha de ingreso
            </DropdownMenuItem>
            <DropdownMenuItem v-if="conHistorial" @select="emit('historial')">
                <History class="size-4" />
                Ver historial de cambios
                <span
                    class="ml-auto rounded-full bg-crema px-1.5 text-xs text-bronce tabular-nums"
                    >{{ a.correcciones }}</span
                >
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
    <span v-else class="sr-only">Sin acciones disponibles</span>
</template>
