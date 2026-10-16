<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CheckCircle2, Clock, Presentation } from '@lucide/vue';
import { computed, ref } from 'vue';
import PresentacionInduccionRh from '@/components/ciclo/PresentacionInduccionRh.vue';
import VisorPdf from '@/components/documentos/VisorPdf.vue';
import { dashboard } from '@/routes';
import { miProceso } from '@/routes/portal';
import { contenido as evaluacionInduccion } from '@/routes/portal/induccion';
import type {
    AccesoInduccionColaborador,
    SesionInduccionPantalla,
} from '@/types';

/**
 * Inducción Mr. Lana = el PDF OFICIAL (docs/onboarding), dibujado página
 * por página con pdf.js. Nada se reconstruye en HTML.
 *  - «Presentar inducción» (modo operativo, solo RH, p. ej. por Meet): el
 *    visor + la sesión (a quién, Enviar evaluación, seguimiento).
 *  - «Mi espacio» (colaborador): nunca presenta. Solo VE el PDF mientras la
 *    sesión de RH que lo incluye está en curso; antes y después recibe el
 *    motivo (AccesoInduccionColaboradorService), y el backend no entrega el
 *    PDF fuera de la sesión.
 */
const props = defineProps<{
    /** null cuando el colaborador no puede verla todavía / ya no. */
    pdf: { url: string; titulo: string; total_paginas: number } | null;
    modoPresentacion?: boolean;
    /** RH: sesión de presentación (a quién, Enviar evaluación, seguimiento). */
    sesionRh?: SesionInduccionPantalla | null;
    /** Colaborador: cuándo puede ver la inducción. */
    acceso?: AccesoInduccionColaborador | null;
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

// RH: el visor aparece al iniciar la sesión (ya eligió a quién) o al
// repasar sin sesión. Colaborador: solo durante la sesión de RH.
const repasando = ref(false);
const muestraVisor = computed(() => {
    if (props.pdf === null) {
        return false;
    }

    if (props.sesionRh) {
        return props.sesionRh.sesion_en_curso !== null || repasando.value;
    }

    return props.acceso?.puede_ver_contenido ?? props.modoPresentacion === true;
});
</script>

<template>
    <Head
        :title="modoPresentacion ? 'Presentar inducción' : 'Inducción Mr. Lana'"
    />

    <div
        v-if="sesionRh && !muestraVisor"
        class="pagina-ancha flex flex-col gap-4 pb-10"
    >
        <PresentacionInduccionRh
            v-model:repasando="repasando"
            :datos="sesionRh"
        />
    </div>

    <div
        v-else-if="!muestraVisor || !pdf"
        class="pagina-ancha flex flex-col gap-4 pb-10"
    >
        <section
            class="flex flex-col gap-4 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-6"
        >
            <div class="flex items-start gap-3">
                <CheckCircle2
                    v-if="acceso?.estado === 'completada'"
                    class="size-8 shrink-0 text-success"
                />
                <Clock
                    v-else
                    class="size-8 shrink-0 text-[var(--mrl-primary)]"
                />
                <div class="min-w-0 flex-1">
                    <h1 class="text-lg font-semibold text-[var(--mrl-texto)]">
                        Inducción Mr. Lana
                    </h1>
                    <p class="mt-1 text-sm text-[var(--mrl-texto-suave)]">
                        {{
                            acceso?.mensaje ??
                            'Recursos Humanos te presentará la Inducción Mr. Lana.'
                        }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    v-if="acceso?.puede_contestar_evaluacion"
                    :href="evaluacionInduccion.url()"
                    class="inline-flex w-fit items-center gap-2 rounded-xl bg-[var(--mrl-primary)] px-4 py-2.5 text-sm font-medium text-white transition-all hover:gap-3 hover:bg-[var(--mrl-primary-alt)]"
                >
                    Contestar evaluación
                    <ArrowRight class="size-4" />
                </Link>
                <Link
                    :href="miProceso()"
                    class="inline-flex w-fit items-center gap-2 rounded-xl border border-[var(--mrl-borde)] px-4 py-2.5 text-sm font-medium text-[var(--mrl-texto)] transition-colors hover:bg-[var(--mrl-fondo)]"
                >
                    Ir a Mis pendientes
                </Link>
            </div>
        </section>
    </div>

    <div
        v-else
        class="pagina-ancha flex h-[calc(100dvh-6rem)] min-h-[32rem] flex-col gap-3"
    >
        <PresentacionInduccionRh
            v-if="sesionRh"
            v-model:repasando="repasando"
            :datos="sesionRh"
        />
        <div
            v-else-if="acceso"
            class="flex items-center gap-3 rounded-2xl border bg-card px-4 py-2.5 text-sm"
        >
            <Presentation class="size-4 shrink-0 text-[var(--mrl-primary)]" />
            <span>{{ acceso.mensaje }}</span>
        </div>

        <VisorPdf :url="pdf.url" :titulo="pdf.titulo" />
    </div>
</template>
