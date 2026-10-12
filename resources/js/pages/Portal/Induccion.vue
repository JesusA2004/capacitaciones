<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, CheckCircle2, Network } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import MiniOrganigramaNodo from '@/components/organigrama/MiniOrganigramaNodo.vue';
import { Button } from '@/components/ui/button';
import { getJson } from '@/lib/http';
import { dashboard } from '@/routes';
import { index as organigramaCompletoIndex } from '@/routes/administracion/jerarquia-puestos';
import { organigrama as organigramaPresentacion } from '@/routes/induccion/presentacion';
import { miProceso } from '@/routes/portal';
import { organigrama, progreso } from '@/routes/portal/induccion';
import type {
    CapituloInduccion,
    InduccionContenidoResumen,
    MiniOrganigramaNodo as MiniOrganigramaNodoType,
} from '@/types';

const props = defineProps<{
    /** null en modo presentación (RH/colaborador por Meet): sin progreso. */
    avanceId: number | null;
    capitulos: CapituloInduccion[];
    resumen: InduccionContenidoResumen | null;
    puedeVerOrganigramaCompleto: boolean;
    modoPresentacion?: boolean;
}>();

// `layout` como función: defineOptions() se compila fuera de setup() y no
// puede leer `props` (ver Rh/Importaciones/Show.vue).
defineOptions({
    layout: (pageProps: { modoPresentacion?: boolean }) => ({
        breadcrumbs: pageProps.modoPresentacion
            ? [
                  { title: 'Inicio', href: dashboard() },
                  { title: 'Inducción Mr. Lana · presentación', href: '' },
              ]
            : [
                  { title: 'Inicio', href: dashboard() },
                  { title: 'Mis pendientes', href: miProceso() },
                  { title: 'Inducción Mr. Lana', href: '' },
              ],
    }),
});

// Claves exactas que guardó el servidor (se puede saltar entre capítulos);
// el conteo solo queda como respaldo para respuestas anteriores.
const vistosIniciales = new Set(
    props.resumen?.claves_vistas ??
        props.capitulos
            .map((c) => c.clave)
            .filter((_, i) => i < (props.resumen?.capitulos_vistos ?? 0)),
);

const indice = ref(
    Math.max(
        0,
        props.capitulos.findIndex(
            (c) => c.clave === props.resumen?.ultima_capitulo,
        ),
    ),
);
const vistos = ref<Set<string>>(vistosIniciales);
const enviando = ref(false);

const capituloActual = computed(() => props.capitulos[indice.value]);
const totalCapitulos = computed(() => props.capitulos.length);

// Organigrama dinámico (capítulo 4): se pide por vista la primera vez que
// se muestra y se guarda en caché — nunca una imagen fija ni datos a mano.
const organigramas = ref<Record<string, MiniOrganigramaNodoType | null>>({});
const organigramasCargando = ref<Record<string, boolean>>({});
const organigramasError = ref<Record<string, boolean>>({});

async function cargarOrganigrama(vista: string) {
    if (vista in organigramas.value || organigramasCargando.value[vista]) {
        return;
    }

    organigramasCargando.value[vista] = true;
    organigramasError.value[vista] = false;

    try {
        const respuesta = await getJson<{ data: MiniOrganigramaNodoType }>(
            props.modoPresentacion
                ? organigramaPresentacion.url({ vista })
                : organigrama.url({ vista }),
        );
        organigramas.value[vista] = respuesta.data;
    } catch {
        organigramasError.value[vista] = true;
    } finally {
        organigramasCargando.value[vista] = false;
    }
}

watch(
    capituloActual,
    (c) => {
        c?.bloques.forEach((bloque) => {
            if (bloque.tipo === 'organigrama_dinamico') {
                cargarOrganigrama(bloque.vista);
            }
        });
    },
    { immediate: true },
);
const porcentaje = computed(() =>
    totalCapitulos.value
        ? Math.round((vistos.value.size / totalCapitulos.value) * 100)
        : 0,
);
const completado = computed(() => vistos.value.size >= totalCapitulos.value);
const esUltimo = computed(() => indice.value === totalCapitulos.value - 1);

function registrarVisto(clave: string) {
    if (vistos.value.has(clave)) {
        return;
    }

    // Modo presentación: solo se marca en pantalla, nunca se registra
    // progreso (no hay avance de un colaborador detrás).
    if (props.modoPresentacion || props.avanceId === null) {
        vistos.value = new Set(vistos.value).add(clave);

        return;
    }

    enviando.value = true;
    router.post(
        progreso.url(props.avanceId),
        { capitulo: clave },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                enviando.value = false;
            },
        },
    );
    vistos.value = new Set(vistos.value).add(clave);
}

function siguiente() {
    registrarVisto(capituloActual.value.clave);

    if (!esUltimo.value) {
        indice.value += 1;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function anterior() {
    if (indice.value > 0) {
        indice.value -= 1;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function irA(i: number) {
    indice.value = i;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>

<template>
    <Head title="Inducción Mr. Lana" />

    <div class="pagina-ancha flex flex-col gap-6 pb-16">
        <header
            class="flex flex-col gap-3 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-6"
        >
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1
                        class="text-2xl font-semibold tracking-tight text-[var(--mrl-texto)]"
                    >
                        Inducción Mr. Lana
                    </h1>
                    <p class="text-sm text-[var(--mrl-texto-suave)]">
                        {{
                            capituloActual?.subtitulo ??
                            'Programa de inducción para nuevos colaboradores'
                        }}
                    </p>
                </div>
                <span
                    class="shrink-0 rounded-full bg-[var(--mrl-fondo)] px-3 py-1 text-sm font-medium text-[var(--mrl-texto)] tabular-nums"
                >
                    {{ vistos.size }} / {{ totalCapitulos }}
                </span>
            </div>

            <div class="h-2 overflow-hidden rounded-full bg-[var(--mrl-fondo)]">
                <div
                    class="h-full rounded-full bg-[var(--mrl-primary)] transition-all"
                    :style="{ width: `${porcentaje}%` }"
                />
            </div>

            <nav class="flex flex-wrap gap-2">
                <button
                    v-for="(c, i) in capitulos"
                    :key="c.clave"
                    type="button"
                    class="flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                    :class="[
                        i === indice
                            ? 'border-[var(--mrl-primary)] bg-[var(--mrl-primary)]/10 text-[var(--mrl-primary)]'
                            : 'border-[var(--mrl-borde)] text-[var(--mrl-texto-suave)] hover:border-[var(--mrl-primary)]/40',
                    ]"
                    @click="irA(i)"
                >
                    <CheckCircle2
                        v-if="vistos.has(c.clave)"
                        class="size-3.5 text-[var(--mrl-success)]"
                    />
                    {{ c.numero }}. {{ c.titulo }}
                </button>
            </nav>
        </header>

        <article
            v-if="capituloActual"
            class="flex flex-col gap-5 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-6"
        >
            <div>
                <p
                    class="text-xs font-semibold tracking-wide text-[var(--mrl-primary)] uppercase"
                >
                    Capítulo {{ capituloActual.numero }} de
                    {{ totalCapitulos }}
                </p>
                <h2
                    class="text-xl font-semibold tracking-tight text-[var(--mrl-texto)]"
                >
                    {{ capituloActual.titulo }}
                </h2>
            </div>

            <template v-for="(bloque, bi) in capituloActual.bloques" :key="bi">
                <div
                    v-if="bloque.tipo === 'parrafo'"
                    class="flex flex-col gap-1.5"
                >
                    <h3
                        v-if="bloque.titulo"
                        class="text-base font-semibold text-[var(--mrl-texto)]"
                    >
                        {{ bloque.titulo }}
                    </h3>
                    <p
                        class="max-w-prose text-sm text-[var(--mrl-texto-suave)]"
                    >
                        {{ bloque.texto }}
                    </p>
                </div>

                <p
                    v-else-if="bloque.tipo === 'destacado'"
                    class="rounded-2xl bg-[var(--mrl-gold)]/10 p-4 text-sm font-medium text-[var(--mrl-texto)]"
                >
                    {{ bloque.texto }}
                </p>

                <div
                    v-else-if="bloque.tipo === 'datos'"
                    class="grid gap-3 sm:grid-cols-3"
                >
                    <div
                        v-for="(d, di) in bloque.items"
                        :key="di"
                        class="rounded-2xl border border-[var(--mrl-borde)] p-3"
                    >
                        <p
                            class="text-xs font-medium text-[var(--mrl-texto-suave)]"
                        >
                            {{ d.etiqueta }}
                        </p>
                        <p
                            class="text-sm font-semibold text-[var(--mrl-texto)]"
                        >
                            {{ d.valor }}
                        </p>
                    </div>
                </div>

                <div
                    v-else-if="bloque.tipo === 'lista'"
                    class="flex flex-col gap-2"
                >
                    <h3
                        v-if="bloque.titulo"
                        class="text-base font-semibold text-[var(--mrl-texto)]"
                    >
                        {{ bloque.titulo }}
                    </h3>
                    <p
                        v-if="bloque.texto"
                        class="text-sm"
                        :class="
                            bloque.destacado
                                ? 'font-medium text-destructive'
                                : 'text-[var(--mrl-texto-suave)]'
                        "
                    >
                        {{ bloque.texto }}
                    </p>
                    <ul
                        class="grid gap-2 sm:grid-cols-2"
                        :class="bloque.destacado ? '' : undefined"
                    >
                        <li
                            v-for="(item, ii) in bloque.items"
                            :key="ii"
                            class="rounded-xl border p-2.5 text-sm"
                            :class="
                                bloque.destacado
                                    ? 'border-destructive/30 bg-destructive/5 text-[var(--mrl-texto)]'
                                    : 'border-[var(--mrl-borde)] text-[var(--mrl-texto)]'
                            "
                        >
                            {{ item }}
                        </li>
                    </ul>
                </div>

                <div
                    v-else-if="bloque.tipo === 'tarjetas'"
                    class="flex flex-col gap-2"
                >
                    <h3
                        v-if="bloque.titulo"
                        class="text-base font-semibold text-[var(--mrl-texto)]"
                    >
                        {{ bloque.titulo }}
                    </h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div
                            v-for="(t, ti) in bloque.items"
                            :key="ti"
                            class="rounded-2xl border border-[var(--mrl-borde)] p-4"
                        >
                            <p
                                class="text-sm font-semibold text-[var(--mrl-texto)]"
                            >
                                {{ t.titulo }}
                            </p>
                            <p
                                class="mt-1 text-sm text-[var(--mrl-texto-suave)]"
                            >
                                {{ t.texto }}
                            </p>
                            <p
                                v-if="t.nota"
                                class="mt-2 text-xs text-[var(--mrl-primary)]"
                            >
                                {{ t.nota }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-else-if="bloque.tipo === 'organigrama_dinamico'"
                    class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-primary)]/30 bg-[var(--mrl-primary)]/5 p-5"
                >
                    <div class="flex items-center gap-2">
                        <Network class="size-5 text-[var(--mrl-primary)]" />
                        <p
                            class="text-sm font-semibold text-[var(--mrl-texto)]"
                        >
                            {{ bloque.titulo }}
                        </p>
                    </div>
                    <p
                        v-if="bloque.nota"
                        class="max-w-prose text-xs text-[var(--mrl-texto-suave)]"
                    >
                        {{ bloque.nota }}
                    </p>

                    <div
                        v-if="
                            organigramasCargando[bloque.vista] &&
                            !organigramas[bloque.vista]
                        "
                        class="animate-pulse rounded-xl bg-[var(--mrl-fondo)] p-4 text-center text-xs text-[var(--mrl-texto-suave)]"
                    >
                        Cargando organigrama…
                    </div>
                    <p
                        v-else-if="organigramasError[bloque.vista]"
                        class="rounded-xl bg-[var(--mrl-fondo)] p-4 text-center text-xs text-[var(--mrl-texto-suave)]"
                    >
                        No se pudo cargar el organigrama. Intenta de nuevo más
                        tarde.
                    </p>
                    <ul
                        v-else-if="organigramas[bloque.vista]"
                        class="flex flex-col gap-2 overflow-x-auto rounded-xl bg-[var(--mrl-surface)] p-3"
                    >
                        <MiniOrganigramaNodo
                            :nodo="organigramas[bloque.vista]!"
                            raiz
                        />
                    </ul>

                    <div
                        v-if="puedeVerOrganigramaCompleto"
                        class="flex justify-end"
                    >
                        <Button
                            as="a"
                            :href="organigramaCompletoIndex.url()"
                            variant="outline"
                            size="sm"
                        >
                            Ver organigrama completo
                        </Button>
                    </div>
                </div>

                <div
                    v-else-if="bloque.tipo === 'cierre'"
                    class="rounded-2xl bg-[var(--mrl-primary)]/5 p-5 text-center"
                >
                    <p class="text-lg font-semibold text-[var(--mrl-texto)]">
                        {{ bloque.titulo }}
                    </p>
                    <p class="mt-2 text-sm text-[var(--mrl-texto-suave)]">
                        {{ bloque.texto }}
                    </p>
                </div>
            </template>

            <div
                class="mt-2 flex items-center justify-between gap-4 border-t border-[var(--mrl-borde)] pt-4"
            >
                <Button
                    variant="outline"
                    :disabled="indice === 0"
                    @click="anterior"
                >
                    <ArrowLeft class="size-4" /> Anterior
                </Button>

                <Button
                    v-if="!esUltimo"
                    :disabled="enviando"
                    @click="siguiente"
                >
                    Siguiente <ArrowRight class="size-4" />
                </Button>
                <Button
                    v-else-if="!vistos.has(capituloActual.clave)"
                    :disabled="enviando"
                    @click="siguiente"
                >
                    {{
                        modoPresentacion
                            ? 'Terminar presentación'
                            : 'Terminar inducción'
                    }}
                    <CheckCircle2 class="size-4" />
                </Button>
            </div>

            <p
                v-if="completado && !modoPresentacion"
                class="rounded-2xl bg-[var(--mrl-success)]/10 p-4 text-sm font-medium text-[var(--mrl-success)]"
            >
                Completaste los {{ totalCapitulos }} capítulos. Regresa a «Mis
                pendientes» para realizar tu evaluación de inducción.
            </p>
        </article>
    </div>
</template>
