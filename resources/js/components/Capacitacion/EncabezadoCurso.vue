<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BookOpen, Presentation, Send } from '@lucide/vue';
import PortadaCurso from '@/components/Capacitacion/PortadaCurso.vue';
import { Badge } from '@/components/ui/badge';
import { usePermisos } from '@/composables/usePermisos';
import { index as indexAsignaciones } from '@/routes/asignaciones';
import { aula, edit } from '@/routes/cursos';

/**
 * Encabezado común del aula y del constructor de un curso: portada, estado,
 * título y una barra para saltar entre «Aula», «Contenido» y sus
 * asignaciones. Las acciones propias de cada pantalla van en el slot.
 */
const props = defineProps<{
    curso: {
        id: number;
        titulo: string;
        estado: string;
        estado_etiqueta: string;
        responsable?: string | null;
        descripcion?: string | null;
    };
    actual: 'aula' | 'contenido';
}>();

const { tienePermiso } = usePermisos();

const CLASE_ESTADO: Record<string, string> = {
    publicado: 'bg-success-soft text-primary',
    borrador: 'bg-warning-soft text-bronce',
    archivado: 'bg-muted text-muted-foreground',
};

const pestanas = [
    {
        clave: 'aula',
        texto: 'Aula',
        icono: Presentation,
        href: aula(props.curso.id),
    },
    {
        clave: 'contenido',
        texto: 'Contenido',
        icono: BookOpen,
        href: edit(props.curso.id),
    },
] as const;
</script>

<template>
    <section
        class="entrada-suave overflow-hidden rounded-3xl border border-border/60 bg-card shadow-sm"
    >
        <PortadaCurso
            :curso-id="curso.id"
            :titulo="curso.titulo"
            alto="h-36 sm:h-44"
        >
            <div
                class="absolute inset-x-0 bottom-0 flex flex-col gap-3 p-5 sm:flex-row sm:items-end sm:justify-between sm:p-6"
            >
                <div class="min-w-0">
                    <Badge
                        variant="secondary"
                        class="mb-2 shadow-sm"
                        :class="CLASE_ESTADO[curso.estado] ?? 'bg-card/80'"
                        >{{ curso.estado_etiqueta }}</Badge
                    >
                    <h1
                        class="text-2xl font-bold text-balance text-foreground sm:text-3xl"
                    >
                        {{ curso.titulo }}
                    </h1>
                    <p
                        v-if="curso.responsable"
                        class="text-sm text-foreground/70"
                    >
                        Responsable: {{ curso.responsable }}
                    </p>
                </div>
                <div
                    v-if="$slots.default"
                    class="flex shrink-0 flex-wrap gap-2"
                >
                    <slot />
                </div>
            </div>
        </PortadaCurso>

        <div
            class="flex flex-col gap-3 border-t border-border/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6"
        >
            <nav
                class="flex gap-1 overflow-x-auto"
                aria-label="Secciones del curso"
            >
                <Link
                    v-for="p in pestanas"
                    :key="p.clave"
                    :href="p.href"
                    class="flex shrink-0 presionable items-center gap-2 rounded-xl px-3 py-1.5 text-sm font-medium transicion-ui"
                    :class="
                        actual === p.clave
                            ? 'bg-salvia text-primary'
                            : 'text-muted-foreground hover:bg-crema/60 hover:text-foreground'
                    "
                    :aria-current="actual === p.clave ? 'page' : undefined"
                >
                    <component :is="p.icono" class="size-4" />
                    {{ p.texto }}
                </Link>
                <Link
                    v-if="tienePermiso('asignaciones.ver')"
                    :href="indexAsignaciones({ query: { curso: curso.id } })"
                    class="flex shrink-0 presionable items-center gap-2 rounded-xl px-3 py-1.5 text-sm font-medium text-muted-foreground transicion-ui hover:bg-crema/60 hover:text-foreground"
                >
                    <Send class="size-4" />
                    Asignaciones
                </Link>
            </nav>
            <p
                v-if="curso.descripcion"
                class="line-clamp-2 text-sm text-muted-foreground sm:max-w-[60%] sm:text-right"
            >
                {{ curso.descripcion }}
            </p>
        </div>
    </section>
</template>
