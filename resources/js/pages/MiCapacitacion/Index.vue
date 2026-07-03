<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { GraduationCap } from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/mi-capacitacion';
import type { InscripcionResumen } from '@/types';

defineProps<{
    inscripciones: InscripcionResumen[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi capacitación', href: index.url() },
        ],
    },
});

function variantePorEstado(estado: string): 'default' | 'secondary' {
    return estado === 'completada' ? 'default' : 'secondary';
}
</script>

<template>
    <Head title="Mi capacitación" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Mi capacitación"
            description="Cursos y capacitaciones asignados a ti"
        />

        <EmptyState
            v-if="inscripciones.length === 0"
            :icono="GraduationCap"
            titulo="Sin capacitaciones asignadas"
            descripcion="Cuando se te asigne un curso, aparecerá aquí."
        />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="inscripcion in inscripciones"
                :key="inscripcion.id"
                :href="show(inscripcion.curso.id)"
            >
                <Card class="h-full transition-shadow hover:shadow-md">
                    <CardHeader>
                        <div class="flex items-start justify-between gap-2">
                            <CardTitle class="text-base">{{
                                inscripcion.curso.titulo
                            }}</CardTitle>
                            <Badge
                                :variant="variantePorEstado(inscripcion.estado)"
                                >{{ inscripcion.estado }}</Badge
                            >
                        </div>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        <p v-if="inscripcion.curso.duracion_estimada_minutos">
                            Duración estimada:
                            {{ inscripcion.curso.duracion_estimada_minutos }}
                            minutos
                        </p>
                        <p v-if="inscripcion.curso.genera_constancia">
                            Genera constancia al completarse
                        </p>
                    </CardContent>
                </Card>
            </Link>
        </div>
    </div>
</template>
