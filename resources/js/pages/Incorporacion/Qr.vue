<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, Copy, Download, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';
import { index as appIndex } from '@/routes/app';

const props = defineProps<{
    valida: boolean;
    estado: string;
    message: string | null;
    token: string;
    appLink: string;
    codigoLegible: string | null;
    nombrePrellenado: string | null;
}>();

const copiado = ref(false);

async function copiarCodigo() {
    if (!props.codigoLegible) {
        return;
    }

    await navigator.clipboard.writeText(props.codigoLegible);
    copiado.value = true;
    setTimeout(() => {
        copiado.value = false;
    }, 2000);
}

/** Intenta abrir la app móvil vía deep link; si no está instalada, el
 * navegador simplemente se queda en esta misma pantalla. */
function continuarEnApp() {
    window.location.href = props.appLink;
}

const urlDescargarApp = appIndex.url({
    query: { from: 'qr', token: props.token },
});
</script>

<template>
    <Head title="Incorporación" />

    <div class="flex min-h-screen items-center justify-center bg-muted/30 p-4">
        <div
            class="w-full max-w-md rounded-xl border bg-background p-8 text-center shadow-sm"
        >
            <h1 class="mb-1 text-lg font-semibold">MR. LANA PEOPLE</h1>
            <p class="mb-6 text-sm text-muted-foreground">
                Proceso de incorporación
            </p>

            <template v-if="valida">
                <CheckCircle2
                    class="mx-auto mb-3 size-12 text-[var(--success)]"
                />
                <p class="mb-1 text-base font-semibold">
                    Bienvenido a tu proceso de incorporación MR. LANA PEOPLE
                </p>
                <p
                    v-if="nombrePrellenado"
                    class="mb-4 text-sm text-muted-foreground"
                >
                    {{ nombrePrellenado }}
                </p>

                <ol
                    class="mb-6 space-y-2 rounded-lg bg-muted/40 p-4 text-left text-sm"
                >
                    <li class="flex gap-2">
                        <span class="font-semibold text-primary">1.</span>
                        Descarga la app MR. LANA PEOPLE
                    </li>
                    <li class="flex gap-2">
                        <span class="font-semibold text-primary">2.</span>
                        Abre la app
                    </li>
                    <li class="flex gap-2">
                        <span class="font-semibold text-primary">3.</span>
                        Continúa tu registro
                    </li>
                    <li class="flex gap-2">
                        <span class="font-semibold text-primary">4.</span>
                        Sube tus documentos
                    </li>
                </ol>

                <Button class="w-full" @click="continuarEnApp">
                    Continuar en la app
                </Button>

                <Button as-child variant="outline" class="mt-2 w-full">
                    <a :href="urlDescargarApp">
                        <Download class="size-4" /> Descargar app
                    </a>
                </Button>

                <p class="mt-4 text-xs text-muted-foreground">
                    Si acabas de instalar la app, vuelve a esta pantalla y toca
                    "Continuar en la app".
                </p>

                <div
                    v-if="codigoLegible"
                    class="mt-4 flex items-center justify-center gap-2 text-sm text-muted-foreground"
                >
                    <span
                        >Código: <strong>{{ codigoLegible }}</strong></span
                    >
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-xs underline underline-offset-2 hover:text-foreground"
                        @click="copiarCodigo"
                    >
                        <Copy class="size-3.5" />
                        {{ copiado ? 'Copiado' : 'Copiar' }}
                    </button>
                </div>
            </template>

            <template v-else>
                <XCircle class="mx-auto mb-3 size-12 text-destructive" />
                <p class="mb-4 text-sm font-medium">
                    Este código de incorporación no es válido, venció o fue
                    revocado. Solicita uno nuevo a Recursos Humanos.
                </p>

                <Button as-child variant="outline" class="w-full">
                    <Link :href="login()">Ir al login</Link>
                </Button>
            </template>
        </div>
    </div>
</template>
