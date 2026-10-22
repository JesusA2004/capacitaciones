<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardCheck, Eye, Lock, LockOpen } from '@lucide/vue';
import { computed, ref, useTemplateRef } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import MiInduccionTarjeta from '@/components/onboarding/MiInduccionTarjeta.vue';
import VisorInduccionColaborador from '@/components/onboarding/VisorInduccionColaborador.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { presentacion as induccionInstitucional } from '@/routes/induccion';
import { colaboradores as buscarColaboradores } from '@/routes/rh/onboarding/asignar';
import {
    vistaColaborador,
    vistaPrevia as vistaPreviaEvaluacion,
} from '@/routes/rh/onboarding/induccion';
import type { ColaboradorBusqueda, MiInduccion } from '@/types';

/**
 * «Ver como colaborador» (RH): EXACTAMENTE lo que una persona —o un
 * colaborador nuevo genérico— ve de su inducción en Mi espacio: la tarjeta
 * «Mi inducción» de su inicio, el visor (hojas + video/audio) y el paso de
 * la evaluación bloqueado o desbloqueado. Mismos componentes del portal en
 * modo vista previa: no se registra ninguna hoja, video ni evaluación.
 */
const props = defineProps<{
    colaborador: ColaboradorBusqueda | null;
    miInduccion: MiInduccion;
    pdf: { url: string; titulo: string; total_paginas: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            {
                title: 'Inducción institucional',
                href: induccionInstitucional(),
            },
            { title: 'Ver como colaborador', href: '' },
        ],
    },
});

const elegido = ref<ColaboradorBusqueda | null>(null);

function cambiar(c: ColaboradorBusqueda | null) {
    if (c) {
        router.visit(vistaColaborador.url(c.id));
    }
}

const visor = useTemplateRef('visor');

function irAlVisor() {
    visor.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

const evaluacionAbierta = computed(
    () => props.miInduccion.puede_contestar_evaluacion,
);
const tieneContenido = computed(
    () =>
        props.miInduccion.presentacion.incluida ||
        (props.miInduccion.material?.incluido ?? false),
);
</script>

<template>
    <Head title="Ver como colaborador" />

    <div class="pagina-ancha flex flex-col gap-5 pb-10">
        <CrudPageHeader titulo="Ver como colaborador" :icono="Eye">
            <Button as-child variant="ghost" class="w-full sm:w-auto">
                <Link :href="induccionInstitucional()"
                    ><ArrowLeft class="size-4" /> Volver</Link
                >
            </Button>
        </CrudPageHeader>

        <div
            class="flex flex-col gap-3 rounded-2xl border-2 border-dashed border-oro/60 bg-warning-soft/60 p-4 lg:flex-row lg:items-center"
            role="note"
        >
            <Eye class="size-6 shrink-0 text-warning" />
            <div class="min-w-0 flex-1">
                <p class="font-semibold">
                    Vista previa — así lo ve el colaborador
                </p>
                <p class="text-sm text-muted-foreground">
                    <template v-if="colaborador">
                        Estás viendo la inducción de
                        <strong>{{ colaborador.nombre }}</strong>
                        <template v-if="colaborador.puesto">
                            · {{ colaborador.puesto }}</template
                        >
                        con su avance real.
                    </template>
                    <template v-else>
                        Así la ve un colaborador nuevo al que se le acaba de
                        asignar.
                    </template>
                    Aquí no se registra nada: ni hojas, ni video, ni evaluación.
                </p>
            </div>
            <div class="w-full lg:w-96">
                <BuscadorColaborador
                    :model-value="elegido"
                    :buscar-url="
                        (t: string) =>
                            buscarColaboradores.url({ query: { q: t } })
                    "
                    @update:model-value="cambiar"
                />
            </div>
            <Button v-if="colaborador" as-child variant="outline" size="sm">
                <Link :href="vistaColaborador.url()"
                    >Ver como colaborador nuevo</Link
                >
            </Button>
        </div>

        <section class="flex flex-col gap-2">
            <h2 class="text-sm font-semibold text-muted-foreground">
                En su «Mi inicio» y «Mis pendientes»
            </h2>
            <MiInduccionTarjeta
                :induccion="miInduccion"
                vista-previa
                @accion="irAlVisor"
            />
        </section>

        <section ref="visor" class="flex scroll-mt-4 flex-col gap-2">
            <h2 class="text-sm font-semibold text-muted-foreground">
                Al entrar a «Mi inducción»
            </h2>
            <VisorInduccionColaborador
                v-if="miInduccion.puede_ver_contenido && tieneContenido"
                :pdf="pdf"
                :induccion="miInduccion"
                vista-previa
            />
            <p
                v-else
                class="rounded-2xl border border-dashed border-border/60 p-5 text-sm text-muted-foreground"
            >
                No ve el contenido ahora: «{{ miInduccion.mensaje }}»
            </p>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-sm font-semibold text-muted-foreground">
                Su evaluación
            </h2>
            <article
                class="flex flex-col gap-3 rounded-3xl border p-5 sm:flex-row sm:items-center"
                :class="
                    evaluacionAbierta
                        ? 'border-primary/40 bg-salvia/40'
                        : 'border-border/60 bg-muted/30'
                "
            >
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-2xl"
                    :class="
                        evaluacionAbierta
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted text-muted-foreground'
                    "
                >
                    <LockOpen v-if="evaluacionAbierta" class="size-5" />
                    <Lock v-else class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">
                        {{
                            evaluacionAbierta
                                ? 'Desbloqueada: la puede contestar'
                                : 'Bloqueada'
                        }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            miInduccion.pasos.find(
                                (p) => p.clave === 'evaluacion',
                            )?.detalle
                        }}
                        <template v-if="miInduccion.evaluacion">
                            · {{ miInduccion.evaluacion.etiqueta }}</template
                        >
                    </p>
                </div>
                <Button as-child variant="outline">
                    <Link :href="vistaPreviaEvaluacion()"
                        ><ClipboardCheck class="size-4" /> Ver la evaluación
                        como colaborador</Link
                    >
                </Button>
            </article>
        </section>
    </div>
</template>
