<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Image, Trash2, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/cumpleanos';
import { actualizar, eliminar as eliminarFondo } from '@/routes/rh/cumpleanos/configuracion/fondo';

const props = defineProps<{
    tieneFondo: boolean;
    fondoUrl: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cumpleaños', href: index.url() },
            { title: 'Fondo de tarjeta', href: '' },
        ],
    },
});

const { mostrarExito, mostrarError, confirmarEliminacion } = useAlertas();

const form = useForm({ fondo: null as File | null });
const previsualizacion = ref<string | null>(null);
const eliminando = ref(false);

const imagenMostrada = computed(() => previsualizacion.value ?? props.fondoUrl);

function elegirArchivo(evento: Event) {
    const input = evento.target as HTMLInputElement;
    const archivo = input.files?.[0] ?? null;
    form.fondo = archivo;

    if (previsualizacion.value) {
        URL.revokeObjectURL(previsualizacion.value);
    }

    previsualizacion.value = archivo ? URL.createObjectURL(archivo) : null;
}

function guardar() {
    if (!form.fondo) {
        return;
    }

    form.post(actualizar.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            mostrarExito('Fondo actualizado.');
            form.reset();
            previsualizacion.value = null;
        },
        onError: () => mostrarError('No se pudo actualizar el fondo.'),
    });
}

async function eliminar() {
    const confirmado = await confirmarEliminacion('el fondo personalizado');

    if (!confirmado) {
        return;
    }

    eliminando.value = true;
    router.delete(eliminarFondo.url(), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('Se volvió al diseño por defecto.'),
        onFinish: () => (eliminando.value = false),
    });
}
</script>

<template>
    <Head title="Fondo de tarjeta de cumpleaños" />

    <div class="flex w-full flex-col gap-6 p-4 sm:px-6 lg:px-8">
        <Link
            :href="index.url()"
            class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" /> Volver al calendario
        </Link>

        <Heading
            title="Fondo de tarjeta de cumpleaños"
            description="La imagen que se usa como base de todas las felicitaciones generadas. Si no subes una, se usa el diseño con globos por defecto."
        />

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Fondo actual</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,22rem)_1fr]">
                    <div
                        class="flex aspect-[4/5] w-full items-center justify-center overflow-hidden rounded-xl border border-dashed bg-muted/30"
                    >
                        <img
                            v-if="imagenMostrada"
                            :src="imagenMostrada"
                            alt="Fondo de la tarjeta"
                            class="size-full object-cover"
                        />
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground"
                        >
                            <Image class="size-8" />
                            Usando el diseño por defecto (color + globos).
                        </div>
                    </div>

                    <div class="flex flex-col gap-4">
                        <div
                            class="rounded-xl border border-border/60 bg-muted/20 p-4 text-sm"
                        >
                            <p class="font-medium">
                                {{
                                    tieneFondo
                                        ? 'Tienes un fondo personalizado activo.'
                                        : 'Todavía no has subido ningún fondo.'
                                }}
                            </p>
                            <p class="mt-1 text-muted-foreground">
                                {{
                                    tieneFondo
                                        ? 'Se usa tal cual en todas las tarjetas nuevas. Puedes reemplazarlo o quitarlo cuando quieras.'
                                        : 'Mientras no subas uno, las tarjetas usan el diseño con globos por defecto.'
                                }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <label
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm hover:bg-accent"
                            >
                                <Upload class="size-4" />
                                {{ tieneFondo ? 'Elegir otra imagen' : 'Elegir imagen' }}
                                <input
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    class="hidden"
                                    @change="elegirArchivo"
                                />
                            </label>

                            <Button
                                :disabled="!form.fondo || form.processing"
                                @click="guardar"
                            >
                                <Spinner v-if="form.processing" />
                                Guardar fondo
                            </Button>

                            <Button
                                v-if="tieneFondo"
                                variant="outline"
                                :disabled="eliminando"
                                @click="eliminar"
                            >
                                <Spinner v-if="eliminando" />
                                <Trash2 v-else class="size-4 text-destructive" />
                                Quitar fondo personalizado
                            </Button>
                        </div>

                        <p class="text-xs text-muted-foreground">
                            Recomendado: imagen vertical (ej. 1080×1350), PNG
                            o JPG, máximo 8&nbsp;MB. El nombre y la frase se
                            siguen colocando automáticamente encima, en las
                            mismas zonas de siempre.
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
