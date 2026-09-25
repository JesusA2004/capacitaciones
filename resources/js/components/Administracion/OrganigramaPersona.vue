<script setup lang="ts">
import { Building2, IdCard } from '@lucide/vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getInitials } from '@/composables/useInitials';
import type { OcupantePuesto } from '@/types';

/**
 * Persona dentro de una tarjeta del organigrama: miniatura + nombre. Al
 * pasar el mouse (o enfocar con teclado) muestra la foto en grande con su
 * sucursal y número de empleado — la "vista previa" para reconocer a
 * alguien sin abrir su expediente.
 */
withDefaults(
    defineProps<{
        persona: OcupantePuesto;
        /** Fila con nombre (true) o solo la cara, para pilas (false). */
        conNombre?: boolean;
        destacado?: boolean;
    }>(),
    { conNombre: true, destacado: false },
);
</script>

<template>
    <Tooltip :delay-duration="150">
        <TooltipTrigger as-child>
            <div
                tabindex="0"
                class="flex min-w-0 items-center gap-2.5 rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :class="
                    conNombre
                        ? 'p-1 pr-2 transition-colors hover:bg-muted/70'
                        : ''
                "
            >
                <ColaboradorAvatar
                    :nombre="persona.nombre"
                    :foto-url="persona.foto_url"
                    :tamano="destacado ? 'lg' : conNombre ? 'md' : 'sm'"
                    :class="destacado ? 'rounded-2xl' : ''"
                />
                <div v-if="conNombre" class="min-w-0 text-left">
                    <p
                        class="truncate leading-tight font-semibold"
                        :class="destacado ? 'text-[15px]' : 'text-sm'"
                    >
                        {{ persona.nombre }}
                    </p>
                    <p
                        v-if="persona.sucursal"
                        class="truncate text-xs text-muted-foreground"
                    >
                        {{ persona.sucursal }}
                    </p>
                </div>
            </div>
        </TooltipTrigger>
        <TooltipContent
            side="right"
            :side-offset="10"
            class="w-60 overflow-hidden rounded-2xl border bg-popover p-0 text-popover-foreground shadow-2xl"
        >
            <div class="aspect-square w-full bg-muted">
                <img
                    v-if="persona.foto_url"
                    :src="persona.foto_url"
                    :alt="persona.nombre"
                    class="size-full object-cover"
                />
                <div
                    v-else
                    class="flex size-full items-center justify-center bg-gradient-to-br from-primary/15 to-primary/5 text-5xl font-semibold text-primary"
                >
                    {{ getInitials(persona.nombre) }}
                </div>
            </div>
            <div class="space-y-1 p-3">
                <p class="text-sm leading-tight font-semibold">
                    {{ persona.nombre }}
                </p>
                <p
                    v-if="persona.sucursal"
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <Building2 class="size-3.5" /> {{ persona.sucursal }}
                </p>
                <p
                    v-if="persona.numero_empleado"
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <IdCard class="size-3.5" /> No.
                    {{ persona.numero_empleado }}
                </p>
                <p
                    v-if="!persona.foto_url"
                    class="pt-1 text-[11px] text-muted-foreground italic"
                >
                    Aún no tiene foto en su expediente.
                </p>
            </div>
        </TooltipContent>
    </Tooltip>
</template>
