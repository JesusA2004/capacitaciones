<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, Compass, Route } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useNavegacion } from '@/composables/useNavegacion';
import { usePermisos } from '@/composables/usePermisos';
import { useTourGuiado } from '@/composables/useTourGuiado';
import {
    modulosDisponibles,
    tourCompleto,
    tourDeModulo,
} from '@/lib/tours/registro';
import type { ContextoGuia, ModuloGuia } from '@/lib/tours/tipos';
import { cn } from '@/lib/utils';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ayuda', href: '/ayuda' }],
    },
});

const { tienePermiso } = usePermisos();
const { esColaborador, tieneAmbosModos } = useNavegacion();
const { iniciar, haVisto } = useTourGuiado();

const contexto = computed<ContextoGuia>(() => ({
    tienePermiso,
    modo: esColaborador.value ? 'colaborador' : 'operativo',
}));

const modulos = computed(() => modulosDisponibles(contexto.value));
const recorrido = computed(() => tourCompleto(contexto.value));

const TONO_GRUPO: Record<string, string> = {
    Panel: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    Personal: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    Estructura: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    Reclutamiento: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    Análisis: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
    Administración: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
    'Mi espacio': 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
};

const grupos = computed(() => {
    const porGrupo = new Map<string, ModuloGuia[]>();

    for (const modulo of modulos.value) {
        porGrupo.set(modulo.grupo, [
            ...(porGrupo.get(modulo.grupo) ?? []),
            modulo,
        ]);
    }

    return [...porGrupo.entries()].map(([titulo, lista]) => ({
        titulo,
        modulos: lista,
    }));
});

const vistos = computed(
    () => modulos.value.filter((modulo) => haVisto(modulo.id)).length,
);
</script>

<template>
    <Head title="Ayuda" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-8 p-4">
        <div
            class="flex flex-col items-center gap-6 rounded-2xl bg-gradient-to-br from-primary/10 via-primary/5 to-transparent p-6 text-center sm:p-10"
        >
            <span
                class="flex size-16 items-center justify-center rounded-full bg-primary/10 text-primary"
            >
                <Compass class="size-8" />
            </span>

            <div class="max-w-2xl space-y-2">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Guía del sistema
                </h1>
                <p
                    class="text-sm text-pretty text-muted-foreground sm:text-base"
                >
                    El recorrido completo te lleva, pantalla por pantalla, por
                    los {{ modulos.length }} módulos que tienes disponibles y te
                    explica para qué sirve cada parte. También puedes aprender
                    un solo módulo con "Ver cómo funciona".
                </p>
            </div>

            <div class="flex flex-col items-center gap-2">
                <Button size="lg" class="gap-2" @click="iniciar(recorrido)">
                    <Route class="size-4" />
                    Iniciar recorrido completo
                </Button>
                <p class="text-xs text-muted-foreground">
                    {{ recorrido.pasos.length }} pasos · puedes saltar módulos o
                    salir cuando quieras
                    <template v-if="modulos.length">
                        · {{ vistos }}/{{ modulos.length }} módulos vistos
                    </template>
                </p>
                <p
                    v-if="tieneAmbosModos"
                    class="max-w-md text-xs text-pretty text-muted-foreground"
                >
                    Estás en
                    <strong>{{
                        esColaborador ? 'Mi espacio' : 'Operación RH'
                    }}</strong
                    >. Cambia de modo en el menú lateral para ver la guía del
                    otro.
                </p>
            </div>
        </div>

        <div
            v-for="(grupo, indiceGrupo) in grupos"
            :key="grupo.titulo"
            class="animate-in space-y-3 fill-mode-both fade-in slide-in-from-bottom-2"
            :style="{ animationDelay: `${indiceGrupo * 60}ms` }"
        >
            <h2
                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ grupo.titulo }}
            </h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="modulo in grupo.modulos"
                    :key="modulo.id"
                    class="flex flex-col gap-3 rounded-xl border p-4 transition-[border-color,box-shadow] duration-200 hover:border-primary/20 hover:shadow-sm"
                >
                    <div class="flex items-start gap-3">
                        <span
                            :class="
                                cn(
                                    'flex size-9 shrink-0 items-center justify-center rounded-full',
                                    TONO_GRUPO[modulo.grupo] ??
                                        TONO_GRUPO.Panel,
                                )
                            "
                        >
                            <component :is="modulo.icono" class="size-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-1.5 font-medium">
                                {{ modulo.nombre }}
                                <CheckCircle2
                                    v-if="haVisto(modulo.id)"
                                    class="size-3.5 text-emerald-600 dark:text-emerald-400"
                                    aria-label="Ya visto"
                                />
                            </p>
                            <p
                                class="text-sm text-pretty text-muted-foreground"
                            >
                                {{ modulo.descripcion }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-auto flex items-center gap-2 pt-1">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                            @click="iniciar(tourDeModulo(modulo))"
                        >
                            <Compass class="size-3.5" /> Ver cómo funciona
                            <span
                                class="text-xs font-normal text-muted-foreground"
                            >
                                ({{ modulo.pasos.length }} pasos)
                            </span>
                        </button>
                        <Link
                            :href="modulo.ruta"
                            class="ml-auto text-xs text-muted-foreground hover:underline"
                        >
                            Ir directo
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
