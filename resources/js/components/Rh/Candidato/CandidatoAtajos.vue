<script setup lang="ts">
import {
    ArrowRight,
    Brain,
    ClipboardList,
    FolderOpen,
    Home,
    MessagesSquare,
    UserCheck,
    UserSearch,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { TONO_SUAVE } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';
import type { CandidatoFicha, PerfilPuestoCandidato } from '@/types';

/**
 * Las secciones secundarias de la ficha en una línea cada una (evaluaciones,
 * expediente, perfil buscado): se ve el estado de un vistazo y «Ver detalle»
 * lleva a su pestaña. Así el resumen no se vuelve una fila larga.
 */
const props = defineProps<{
    candidato: CandidatoFicha;
    perfilPuesto: PerfilPuestoCandidato;
}>();

const emit = defineEmits<{
    ir: [pestana: 'evaluaciones' | 'expediente' | 'perfil'];
}>();

function tonoDe(resultado: string | null | undefined, hay: boolean): Tono {
    if (!hay) {
        return 'neutro';
    }

    if (resultado === 'viable' || resultado === 'positiva') {
        return 'exito';
    }

    if (resultado === 'no_viable' || resultado === 'negativa') {
        return 'peligro';
    }

    return 'alerta';
}

function ultimo<T>(lista: T[]): T | undefined {
    return lista[lista.length - 1];
}

const evaluaciones = computed<
    { etiqueta: string; estado: string; icono: Component; tono: Tono }[]
>(() => {
    const c = props.candidato;
    const entrevista = ultimo(c.entrevistas);
    const psicometrica = ultimo(c.psicometricas);
    const socioeconomico = ultimo(c.socioeconomicos);
    const referencia = ultimo(c.referencias);

    return [
        {
            etiqueta: 'Entrevista',
            estado: entrevista?.resultado_etiqueta ?? 'Sin registrar',
            icono: MessagesSquare,
            tono: tonoDe(entrevista?.resultado, !!entrevista),
        },
        {
            etiqueta: 'Psicométricas',
            estado: !psicometrica
                ? 'Sin registrar'
                : psicometrica.revision_resultado === 'viable'
                  ? 'En perfil'
                  : psicometrica.revision_resultado === 'no_viable'
                    ? 'Fuera de perfil'
                    : 'Pendiente',
            icono: Brain,
            tono: tonoDe(psicometrica?.revision_resultado, !!psicometrica),
        },
        {
            etiqueta: 'Socioeconómico',
            estado: socioeconomico?.resultado_etiqueta ?? 'Sin registrar',
            icono: Home,
            tono: tonoDe(socioeconomico?.resultado, !!socioeconomico),
        },
        {
            etiqueta:
                c.referencias.length > 1
                    ? `Referencias (${c.referencias.length})`
                    : 'Referencias',
            estado: referencia?.resultado_etiqueta ?? 'Sin registrar',
            icono: UserCheck,
            tono: tonoDe(referencia?.resultado, !!referencia),
        },
    ];
});

const evaluacionesHechas = computed(
    () => evaluaciones.value.filter((e) => e.tono !== 'neutro').length,
);

/** Secciones del mini expediente con al menos un archivo (CV incluido). */
const expediente = computed(() => {
    const e = props.candidato.expediente;
    const secciones = [
        { titulo: 'CV', lleno: e.cv !== null || props.candidato.tiene_cv },
        ...e.grupos.map((g) => ({
            titulo: g.titulo,
            lleno: g.archivos.length > 0,
        })),
    ];

    return {
        total: e.total,
        llenas: secciones.filter((s) => s.lleno).length,
        secciones,
    };
});

const perfil = computed(() => {
    const datos = props.perfilPuesto?.perfil;

    if (!props.perfilPuesto) {
        return null;
    }

    return {
        puesto: props.perfilPuesto.puesto,
        resumen: datos
            ? [
                  `${datos.conocimientos.length} conocimientos`,
                  `${datos.competencias.length} competencias`,
                  datos.escolaridad,
              ].filter((t): t is string => !!t)
            : ['Este puesto aún no tiene perfil'],
    };
});
</script>

<template>
    <div
        class="grid gap-3 md:grid-cols-3"
        role="group"
        aria-label="Resumen de evaluaciones, expediente y perfil"
    >
        <button
            type="button"
            class="flex min-w-0 tarjeta-interactiva flex-col gap-2.5 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3.5 text-left shadow-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            data-atajo="evaluaciones"
            @click="emit('ir', 'evaluaciones')"
        >
            <span class="flex w-full items-center gap-2 text-sm font-semibold">
                <ClipboardList class="size-4 text-info" aria-hidden="true" />
                Evaluaciones
                <span
                    class="ml-auto rounded-full bg-crema px-2 py-0.5 text-xs text-bronce tabular-nums"
                    >{{ evaluacionesHechas }} de {{ evaluaciones.length }}</span
                >
            </span>
            <span class="grid w-full grid-cols-4 gap-1.5">
                <span
                    v-for="e in evaluaciones"
                    :key="e.etiqueta"
                    class="flex min-w-0 flex-col items-center gap-1 text-center"
                    :title="`${e.etiqueta}: ${e.estado}`"
                >
                    <span
                        class="flex size-9 items-center justify-center rounded-xl"
                        :class="TONO_SUAVE[e.tono]"
                    >
                        <component
                            :is="e.icono"
                            class="size-4"
                            aria-hidden="true"
                        />
                    </span>
                    <span
                        class="w-full truncate text-[11px] leading-tight text-muted-foreground"
                        >{{ e.estado }}</span
                    >
                    <span class="sr-only">{{ e.etiqueta }}</span>
                </span>
            </span>
            <span
                class="mt-auto inline-flex items-center gap-1 text-xs font-semibold text-[var(--mrl-petroleo)]"
            >
                Ver detalle <ArrowRight class="size-3.5" aria-hidden="true" />
            </span>
        </button>

        <button
            type="button"
            class="flex min-w-0 tarjeta-interactiva flex-col gap-2.5 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3.5 text-left shadow-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            data-atajo="expediente"
            @click="emit('ir', 'expediente')"
        >
            <span class="flex w-full items-center gap-2 text-sm font-semibold">
                <FolderOpen class="size-4 text-primary" aria-hidden="true" />
                Expediente y archivos
                <span
                    class="ml-auto rounded-full bg-crema px-2 py-0.5 text-xs text-bronce tabular-nums"
                    >{{ expediente.llenas }} de
                    {{ expediente.secciones.length }}</span
                >
            </span>
            <span class="flex w-full gap-1" aria-hidden="true">
                <span
                    v-for="s in expediente.secciones"
                    :key="s.titulo"
                    class="h-2 flex-1 rounded-full"
                    :class="s.lleno ? 'bg-success' : 'bg-muted'"
                    :title="`${s.titulo}: ${s.lleno ? 'con archivos' : 'sin archivos'}`"
                />
            </span>
            <span class="text-xs break-words text-muted-foreground">
                {{ expediente.total }}
                {{ expediente.total === 1 ? 'archivo' : 'archivos' }} ·
                {{
                    expediente.secciones[0].lleno ? 'CV cargado' : 'falta el CV'
                }}
            </span>
            <span
                class="mt-auto inline-flex items-center gap-1 text-xs font-semibold text-[var(--mrl-petroleo)]"
            >
                Ver detalle <ArrowRight class="size-3.5" aria-hidden="true" />
            </span>
        </button>

        <button
            v-if="perfil"
            type="button"
            class="flex min-w-0 tarjeta-interactiva flex-col gap-2.5 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3.5 text-left shadow-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            data-atajo="perfil"
            @click="emit('ir', 'perfil')"
        >
            <span class="flex w-full items-center gap-2 text-sm font-semibold">
                <UserSearch class="size-4 text-bronce" aria-hidden="true" />
                Perfil buscado
            </span>
            <span class="text-sm font-medium break-words">{{
                perfil.puesto
            }}</span>
            <span class="flex flex-wrap gap-1.5">
                <span
                    v-for="texto in perfil.resumen"
                    :key="texto"
                    class="rounded-full bg-crema px-2 py-0.5 text-xs break-words text-bronce"
                    >{{ texto }}</span
                >
            </span>
            <span
                class="mt-auto inline-flex items-center gap-1 text-xs font-semibold text-[var(--mrl-petroleo)]"
            >
                Ver detalle <ArrowRight class="size-3.5" aria-hidden="true" />
            </span>
        </button>
    </div>
</template>
