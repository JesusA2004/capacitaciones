<script setup lang="ts">
import {
    Briefcase,
    Building,
    Building2,
    History,
    Landmark,
    Pencil,
    Shuffle,
    TrendingUp,
    UserCog,
    UserMinus,
    UserPlus,
} from '@lucide/vue';
import type { Component } from 'vue';
import ProximamenteTab from '@/components/Rh/ProximamenteTab.vue';
import { Badge } from '@/components/ui/badge';
import type { MovimientoLaboralItem, MovimientoLaboralTipo } from '@/types';

defineProps<{
    movimientos: MovimientoLaboralItem[];
}>();

const ICONOS: Record<MovimientoLaboralTipo, Component> = {
    alta: UserPlus,
    baja: UserMinus,
    promocion: TrendingUp,
    cambio_puesto: Briefcase,
    cambio_sucursal: Building2,
    cambio_departamento: Building,
    cambio_jefe: UserCog,
    cambio_empresa: Landmark,
    cobertura_temporal: Shuffle,
    reingreso: UserPlus,
    ajuste_manual: Pencil,
};

const COLORES: Record<MovimientoLaboralTipo, string> = {
    alta: 'bg-success/15 text-success',
    reingreso: 'bg-success/15 text-success',
    baja: 'bg-destructive/15 text-destructive',
    promocion: 'bg-primary/15 text-primary',
    cambio_puesto: 'bg-accent text-accent-foreground',
    cambio_sucursal: 'bg-accent text-accent-foreground',
    cambio_departamento: 'bg-accent text-accent-foreground',
    cambio_jefe: 'bg-accent text-accent-foreground',
    cambio_empresa: 'bg-accent text-accent-foreground',
    cobertura_temporal: 'bg-warning/15 text-warning',
    ajuste_manual: 'bg-muted text-muted-foreground',
};

function formatearFecha(fecha: string): string {
    return new Date(fecha).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <ProximamenteTab
        v-if="!movimientos.length"
        :icono="History"
        titulo="Sin movimientos registrados"
        descripcion="Aquí aparecerán altas, cambios de puesto/sucursal/departamento/jefe, promociones, bajas y coberturas de este colaborador."
    />

    <ol v-else class="relative flex flex-col gap-6 pl-2">
        <li
            v-for="movimiento in movimientos"
            :key="movimiento.id"
            class="relative flex gap-4 pl-8"
        >
            <span
                class="absolute top-1 left-0 flex size-7 items-center justify-center rounded-full ring-4 ring-background"
                :class="COLORES[movimiento.tipo_movimiento]"
            >
                <component
                    :is="ICONOS[movimiento.tipo_movimiento]"
                    class="size-3.5"
                />
            </span>
            <span
                class="absolute top-8 bottom-[-1.5rem] left-[13px] w-px bg-border last:hidden"
            />

            <div
                class="flex-1 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all duration-200 hover:border-primary/40 hover:shadow-md"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Badge variant="outline" class="text-xs font-medium">{{
                        formatearFecha(movimiento.fecha_movimiento)
                    }}</Badge>
                    <span
                        v-if="movimiento.registrado_por"
                        class="text-xs text-muted-foreground"
                    >
                        Registrado por
                        {{ movimiento.registrado_por.name }}
                        {{ movimiento.registrado_por.apellidos ?? '' }}
                    </span>
                </div>

                <p class="mt-2 text-sm leading-relaxed">
                    {{ movimiento.descripcion }}
                </p>

                <p
                    v-if="movimiento.observaciones"
                    class="mt-1 text-xs whitespace-pre-line text-muted-foreground"
                >
                    {{ movimiento.observaciones }}
                </p>

                <div
                    v-if="movimiento.vacante || movimiento.documento"
                    class="mt-2 flex flex-wrap gap-1.5"
                >
                    <Badge
                        v-if="movimiento.vacante"
                        variant="secondary"
                        class="text-xs"
                    >
                        Vacante: {{ movimiento.vacante.puesto?.nombre ?? '—' }}
                    </Badge>
                    <Badge
                        v-if="movimiento.documento"
                        variant="secondary"
                        class="text-xs"
                    >
                        Documento: {{ movimiento.documento.original_name }}
                    </Badge>
                </div>
            </div>
        </li>
    </ol>
</template>
