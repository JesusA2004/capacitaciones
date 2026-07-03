<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import {
    calificar,
    descargar,
    index,
} from '@/routes/calificaciones/actividades';
import type { EntregaDetalleCalificacion } from '@/types';

const props = defineProps<{
    entrega: EntregaDetalleCalificacion;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Calificar actividades', href: index.url() },
            { title: 'Entrega', href: '#' },
        ],
    },
});

const { mostrarExito, mostrarError } = useAlertas();

const calificacion = ref<number | undefined>(
    props.entrega.calificacion ?? undefined,
);
const retroalimentacion = ref(props.entrega.retroalimentacion ?? '');

function enviarCalificacion(aprobada: boolean) {
    router.post(
        calificar.url(props.entrega.id),
        {
            aprobada,
            calificacion: calificacion.value,
            retroalimentacion: retroalimentacion.value || undefined,
        },
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Entrega calificada correctamente.'),
            onError: () => mostrarError('No fue posible calificar la entrega.'),
        },
    );
}
</script>

<template>
    <Head title="Calificar entrega" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="entrega.actividad.titulo"
            :description="`${entrega.usuario.name} ${entrega.usuario.apellidos ?? ''} — Entrega #${entrega.version}`"
        />

        <div class="rounded-lg border p-4">
            <p
                v-if="entrega.contenido_texto"
                class="text-sm whitespace-pre-line"
            >
                {{ entrega.contenido_texto }}
            </p>
            <a
                v-if="entrega.url"
                :href="entrega.url"
                target="_blank"
                rel="noopener noreferrer"
                class="text-sm text-primary underline"
            >
                Abrir enlace entregado
            </a>
            <a
                v-if="entrega.recursoMultimedia"
                :href="descargar.url(entrega.id)"
                class="text-sm text-primary underline"
            >
                Descargar {{ entrega.recursoMultimedia.nombre_original }}
            </a>
        </div>

        <div class="grid gap-4 rounded-lg border p-4">
            <div class="grid gap-2">
                <Label for="calificacion">Calificación (%)</Label>
                <Input
                    id="calificacion"
                    v-model.number="calificacion"
                    type="number"
                    min="0"
                    max="100"
                    class="w-40"
                />
            </div>

            <div class="grid gap-2">
                <Label for="retroalimentacion">Retroalimentación</Label>
                <Textarea
                    id="retroalimentacion"
                    v-model="retroalimentacion"
                    rows="3"
                />
            </div>

            <div class="flex gap-2">
                <Button @click="enviarCalificacion(true)">Aprobar</Button>
                <Button variant="outline" @click="enviarCalificacion(false)"
                    >Rechazar</Button
                >
            </div>
        </div>
    </div>
</template>
