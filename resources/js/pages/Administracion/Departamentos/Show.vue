<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Briefcase,
    Check,
    CheckCheck,
    Loader2,
    Minus,
    Search,
    Shuffle,
    Users,
    X,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { getInitials } from '@/composables/useInitials';
import { dashboard } from '@/routes';
import {
    index,
    moverColaboradores,
} from '@/routes/administracion/departamentos';
import type {
    DepartamentoDetalle,
    DestinoDepartamento,
    PersonaDepartamento,
    ResultadoMovimientoDepartamento,
} from '@/types';

/**
 * Detalle de un departamento + «mover personas» en lote: a la izquierda
 * quién está hoy (tarjetas seleccionables con buscador sin mínimo de
 * letras), a la derecha a dónde van (departamento destino) y el resumen.
 * Confirmar abre una hoja propia; el backend (MovimientoDepartamentoService)
 * vuelve a validar alcance, permisos y «nadie se mueve a sí mismo».
 */
const props = defineProps<{
    departamento: DepartamentoDetalle;
    colaboradores: PersonaDepartamento[];
    destinos: DestinoDepartamento[];
    puedeMover: boolean;
}>();

defineOptions({
    layout: (pageProps: { departamento: { nombre: string } }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Departamentos', href: index.url() },
            { title: pageProps.departamento.nombre, href: '#' },
        ],
    }),
});

const numero = new Intl.NumberFormat('es-MX');

function normalizar(texto: string | null | undefined): string {
    return (texto ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}

function personas(n: number): string {
    return `${numero.format(n)} ${n === 1 ? 'persona' : 'personas'}`;
}

// --- Origen: búsqueda y selección -------------------------------------

const busqueda = ref('');
const seleccion = ref<Set<number>>(new Set());

const filtrados = computed(() => {
    const termino = normalizar(busqueda.value);

    if (termino === '') {
        return props.colaboradores;
    }

    return props.colaboradores.filter((p) =>
        normalizar(
            `${p.nombre} ${p.puesto ?? ''} ${p.sucursal ?? ''} ${p.numero_empleado ?? ''}`,
        ).includes(termino),
    );
});

const movibles = computed(() => filtrados.value.filter((p) => p.movible));
const seleccionados = computed(() =>
    props.colaboradores.filter((p) => seleccion.value.has(p.id)),
);
const totalSeleccionados = computed(() => seleccionados.value.length);

const estadoTodos = computed<'ninguno' | 'parcial' | 'todos'>(() => {
    const marcados = movibles.value.filter((p) =>
        seleccion.value.has(p.id),
    ).length;

    if (marcados === 0) {
        return 'ninguno';
    }

    return marcados === movibles.value.length ? 'todos' : 'parcial';
});

function alternar(persona: PersonaDepartamento) {
    if (!props.puedeMover || !persona.movible) {
        return;
    }

    const nueva = new Set(seleccion.value);

    if (nueva.has(persona.id)) {
        nueva.delete(persona.id);
    } else {
        nueva.add(persona.id);
    }

    seleccion.value = nueva;
}

function alternarTodos() {
    const nueva = new Set(seleccion.value);

    if (estadoTodos.value === 'todos') {
        movibles.value.forEach((p) => nueva.delete(p.id));
    } else {
        movibles.value.forEach((p) => nueva.add(p.id));
    }

    seleccion.value = nueva;
}

function limpiarSeleccion() {
    seleccion.value = new Set();
}

// Si el listado cambia (después de mover), se quitan de la selección los
// que ya no están.
watch(
    () => props.colaboradores,
    (lista) => {
        const ids = new Set(lista.map((p) => p.id));
        seleccion.value = new Set(
            [...seleccion.value].filter((id) => ids.has(id)),
        );
    },
);

// --- Destino -------------------------------------------------------------

const destinoId = ref<number | null>(null);
const busquedaDestino = ref('');
const destino = computed(
    () => props.destinos.find((d) => d.id === destinoId.value) ?? null,
);
const destinosFiltrados = computed(() => {
    const termino = normalizar(busquedaDestino.value);

    return termino === ''
        ? props.destinos
        : props.destinos.filter((d) => normalizar(d.nombre).includes(termino));
});

const motivo = ref('');
const listo = computed(
    () => totalSeleccionados.value > 0 && destino.value !== null,
);

const resumen = computed(() =>
    destino.value
        ? `Vas a mover ${personas(totalSeleccionados.value)} de ${props.departamento.nombre} a ${destino.value.nombre}`
        : '',
);

// --- Confirmación y envío --------------------------------------------------

const confirmando = ref(false);
const enviando = ref(false);
const errores = ref<Record<string, string>>({});
const resultado = ref<ResultadoMovimientoDepartamento | null>(null);
const botonConfirmar = ref<HTMLButtonElement | null>(null);
const panelDestino = ref<HTMLElement | null>(null);
const panelResultado = ref<HTMLElement | null>(null);

// El resultado (movidos / omitidos) aparece arriba: se lleva la vista ahí.
watch(resultado, (valor) => {
    if (valor) {
        nextTick(() =>
            panelResultado.value?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            }),
        );
    }
});

function continuar() {
    if (!listo.value) {
        panelDestino.value?.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });

        return;
    }

    errores.value = {};
    confirmando.value = true;
    nextTick(() => botonConfirmar.value?.focus());
}

function cerrarConfirmacion() {
    if (!enviando.value) {
        confirmando.value = false;
    }
}

function alTeclear(evento: KeyboardEvent) {
    if (evento.key === 'Escape' && confirmando.value) {
        cerrarConfirmacion();
    }
}

watch(confirmando, (abierto) => {
    if (abierto) {
        window.addEventListener('keydown', alTeclear);
    } else {
        window.removeEventListener('keydown', alTeclear);
    }
});

onBeforeUnmount(() => window.removeEventListener('keydown', alTeclear));

function confirmar() {
    if (!destino.value || totalSeleccionados.value === 0) {
        return;
    }

    enviando.value = true;

    router.post(
        moverColaboradores.url(props.departamento.id),
        {
            departamento_destino_id: destino.value.id,
            colaborador_ids: [...seleccion.value],
            motivo: motivo.value.trim() || null,
        },
        {
            preserveScroll: true,
            onFlash: (flash) => {
                const dato = (flash as Record<string, unknown>)
                    .movimientoDepartamento as
                    ResultadoMovimientoDepartamento | undefined;

                if (dato) {
                    resultado.value = dato;
                }
            },
            onSuccess: () => {
                confirmando.value = false;
                limpiarSeleccion();
                destinoId.value = null;
                motivo.value = '';
            },
            onError: (e) => {
                errores.value = e;
                confirmando.value = false;
            },
            onFinish: () => {
                enviando.value = false;
            },
        },
    );
}

const avatarSeleccion = computed(() => seleccionados.value.slice(0, 5));
</script>

<template>
    <Head :title="departamento.nombre" />

    <div
        class="pagina-ancha flex flex-col gap-5"
        :class="puedeMover && totalSeleccionados > 0 && 'pb-28 lg:pb-24'"
    >
        <!-- Encabezado -->
        <header
            class="relative overflow-hidden rounded-3xl border bg-card px-5 py-5 sm:px-7 sm:py-6"
        >
            <div
                class="pointer-events-none absolute -top-16 -right-10 size-56 rounded-full bg-salvia/60 blur-3xl"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute -bottom-20 left-1/3 size-48 rounded-full bg-crema/70 blur-3xl"
                aria-hidden="true"
            />

            <div class="relative flex flex-col gap-3">
                <Link
                    :href="index.url()"
                    class="-ml-2 inline-flex w-fit items-center gap-1.5 rounded-full px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-crema/60 hover:text-foreground"
                >
                    <ArrowLeft class="size-3.5" /> Departamentos
                </Link>

                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0">
                        <h1
                            class="text-2xl font-semibold tracking-tight sm:text-3xl"
                        >
                            {{ departamento.nombre }}
                        </h1>
                        <p
                            v-if="departamento.descripcion"
                            class="mt-1 max-w-prose text-sm text-muted-foreground"
                        >
                            {{ departamento.descripcion }}
                        </p>
                    </div>

                    <ul class="flex flex-wrap gap-2 text-xs">
                        <li
                            class="inline-flex items-center gap-1.5 rounded-full border bg-background/70 px-3 py-1.5 font-medium"
                        >
                            <Users class="size-3.5 text-primary" />
                            {{ personas(colaboradores.length) }}
                        </li>
                        <li
                            class="inline-flex items-center gap-1.5 rounded-full border bg-background/70 px-3 py-1.5 font-medium"
                        >
                            <Briefcase class="size-3.5 text-primary" />
                            {{ departamento.puestos }}
                            {{
                                departamento.puestos === 1
                                    ? 'puesto'
                                    : 'puestos'
                            }}
                        </li>
                        <li
                            class="inline-flex items-center rounded-full px-3 py-1.5 font-medium"
                            :class="
                                departamento.activo
                                    ? 'bg-success-soft text-success'
                                    : 'bg-danger-soft text-danger'
                            "
                        >
                            {{ departamento.activo ? 'Activo' : 'Inactivo' }}
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Resultado del último movimiento -->
        <Transition name="aparecer">
            <section
                v-if="resultado"
                ref="panelResultado"
                class="scroll-mt-4 rounded-2xl border bg-card p-4 sm:p-5"
                aria-live="polite"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-full"
                        :class="
                            resultado.movidos.length > 0
                                ? 'bg-success-soft text-success'
                                : 'bg-warning-soft text-warning'
                        "
                    >
                        <CheckCheck
                            v-if="resultado.movidos.length > 0"
                            class="size-4.5"
                        />
                        <AlertTriangle v-else class="size-4.5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">
                            <template v-if="resultado.movidos.length > 0">
                                {{ personas(resultado.movidos.length) }}
                                {{
                                    resultado.movidos.length === 1
                                        ? 'ahora está'
                                        : 'ahora están'
                                }}
                                en {{ resultado.destino }}
                            </template>
                            <template v-else>Nadie se movió</template>
                        </p>
                        <ul
                            v-if="resultado.movidos.length > 0"
                            class="mt-2 flex flex-wrap gap-1.5"
                        >
                            <li
                                v-for="m in resultado.movidos"
                                :key="m.id"
                                class="rounded-full bg-menta px-2.5 py-1 text-xs"
                            >
                                {{ m.nombre }}
                            </li>
                        </ul>
                        <div
                            v-if="resultado.omitidos.length > 0"
                            class="mt-3 rounded-xl bg-warning-soft/60 px-3 py-2.5"
                        >
                            <p class="text-xs font-medium">
                                {{ resultado.omitidos.length }}
                                {{
                                    resultado.omitidos.length === 1
                                        ? 'persona omitida'
                                        : 'personas omitidas'
                                }}
                            </p>
                            <ul class="mt-1 space-y-0.5 text-xs">
                                <li v-for="o in resultado.omitidos" :key="o.id">
                                    <span class="font-medium">{{
                                        o.nombre
                                    }}</span>
                                    <span class="text-muted-foreground">
                                        — {{ o.motivo }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="grid size-8 shrink-0 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-crema/60 hover:text-foreground"
                        aria-label="Cerrar resultado"
                        @click="resultado = null"
                    >
                        <X class="size-4" />
                    </button>
                </div>
            </section>
        </Transition>

        <div
            class="grid items-start gap-5"
            :class="
                puedeMover && 'lg:grid-cols-[minmax(0,1fr)_minmax(320px,400px)]'
            "
        >
            <!-- Origen: personas del departamento -->
            <section
                class="flex min-w-0 flex-col gap-3 rounded-3xl border bg-card p-4 sm:p-5"
                aria-labelledby="titulo-origen"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span
                            v-if="puedeMover"
                            class="grid size-6 place-items-center rounded-full bg-primary text-[11px] font-semibold text-primary-foreground"
                            >1</span
                        >
                        <h2 id="titulo-origen" class="font-semibold">
                            {{
                                puedeMover
                                    ? '¿A quiénes vas a mover?'
                                    : 'Personas en este departamento'
                            }}
                        </h2>
                    </div>
                    <p
                        v-if="puedeMover"
                        class="text-xs text-muted-foreground tabular-nums"
                    >
                        {{ totalSeleccionados }} de
                        {{ colaboradores.filter((p) => p.movible).length }}
                        seleccionadas
                    </p>
                </div>

                <div
                    v-if="colaboradores.length > 0"
                    class="flex flex-col gap-2 sm:flex-row sm:items-center"
                >
                    <label class="buscador relative flex-1">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <span class="sr-only">Buscar persona</span>
                        <input
                            v-model="busqueda"
                            type="search"
                            placeholder="Buscar por nombre, puesto, sucursal o número…"
                            class="h-11 w-full rounded-2xl border bg-background/60 pr-10 pl-10 text-sm transition-[border-color,box-shadow] outline-none placeholder:text-muted-foreground focus:border-primary/50 focus:ring-4 focus:ring-primary/10"
                        />
                        <button
                            v-if="busqueda"
                            type="button"
                            class="absolute top-1/2 right-2 grid size-7 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-crema/60"
                            aria-label="Limpiar búsqueda"
                            @click="busqueda = ''"
                        >
                            <X class="size-3.5" />
                        </button>
                    </label>

                    <button
                        v-if="puedeMover && movibles.length > 0"
                        type="button"
                        role="checkbox"
                        :aria-checked="
                            estadoTodos === 'todos'
                                ? 'true'
                                : estadoTodos === 'parcial'
                                  ? 'mixed'
                                  : 'false'
                        "
                        class="inline-flex h-11 shrink-0 items-center gap-2 rounded-2xl border px-4 text-sm font-medium transition-colors"
                        :class="
                            estadoTodos === 'ninguno'
                                ? 'bg-background/60 hover:bg-crema/50'
                                : 'border-primary/40 bg-menta text-primary'
                        "
                        @click="alternarTodos"
                    >
                        <span
                            class="grid size-5 place-items-center rounded-md border-2 transition-colors"
                            :class="
                                estadoTodos === 'ninguno'
                                    ? 'border-muted-foreground/40'
                                    : 'border-primary bg-primary text-primary-foreground'
                            "
                        >
                            <Check
                                v-if="estadoTodos === 'todos'"
                                class="size-3.5"
                                :stroke-width="3"
                            />
                            <Minus
                                v-else-if="estadoTodos === 'parcial'"
                                class="size-3.5"
                                :stroke-width="3"
                            />
                        </span>
                        {{
                            busqueda
                                ? `Seleccionar las ${movibles.length} encontradas`
                                : 'Seleccionar todas'
                        }}
                    </button>
                </div>

                <!-- Vacío -->
                <div
                    v-if="colaboradores.length === 0"
                    class="flex flex-col items-center gap-2 rounded-2xl bg-crema/30 px-6 py-12 text-center"
                >
                    <span
                        class="grid size-12 place-items-center rounded-full bg-salvia text-primary"
                    >
                        <Users class="size-5" />
                    </span>
                    <p class="font-medium">
                        Nadie trabaja hoy en {{ departamento.nombre }}
                    </p>
                    <p class="max-w-sm text-sm text-muted-foreground">
                        Aquí aparecen las personas activas o en incorporación de
                        este departamento dentro de tu alcance.
                    </p>
                </div>
                <p
                    v-else-if="filtrados.length === 0"
                    class="rounded-2xl bg-crema/30 px-4 py-8 text-center text-sm text-muted-foreground"
                >
                    Nadie coincide con «{{ busqueda }}».
                </p>

                <TransitionGroup
                    v-else
                    tag="ul"
                    name="persona"
                    class="grid gap-2.5 sm:grid-cols-2 2xl:grid-cols-3"
                    :aria-label="`Personas de ${departamento.nombre}`"
                >
                    <li
                        v-for="(p, i) in filtrados"
                        :key="p.id"
                        class="entrada-persona"
                        :style="{ animationDelay: `${Math.min(i, 14) * 22}ms` }"
                    >
                        <component
                            :is="puedeMover ? 'button' : 'div'"
                            :type="puedeMover ? 'button' : undefined"
                            :role="puedeMover ? 'checkbox' : undefined"
                            :aria-checked="
                                puedeMover
                                    ? seleccion.has(p.id)
                                        ? 'true'
                                        : 'false'
                                    : undefined
                            "
                            :aria-disabled="
                                puedeMover && !p.movible ? 'true' : undefined
                            "
                            :title="p.motivo_no_movible ?? undefined"
                            class="tarjeta-persona group relative flex w-full items-center gap-3 rounded-2xl border p-3 text-left"
                            :class="[
                                seleccion.has(p.id)
                                    ? 'seleccionada border-primary/50 bg-menta'
                                    : 'bg-background/40',
                                puedeMover && p.movible ? 'cursor-pointer' : '',
                                puedeMover && p.movible && !seleccion.has(p.id)
                                    ? 'hover:border-oro/60 hover:bg-crema/30'
                                    : '',
                                puedeMover && !p.movible
                                    ? 'cursor-not-allowed opacity-60'
                                    : '',
                            ]"
                            @click="alternar(p)"
                        >
                            <span class="relative shrink-0">
                                <img
                                    v-if="p.foto_url"
                                    :src="p.foto_url"
                                    :alt="''"
                                    class="size-11 rounded-full object-cover ring-2 ring-card"
                                    loading="lazy"
                                />
                                <span
                                    v-else
                                    class="grid size-11 place-items-center rounded-full bg-salvia text-sm font-semibold text-primary ring-2 ring-card"
                                    >{{ getInitials(p.nombre) }}</span
                                >
                                <span
                                    v-if="puedeMover && p.movible"
                                    class="marca absolute -right-1 -bottom-1 grid size-5 place-items-center rounded-full border-2 border-card"
                                    :class="
                                        seleccion.has(p.id)
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted text-transparent group-hover:bg-crema-2'
                                    "
                                >
                                    <Check class="size-3" :stroke-width="3.5" />
                                </span>
                            </span>

                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium"
                                    >{{ p.nombre }}</span
                                >
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ p.puesto ?? 'Sin puesto' }}
                                    <template v-if="p.sucursal">
                                        · {{ p.sucursal }}</template
                                    >
                                </span>
                                <span class="mt-1 flex flex-wrap gap-1">
                                    <span
                                        v-if="p.numero_empleado"
                                        class="rounded-md bg-crema/60 px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground tabular-nums"
                                        >#{{ p.numero_empleado }}</span
                                    >
                                    <span
                                        v-if="p.en_incorporacion"
                                        class="rounded-md bg-info-soft px-1.5 py-0.5 text-[10px] font-medium"
                                        >En incorporación</span
                                    >
                                    <span
                                        v-if="puedeMover && !p.movible"
                                        class="rounded-md bg-warning-soft px-1.5 py-0.5 text-[10px] font-medium"
                                        >Eres tú</span
                                    >
                                </span>
                            </span>
                        </component>
                    </li>
                </TransitionGroup>

                <p
                    v-if="!puedeMover && colaboradores.length > 0"
                    class="text-xs text-muted-foreground"
                >
                    Para mover personas a otro departamento se necesita el
                    permiso de editar expedientes (RH).
                </p>
            </section>

            <!-- Destino y resumen -->
            <aside
                v-if="puedeMover"
                ref="panelDestino"
                class="flex scroll-mt-4 flex-col gap-4 lg:sticky lg:top-4"
                aria-labelledby="titulo-destino"
            >
                <section class="rounded-3xl border bg-card p-4 sm:p-5">
                    <div class="flex items-center gap-2">
                        <span
                            class="grid size-6 place-items-center rounded-full text-[11px] font-semibold transition-colors"
                            :class="
                                totalSeleccionados > 0
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                            >2</span
                        >
                        <h2 id="titulo-destino" class="font-semibold">
                            ¿A qué departamento?
                        </h2>
                    </div>

                    <p
                        v-if="destinos.length === 0"
                        class="mt-3 rounded-2xl bg-crema/30 px-4 py-6 text-center text-sm text-muted-foreground"
                    >
                        No hay otro departamento activo. Crea uno en
                        Departamentos.
                    </p>

                    <template v-else>
                        <label
                            v-if="destinos.length > 6"
                            class="relative mt-3 block"
                        >
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                            />
                            <span class="sr-only">Buscar departamento</span>
                            <input
                                v-model="busquedaDestino"
                                type="search"
                                placeholder="Buscar departamento…"
                                class="h-9 w-full rounded-xl border bg-background/60 pr-3 pl-9 text-sm outline-none placeholder:text-muted-foreground focus:border-primary/50 focus:ring-4 focus:ring-primary/10"
                            />
                        </label>

                        <ul
                            role="radiogroup"
                            aria-label="Departamento destino"
                            class="mt-3 flex max-h-[min(22rem,45vh)] flex-col gap-1.5 overflow-y-auto pr-1"
                        >
                            <li v-for="d in destinosFiltrados" :key="d.id">
                                <button
                                    type="button"
                                    role="radio"
                                    :aria-checked="
                                        destinoId === d.id ? 'true' : 'false'
                                    "
                                    class="flex w-full items-center gap-3 rounded-2xl border px-3 py-2.5 text-left transition-[background-color,border-color] duration-150"
                                    :class="
                                        destinoId === d.id
                                            ? 'border-primary/50 bg-menta'
                                            : 'border-transparent hover:border-oro/50 hover:bg-crema/30'
                                    "
                                    @click="
                                        destinoId =
                                            destinoId === d.id ? null : d.id
                                    "
                                >
                                    <span
                                        class="grid size-9 shrink-0 place-items-center rounded-xl text-sm font-semibold transition-colors"
                                        :class="
                                            destinoId === d.id
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-oro/15 text-bronce'
                                        "
                                        >{{ d.nombre.charAt(0) }}</span
                                    >
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-sm font-medium"
                                            >{{ d.nombre }}</span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >{{
                                                personas(d.personas)
                                            }}
                                            hoy</span
                                        >
                                    </span>
                                    <span
                                        class="grid size-5 shrink-0 place-items-center rounded-full border-2 transition-colors"
                                        :class="
                                            destinoId === d.id
                                                ? 'border-primary'
                                                : 'border-muted-foreground/30'
                                        "
                                    >
                                        <span
                                            class="punto size-2.5 rounded-full bg-primary"
                                            :class="
                                                destinoId === d.id
                                                    ? 'scale-100'
                                                    : 'scale-0'
                                            "
                                        />
                                    </span>
                                </button>
                            </li>
                            <li
                                v-if="destinosFiltrados.length === 0"
                                class="px-3 py-4 text-center text-xs text-muted-foreground"
                            >
                                Ningún departamento coincide.
                            </li>
                        </ul>
                    </template>
                </section>

                <!-- Resumen -->
                <section
                    class="resumen rounded-3xl border p-4 sm:p-5"
                    :class="listo ? 'listo' : 'bg-card'"
                >
                    <div class="flex items-center gap-2">
                        <span
                            class="grid size-6 place-items-center rounded-full text-[11px] font-semibold transition-colors"
                            :class="
                                listo
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                            >3</span
                        >
                        <h2 class="font-semibold">Revisa y confirma</h2>
                    </div>

                    <Transition name="aparecer" mode="out-in">
                        <div v-if="listo" key="listo" class="mt-3">
                            <div class="flex items-center gap-3">
                                <div class="flex -space-x-2.5">
                                    <template
                                        v-for="p in avatarSeleccion"
                                        :key="p.id"
                                    >
                                        <img
                                            v-if="p.foto_url"
                                            :src="p.foto_url"
                                            alt=""
                                            class="size-8 rounded-full object-cover ring-2 ring-card"
                                        />
                                        <span
                                            v-else
                                            class="grid size-8 place-items-center rounded-full bg-salvia text-[11px] font-semibold text-primary ring-2 ring-card"
                                            >{{ getInitials(p.nombre) }}</span
                                        >
                                    </template>
                                    <span
                                        v-if="totalSeleccionados > 5"
                                        class="grid size-8 place-items-center rounded-full bg-crema-2 text-[11px] font-semibold ring-2 ring-card"
                                        >+{{ totalSeleccionados - 5 }}</span
                                    >
                                </div>
                            </div>
                            <p class="mt-3 text-sm leading-relaxed">
                                Vas a mover
                                <strong class="font-semibold">{{
                                    personas(totalSeleccionados)
                                }}</strong>
                                de
                                <strong class="font-semibold">{{
                                    departamento.nombre
                                }}</strong>
                                a
                                <strong class="font-semibold text-primary">{{
                                    destino?.nombre
                                }}</strong>
                            </p>
                            <label class="mt-3 block">
                                <span
                                    class="text-xs font-medium text-muted-foreground"
                                    >Motivo (opcional)</span
                                >
                                <textarea
                                    v-model="motivo"
                                    rows="2"
                                    maxlength="255"
                                    placeholder="Ej. Reorganización del área"
                                    class="mt-1 w-full resize-none rounded-xl border bg-background/70 px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus:border-primary/50 focus:ring-4 focus:ring-primary/10"
                                />
                            </label>
                        </div>
                        <p
                            v-else
                            key="pendiente"
                            class="mt-3 text-sm text-muted-foreground"
                        >
                            {{
                                totalSeleccionados === 0
                                    ? 'Marca a una o varias personas de la lista.'
                                    : `${personas(totalSeleccionados)} ${totalSeleccionados === 1 ? 'lista' : 'listas'}. Ahora elige el departamento destino.`
                            }}
                        </p>
                    </Transition>

                    <p
                        v-for="(mensaje, campo) in errores"
                        :key="campo"
                        class="mt-2 rounded-lg bg-danger-soft px-3 py-2 text-xs"
                    >
                        {{ mensaje }}
                    </p>

                    <button
                        type="button"
                        class="boton-principal mt-4 inline-flex h-11 w-full items-center justify-center gap-2 rounded-2xl px-4 text-sm font-semibold transition-[background-color,opacity,transform] duration-150 active:scale-[0.98] disabled:cursor-not-allowed"
                        :disabled="!listo"
                        @click="continuar"
                    >
                        <Shuffle class="size-4" />
                        {{
                            listo
                                ? `Mover ${personas(totalSeleccionados)}`
                                : 'Mover personas'
                        }}
                    </button>
                    <p
                        class="mt-2 text-[11px] leading-snug text-muted-foreground"
                    >
                        Cada persona queda con su movimiento laboral «Cambio de
                        departamento». No cambia su puesto, sucursal ni la
                        plantilla autorizada.
                    </p>
                </section>
            </aside>
        </div>

        <!-- Contador flotante (escritorio) / barra de acción fija (móvil) -->
        <Transition name="subir">
            <div
                v-if="puedeMover && totalSeleccionados > 0 && !confirmando"
                class="barra-accion fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] z-40 pr-20 pb-2 pl-3 sm:bottom-0 sm:pb-3 lg:inset-x-auto lg:bottom-6 lg:left-1/2 lg:-translate-x-1/2 lg:px-0 lg:pb-0"
            >
                <!-- pr-20: deja libre el botón de ayuda (esquina inferior
                     derecha); en móvil la barra va sobre el menú inferior. -->
                <div
                    class="mx-auto flex max-w-xl items-center gap-2.5 rounded-2xl border bg-card/95 p-2 pl-3 shadow-lg backdrop-blur sm:gap-3 sm:p-2.5 sm:pl-4 lg:rounded-full lg:pl-5"
                >
                    <span
                        class="contador grid size-8 shrink-0 place-items-center rounded-full bg-primary text-sm font-semibold text-primary-foreground tabular-nums"
                        :key="totalSeleccionados"
                        >{{ totalSeleccionados }}</span
                    >
                    <span class="min-w-0 flex-1 text-sm leading-tight">
                        <span class="block font-medium">
                            {{
                                totalSeleccionados === 1
                                    ? 'seleccionada'
                                    : 'seleccionadas'
                            }}
                        </span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                        >
                            {{
                                destino
                                    ? `→ ${destino.nombre}`
                                    : 'Falta el destino'
                            }}
                        </span>
                    </span>
                    <button
                        type="button"
                        class="hidden size-9 shrink-0 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-crema/60 hover:text-foreground sm:grid"
                        aria-label="Quitar selección"
                        @click="limpiarSeleccion"
                    >
                        <X class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="boton-principal inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl px-3.5 text-sm font-semibold sm:px-4 lg:rounded-full"
                        @click="continuar"
                    >
                        <template v-if="destino">Mover</template>
                        <template v-else>
                            <span class="sm:hidden">Destino</span>
                            <span class="hidden sm:inline">Elegir destino</span>
                        </template>
                        <ArrowRight class="size-4" />
                    </button>
                </div>
            </div>
        </Transition>

        <!-- Confirmación (hoja propia) -->
        <Teleport to="body">
            <Transition name="velo">
                <div
                    v-if="confirmando"
                    class="fixed inset-0 z-50 flex items-end justify-center bg-foreground/25 backdrop-blur-[2px] sm:items-center sm:p-6"
                    @click.self="cerrarConfirmacion"
                >
                    <div
                        class="hoja w-full max-w-lg rounded-t-3xl border bg-card p-5 shadow-2xl sm:rounded-3xl sm:p-6"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="titulo-confirmacion"
                    >
                        <div
                            class="mx-auto mb-4 h-1.5 w-10 rounded-full bg-muted sm:hidden"
                            aria-hidden="true"
                        />
                        <h2
                            id="titulo-confirmacion"
                            class="text-lg font-semibold tracking-tight"
                        >
                            {{ resumen }}
                        </h2>

                        <div
                            class="mt-4 flex items-center gap-2 rounded-2xl bg-crema/40 p-3"
                        >
                            <span
                                class="min-w-0 flex-1 truncate rounded-xl bg-card px-3 py-2 text-center text-sm font-medium"
                                >{{ departamento.nombre }}</span
                            >
                            <span
                                class="flecha grid size-9 shrink-0 place-items-center rounded-full bg-primary text-primary-foreground"
                            >
                                <ArrowRight class="size-4" />
                            </span>
                            <span
                                class="min-w-0 flex-1 truncate rounded-xl bg-menta px-3 py-2 text-center text-sm font-semibold text-primary"
                                >{{ destino?.nombre }}</span
                            >
                        </div>

                        <ul
                            class="mt-4 max-h-56 divide-y overflow-y-auto rounded-2xl border"
                        >
                            <li
                                v-for="p in seleccionados"
                                :key="p.id"
                                class="flex items-center gap-3 px-3 py-2"
                            >
                                <img
                                    v-if="p.foto_url"
                                    :src="p.foto_url"
                                    alt=""
                                    class="size-7 rounded-full object-cover"
                                />
                                <span
                                    v-else
                                    class="grid size-7 place-items-center rounded-full bg-salvia text-[10px] font-semibold text-primary"
                                    >{{ getInitials(p.nombre) }}</span
                                >
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate text-sm font-medium"
                                        >{{ p.nombre }}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                        >{{ p.puesto ?? 'Sin puesto' }}</span
                                    >
                                </span>
                            </li>
                        </ul>

                        <p
                            v-if="motivo.trim()"
                            class="mt-3 text-xs text-muted-foreground"
                        >
                            Motivo: «{{ motivo.trim() }}»
                        </p>

                        <div
                            class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                        >
                            <button
                                type="button"
                                class="inline-flex h-11 items-center justify-center rounded-2xl border px-5 text-sm font-medium transition-colors hover:bg-crema/50 disabled:opacity-50"
                                :disabled="enviando"
                                @click="cerrarConfirmacion"
                            >
                                Volver
                            </button>
                            <button
                                ref="botonConfirmar"
                                type="button"
                                class="boton-principal inline-flex h-11 items-center justify-center gap-2 rounded-2xl px-5 text-sm font-semibold"
                                :disabled="enviando"
                                @click="confirmar"
                            >
                                <Loader2
                                    v-if="enviando"
                                    class="size-4 animate-spin"
                                />
                                <Check v-else class="size-4" />
                                Sí, mover {{ personas(totalSeleccionados) }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<style scoped>
/* Entrada escalonada de las tarjetas de persona. */
.entrada-persona {
    animation: entrada-persona 260ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

@keyframes entrada-persona {
    from {
        opacity: 0;
        transform: translateY(6px) scale(0.985);
    }
}

.tarjeta-persona {
    transition:
        background-color 160ms ease,
        border-color 160ms ease,
        box-shadow 200ms ease,
        transform 160ms ease;
}

.tarjeta-persona.seleccionada {
    box-shadow:
        0 0 0 3px color-mix(in oklab, var(--primary) 14%, transparent),
        0 10px 22px -16px color-mix(in oklab, var(--primary) 60%, transparent);
}

.tarjeta-persona:focus-visible {
    outline: 2px solid var(--pastel-oro);
    outline-offset: 2px;
}

.tarjeta-persona:active:not([aria-disabled='true']) {
    transform: scale(0.985);
}

.marca {
    transition:
        background-color 160ms ease,
        color 160ms ease,
        transform 220ms cubic-bezier(0.34, 1.56, 0.64, 1);
}

.seleccionada .marca {
    transform: scale(1.12);
}

.punto {
    transition: transform 220ms cubic-bezier(0.34, 1.56, 0.64, 1);
}

/* Botón principal propio (petróleo de la paleta). */
.boton-principal {
    background-color: var(--primary);
    color: var(--primary-foreground);
}

.boton-principal:hover:not(:disabled) {
    background-color: color-mix(in oklab, var(--primary) 88%, black);
}

.boton-principal:disabled {
    background-color: var(--muted);
    color: var(--muted-foreground);
}

.boton-principal:focus-visible {
    outline: 2px solid var(--pastel-oro);
    outline-offset: 2px;
}

/* Resumen listo: se ilumina con un degradado suave de la paleta. */
.resumen {
    transition:
        background-color 220ms ease,
        border-color 220ms ease;
}

.resumen.listo {
    border-color: color-mix(in oklab, var(--primary) 30%, transparent);
    background-image: linear-gradient(
        160deg,
        var(--pastel-menta),
        var(--pastel-superficie) 70%
    );
}

.flecha {
    animation: empuje 1.6s ease-in-out infinite;
}

/* El contador «late» cada vez que cambia la selección. */
.contador {
    animation: latido 260ms cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes latido {
    from {
        transform: scale(0.7);
    }
}

@keyframes empuje {
    50% {
        transform: translateX(3px);
    }
}

/* Personas que salen del departamento al moverse. */
.persona-move {
    transition: transform 280ms ease;
}

.persona-leave-active {
    transition:
        opacity 220ms ease,
        transform 220ms ease;
}

.persona-leave-to {
    opacity: 0;
    transform: translateX(16px) scale(0.96);
}

.aparecer-enter-active,
.aparecer-leave-active {
    transition:
        opacity 200ms ease,
        transform 200ms ease;
}

.aparecer-enter-from,
.aparecer-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

.subir-enter-active,
.subir-leave-active {
    transition:
        opacity 220ms ease,
        transform 260ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

.subir-enter-from,
.subir-leave-to {
    opacity: 0;
    transform: translateY(16px);
}

.velo-enter-active,
.velo-leave-active {
    transition: opacity 200ms ease;
}

.velo-enter-active .hoja,
.velo-leave-active .hoja {
    transition: transform 280ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

.velo-enter-from,
.velo-leave-to {
    opacity: 0;
}

.velo-enter-from .hoja,
.velo-leave-to .hoja {
    transform: translateY(32px);
}

@media (min-width: 640px) {
    .velo-enter-from .hoja,
    .velo-leave-to .hoja {
        transform: translateY(8px) scale(0.97);
    }
}

@media (prefers-reduced-motion: reduce) {
    .entrada-persona,
    .flecha,
    .contador {
        animation: none;
    }

    .tarjeta-persona,
    .marca,
    .punto,
    .persona-move,
    .persona-leave-active,
    .aparecer-enter-active,
    .aparecer-leave-active,
    .subir-enter-active,
    .subir-leave-active,
    .velo-enter-active .hoja,
    .velo-leave-active .hoja {
        transition: none;
    }
}
</style>
