<script setup lang="ts">
import { ArrowDown, ArrowUp, Copy, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * Tarjeta de pregunta del editor (como Google Forms): se muestra resumida y,
 * al hacer clic, se vuelve editable con su barra de acciones (subir, bajar,
 * duplicar, eliminar).
 */
withDefaults(
    defineProps<{
        activa: boolean;
        etiqueta: string;
        soloLectura?: boolean;
        puedeSubir?: boolean;
        puedeBajar?: boolean;
        puedeEliminar?: boolean;
        puedeDuplicar?: boolean;
    }>(),
    {
        soloLectura: false,
        puedeSubir: true,
        puedeBajar: true,
        puedeEliminar: true,
        puedeDuplicar: true,
    },
);

const emit = defineEmits<{
    activar: [];
    subir: [];
    bajar: [];
    duplicar: [];
    eliminar: [];
}>();
</script>

<template>
    <article
        :class="
            cn(
                'group relative rounded-2xl border bg-card shadow-xs transition-all',
                activa
                    ? 'border-[color-mix(in_oklab,var(--acento)_45%,transparent)] shadow-md'
                    : 'tarjeta-interactiva border-border',
            )
        "
        @click="!activa && emit('activar')"
    >
        <span
            v-if="activa"
            class="absolute inset-y-3 left-0 w-1.5 rounded-r-full bg-[var(--acento)]"
            aria-hidden="true"
        />
        <div class="flex flex-col gap-3 p-4 pl-5">
            <p
                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ etiqueta }}
            </p>
            <slot v-if="activa" />
            <slot v-else name="resumen" />
        </div>
        <footer
            v-if="activa && !soloLectura"
            class="flex flex-wrap items-center justify-end gap-1 border-t border-border/70 px-3 py-2"
        >
            <slot name="pie" />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                title="Subir"
                aria-label="Subir"
                :disabled="!puedeSubir"
                @click.stop="emit('subir')"
            >
                <ArrowUp class="size-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                title="Bajar"
                aria-label="Bajar"
                :disabled="!puedeBajar"
                @click.stop="emit('bajar')"
            >
                <ArrowDown class="size-4" />
            </Button>
            <Button
                v-if="puedeDuplicar"
                type="button"
                variant="ghost"
                size="icon"
                title="Duplicar"
                aria-label="Duplicar"
                @click.stop="emit('duplicar')"
            >
                <Copy class="size-4" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                title="Eliminar"
                aria-label="Eliminar"
                class="text-destructive hover:text-destructive"
                :disabled="!puedeEliminar"
                @click.stop="emit('eliminar')"
            >
                <Trash2 class="size-4" />
            </Button>
        </footer>
    </article>
</template>
