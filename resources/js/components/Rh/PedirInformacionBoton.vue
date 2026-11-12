<script setup lang="ts">
import {
    Camera,
    ClipboardList,
    FileSignature,
    FileUp,
    Send,
    UserRound,
    Wrench,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAlertas } from '@/composables/useAlertas';
import { enviarJson, getJson } from '@/lib/http';
import {
    pedir as rutaPedir,
    show as rutaResumen,
} from '@/routes/rh/pedir-informacion';

/**
 * «Pedir información al colaborador»: muestra TODO lo que le falta (datos,
 * documentos, solicitud de empleo y socioeconómico, firmas, foto) y se lo
 * pide en un solo aviso a su web y su app (PedirInformacionService).
 */
const props = withDefaults(
    defineProps<{
        colaboradorId: number;
        size?: 'sm' | 'default';
        variant?: 'default' | 'outline';
    }>(),
    { size: 'sm', variant: 'default' },
);

type Rubro = {
    clave: string;
    titulo: string;
    cantidad: number;
    detalle: string[];
};
type Resumen = {
    persona: Rubro[];
    rh: Rubro[];
    total_persona: number;
    tiene_cuenta: boolean;
};

const { mostrarExito, mostrarError } = useAlertas();
const abierto = ref(false);
const cargando = ref(false);
const enviando = ref(false);
const resumen = ref<Resumen | null>(null);

const iconos: Record<string, Component> = {
    datos_personales: UserRound,
    documentos: FileUp,
    solicitud_empleo: ClipboardList,
    firmas: FileSignature,
    foto: Camera,
};

const puedeEnviar = computed(
    () =>
        !!resumen.value &&
        resumen.value.persona.length > 0 &&
        resumen.value.tiene_cuenta,
);

async function abrir(): Promise<void> {
    abierto.value = true;
    cargando.value = true;

    try {
        const r = await getJson<{ data: Resumen }>(
            rutaResumen.url(props.colaboradorId),
        );
        resumen.value = r.data;
    } catch (e) {
        abierto.value = false;
        await mostrarError(
            e instanceof Error ? e.message : 'No se pudo revisar qué falta.',
        );
    } finally {
        cargando.value = false;
    }
}

async function enviar(): Promise<void> {
    enviando.value = true;

    try {
        const r = await enviarJson<{ message: string }>(
            'POST',
            rutaPedir.url(props.colaboradorId),
            {},
        );
        abierto.value = false;
        await mostrarExito(r.message);
    } catch (e) {
        await mostrarError(
            e instanceof Error ? e.message : 'No se pudo enviar el aviso.',
        );
    } finally {
        enviando.value = false;
    }
}
</script>

<template>
    <Button type="button" :size="size" :variant="variant" @click="abrir">
        <Send class="size-4" /> Pedir información al colaborador
    </Button>

    <Dialog v-model:open="abierto">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Pedir información al colaborador</DialogTitle>
                <DialogDescription>
                    Le llega en la web y en la app, con todo lo que le falta.
                </DialogDescription>
            </DialogHeader>

            <p v-if="cargando" class="py-6 text-center text-sm text-muted-foreground">
                Revisando qué le falta…
            </p>

            <template v-else-if="resumen">
                <p
                    v-if="!resumen.persona.length"
                    class="rounded-2xl bg-success-soft px-4 py-3 text-sm"
                >
                    A la persona no le falta nada que ella pueda entregar.
                </p>
                <ul v-else class="flex flex-col gap-2">
                    <li
                        v-for="r in resumen.persona"
                        :key="r.clave"
                        class="rounded-2xl border border-border/70 bg-crema/40 px-4 py-3"
                    >
                        <div class="flex items-center gap-3">
                            <component
                                :is="iconos[r.clave] ?? ClipboardList"
                                class="size-4 shrink-0 text-[var(--mrl-primary)]"
                            />
                            <span class="flex-1 text-sm font-medium">{{
                                r.titulo
                            }}</span>
                            <span
                                class="rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold"
                                >{{ r.cantidad }}</span
                            >
                        </div>
                        <div
                            v-if="r.detalle.length"
                            class="mt-2 flex flex-wrap gap-1.5 pl-7"
                        >
                            <span
                                v-for="d in r.detalle"
                                :key="d"
                                class="rounded-full bg-card px-2 py-0.5 text-xs text-muted-foreground"
                                >{{ d }}</span
                            >
                        </div>
                    </li>
                </ul>

                <div
                    v-if="resumen.rh.length"
                    class="rounded-2xl bg-muted/50 px-4 py-3"
                >
                    <p class="flex items-center gap-2 text-sm font-medium">
                        <Wrench class="size-4" /> Esto lo completa RH (no se le
                        pide)
                    </p>
                    <ul class="mt-1.5 flex flex-col gap-1 pl-6 text-xs text-muted-foreground">
                        <li v-for="r in resumen.rh" :key="r.clave">
                            <strong>{{ r.titulo }}:</strong>
                            {{ r.detalle.join(', ') }}
                        </li>
                    </ul>
                </div>

                <p
                    v-if="resumen.persona.length && !resumen.tiene_cuenta"
                    class="rounded-2xl bg-warning-soft px-4 py-3 text-sm"
                >
                    Todavía no tiene cuenta: genérale credenciales para que
                    reciba el aviso.
                </p>
            </template>

            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    @click="abierto = false"
                    >Cancelar</Button
                >
                <Button
                    type="button"
                    :disabled="!puedeEnviar || enviando"
                    @click="enviar"
                >
                    <Send class="size-4" />
                    {{ enviando ? 'Enviando…' : 'Enviar a web y app' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
