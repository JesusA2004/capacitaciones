<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, House } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';

/**
 * Página de error corporativa (bootstrap/app.php, solo con APP_DEBUG=false):
 * nunca muestra trazas, rutas del servidor, consultas ni cookies. Se pinta
 * sin AppLayout (ver resources/js/app.ts) porque un 404 o un 500 puede
 * ocurrir sin sesión ni props compartidas.
 */
const props = defineProps<{ status: number }>();

const TEXTOS: Record<number, { titulo: string; descripcion: string }> = {
    403: {
        titulo: 'No tienes acceso a esta sección',
        descripcion:
            'Tu rol no tiene permiso para abrir esta pantalla. Si crees que es un error, pide a Recursos Humanos o a Sistemas que revisen tus permisos.',
    },
    404: {
        titulo: 'No encontramos esta página',
        descripcion:
            'La dirección no existe o el registro ya no está disponible. Revisa el enlace o regresa al inicio.',
    },
    419: {
        titulo: 'Tu sesión expiró',
        descripcion:
            'Por seguridad, la página caducó. Recarga e intenta de nuevo.',
    },
    422: {
        titulo: 'No se pudo procesar la solicitud',
        descripcion:
            'Algunos datos no son válidos. Regresa, revisa la información e intenta de nuevo.',
    },
    429: {
        titulo: 'Demasiados intentos',
        descripcion: 'Espera un momento antes de volver a intentarlo.',
    },
    500: {
        titulo: 'Algo salió mal',
        descripcion:
            'Ocurrió un error inesperado y ya quedó registrado para que Sistemas lo revise. Intenta de nuevo en unos minutos.',
    },
    503: {
        titulo: 'Estamos en mantenimiento',
        descripcion:
            'MR. LANA PEOPLE está temporalmente fuera de servicio por mantenimiento. Vuelve a intentarlo en unos minutos.',
    },
};

const texto = computed(() => TEXTOS[props.status] ?? TEXTOS[500]);

function volver() {
    if (window.history.length > 1) {
        window.history.back();

        return;
    }

    window.location.assign('/');
}
</script>

<template>
    <Head :title="texto.titulo" />

    <main
        class="flex min-h-svh items-center justify-center bg-background px-4 py-10 text-foreground"
    >
        <div
            class="flex w-full max-w-md flex-col gap-6 rounded-2xl border bg-card p-6 shadow-sm sm:p-8"
        >
            <div class="flex items-center">
                <AppLogo />
            </div>

            <div class="space-y-2">
                <p
                    class="text-sm font-semibold text-[var(--brand-primary)] tabular-nums"
                >
                    Error {{ status }}
                </p>
                <h1 class="text-xl font-semibold tracking-tight text-balance">
                    {{ texto.titulo }}
                </h1>
                <p class="text-sm text-pretty text-muted-foreground">
                    {{ texto.descripcion }}
                </p>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button variant="outline" class="gap-2" @click="volver">
                    <ArrowLeft class="size-4" />
                    Regresar
                </Button>
                <Button as-child class="gap-2">
                    <Link href="/">
                        <House class="size-4" />
                        Ir al inicio
                    </Link>
                </Button>
            </div>
        </div>
    </main>
</template>
