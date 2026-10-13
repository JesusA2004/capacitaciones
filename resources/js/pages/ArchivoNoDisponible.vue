<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, FileWarning, Loader2, RefreshCw } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { ErrorSolicitud, enviarJson } from '@/lib/http';

/**
 * El registro existe pero su archivo generado no está disponible
 * (App\Exceptions\ArchivoGeneradoNoDisponibleException): error operativo
 * recuperable, nunca un 404. Si el usuario tiene permiso, puede generarlo
 * de nuevo aquí mismo y se abre al terminar.
 */
const props = defineProps<{
    titulo: string;
    mensaje: string;
    urlReparar: string | null;
    urlVolver: string | null;
}>();

const reparando = ref(false);
const error = ref<string | null>(null);

async function reparar() {
    if (!props.urlReparar || reparando.value) {
        return;
    }

    reparando.value = true;
    error.value = null;

    try {
        const { url } = await enviarJson<{ url: string }>(
            'POST',
            props.urlReparar,
        );
        window.location.assign(url);
    } catch (e) {
        error.value =
            e instanceof ErrorSolicitud
                ? e.message
                : 'No se pudo generar el archivo. Intenta de nuevo.';
        reparando.value = false;
    }
}

function volver() {
    if (props.urlVolver) {
        router.visit(props.urlVolver);

        return;
    }

    if (window.history.length > 1) {
        window.history.back();

        return;
    }

    router.visit('/');
}
</script>

<template>
    <Head :title="titulo" />

    <div class="pagina-ancha flex min-h-[60vh] items-center justify-center">
        <section
            class="flex w-full max-w-md animate-in flex-col gap-5 rounded-2xl border bg-card p-6 shadow-xs duration-200 fade-in slide-in-from-bottom-1"
            role="alert"
        >
            <span
                class="flex size-11 items-center justify-center rounded-full bg-warning-soft text-warning-foreground"
            >
                <FileWarning class="size-5" />
            </span>
            <div class="space-y-1.5">
                <h1 class="text-lg font-semibold tracking-tight">
                    {{ titulo }}
                </h1>
                <p class="text-sm text-pretty text-muted-foreground">
                    {{ mensaje }}
                </p>
                <p v-if="error" class="text-sm text-danger">{{ error }}</p>
            </div>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button variant="ghost" @click="volver">
                    <ArrowLeft class="size-4" /> Regresar
                </Button>
                <Button
                    v-if="urlReparar"
                    :disabled="reparando"
                    @click="reparar"
                >
                    <Loader2 v-if="reparando" class="size-4 animate-spin" />
                    <RefreshCw v-else class="size-4" />
                    {{ reparando ? 'Generando…' : 'Generar de nuevo y abrir' }}
                </Button>
            </div>
        </section>
    </div>
</template>
