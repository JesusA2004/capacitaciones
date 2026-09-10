<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Download, RefreshCw, Send } from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/cumpleanos';
import {
    descargar as descargarFelicitacion,
    enviar as enviarFelicitacion,
    regenerar as regenerarFelicitacion,
} from '@/routes/rh/cumpleanos/felicitacion';

const props = defineProps<{
    colaborador: { id: number; nombre: string; sucursal: string | null };
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
const regenerando = ref(false);
const enviando = ref(false);

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
</script>

<template>
    <Head :title="`Felicitación — ${colaborador.nombre}`" />

    <Link
        :href="index.url()"
        class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
    >
        <ArrowLeft class="size-4" /> Volver a cumpleaños
    </Link>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card>
            <CardContent class="flex items-center justify-center p-4">
                <img
                    v-if="greeting.tieneImagen"
                    :src="greeting.imagenUrl"
                    :alt="`Felicitación de ${colaborador.nombre}`"
                    class="max-h-[560px] w-full rounded-lg object-contain shadow-sm"
                />
                <p v-else class="py-16 text-sm text-muted-foreground">
                    La tarjeta aún no se ha generado.
                </p>
            </CardContent>
        </Card>

        <div class="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardTitle>{{ colaborador.nombre }}</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <p
                        v-if="colaborador.sucursal"
                        class="text-sm text-muted-foreground"
                    >
                        {{ colaborador.sucursal }}
                    </p>
                    <blockquote
                        class="border-l-2 border-primary/40 pl-3 text-sm italic"
                    >
                        "{{ greeting.frase }}"
                    </blockquote>
                    <Badge
                        v-if="greeting.enviadaAt"
                        variant="outline"
                        class="w-fit"
                    >
                        Enviada el
                        {{
                            new Date(greeting.enviadaAt).toLocaleDateString(
                                'es-MX',
                            )
                        }}
                    </Badge>
                    <Badge v-else variant="outline" class="w-fit">
                        Aún no se ha enviado
                    </Badge>
                </CardContent>
            </Card>

            <div class="flex flex-wrap gap-2">
                <Button v-if="permisos.descargarImagen" as-child>
                    <a :href="descargarFelicitacion.url(colaborador.id)">
                        <Download class="size-4" /> Descargar imagen
                    </a>
                </Button>

                <Button
                    variant="outline"
                    :disabled="regenerando"
                    @click="regenerar"
                >
                    <Spinner v-if="regenerando" />
                    <RefreshCw v-else class="size-4" />
                    Regenerar (nueva frase)
                </Button>

                <Button
                    v-if="permisos.gestionarNotificaciones"
                    variant="outline"
                    :disabled="enviando"
                    @click="enviarManual"
                >
                    <Spinner v-if="enviando" />
                    <Send v-else class="size-4" />
                    Enviar felicitación manual
                </Button>
            </div>
        </div>
    </div>
</template>
