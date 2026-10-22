<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Upload } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { subirFirmado } from '@/routes/rh/contratos';

/**
 * «Subir firmado»: el original firmado (PDF o imagen) entra al expediente en
 * revisión de RH. No abre ni descarga el contrato del sistema. Quién puede
 * subirlo lo decide el backend (ContratoFirmadoService); este botón solo se
 * muestra cuando el plan trae puede_subir_firmado.
 */
const props = defineProps<{
    colaboradorId: number;
    clave: string;
    class?: string;
}>();

const entrada = ref<HTMLInputElement | null>(null);
const subiendo = ref(false);

function elegir() {
    entrada.value?.click();
}

function alElegir(evento: Event) {
    const archivo = (evento.target as HTMLInputElement).files?.[0];

    if (!archivo) {
        return;
    }

    subiendo.value = true;
    router.post(
        subirFirmado.url(props.colaboradorId),
        { clave: props.clave, archivo },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                subiendo.value = false;

                if (entrada.value) {
                    entrada.value.value = '';
                }
            },
        },
    );
}
</script>

<template>
    <Button
        variant="outline"
        :class="props.class ?? 'h-10'"
        :disabled="subiendo"
        @click="elegir"
    >
        <Spinner v-if="subiendo" />
        <Upload v-else class="size-5" />
        {{ subiendo ? 'Subiendo…' : 'Subir firmado' }}
    </Button>
    <input
        ref="entrada"
        type="file"
        class="hidden"
        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
        @change="alElegir"
    />
</template>
