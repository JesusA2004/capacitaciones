<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    ClipboardList,
    GraduationCap,
    PencilLine,
    UserCheck,
} from '@lucide/vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { formatearFecha } from '@/lib/fechas';
import { estiloAcento } from '@/lib/formulariosEvaluacion';
import { dashboard } from '@/routes';
import { editar, index } from '@/routes/rh/formularios-evaluacion';
import type { ResumenFormularioEvaluacion } from '@/types';

/**
 * Módulo «Formularios de evaluación»: las dos evaluaciones que RH configura
 * como en Google Forms (inducción y capacitación inicial / periodo de
 * prueba). Cada tarjeta abre su editor.
 */
defineProps<{ formularios: ResumenFormularioEvaluacion[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Formularios de evaluación', href: index() },
        ],
    },
});
</script>

<template>
    <Head title="Formularios de evaluación" />

    <div class="pagina-ancha flex flex-col gap-6 pb-12">
        <CrudPageHeader
            titulo="Formularios de evaluación"
            descripcion="Configura las preguntas, el diseño y las reglas de las evaluaciones."
            :icono="ClipboardList"
        />

        <p class="max-w-3xl text-sm text-muted-foreground">
            Edita las preguntas, el diseño y las reglas de cada evaluación, y
            revisa la vista previa antes de publicar. Al publicar una versión
            nueva, solo las evaluaciones que se abran desde ese momento la usan:
            las que ya están en curso o contestadas conservan la versión con la
            que empezaron.
        </p>

        <div data-tour="formularios-tarjetas" class="grid gap-5 lg:grid-cols-2">
            <Link
                v-for="f in formularios"
                :key="f.clave"
                :href="editar(f.clave)"
                class="group flex tarjeta-interactiva flex-col overflow-hidden rounded-3xl border border-border bg-card shadow-sm"
                :style="estiloAcento(f.publicado.diseno)"
            >
                <div
                    class="relative flex h-28 items-end bg-[var(--acento)] p-5 text-[var(--acento-texto)]"
                >
                    <img
                        v-if="f.publicado.diseno.banner_url"
                        :src="f.publicado.diseno.banner_url"
                        alt=""
                        class="absolute inset-0 size-full object-cover opacity-60"
                    />
                    <span
                        class="relative flex size-12 items-center justify-center rounded-2xl bg-card/90 text-[var(--primary)] shadow-sm"
                    >
                        <GraduationCap
                            v-if="f.clave === 'induccion'"
                            class="size-6"
                        />
                        <UserCheck v-else class="size-6" />
                    </span>
                </div>
                <div class="flex flex-1 flex-col gap-3 p-5">
                    <div>
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            {{ f.etiqueta }}
                        </p>
                        <h2 class="mt-1 text-lg font-semibold tracking-tight">
                            {{ f.publicado.titulo }}
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ f.descripcion_modulo }}
                        </p>
                    </div>

                    <dl class="grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-xl bg-muted/40 p-2">
                            <dt class="text-xs text-muted-foreground">
                                Preguntas
                            </dt>
                            <dd class="text-lg font-semibold tabular-nums">
                                {{ f.resumen.preguntas }}
                            </dd>
                        </div>
                        <div class="rounded-xl bg-muted/40 p-2">
                            <dt class="text-xs text-muted-foreground">
                                Puntos
                            </dt>
                            <dd class="text-lg font-semibold tabular-nums">
                                {{ f.resumen.total_puntos }}
                            </dd>
                        </div>
                        <div class="rounded-xl bg-muted/40 p-2">
                            <dt class="text-xs text-muted-foreground">
                                {{
                                    f.clave === 'induccion'
                                        ? 'Aprueba con'
                                        : 'Renueva con'
                                }}
                            </dt>
                            <dd class="text-lg font-semibold tabular-nums">
                                {{ f.resumen.aprobatoria }}
                            </dd>
                        </div>
                    </dl>

                    <div
                        class="mt-auto flex flex-wrap items-center justify-between gap-2 text-sm"
                    >
                        <span class="text-muted-foreground">
                            Versión {{ f.publicado.version }} vigente
                            <template v-if="f.publicado.publicado_en">
                                · desde
                                {{ formatearFecha(f.publicado.publicado_en) }}
                            </template>
                        </span>
                        <span
                            v-if="f.borrador"
                            class="inline-flex items-center gap-1 rounded-full bg-warning-soft px-2.5 py-0.5 text-xs font-medium"
                        >
                            <PencilLine class="size-3.5" /> Borrador de la
                            versión {{ f.borrador.version }} sin publicar
                        </span>
                        <span
                            class="inline-flex items-center gap-1 font-medium text-[var(--primary)]"
                        >
                            Abrir editor
                            <ArrowRight
                                class="size-4 transition-transform group-hover:translate-x-0.5"
                            />
                        </span>
                    </div>
                </div>
            </Link>
        </div>
    </div>
</template>
