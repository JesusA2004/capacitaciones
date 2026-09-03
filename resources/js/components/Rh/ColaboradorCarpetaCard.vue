<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Briefcase,
    Building2,
    FileWarning,
    MapPinned,
    User,
} from '@lucide/vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import { Progress } from '@/components/ui/progress';
import { show } from '@/routes/rh/expedientes';
import type { ColaboradorExpedienteItem } from '@/types';

defineProps<{
    colaborador: ColaboradorExpedienteItem;
}>();
</script>

<template>
    <Link :href="show(colaborador.id)" class="group relative block pt-3">
        <!-- Hojas apiladas detrás de la carpeta: da la sensación de que
             adentro hay documentos, como en un explorador de archivos. -->
        <span
            class="absolute inset-x-3 top-4 h-full rounded-2xl bg-border/40 transition-transform duration-200 group-hover:translate-y-0.5"
            aria-hidden="true"
        />
        <span
            class="absolute inset-x-1.5 top-2 h-full rounded-2xl bg-border/60 transition-transform duration-200 group-hover:translate-y-0.5"
            aria-hidden="true"
        />

        <!-- Pestaña de la carpeta (como un ícono de carpeta de Windows/Linux) -->
        <span
            class="absolute top-0 left-5 h-3 w-16 rounded-t-lg bg-[var(--brand-primary)]/25 transition-colors duration-200 group-hover:bg-[var(--brand-primary)]/40"
            aria-hidden="true"
        />

        <!-- Cuerpo de la carpeta -->
        <div
            class="relative flex flex-col gap-3 rounded-tr-2xl rounded-b-2xl border border-[var(--brand-primary)]/15 bg-gradient-to-b from-[var(--brand-primary)]/[0.06] to-card p-4 pt-7 shadow-sm transition-all duration-200 group-hover:-translate-y-1 group-hover:border-[var(--brand-primary)]/40 group-hover:shadow-xl"
        >
            <!-- Foto del colaborador: "clipeada" en la esquina de la carpeta,
                 como una foto sujeta con un clip a un folder físico. -->
            <span
                class="absolute -top-3 right-4 flex size-12 rotate-3 items-center justify-center overflow-hidden rounded-xl border-4 border-card bg-primary/10 text-primary shadow-md ring-1 ring-border/60 transition-transform duration-200 group-hover:rotate-0"
            >
                <img
                    v-if="colaborador.foto_url"
                    :src="colaborador.foto_url"
                    alt=""
                    class="size-full object-cover"
                />
                <User v-else class="size-5" />
            </span>

            <div class="flex items-start gap-2 pr-12">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">
                        {{ colaborador.name }} {{ colaborador.apellidos }}
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{
                            colaborador.numero_empleado ??
                            'Sin número de empleado'
                        }}
                    </p>
                </div>
            </div>

            <EstadoBadge :estado="colaborador.estatus" class="w-fit" />

            <div class="flex flex-col gap-1 text-xs text-muted-foreground">
                <span
                    v-if="colaborador.puesto"
                    class="flex items-center gap-1.5"
                >
                    <Briefcase class="size-3.5 shrink-0" />
                    {{ colaborador.puesto.nombre }}
                </span>
                <span
                    v-if="colaborador.sucursal"
                    class="flex items-center gap-1.5"
                >
                    <MapPinned class="size-3.5 shrink-0" />
                    {{ colaborador.sucursal.nombre }}
                </span>
                <span
                    v-if="colaborador.empresa"
                    class="flex items-center gap-1.5"
                >
                    <Building2 class="size-3.5 shrink-0" />
                    {{ colaborador.empresa.nombre }}
                </span>
            </div>

            <div class="mt-auto flex flex-col gap-1.5">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">Expediente</span>
                    <span class="font-semibold tabular-nums"
                        >{{ colaborador.expediente_porcentaje }}%</span
                    >
                </div>
                <Progress
                    :model-value="colaborador.expediente_porcentaje"
                    class="h-1.5"
                />
                <span
                    v-if="colaborador.documentos_pendientes > 0"
                    class="flex items-center gap-1.5 text-xs text-warning"
                >
                    <FileWarning class="size-3.5 shrink-0" />
                    {{ colaborador.documentos_pendientes }} documento(s)
                    pendiente(s)
                </span>
            </div>
        </div>
    </Link>
</template>
