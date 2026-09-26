<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Megaphone, Send } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { usePermisos } from '@/composables/usePermisos';
import { avisarTodos, enviar } from '@/routes/rh/celebraciones';
import type { TipoCelebracion } from '@/types';

/**
 * "Enviar al colaborador" y "Avisar a todos" para el evento de HOY de una
 * persona (cumpleaños o aniversario). El backend es idempotente: un segundo
 * "Avisar a todos" no vuelve a notificar, solo informa cuándo se hizo.
 *
 * Las etiquetas se acortan según el ancho de la tarjeta que las contiene
 * (container query de TarjetaCelebracionPersona), nunca se recortan.
 */
const props = defineProps<{
    colaboradorId: number;
    tipo: TipoCelebracion;
    nombre: string;
    anios?: number | null;
    enviadaAt?: string | null;
    avisadaTodosAt?: string | null;
}>();

const { tienePermiso } = usePermisos();
const { confirmarAccion } = useAlertas();
const enviando = ref(false);
const avisando = ref(false);

function textoAnios(anios: number): string {
    return `${anios} ${anios === 1 ? 'año' : 'años'}`;
}

function primerError(errores: Record<string, string>, respaldo: string): string {
    return Object.values(errores)[0] ?? respaldo;
}

async function enviarAlColaborador() {
    const ok = await confirmarAccion(
        props.enviadaAt ? `¿Enviar de nuevo la felicitación a ${props.nombre}?` : `¿Enviar la felicitación a ${props.nombre}?`,
        'Recibirá una notificación y un aviso en su app con su tarjeta.',
        'Sí, enviar',
    );

    if (!ok) {
        return;
    }

    enviando.value = true;
    router.post(
        enviar.url([props.colaboradorId, props.tipo]),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errores) => toast.error(primerError(errores, 'No se pudo enviar la felicitación. Intenta de nuevo.')),
            onFinish: () => (enviando.value = false),
        },
    );
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
    router.post(
        avisarTodos.url([props.colaboradorId, props.tipo]),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errores) => toast.error(primerError(errores, 'No se pudo enviar el aviso general. Intenta de nuevo.')),
            onFinish: () => (avisando.value = false),
        },
    );
}

function fecha(valor: string): string {
    return new Date(valor).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <template v-if="tienePermiso('celebraciones.enviar')">
        <Button
            size="sm"
            :variant="enviadaAt ? 'outline' : 'default'"
            :disabled="enviando"
            :title="enviadaAt ? `Enviada el ${fecha(enviadaAt)}` : 'Enviar al colaborador'"
            @click="enviarAlColaborador"
        >
            <Spinner v-if="enviando" />
            <Send v-else class="size-4" />
            <template v-if="enviadaAt">Reenviar</template>
            <template v-else>
                <span class="@[30rem]:hidden">Enviar</span>
                <span class="hidden @[30rem]:inline">Enviar al colaborador</span>
            </template>
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
