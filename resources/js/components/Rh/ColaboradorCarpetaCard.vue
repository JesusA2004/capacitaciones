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
    <Link
        :href="show(colaborador.id)"
        class="group flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg"
    >
        <div class="flex items-start gap-3">
            <span
                class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-primary/10 text-primary"
            >
                <img
                    v-if="colaborador.foto_path"
                    :src="colaborador.foto_path"
                    alt=""
                    class="size-full object-cover"
                />
                <User v-else class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold">
                    {{ colaborador.name }} {{ colaborador.apellidos }}
                </p>
                <p class="truncate text-xs text-muted-foreground">
                    {{
                        colaborador.numero_empleado ?? 'Sin número de empleado'
                    }}
                </p>
            </div>
            <EstadoBadge :estado="colaborador.estatus" />
        </div>

        <div class="flex flex-col gap-1 text-xs text-muted-foreground">
            <span v-if="colaborador.puesto" class="flex items-center gap-1.5">
                <Briefcase class="size-3.5 shrink-0" />
                {{ colaborador.puesto.nombre }}
            </span>
            <span v-if="colaborador.sucursal" class="flex items-center gap-1.5">
                <MapPinned class="size-3.5 shrink-0" />
                {{ colaborador.sucursal.nombre }}
            </span>
            <span v-if="colaborador.empresa" class="flex items-center gap-1.5">
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
    </Link>
</template>
