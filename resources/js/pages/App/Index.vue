<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Bell,
    CalendarDays,
    ClipboardList,
    Download,
    FileText,
    QrCode,
    Smartphone,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { login } from '@/routes';
import { descargar, versiones } from '@/routes/app';

const props = defineProps<{
    downloadEnabled: boolean;
    token: string | null;
    from: string | null;
    latest: {
        version: string;
        buildNumber: string | null;
        changelog: string | null;
        fileSize: number | null;
        publishedAt: string | null;
    } | null;
}>();

const urlDescarga = computed(() => {
    if (!props.token) {
return descargar.url();
}

    return `${descargar.url()}?token=${encodeURIComponent(props.token)}`;
});

const deepLink = computed(() =>
    props.token ? `mrlanapeople://incorporacion/qr/${props.token}` : null,
);

const tamanoFormateado = computed(() => {
    if (!props.latest?.fileSize) {
return null;
}

    return `${(props.latest.fileSize / (1024 * 1024)).toFixed(1)} MB`;
});

const fechaFormateada = computed(() => {
    if (!props.latest?.publishedAt) {
return null;
}

    return new Date(props.latest.publishedAt).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
});
</script>

<template>
    <Head title="Descarga MR. LANA PEOPLE" />

    <div class="min-h-screen bg-muted/30 px-4 py-10">
        <div class="mx-auto flex w-full max-w-lg flex-col items-center text-center">
            <span
                class="mb-4 flex size-16 items-center justify-center rounded-2xl bg-primary/10 text-primary"
            >
                <Smartphone class="size-8" />
            </span>

            <h1 class="text-2xl font-bold tracking-tight">
                Descarga MR. LANA PEOPLE
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Lleva tus solicitudes, vacaciones, documentos y
                notificaciones en tu celular.
            </p>

            <template v-if="downloadEnabled && latest">
                <Button as-child size="lg" class="mt-6 w-full max-w-xs">
                    <a :href="urlDescarga">
                        <Download class="size-5" /> Descargar para Android
                    </a>
                </Button>

                <p class="mt-3 text-xs text-muted-foreground">
                    Versión {{ latest.version }}
                    <template v-if="fechaFormateada">
                        · Publicada el {{ fechaFormateada }}</template
                    >
                    <template v-if="tamanoFormateado">
                        · {{ tamanoFormateado }}</template
                    >
                    ·
                    <Link :href="versiones()" class="underline underline-offset-2"
                        >Ver historial</Link
                    >
                </p>

                <Card v-if="latest.changelog" class="mt-4 w-full text-left">
                    <CardContent class="pt-4">
                        <p class="mb-1 text-xs font-semibold text-muted-foreground">
                            Notas de esta versión
                        </p>
                        <p class="text-sm whitespace-pre-line">
                            {{ latest.changelog }}
                        </p>
                    </CardContent>
                </Card>

                <template v-if="from === 'qr' && token">
                    <Card class="mt-6 w-full text-left">
                        <CardContent class="pt-4">
                            <p class="mb-2 text-sm font-semibold">
                                Después de descargar
                            </p>
                            <ol class="ml-4 list-decimal space-y-1 text-sm text-muted-foreground">
                                <li>Instala la APK.</li>
                                <li>Abre nuevamente este enlace QR.</li>
                                <li>Toca "Continuar en la app".</li>
                            </ol>
                        </CardContent>
                    </Card>

                    <Button as-child variant="outline" class="mt-3 w-full max-w-xs">
                        <a :href="deepLink!">Ya instalé la app, continuar</a>
                    </Button>
                </template>
            </template>

            <Card v-else class="mt-6 w-full border-dashed text-left">
                <CardContent class="pt-4 text-sm text-muted-foreground">
                    La descarga aún no está disponible. Recursos Humanos te
                    avisará cuando esté lista.
                </CardContent>
            </Card>

            <p class="mt-4 text-xs text-muted-foreground">
                Disponible por ahora para Android.
            </p>

            <div class="mt-8 w-full rounded-xl border bg-background p-4 text-left">
                <p class="mb-3 text-sm font-semibold">
                    ¿Ya tienes la app? Escanea tu QR o inicia sesión.
                </p>
                <div class="flex flex-wrap gap-4 text-sm text-muted-foreground">
                    <span class="flex items-center gap-1.5"
                        ><QrCode class="size-4" /> Escanea tu QR de
                        incorporación</span
                    >
                    <span class="flex items-center gap-1.5"
                        ><CalendarDays class="size-4" /> Vacaciones</span
                    >
                    <span class="flex items-center gap-1.5"
                        ><ClipboardList class="size-4" /> Solicitudes</span
                    >
                    <span class="flex items-center gap-1.5"
                        ><FileText class="size-4" /> Documentos</span
                    >
                    <span class="flex items-center gap-1.5"
                        ><Bell class="size-4" /> Notificaciones</span
                    >
                </div>
            </div>

            <Button as-child variant="secondary" class="mt-4 w-full max-w-xs">
                <Link :href="login()">Volver al login</Link>
            </Button>
        </div>
    </div>
</template>
