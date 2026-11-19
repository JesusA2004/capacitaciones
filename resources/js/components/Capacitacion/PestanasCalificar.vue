<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ClipboardCheck, HelpCircle, Presentation, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { index as indexActividades } from '@/routes/calificaciones/actividades';
import { index as indexCuestionarios } from '@/routes/calificaciones/cuestionarios';
import { aula } from '@/routes/cursos';

/**
 * Cambia entre las dos bandejas de calificación (cuestionarios y
 * actividades) conservando el filtro por curso que llega desde el aula.
 */
const props = defineProps<{
    actual: 'cuestionarios' | 'actividades';
    total: number;
    curso: { id: number; titulo: string } | null;
}>();

const consulta = () =>
    props.curso ? { query: { curso: props.curso.id } } : {};
</script>

<template>
    <div class="flex flex-col gap-3">
        <nav
            class="flex w-fit max-w-full gap-1 overflow-x-auto rounded-2xl border border-border/60 bg-crema/50 p-1"
            aria-label="Bandejas de calificación"
        >
            <Link
                :href="indexCuestionarios(consulta())"
                class="flex shrink-0 presionable items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transicion-ui"
                :class="
                    actual === 'cuestionarios'
                        ? 'bg-card text-primary shadow-sm'
                        : 'text-muted-foreground hover:bg-card/60 hover:text-foreground'
                "
                :aria-current="actual === 'cuestionarios' ? 'page' : undefined"
            >
                <HelpCircle class="size-4" />
                Cuestionarios
                <span
                    v-if="actual === 'cuestionarios'"
                    class="rounded-full bg-warning-soft px-2 text-xs text-bronce tabular-nums"
                    >{{ total }}</span
                >
            </Link>
            <Link
                :href="indexActividades(consulta())"
                class="flex shrink-0 presionable items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transicion-ui"
                :class="
                    actual === 'actividades'
                        ? 'bg-card text-primary shadow-sm'
                        : 'text-muted-foreground hover:bg-card/60 hover:text-foreground'
                "
                :aria-current="actual === 'actividades' ? 'page' : undefined"
            >
                <ClipboardCheck class="size-4" />
                Actividades
                <span
                    v-if="actual === 'actividades'"
                    class="rounded-full bg-warning-soft px-2 text-xs text-bronce tabular-nums"
                    >{{ total }}</span
                >
            </Link>
        </nav>

        <div
            v-if="curso"
            class="flex entrada-suave flex-wrap items-center gap-2 rounded-2xl border border-border/60 bg-salvia/50 px-4 py-3 text-sm"
        >
            <span class="text-muted-foreground">Solo del curso</span>
            <Link
                :href="aula(curso.id)"
                class="inline-flex items-center gap-1.5 font-semibold text-primary hover:underline"
            >
                <Presentation class="size-4" />
                {{ curso.titulo }}
            </Link>
            <Button variant="ghost" size="sm" class="ml-auto" as-child>
                <Link
                    :href="
                        actual === 'cuestionarios'
                            ? indexCuestionarios()
                            : indexActividades()
                    "
                >
                    <X class="size-4" />
                    Ver de todos los cursos
                </Link>
            </Button>
        </div>
    </div>
</template>
