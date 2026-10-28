<script setup lang="ts">
import { BellRing, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { useWebPush } from '@/composables/useWebPush';

/**
 * Franja superior al estilo WhatsApp Web («Recibe notificaciones de
 * mensajes nuevos · Activar notificaciones de escritorio»): aparece
 * mientras el navegador no tiene el permiso, con un clic lo pide y se va.
 * «Ahora no» la oculta 7 días en este navegador.
 */
const CLAVE = 'people:banner-notificaciones-oculto-hasta';
const SIETE_DIAS = 7 * 86_400_000;

const { estado, procesando, activar, probar } = useWebPush();

function ocultoGuardado(): boolean {
    try {
        return Number(localStorage.getItem(CLAVE) ?? 0) > Date.now();
    } catch {
        return false;
    }
}

const oculto = ref(ocultoGuardado());
const visible = computed(() => estado.value === 'pendiente' && !oculto.value);

function ahoraNo(): void {
    oculto.value = true;

    try {
        localStorage.setItem(CLAVE, String(Date.now() + SIETE_DIAS));
    } catch {
        // Sin almacenamiento: se oculta solo en esta pestaña.
    }
}

async function alActivar(): Promise<void> {
    if (await activar()) {
        toast.success('Notificaciones activadas', {
            description: 'Así te avisaremos cuando llegue algo nuevo.',
        });
        await probar().catch(() => undefined);
    } else if (estado.value === 'bloqueado') {
        toast.warning('El navegador bloqueó las notificaciones', {
            description:
                'Haz clic en el candado junto a la dirección › Notificaciones › Permitir.',
        });
    }
}
</script>

<template>
    <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="-translate-y-2 opacity-0"
        leave-active-class="transition duration-200 ease-in"
        leave-to-class="-translate-y-2 opacity-0"
    >
        <div
            v-if="visible"
            role="region"
            aria-label="Activar notificaciones de escritorio"
            class="mx-4 mt-3 flex items-center gap-3 rounded-2xl border border-info/20 bg-info-soft px-3 py-2.5 sm:mx-6 md:mx-8"
            data-banner-notificaciones
        >
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-info text-info-soft"
            >
                <BellRing class="size-4" />
            </span>
            <div
                class="flex min-w-0 flex-1 flex-col sm:flex-row sm:items-center sm:gap-2"
            >
                <p class="text-sm font-semibold text-foreground">
                    Recibe avisos de PEOPLE al instante
                </p>
                <p class="text-xs text-muted-foreground sm:text-sm">
                    Chrome te avisa de solicitudes y aprobaciones aunque estés
                    en otra pestaña.
                </p>
            </div>
            <Button
                size="sm"
                class="shrink-0"
                :disabled="procesando"
                @click="alActivar"
            >
                Activar
            </Button>
            <button
                type="button"
                class="flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-background/60 hover:text-foreground"
                aria-label="Ahora no"
                title="Ahora no"
                @click="ahoraNo"
            >
                <X class="size-4" />
            </button>
        </div>
    </Transition>
</template>
