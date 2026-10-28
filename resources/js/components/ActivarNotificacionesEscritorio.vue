<script setup lang="ts">
import {
    BellOff,
    BellRing,
    CheckCircle2,
    Lock,
    Volume2,
    VolumeX,
} from '@lucide/vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { useWebPush } from '@/composables/useWebPush';
import {
    cambiarSonidoAvisos,
    sonarAviso,
    sonidoAvisos,
} from '@/lib/avisosNavegador';

/**
 * Activa las notificaciones de escritorio (Web Push) del navegador actual:
 * con ellas cada aviso llega al centro de notificaciones de Windows/macOS/
 * Android aunque PEOPLE no esté abierto. `compacto` = versión para la
 * campana; la completa vive en «Mis notificaciones».
 */
withDefaults(defineProps<{ compacto?: boolean }>(), { compacto: false });

const { estado, modoLocal, procesando, activar, desactivar, probar } =
    useWebPush();

function alternarSonido(): void {
    cambiarSonidoAvisos(!sonidoAvisos.value);

    if (sonidoAvisos.value) {
        sonarAviso();
    }
}

async function alActivar(): Promise<void> {
    const ok = await activar();

    if (ok) {
        toast.success('Notificaciones de escritorio activadas', {
            description:
                'Te enviamos una de prueba para que veas cómo se verán.',
        });
        await probar().catch(() => undefined);
    } else if (estado.value === 'bloqueado') {
        toast.warning('El navegador bloqueó las notificaciones', {
            description:
                'Haz clic en el candado junto a la dirección del sitio › Notificaciones › Permitir.',
        });
    }
}

async function alProbar(): Promise<void> {
    try {
        await probar();
        toast.success('Aviso de prueba enviado', {
            description: 'Debe aparecer en unos segundos en tu escritorio.',
        });
    } catch {
        toast.error('No se pudo enviar el aviso de prueba.');
    }
}

async function alDesactivar(): Promise<void> {
    await desactivar();
    toast.info('Este navegador ya no mostrará notificaciones de escritorio.');
}
</script>

<template>
    <template v-if="estado !== 'no-soportado' && estado !== 'sin-configurar'">
        <!-- Campana: solo invita a activar (o explica el bloqueo). -->
        <div
            v-if="compacto && estado !== 'activo'"
            class="mx-2 my-1.5 flex items-start gap-2.5 rounded-xl border border-primary/20 bg-crema p-2.5"
        >
            <span
                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
            >
                <component
                    :is="estado === 'bloqueado' ? Lock : BellRing"
                    class="size-4"
                />
            </span>
            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <p class="text-xs leading-snug font-medium">
                    {{
                        estado === 'bloqueado'
                            ? 'Notificaciones bloqueadas en este navegador'
                            : 'Recibe avisos aunque no tengas PEOPLE abierto'
                    }}
                </p>
                <p
                    v-if="estado === 'bloqueado'"
                    class="text-[11px] leading-snug text-muted-foreground"
                >
                    Candado junto a la dirección › Notificaciones › Permitir.
                </p>
                <Button
                    v-else
                    size="sm"
                    class="h-7 w-fit text-xs"
                    :disabled="procesando"
                    @click.stop="alActivar"
                >
                    Activar notificaciones
                </Button>
            </div>
        </div>

        <section
            v-else-if="!compacto"
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 sm:flex-row sm:items-center"
        >
            <span
                class="flex size-11 shrink-0 items-center justify-center rounded-2xl"
                :class="
                    estado === 'activo'
                        ? 'bg-success-soft text-success'
                        : 'bg-crema text-primary'
                "
            >
                <component
                    :is="
                        estado === 'activo'
                            ? CheckCircle2
                            : estado === 'bloqueado'
                              ? Lock
                              : BellRing
                    "
                    class="size-5"
                />
            </span>
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <h2 class="text-sm font-semibold">
                    {{
                        estado === 'activo'
                            ? 'Notificaciones de escritorio activadas'
                            : 'Notificaciones de escritorio'
                    }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    <template v-if="estado === 'activo'">
                        <template v-if="modoLocal">
                            Como WhatsApp Web: con PEOPLE abierto (aunque estés
                            en otra pestaña o programa), Chrome te avisa en
                            cuanto llega algo nuevo.
                        </template>
                        <template v-else>
                            Este navegador te avisa de todo lo que también llega
                            a la app, aunque PEOPLE esté cerrado.
                        </template>
                    </template>
                    <template v-else-if="estado === 'bloqueado'">
                        El navegador las bloqueó. Haz clic en el candado junto a
                        la dirección del sitio › Notificaciones › Permitir, y
                        recarga la página.
                    </template>
                    <template v-else>
                        Como WhatsApp Web: cada solicitud, aprobación o aviso
                        aparece como notificación de Chrome en tu computadora en
                        cuanto llega, con un tono corto.
                    </template>
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <Button
                    variant="ghost"
                    size="sm"
                    :aria-pressed="sonidoAvisos"
                    :title="
                        sonidoAvisos
                            ? 'Silenciar el tono de avisos'
                            : 'Activar el tono de avisos'
                    "
                    @click="alternarSonido"
                >
                    <component
                        :is="sonidoAvisos ? Volume2 : VolumeX"
                        class="size-4"
                    />
                    {{ sonidoAvisos ? 'Sonido activado' : 'Sin sonido' }}
                </Button>
                <template v-if="estado === 'activo'">
                    <Button variant="outline" size="sm" @click="alProbar">
                        Enviar prueba
                    </Button>
                    <Button
                        v-if="!modoLocal"
                        variant="ghost"
                        size="sm"
                        @click="alDesactivar"
                    >
                        <BellOff class="size-4" />
                        Desactivar
                    </Button>
                </template>
                <Button
                    v-else-if="estado === 'pendiente'"
                    size="sm"
                    :disabled="procesando"
                    @click="alActivar"
                >
                    <BellRing class="size-4" />
                    Activar
                </Button>
            </div>
        </section>
    </template>
</template>
