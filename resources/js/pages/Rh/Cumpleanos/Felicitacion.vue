<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Copy, Download, RefreshCw, Send } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { useInitials } from '@/composables/useInitials';
import { mensajeFelicitacion } from '@/lib/cumpleanos';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/cumpleanos';
import {
    descargar as descargarFelicitacion,
    enviar as enviarFelicitacion,
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

async function regenerar() {
    const confirmado = await confirmarRegeneracion('la tarjeta actual');

    if (!confirmado) {
        return;
    }

    regenerando.value = true;
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
                <img
                    v-if="greeting.tieneImagen"
                    :src="greeting.imagenUrl"
                    :alt="`Felicitación de ${colaborador.nombre}`"
                    class="max-h-[640px] w-full rounded-lg object-contain shadow-md"
                />
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
