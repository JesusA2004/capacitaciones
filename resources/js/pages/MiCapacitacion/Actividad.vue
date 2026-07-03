<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { index } from '@/routes/mi-capacitacion';
import { store } from '@/routes/mi-capacitacion/lecciones/actividad';
import type { ActividadItem, EntregaActividadItem, LeccionItem } from '@/types';

const props = defineProps<{
    leccion: LeccionItem;
    actividad: ActividadItem;
    entregas: EntregaActividadItem[];
    puedeEntregar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi capacitación', href: index.url() },
            { title: 'Actividad', href: '#' },
        ],
    },
});

const { mostrarExito, mostrarError } = useAlertas();

const form = useForm({
    contenido_texto: '',
    url: '',
    archivo: null as File | null,
});

const etiquetaEstado: Record<string, string> = {
    entregada: 'Pendiente de revisión',
    aprobada: 'Aprobada',
    rechazada: 'Rechazada',
};

function seleccionarArchivo(evento: Event) {
    const objetivo = evento.target as HTMLInputElement;
    form.archivo = objetivo.files?.[0] ?? null;
}

function enviar() {
    form.post(store.url(props.leccion.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            mostrarExito('Entrega registrada correctamente.');
        },
        onError: () => mostrarError('No fue posible registrar la entrega.'),
    });
}
</script>

<template>
    <Head :title="actividad.titulo" />

    <div class="flex flex-col gap-6 p-4">
        <Heading :title="actividad.titulo" :description="leccion.titulo" />

        <p v-if="actividad.instrucciones" class="text-sm text-muted-foreground">
            {{ actividad.instrucciones }}
        </p>

        <div v-if="entregas.length" class="flex flex-col gap-3">
            <div
                v-for="entrega in entregas"
                :key="entrega.id"
                class="rounded-lg border p-4"
            >
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium"
                        >Entrega #{{ entrega.version }}</span
                    >
                    <Badge
                        :variant="
                            entrega.estado === 'aprobada'
                                ? 'default'
                                : entrega.estado === 'rechazada'
                                  ? 'outline'
                                  : 'secondary'
                        "
                    >
                        {{ etiquetaEstado[entrega.estado] ?? entrega.estado }}
                    </Badge>
                </div>
                <p v-if="entrega.contenido_texto" class="mt-2 text-sm">
                    {{ entrega.contenido_texto }}
                </p>
                <a
                    v-if="entrega.url"
                    :href="entrega.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-2 block text-sm text-primary underline"
                >
                    Abrir enlace entregado
                </a>
                <p
                    v-if="entrega.retroalimentacion"
                    class="mt-2 rounded-md bg-muted/50 p-2 text-sm"
                >
                    {{ entrega.retroalimentacion }}
                </p>
            </div>
        </div>

        <form
            v-if="puedeEntregar"
            class="grid gap-4 rounded-lg border p-4"
            @submit.prevent="enviar"
        >
            <h2 class="text-sm font-semibold">Nueva entrega</h2>

            <div v-if="actividad.tipo_entrega === 'texto'" class="grid gap-2">
                <Label for="contenido_texto">Respuesta</Label>
                <Textarea
                    id="contenido_texto"
                    v-model="form.contenido_texto"
                    rows="5"
                />
            </div>

            <div
                v-else-if="actividad.tipo_entrega === 'enlace'"
                class="grid gap-2"
            >
                <Label for="url">Enlace</Label>
                <Input id="url" v-model="form.url" placeholder="https://" />
            </div>

            <div v-else class="grid gap-2">
                <Label for="archivo">Archivo</Label>
                <input
                    id="archivo"
                    type="file"
                    class="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-primary-foreground"
                    @change="seleccionarArchivo"
                />
            </div>

            <Button
                type="submit"
                class="self-start"
                :disabled="form.processing"
            >
                Enviar entrega
            </Button>
        </form>

        <p v-else class="text-sm text-muted-foreground">
            Ya tienes una entrega en revisión o aprobada para esta actividad.
        </p>
    </div>
</template>
