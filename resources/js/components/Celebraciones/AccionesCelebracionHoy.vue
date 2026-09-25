<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Megaphone, Send } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { usePermisos } from '@/composables/usePermisos';
import { avisarTodos, enviar } from '@/routes/rh/celebraciones';

/**
 * "Enviar al colaborador" y "Avisar a todos" para el evento de HOY de una
 * persona (cumpleaños o aniversario). El backend es idempotente: un segundo
 * "Avisar a todos" no vuelve a notificar, solo informa cuándo se hizo.
 */
const props = defineProps<{
    colaboradorId: number;
    tipo: 'cumpleanos' | 'aniversario_laboral';
    nombre: string;
    anios?: number | null;
    avisadaTodosAt?: string | null;
}>();

const { tienePermiso } = usePermisos();
const { confirmarAccion } = useAlertas();
const enviando = ref(false);
const avisando = ref(false);

function textoAnios(anios: number): string {
    return `${anios} ${anios === 1 ? 'año' : 'años'}`;
}

async function enviarAlColaborador() {
    const ok = await confirmarAccion(
        `¿Enviar la felicitación a ${props.nombre}?`,
        'Recibirá una notificación y un aviso en su app con su tarjeta.',
        'Sí, enviar',
    );

    if (!ok) {
        return;
    }

    enviando.value = true;
    router.post(enviar.url([props.colaboradorId, props.tipo]), {}, { preserveScroll: true, onFinish: () => (enviando.value = false) });
}

async function avisarATodos() {
    const texto =
        props.tipo === 'aniversario_laboral' && props.anios
            ? `¿Avisar a todos que ${props.nombre} cumple ${textoAnios(props.anios)} en MR. LANA?`
            : `¿Avisar a todos los colaboradores sobre el cumpleaños de ${props.nombre}?`;
    const ok = await confirmarAccion(texto, 'Los colaboradores activos recibirán un aviso y podrán dejarle una felicitación privada. Solo se envía una vez.', 'Sí, avisar');

    if (!ok) {
        return;
    }

    avisando.value = true;
    router.post(avisarTodos.url([props.colaboradorId, props.tipo]), {}, { preserveScroll: true, onFinish: () => (avisando.value = false) });
}

function fecha(valor: string): string {
    return new Date(valor).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <template v-if="tienePermiso('celebraciones.enviar')">
        <Button size="sm" :disabled="enviando" @click="enviarAlColaborador">
            <Spinner v-if="enviando" />
            <Send v-else class="size-4" />
            Enviar al colaborador
        </Button>
        <Button
            size="sm"
            variant="outline"
            :disabled="avisando || !!avisadaTodosAt"
            :title="avisadaTodosAt ? `Aviso general enviado el ${fecha(avisadaTodosAt)}` : undefined"
            @click="avisarATodos"
        >
            <Spinner v-if="avisando" />
            <Megaphone v-else class="size-4" />
            {{ avisadaTodosAt ? 'Todos avisados' : 'Avisar a todos' }}
        </Button>
    </template>
</template>
