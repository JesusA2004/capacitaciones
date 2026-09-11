<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, Copy, Download, RefreshCw, Send } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { useInitials } from '@/composables/useInitials';
import { mensajeFelicitacion } from '@/lib/cumpleanos';
import { postBlobUrl } from '@/lib/http';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/cumpleanos';
import {
    confirmarFrase as confirmarFraseRoute,
    descargar as descargarFelicitacion,
    enviar as enviarFelicitacion,
    previsualizar,
    regenerar as regenerarFelicitacion,
} from '@/routes/rh/cumpleanos/felicitacion';

const props = defineProps<{
    colaborador: {
        id: number;
        nombre: string;
        sucursal: string | null;
        foto_url: string | null;
    };
    greeting: {
        id: number;
        fecha: string;
        frase: string;
        enviadaAt: string | null;
        tieneImagen: boolean;
        imagenUrl: string;
    };
    opciones: {
        frases: { id: number; texto: string }[];
    };
    permisos: {
        descargarImagen: boolean;
        gestionarNotificaciones: boolean;
    };
}>();

// `layout` recibe una funcion (no un objeto estatico) porque
// `defineOptions()` se compila fuera del scope de setup() y no puede
// referenciar variables locales como `props`; Inertia la invoca con las
// props actuales de la pagina en cada render (ver @inertiajs/vue3).
defineOptions({
    layout: (pageProps: { colaborador: { nombre: string } }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cumpleaños', href: index.url() },
            { title: pageProps.colaborador.nombre, href: '' },
        ],
    }),
});

const { mostrarExito, mostrarError, confirmarRegeneracion } = useAlertas();
const { getInitials } = useInitials();
const regenerando = ref(false);
const enviando = ref(false);

const estado = computed(() => {
    if (props.greeting.enviadaAt) {
        return { texto: 'Enviada', tono: 'border-[var(--success)]/40 text-[var(--success)]' };
    }

    if (props.greeting.tieneImagen) {
        return { texto: 'Generada · pendiente de envío', tono: 'border-amber-500/40 text-amber-600 dark:text-amber-400' };
    }

    return { texto: 'Pendiente de generar', tono: 'text-muted-foreground' };
});

// --- Selector de frase + vista previa en vivo (sin guardar hasta Confirmar) ---
const fraseSeleccionadaId = ref('');
const previsualizando = ref(false);
const confirmando = ref(false);
const imagenPreview = ref<string | null>(null);

const hayCambioPendiente = computed(() => imagenPreview.value !== null);
const imagenMostrada = computed(() => imagenPreview.value ?? props.greeting.imagenUrl);

function liberarPreview() {
    if (imagenPreview.value) {
        URL.revokeObjectURL(imagenPreview.value);
        imagenPreview.value = null;
    }
}

async function alElegirFrase(valor: unknown) {
    const id = String(valor ?? '');
    fraseSeleccionadaId.value = id;

    const frase = props.opciones.frases.find((f) => String(f.id) === id);

    if (!frase) {
        liberarPreview();

        return;
    }

    previsualizando.value = true;

    try {
        const url = await postBlobUrl(previsualizar.url(props.colaborador.id), {
            frase: frase.texto,
        });
        liberarPreview();
        imagenPreview.value = url;
    } catch {
        mostrarError('No se pudo generar la vista previa.');
    } finally {
        previsualizando.value = false;
    }
}

function cancelarCambio() {
    fraseSeleccionadaId.value = '';
    liberarPreview();
}

function confirmarFraseElegida() {
    const frase = props.opciones.frases.find((f) => String(f.id) === fraseSeleccionadaId.value);

    if (!frase) {
        return;
    }

    confirmando.value = true;
    router.post(
        confirmarFraseRoute.url(props.colaborador.id),
        { frase: frase.texto, birthday_phrase_id: frase.id },
        {
            preserveScroll: true,
            onSuccess: () => {
                mostrarExito('Frase confirmada.');
                fraseSeleccionadaId.value = '';
                liberarPreview();
            },
            onError: () => mostrarError('No se pudo confirmar la frase.'),
            onFinish: () => (confirmando.value = false),
        },
    );
}

onBeforeUnmount(liberarPreview);

async function regenerar() {
    const confirmado = await confirmarRegeneracion('la tarjeta actual');

    if (!confirmado) {
        return;
    }

    regenerando.value = true;
    cancelarCambio();
    router.post(
        regenerarFelicitacion.url(props.colaborador.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => (regenerando.value = false),
        },
    );
}

async function enviarManual() {
    enviando.value = true;
    router.post(
        enviarFelicitacion.url(props.colaborador.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Felicitación enviada.'),
            onError: () => mostrarError('No se pudo enviar la felicitación.'),
            onFinish: () => (enviando.value = false),
        },
    );
}

async function copiarMensaje() {
    try {
        await navigator.clipboard.writeText(
            mensajeFelicitacion(props.colaborador.nombre),
        );
        mostrarExito('Mensaje copiado al portapapeles.');
    } catch {
        mostrarError('No se pudo copiar el mensaje.');
    }
}
</script>

<template>
    <Head :title="`Felicitación — ${colaborador.nombre}`" />

    <div class="mx-auto flex max-w-screen-2xl flex-col p-4 sm:px-6 lg:px-8">
    <Link
        :href="index.url()"
        class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
    >
        <ArrowLeft class="size-4" /> Volver al calendario
    </Link>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card class="overflow-hidden">
            <CardContent class="flex items-center justify-center bg-muted/20 p-4">
                <div v-if="greeting.tieneImagen || imagenMostrada" class="relative w-full">
                    <img
                        :src="imagenMostrada"
                        :alt="`Felicitación de ${colaborador.nombre}`"
                        class="max-h-[640px] w-full rounded-lg object-contain shadow-md"
                    />
                    <Badge
                        v-if="hayCambioPendiente"
                        variant="secondary"
                        class="absolute top-2 right-2 shadow"
                    >
                        Vista previa sin guardar
                    </Badge>
                    <div
                        v-if="previsualizando"
                        class="absolute inset-0 flex items-center justify-center rounded-lg bg-background/60"
                    >
                        <Spinner />
                    </div>
                </div>
                <div v-else class="flex flex-col items-center gap-2 py-16 text-sm text-muted-foreground">
                    <Spinner />
                    Generando la tarjeta...
                </div>
            </CardContent>
        </Card>

        <div class="flex flex-col gap-4">
            <Card>
                <CardHeader class="flex-row items-center gap-3 space-y-0">
                    <Avatar class="size-12">
                        <AvatarImage v-if="colaborador.foto_url" :src="colaborador.foto_url" :alt="colaborador.nombre" />
                        <AvatarFallback>{{ getInitials(colaborador.nombre) }}</AvatarFallback>
                    </Avatar>
                    <div class="min-w-0">
                        <CardTitle>{{ colaborador.nombre }}</CardTitle>
                        <p v-if="colaborador.sucursal" class="text-sm text-muted-foreground">
                            {{ colaborador.sucursal }}
                        </p>
                    </div>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <blockquote class="border-l-2 border-primary/40 pl-3 text-sm italic">
                        "{{ greeting.frase }}"
                    </blockquote>
                    <Badge variant="outline" class="w-fit" :class="estado.tono">
                        {{ estado.texto }}
                    </Badge>
                    <p v-if="greeting.enviadaAt" class="text-xs text-muted-foreground">
                        Enviada el {{ new Date(greeting.enviadaAt).toLocaleDateString('es-MX', { day: 'numeric', month: 'long', year: 'numeric' }) }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Cambiar frase</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <Select :model-value="fraseSeleccionadaId" @update:model-value="alElegirFrase">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Elegir una frase del catálogo..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="frase in opciones.frases"
                                :key="frase.id"
                                :value="String(frase.id)"
                            >
                                {{ frase.texto }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <div v-if="hayCambioPendiente" class="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            :disabled="confirmando || previsualizando"
                            @click="confirmarFraseElegida"
                        >
                            <Spinner v-if="confirmando" />
                            <Check v-else class="size-4" />
                            Confirmar esta frase
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            :disabled="confirmando"
                            @click="cancelarCambio"
                        >
                            Cancelar
                        </Button>
                    </div>
                    <p v-else class="text-xs text-muted-foreground">
                        Elige una frase para ver la tarjeta actualizada antes de guardarla.
                    </p>
                </CardContent>
            </Card>

            <div class="flex flex-wrap gap-2">
                <Button v-if="permisos.descargarImagen" as-child>
                    <a :href="descargarFelicitacion.url(colaborador.id)">
                        <Download class="size-4" /> Descargar PNG
                    </a>
                </Button>

                <Button variant="outline" @click="copiarMensaje">
                    <Copy class="size-4" /> Copiar mensaje
                </Button>

                <Button variant="outline" :disabled="regenerando" @click="regenerar">
                    <Spinner v-if="regenerando" />
                    <RefreshCw v-else class="size-4" />
                    Regenerar con otra frase
                </Button>

                <Button
                    v-if="permisos.gestionarNotificaciones"
                    variant="outline"
                    :disabled="enviando"
                    @click="enviarManual"
                >
                    <Spinner v-if="enviando" />
                    <Send v-else class="size-4" />
                    Enviar al colaborador
                </Button>
            </div>
        </div>
    </div>
    </div>
</template>
