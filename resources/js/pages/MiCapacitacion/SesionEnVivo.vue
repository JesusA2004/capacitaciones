<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index } from '@/routes/mi-capacitacion';
import type { AsistenciaItem, LeccionItem, SesionEnVivoItem } from '@/types';

const props = defineProps<{
    leccion: LeccionItem;
    sesion: SesionEnVivoItem;
    miAsistencia: AsistenciaItem | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi capacitación', href: index.url() },
            { title: 'Sesión en vivo', href: '#' },
        ],
    },
});

const etiquetaEstado: Record<string, string> = {
    pendiente: 'Aún no registrada',
    presente: 'Presente',
    ausente: 'Ausente',
    tarde: 'Llegó tarde',
};

const fecha = new Date(props.sesion.fecha_inicio).toLocaleString('es-MX', {
    dateStyle: 'long',
    timeStyle: 'short',
});
</script>

<template>
    <Head :title="sesion.titulo" />

    <div class="flex flex-col gap-6 p-4">
        <Heading :title="sesion.titulo" :description="leccion.titulo" />

        <div class="rounded-lg border p-4">
            <p class="text-sm text-muted-foreground">{{ fecha }}</p>
            <p v-if="sesion.descripcion" class="mt-2 text-sm">
                {{ sesion.descripcion }}
            </p>

            <Button v-if="sesion.enlace_reunion" as-child class="mt-4">
                <a
                    :href="sesion.enlace_reunion"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Unirse a la sesión
                </a>
            </Button>
            <p v-else class="mt-4 text-sm text-muted-foreground">
                El enlace de la reunión todavía no está disponible.
            </p>
        </div>

        <div class="rounded-lg border p-4">
            <p class="text-sm font-medium">Tu asistencia</p>
            <Badge class="mt-2" variant="secondary">
                {{
                    etiquetaEstado[miAsistencia?.estado ?? 'pendiente'] ??
                    'Aún no registrada'
                }}
            </Badge>
            <p class="mt-2 text-sm text-muted-foreground">
                El instructor registra la asistencia durante o después de la
                sesión; esta lección se completa automáticamente al quedar
                marcada como presente.
            </p>
        </div>
    </div>
</template>
