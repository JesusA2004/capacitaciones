<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { MailCheck, MailWarning, Send } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { formatearFecha } from '@/lib/fechas';
import { reenviar } from '@/routes/rh/candidatos/aviso-sistemas';

export type ResumenAvisoSistemas = {
    estado: 'pendiente' | 'enviado' | 'fallido' | null;
    estado_etiqueta: string | null;
    enviado_en: string | null;
    ultimo_error: string | null;
    puede_reenviar: boolean;
};

/**
 * Correo «Alta para asignar usuarios» a Sistemas (fase 6 · Contratación):
 * estado del último envío y reenvío manual (AvisoAltaSistemasService).
 */
const props = defineProps<{
    candidatoId: number;
    aviso: ResumenAvisoSistemas;
}>();

const enviando = ref(false);

const texto = computed(() => {
    if (props.aviso.estado === 'enviado' && props.aviso.enviado_en) {
        return `Aviso a Sistemas enviado el ${formatearFecha(props.aviso.enviado_en)}`;
    }

    if (props.aviso.estado === 'fallido') {
        return 'El aviso a Sistemas no se pudo enviar';
    }

    if (props.aviso.estado === 'pendiente') {
        return 'Aviso a Sistemas en cola';
    }

    return 'Sin aviso a Sistemas registrado';
});

function reenviarAviso() {
    enviando.value = true;
    router.post(
        reenviar.url(props.candidatoId),
        {},
        { preserveScroll: true, onFinish: () => (enviando.value = false) },
    );
}
</script>

<template>
    <div
        class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 py-2 text-sm"
    >
        <span
            class="flex items-center gap-2"
            :class="
                aviso.estado === 'fallido'
                    ? 'text-destructive'
                    : 'text-muted-foreground'
            "
        >
            <MailWarning v-if="aviso.estado === 'fallido'" class="size-4" />
            <MailCheck v-else class="size-4" />
            {{ texto }}
        </span>
        <Button
            v-if="aviso.puede_reenviar"
            size="sm"
            variant="outline"
            :disabled="enviando"
            @click="reenviarAviso"
        >
            <Send class="size-3.5" />
            Reenviar a Sistemas
        </Button>
    </div>
</template>
