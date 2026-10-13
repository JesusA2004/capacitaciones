<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, Loader2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import VisorPdf from '@/components/documentos/VisorPdf.vue';
import { Button } from '@/components/ui/button';
import { enviarJson } from '@/lib/http';
import { dashboard } from '@/routes';
import { miProceso } from '@/routes/portal';
import { pagina as paginaRuta, terminar } from '@/routes/portal/induccion';
import type { InduccionContenidoResumen } from '@/types';

/**
 * Inducción Mr. Lana = el PDF OFICIAL (docs/onboarding), dibujado página
 * por página con pdf.js. Nada se reconstruye en HTML.
 *  - Presentar inducción (RH, p. ej. por Meet): mismo visor, sin registrar
 *    progreso de nadie.
 *  - Colaborador: cada página MOSTRADA se registra; al recorrerlas todas,
 *    «Terminé la inducción» lo lleva a su evaluación.
 */
const props = defineProps<{
    /** null en modo presentación: no hay avance de nadie detrás. */
    avanceId: number | null;
    pdf: { url: string; titulo: string; total_paginas: number };
    resumen: InduccionContenidoResumen | null;
    modoPresentacion?: boolean;
}>();

// `layout` como función: defineOptions() se compila fuera de setup() y no
// puede leer `props` (ver Rh/Importaciones/Show.vue).
defineOptions({
    layout: (pageProps: { modoPresentacion?: boolean }) => ({
        breadcrumbs: pageProps.modoPresentacion
            ? [
                  { title: 'Inicio', href: dashboard() },
                  { title: 'Presentar inducción', href: '' },
              ]
            : [
                  { title: 'Inicio', href: dashboard() },
                  { title: 'Mis pendientes', href: miProceso() },
                  { title: 'Inducción Mr. Lana', href: '' },
              ],
    }),
});

const registraAvance = computed(
    () => !props.modoPresentacion && props.avanceId !== null,
);
const vistas = ref(new Set<number>(props.resumen?.paginas_vistas ?? []));
const total = ref(props.pdf.total_paginas);
const completado = ref(props.resumen?.completado ?? false);
const terminando = ref(false);
const faltantes = computed(() => Math.max(0, total.value - vistas.value.size));

function alMostrarPagina(numero: number, totalPdf: number) {
    total.value = totalPdf;

    if (!registraAvance.value || vistas.value.has(numero)) {
        return;
    }

    vistas.value = new Set(vistas.value).add(numero);
    // Sin bloquear la lectura: si falla la red, la página se vuelve a
    // reportar la próxima vez que se muestre.
    enviarJson('POST', paginaRuta.url(props.avanceId!), {
        pagina: numero,
    }).catch(() => {
        const copia = new Set(vistas.value);
        copia.delete(numero);
        vistas.value = copia;
    });
}

function terminarInduccion() {
    if (props.avanceId === null || terminando.value) {
        return;
    }

    terminando.value = true;
    router.post(
        terminar.url(props.avanceId),
        {},
        { onFinish: () => (terminando.value = false) },
    );
}
</script>

<template>
    <Head :title="modoPresentacion ? 'Presentar inducción' : pdf.titulo" />

    <div
        class="pagina-ancha flex h-[calc(100dvh-6rem)] min-h-[32rem] flex-col gap-3"
    >
        <div
            v-if="registraAvance"
            class="flex items-center gap-3 rounded-2xl border bg-card px-4 py-2.5 text-sm"
        >
            <template v-if="completado">
                <CheckCircle2 class="size-4 text-success" />
                <span
                    >Ya terminaste tu inducción. Puedes repasarla cuando
                    quieras.</span
                >
            </template>
            <template v-else>
                <span class="text-muted-foreground">Tu avance</span>
                <div
                    class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="vistas.size"
                    aria-valuemin="0"
                    :aria-valuemax="total"
                >
                    <div
                        class="h-full rounded-full bg-primary transition-[width] duration-200"
                        :style="{
                            width: `${total ? (vistas.size / total) * 100 : 0}%`,
                        }"
                    />
                </div>
                <span class="tabular-nums"
                    >{{ vistas.size }} / {{ total }}</span
                >
            </template>
        </div>

        <VisorPdf
            :url="pdf.url"
            :titulo="pdf.titulo"
            :pagina-inicial="resumen?.ultima_pagina ?? 1"
            @pagina="alMostrarPagina"
        >
            <template v-if="registraAvance && !completado" #ultima>
                <Button
                    :disabled="faltantes > 0 || terminando"
                    :title="
                        faltantes > 0
                            ? `Te faltan ${faltantes} página(s)`
                            : undefined
                    "
                    @click="terminarInduccion"
                >
                    <Loader2 v-if="terminando" class="size-4 animate-spin" />
                    <CheckCircle2 v-else class="size-4" />
                    Terminé la inducción
                </Button>
            </template>
            <template
                v-if="registraAvance && !completado && faltantes > 0"
                #centro
            >
                <span class="text-xs text-muted-foreground"
                    >Te faltan {{ faltantes }} página(s)</span
                >
            </template>
        </VisorPdf>
    </div>
</template>
