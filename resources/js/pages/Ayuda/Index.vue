<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BookOpen, CheckCircle2, Compass, Route, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useNavegacion } from '@/composables/useNavegacion';
import { usePermisos } from '@/composables/usePermisos';
import { useTourGuiado } from '@/composables/useTourGuiado';
import { AYUDA_ESCRITA } from '@/lib/tours/ayudaEscrita';
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
const busqueda = ref('');

function normalizar(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

/**
 * Tour de cada módulo ya filtrado por los permisos del usuario: la guía
 * escrita y el recorrido interactivo muestran exactamente los mismos pasos.
 */
const tours = computed(
    () =>
        new Map(
            modulos.value.map((modulo) => [
                modulo.id,
                tourDeModulo(modulo, contexto.value),
            ]),
        ),
);

/** Búsqueda por nombre, descripción o el texto de cualquiera de sus pasos. */
const modulosFiltrados = computed(() => {
    const termino = normalizar(busqueda.value.trim());

    if (termino === '') {
        return modulos.value;
    }

    return modulos.value.filter((modulo) => {
        const pasos = tours.value.get(modulo.id)?.pasos ?? [];
        const ayuda = AYUDA_ESCRITA[modulo.id];
        const texto = [
            modulo.nombre,
            modulo.descripcion,
            ...pasos.flatMap((p) => [p.titulo, p.texto, p.consejo ?? '']),
            ...(ayuda
                ? [
                      ayuda.queEs,
                      ...ayuda.puedes,
                      ...ayuda.flujo,
                      ayuda.permisos,
                      ...ayuda.errores,
                  ]
                : []),
        ].join(' ');

        return normalizar(texto).includes(termino);
    });
});
const recorrido = computed(() => tourCompleto(contexto.value));

const TONO_GRUPO: Record<string, string> = {
    Panel: 'bg-info/10 text-info',
    Personal: 'bg-success/10 text-success',
    Estructura: 'bg-crema text-bronce',
    Reclutamiento: 'bg-warning/10 text-warning',
    Análisis: 'bg-info/10 text-info',
    Administración: 'bg-muted text-muted-foreground',
    'Mi espacio': 'bg-info/10 text-info',
};

const grupos = computed(() => {
    const porGrupo = new Map<string, ModuloGuia[]>();

    for (const modulo of modulosFiltrados.value) {
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

    <div class="pagina-media flex flex-col gap-8">
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
                    un solo módulo con "Iniciar recorrido", o leer su guía
                    escrita con "Leer la guía".
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

        <div class="relative mx-auto w-full max-w-xl">
            <Search
                class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
            />
            <Input
                v-model="busqueda"
                type="search"
                placeholder="Buscar en la guía (p. ej. «vacaciones», «PDF», «aniversario»)…"
                aria-label="Buscar en la guía"
                class="pl-9"
            />
        </div>

        <p
            v-if="modulosFiltrados.length === 0"
            class="text-center text-sm text-muted-foreground"
        >
            Ningún módulo de tu guía coincide con «{{ busqueda }}».
        </p>

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
                                    class="size-3.5 text-success"
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
                    <!-- Guía escrita: el módulo se puede aprender aunque el
                         recorrido interactivo no encuentre un elemento. -->
                    <details class="group/guia text-sm">
                        <summary
                            class="inline-flex cursor-pointer list-none items-center gap-1.5 text-muted-foreground hover:text-foreground"
                        >
                            <BookOpen class="size-3.5" />
                            Leer la guía
                        </summary>
                        <div
                            v-if="AYUDA_ESCRITA[modulo.id]"
                            class="mt-3 space-y-3 text-pretty"
                        >
                            <section>
                                <h3
                                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Qué es
                                </h3>
                                <p>{{ AYUDA_ESCRITA[modulo.id].queEs }}</p>
                            </section>
                            <section>
                                <h3
                                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Qué puedes hacer
                                </h3>
                                <ul class="list-disc space-y-0.5 pl-5">
                                    <li
                                        v-for="punto in AYUDA_ESCRITA[modulo.id]
                                            .puedes"
                                        :key="punto"
                                    >
                                        {{ punto }}
                                    </li>
                                </ul>
                            </section>
                            <section>
                                <h3
                                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Flujo principal
                                </h3>
                                <ol class="list-decimal space-y-0.5 pl-5">
                                    <li
                                        v-for="punto in AYUDA_ESCRITA[modulo.id]
                                            .flujo"
                                        :key="punto"
                                    >
                                        {{ punto }}
                                    </li>
                                </ol>
                            </section>
                            <section>
                                <h3
                                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Quién tiene permiso
                                </h3>
                                <p>{{ AYUDA_ESCRITA[modulo.id].permisos }}</p>
                            </section>
                            <section>
                                <h3
                                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Errores frecuentes
                                </h3>
                                <ul class="list-disc space-y-0.5 pl-5">
                                    <li
                                        v-for="punto in AYUDA_ESCRITA[modulo.id]
                                            .errores"
                                        :key="punto"
                                    >
                                        {{ punto }}
                                    </li>
                                </ul>
                            </section>
                        </div>
                        <h3
                            class="mt-4 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Paso a paso en pantalla
                        </h3>
                        <ol
                            class="mt-2 list-decimal space-y-2 pl-5 text-pretty"
                        >
                            <li
                                v-for="(paso, indice) in tours.get(modulo.id)
                                    ?.pasos ?? []"
                                :key="indice"
                            >
                                <p class="font-medium">{{ paso.titulo }}</p>
                                <p class="text-muted-foreground">
                                    {{ paso.texto }}
                                </p>
                                <p
                                    v-if="paso.consejo"
                                    class="mt-0.5 text-xs text-muted-foreground italic"
                                >
                                    Consejo: {{ paso.consejo }}
                                </p>
                            </li>
                        </ol>
                    </details>
                    <div class="mt-auto flex items-center gap-2 pt-1">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                            @click="
                                iniciar(
                                    tours.get(modulo.id) ??
                                        tourDeModulo(modulo, contexto),
                                )
                            "
                        >
                            <Compass class="size-3.5" /> Iniciar recorrido
                            <span
                                class="text-xs font-normal text-muted-foreground"
                            >
                                ({{ tours.get(modulo.id)?.pasos.length ?? 0 }}
                                pasos)
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
