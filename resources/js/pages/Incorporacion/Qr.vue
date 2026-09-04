<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, Copy, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';

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

                <Button class="w-full" @click="continuarEnApp">
                    Continuar en la app
                </Button>

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
                <p class="text-sm font-medium">
                    Este código de incorporación no es válido, venció o fue
                    revocado. Solicita uno nuevo a Recursos Humanos.
                </p>
            </template>
        </div>
    </div>
</template>
