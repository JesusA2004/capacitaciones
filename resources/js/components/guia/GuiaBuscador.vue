<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BookOpen, CircleHelp, Search, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import { useGuia } from '@/composables/useGuia';
import {
    ARTICULOS,
    articuloVisible,
    GLOSARIO,
    PREGUNTAS_FRECUENTES,
    preguntaVisible,
    procesoDeArticulo,
} from '@/lib/guia';
import {
    buscarArticulos,
    buscarGlosario,
    buscarPreguntas,
} from '@/lib/guia/buscador';

/**
 * Buscador de la Guía: búsqueda local (sin IA ni servidor) sobre títulos,
 * preguntas, pasos, glosario y preguntas frecuentes. «¿Cómo genero un
 * permiso?» lleva directo al artículo.
 */
const props = withDefaults(defineProps<{ grande?: boolean }>(), {
    grande: false,
});

const consulta = ref('');
const entrada = ref<InstanceType<typeof Input> | null>(null);

const { alcance } = useGuia();

// El buscador nunca ofrece artículos de otro rol.
const resultados = computed(() =>
    buscarArticulos(
        consulta.value,
        ARTICULOS.filter((a) => articuloVisible(a, alcance.value)),
        6,
    ),
);
const terminos = computed(() =>
    buscarGlosario(consulta.value, GLOSARIO).slice(0, 3),
);
const preguntas = computed(() =>
    buscarPreguntas(
        consulta.value,
        PREGUNTAS_FRECUENTES.filter((p) => preguntaVisible(p, alcance.value)),
    ).slice(0, 3),
);
const hayConsulta = computed(() => consulta.value.trim().length > 1);
const sinResultados = computed(
    () =>
        hayConsulta.value &&
        resultados.value.length === 0 &&
        terminos.value.length === 0 &&
        preguntas.value.length === 0,
);

const ejemplos = computed(() =>
    alcance.value.global || alcance.value.roles.includes('rh')
        ? [
              '¿Cómo contrato un candidato?',
              '¿Cómo genero un permiso?',
              '¿Cómo emito nómina?',
              '¿Cómo hago una baja?',
              '¿Cómo completo datos?',
          ]
        : alcance.value.roles.includes('colaborador')
          ? [
                '¿Cómo pido vacaciones?',
                '¿Cómo pido un préstamo?',
                '¿Cómo completo mis datos?',
                '¿Cómo cambio mi contraseña?',
            ]
          : alcance.value.roles.includes('sistemas')
            ? ['¿Cómo creo una cuenta?', '¿Cómo cambio un rol?', '¿Cómo publico la app?']
            : ['¿Cómo registro una entrevista?', '¿Cómo inicio una baja?', '¿Cómo levanto un acta?'],
);

function enfocar(): void {
    const el = (entrada.value as unknown as { $el?: HTMLElement } | null)?.$el;
    (el instanceof HTMLInputElement ? el : el?.querySelector('input'))?.focus();
}

defineExpose({ enfocar });
</script>

<template>
    <div data-tour="guia-buscar" class="relative w-full">
        <Search
            :class="[
                'pointer-events-none absolute left-4 text-[var(--mrl-texto-suave)]',
                props.grande ? 'top-4 size-5' : 'top-2.5 size-4',
            ]"
        />
        <Input
            id="guia-buscar"
            ref="entrada"
            v-model="consulta"
            type="search"
            autocomplete="off"
            placeholder="Escribe tu duda: «¿cómo contrato un candidato?»"
            aria-label="Buscar en la guía"
            :class="[
                'rounded-2xl border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] shadow-sm',
                props.grande ? 'h-13 pr-10 pl-12 text-base' : 'pl-10',
            ]"
        />
        <button
            v-if="consulta"
            type="button"
            class="absolute top-1/2 right-3 -translate-y-1/2 rounded-full p-1 text-[var(--mrl-texto-suave)] hover:bg-crema"
            aria-label="Limpiar búsqueda"
            @click="consulta = ''"
        >
            <X class="size-4" />
        </button>

        <div
            v-if="!hayConsulta && props.grande"
            class="mt-3 flex flex-wrap justify-center gap-2"
        >
            <button
                v-for="ejemplo in ejemplos"
                :key="ejemplo"
                type="button"
                class="rounded-full border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 py-1 text-xs text-[var(--mrl-texto-suave)] transition hover:border-oro hover:text-[var(--mrl-texto)]"
                @click="consulta = ejemplo"
            >
                {{ ejemplo }}
            </button>
        </div>

        <div
            v-if="hayConsulta"
            class="mt-2 overflow-hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] text-left shadow-lg"
            role="region"
            aria-live="polite"
            aria-label="Resultados de la guía"
        >
            <ul
                v-if="resultados.length"
                class="divide-y divide-[var(--mrl-borde)]"
            >
                <li v-for="r in resultados" :key="r.articulo.id">
                    <Link
                        :href="`/ayuda/articulo/${r.articulo.id}`"
                        class="flex items-start gap-3 px-4 py-3 transition hover:bg-crema/50"
                    >
                        <BookOpen
                            class="mt-0.5 size-4 shrink-0 text-[var(--mrl-petroleo)]"
                        />
                        <span class="min-w-0">
                            <span
                                class="block font-medium text-[var(--mrl-texto)]"
                            >
                                {{ r.articulo.titulo }}
                            </span>
                            <span
                                class="block text-xs text-[var(--mrl-texto-suave)]"
                            >
                                {{ procesoDeArticulo(r.articulo)?.titulo }}
                                <template v-if="r.coincidencia">
                                    · «{{ r.coincidencia }}»</template
                                >
                            </span>
                        </span>
                    </Link>
                </li>
            </ul>

            <div
                v-if="preguntas.length"
                class="border-t border-[var(--mrl-borde)] bg-salvia/30 px-4 py-3"
            >
                <p
                    class="mb-1 text-[11px] font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                >
                    Preguntas frecuentes
                </p>
                <Link
                    v-for="p in preguntas"
                    :key="p.pregunta"
                    :href="
                        p.articulo
                            ? `/ayuda/articulo/${p.articulo}`
                            : '/ayuda/preguntas'
                    "
                    class="flex items-center gap-2 py-1 text-sm hover:underline"
                >
                    <CircleHelp class="size-3.5 shrink-0" /> {{ p.pregunta }}
                </Link>
            </div>

            <div
                v-if="terminos.length"
                class="border-t border-[var(--mrl-borde)] bg-crema/40 px-4 py-3"
            >
                <p
                    class="mb-1 text-[11px] font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                >
                    Glosario
                </p>
                <p
                    v-for="t in terminos"
                    :key="t.termino"
                    class="py-0.5 text-sm"
                >
                    <strong>{{ t.termino }}:</strong> {{ t.definicion }}
                </p>
            </div>

            <p
                v-if="sinResultados"
                class="px-4 py-4 text-sm text-[var(--mrl-texto-suave)]"
            >
                No encontré nada con «{{ consulta }}». Prueba con otra palabra
                (por ejemplo «vacaciones», «QR», «finiquito») o revisa las
                <Link href="/ayuda/preguntas" class="underline"
                    >preguntas frecuentes</Link
                >.
            </p>
        </div>
    </div>
</template>
