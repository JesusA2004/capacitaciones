<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlarmClock,
    ArrowRight,
    CalendarClock,
    Check,
    CheckCircle2,
    Eye,
    EyeOff,
    Flame,
    Inbox,
    Layers,
    RotateCcw,
    Sparkles,
    X,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Input } from '@/components/ui/input';
import { parametrosPorPaginaActuales } from '@/composables/usePaginacion';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { descartar, hecho, index, restaurar } from '@/routes/rh/pendientes';
import type { TareaBandeja } from '@/types';

type FilaPendiente = TareaBandeja & {
    url: string | null;
    foto_url?: string | null;
    descartada: boolean;
    motivo_descarte: string | null;
    puede_marcar_hecho: boolean;
};
type GrupoTipo = {
    clave: string;
    etiqueta: string;
    total: number;
    informativo: boolean;
};

type Paginado<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Serie = { clave: string; etiqueta: string; total: number };

type Atajo = {
    clave: string;
    titulo: string;
    descripcion: string;
    conteo: number;
    url: string;
    tono: 'primario' | 'oro' | 'alerta' | 'neutro';
};

const TONO_ATAJO: Record<Atajo['tono'], string> = {
    primario: 'bg-salvia text-primary',
    oro: 'bg-warning-soft text-warning',
    alerta: 'bg-danger-soft text-destructive',
    neutro: 'bg-crema text-foreground',
};

/** Prioridad visible de cada tarjeta (el tono lo decide el backend). */
const PRIORIDAD_ATAJO: Record<
    Atajo['tono'],
    { etiqueta: string; clase: string }
> = {
    alerta: {
        etiqueta: 'Prioridad alta',
        clase: 'bg-danger-soft text-destructive',
    },
    oro: { etiqueta: 'Prioridad media', clase: 'bg-warning-soft text-warning' },
    primario: { etiqueta: 'Prioridad media', clase: 'bg-salvia text-primary' },
    neutro: {
        etiqueta: 'Prioridad normal',
        clase: 'bg-muted text-muted-foreground',
    },
};

const props = defineProps<{
    atajos: Atajo[];
    tareas: Paginado<FilaPendiente>;
    conteos: {
        abiertas: number;
        no_leidas: number;
        vencidas: number;
        descartadas: number;
    };
    distribucion: { por_etapa: Serie[]; por_prioridad: Serie[] };
    por_tipo: GrupoTipo[];
    filtros: {
        etapa?: string | null;
        sucursal_id?: string | number | null;
        urgencia?: string | null;
        tipo?: string | null;
        estado?: 'abiertas' | 'descartadas' | 'resueltas' | null;
    };
    opciones: {
        etapas: { value: string; etiqueta: string }[];
        sucursales: { id: number; nombre: string }[];
        motivo_max: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mis pendientes', href: '' },
        ],
    },
});

const filtros = reactive({
    etapa: props.filtros.etapa ?? '',
    sucursal_id: props.filtros.sucursal_id
        ? String(props.filtros.sucursal_id)
        : '',
    urgencia: props.filtros.urgencia ?? '',
    tipo: props.filtros.tipo ?? '',
    estado: props.filtros.estado ?? 'abiertas',
});

watch(filtros, () => {
    router.get(
        index.url(),
        { ...parametrosPorPaginaActuales(), ...filtros },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

function alternarEtapa(clave: string) {
    filtros.etapa = filtros.etapa === clave ? '' : clave;
}

const maxEtapa = computed(() =>
    Math.max(1, ...props.distribucion.por_etapa.map((e) => e.total)),
);
const totalPrioridad = computed(() =>
    props.distribucion.por_prioridad.reduce((a, p) => a + p.total, 0),
);

// Prioridad = estado de atención: colores de estado reservados, siempre con etiqueta.
const COLOR_PRIORIDAD: Record<string, string> = {
    urgente: 'var(--mrl-danger)',
    alta: 'var(--mrl-gold)',
    media: 'var(--mrl-accent)',
    baja: 'var(--mrl-muted)',
};

function venceEn(tarea: TareaBandeja): { texto: string; clase: string } | null {
    if (tarea.vencida) {
        return {
            texto: 'Vencido',
            clase: 'bg-[var(--mrl-danger)]/10 text-[var(--mrl-danger)]',
        };
    }

    if (!tarea.vence_en) {
        return null;
    }

    const dias = Math.ceil(
        (new Date(`${tarea.vence_en}T23:59:59`).getTime() - Date.now()) /
            86_400_000,
    );

    return dias <= 3
        ? {
              texto:
                  dias <= 0
                      ? 'Vence hoy'
                      : `Vence en ${dias} día${dias === 1 ? '' : 's'}`,
              clase: 'bg-[var(--mrl-gold)]/15 text-[var(--mrl-gold-dark)]',
          }
        : {
              texto: `Vence el ${formatearFecha(tarea.vence_en)}`,
              clase: 'bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)]',
          };
}

const segmento = (activo: boolean) =>
    `rounded-lg px-3 py-1.5 text-sm font-medium transicion-ui ${activo ? 'bg-[var(--mrl-primary)] text-white shadow-sm' : 'text-[var(--mrl-texto-suave)] hover:bg-[var(--mrl-fondo)] hover:text-[var(--mrl-texto)]'}`;

// ---------------------------------------------- semáforo (antigüedad)
const SEMAFORO: Record<
    'verde' | 'ambar' | 'rojo',
    { etiqueta: string; clase: string; punto: string }
> = {
    verde: {
        etiqueta: 'Al día',
        clase: 'bg-success-soft text-success',
        punto: 'bg-[var(--mrl-success)]',
    },
    ambar: {
        etiqueta: 'Atiéndelo pronto',
        clase: 'bg-warning-soft text-warning',
        punto: 'bg-[var(--mrl-gold)]',
    },
    rojo: {
        etiqueta: 'Atrasado',
        clase: 'bg-danger-soft text-destructive',
        punto: 'bg-[var(--mrl-danger)]',
    },
};

function antiguedad(tarea: TareaBandeja): string {
    return tarea.antiguedad_dias === 0
        ? 'hoy'
        : `hace ${tarea.antiguedad_dias} día${tarea.antiguedad_dias === 1 ? '' : 's'}`;
}

// ------------------------------------------- agrupar por tipo (página)
const agrupar = ref(false);
const grupos = computed(() => {
    const mapa = new Map<
        string,
        { etiqueta: string; filas: FilaPendiente[] }
    >();

    for (const fila of props.tareas.data) {
        const grupo = mapa.get(fila.tipo) ?? {
            etiqueta: fila.tipo_etiqueta,
            filas: [],
        };
        grupo.filas.push(fila);
        mapa.set(fila.tipo, grupo);
    }

    return [...mapa.entries()].map(([clave, g]) => ({ clave, ...g }));
});
const secciones = computed(() =>
    agrupar.value
        ? grupos.value
        : [{ clave: 'todas', etiqueta: '', filas: props.tareas.data }],
);

function alternarTipo(clave: string) {
    filtros.tipo = filtros.tipo === clave ? '' : clave;
}

// ------------------------------------------------- acciones por fila
const quitando = ref<number | null>(null);
const motivo = ref('');
const procesando = ref<number | null>(null);

function abrirQuitar(id: number) {
    quitando.value = quitando.value === id ? null : id;
    motivo.value = '';
}

function enviar(url: string, id: number, datos: Record<string, string> = {}) {
    procesando.value = id;
    router.post(url, datos, {
        preserveScroll: true,
        onFinish: () => {
            procesando.value = null;
            quitando.value = null;
        },
    });
}

function confirmarQuitar(tarea: FilaPendiente) {
    enviar(descartar.url(tarea.id), tarea.id, {
        motivo: motivo.value.trim(),
    });
}

function cierreAutomatico(tarea: TareaBandeja): string | null {
    const motivoCierre = tarea.datos?.cierre_automatico;

    return typeof motivoCierre === 'string' ? motivoCierre : null;
}

const vacio = computed(() => {
    if (filtros.estado === 'descartadas') {
        return {
            titulo: 'No has quitado nada de tu lista',
            texto: 'Lo que quites con «Quitar de mi lista» aparece aquí para que puedas restaurarlo.',
        };
    }

    if (filtros.estado === 'resueltas') {
        return {
            titulo: 'Aún no hay pendientes resueltos',
            texto: 'Cuando atiendas un pendiente (o se cierre solo porque ya se cumplió) lo verás aquí.',
        };
    }

    return filtros.tipo ||
        filtros.etapa ||
        filtros.urgencia ||
        filtros.sucursal_id
        ? {
              titulo: 'Nada con estos filtros',
              texto: 'Quita algún filtro para ver el resto de tus pendientes.',
          }
        : {
              titulo: '¡Todo al día!',
              texto: 'No tienes pendientes abiertos. Los nuevos aparecerán aquí y se quitan solos cuando se cumplen.',
          };
});
</script>

<template>
    <Head title="Mis pendientes" />

    <div class="pagina-ancha flex flex-col gap-6">
        <!-- Encabezado con el número que importa -->
        <section
            class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[var(--mrl-primary)] to-[var(--mrl-verde-profundo)] p-5 text-white shadow-lg sm:p-8"
        >
            <div
                aria-hidden="true"
                class="pointer-events-none absolute -top-24 -right-16 size-80 rounded-full bg-white/10"
            />
            <div
                class="relative flex flex-wrap items-end justify-between gap-6"
            >
                <div>
                    <p class="flex items-center gap-2 text-sm text-white/80">
                        <Inbox class="size-4" /> ¿Qué me toca hacer?
                    </p>
                    <p class="mt-1 text-5xl font-semibold tabular-nums">
                        {{ conteos.abiertas }}
                    </p>
                    <p class="text-sm text-white/85">
                        pendientes abiertos dentro de tu alcance
                    </p>
                </div>
                <div
                    data-tour="pendientes-conteos"
                    class="grid w-full grid-cols-3 gap-2 sm:w-auto sm:gap-3"
                >
                    <button
                        type="button"
                        class="rounded-2xl bg-white/10 px-3 py-3 text-left backdrop-blur transicion-ui hover:-translate-y-0.5 hover:bg-white/20 sm:px-4"
                        @click="filtros.urgencia = ''"
                    >
                        <p
                            class="flex items-center gap-1.5 text-xs text-white/80"
                        >
                            <Inbox class="hidden size-3.5 sm:block" /> Abiertos
                        </p>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ conteos.abiertas }}
                        </p>
                    </button>
                    <div
                        class="rounded-2xl bg-white/10 px-3 py-3 backdrop-blur sm:px-4"
                    >
                        <p
                            class="flex items-center gap-1.5 text-xs text-white/80"
                        >
                            <Eye class="hidden size-3.5 sm:block" /> Sin ver
                        </p>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ conteos.no_leidas }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-2xl px-3 py-3 text-left backdrop-blur transicion-ui hover:-translate-y-0.5 sm:px-4"
                        :class="
                            conteos.vencidas
                                ? 'bg-[var(--mrl-danger)]/80 hover:bg-[var(--mrl-danger)]'
                                : 'bg-white/10 hover:bg-white/20'
                        "
                        @click="filtros.urgencia = 'vencidas'"
                    >
                        <p
                            class="flex items-center gap-1.5 text-xs text-white/90"
                        >
                            <AlarmClock class="hidden size-3.5 sm:block" />
                            Vencidos
                        </p>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ conteos.vencidas }}
                        </p>
                    </button>
                </div>
            </div>
        </section>

        <!-- Todo lo que espera una acción tuya, en un solo lugar -->
        <section
            v-if="atajos.length"
            aria-label="Resumen de pendientes"
            class="rejilla-auto gap-3 [--rejilla-min:15rem]"
        >
            <Link
                v-for="atajo in atajos"
                :key="atajo.clave"
                :href="atajo.url"
                class="group flex tarjeta-interactiva items-start gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
            >
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl text-lg font-bold tabular-nums"
                    :class="TONO_ATAJO[atajo.tono]"
                >
                    {{ atajo.conteo }}
                </span>
                <span class="flex min-w-0 flex-col gap-1">
                    <span
                        class="block text-sm font-semibold group-hover:text-primary"
                        >{{ atajo.titulo }}</span
                    >
                    <span class="block text-xs text-muted-foreground">{{
                        atajo.descripcion
                    }}</span>
                    <span class="mt-1 flex flex-wrap items-center gap-2">
                        <span
                            class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                            :class="PRIORIDAD_ATAJO[atajo.tono].clase"
                            >{{ PRIORIDAD_ATAJO[atajo.tono].etiqueta }}</span
                        >
                        <span
                            class="text-xs font-semibold text-primary group-hover:underline"
                            >Resolver →</span
                        >
                    </span>
                </span>
            </Link>
        </section>

        <div
            class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_340px] 2xl:grid-cols-[minmax(0,1fr)_380px]"
        >
            <!-- Lista -->
            <div class="flex min-w-0 flex-col gap-4">
                <!-- Abiertos / Quitados de mi lista / Resueltos -->
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div
                        class="flex rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-1"
                        role="group"
                        aria-label="Estado"
                    >
                        <button
                            type="button"
                            :class="segmento(filtros.estado === 'abiertas')"
                            @click="filtros.estado = 'abiertas'"
                        >
                            Abiertos
                            <span class="ml-1 tabular-nums opacity-80">{{
                                conteos.abiertas
                            }}</span>
                        </button>
                        <button
                            type="button"
                            :class="segmento(filtros.estado === 'descartadas')"
                            @click="filtros.estado = 'descartadas'"
                        >
                            <EyeOff class="mr-1 inline size-3.5" />Quitados
                            <span class="ml-1 tabular-nums opacity-80">{{
                                conteos.descartadas
                            }}</span>
                        </button>
                        <button
                            type="button"
                            :class="segmento(filtros.estado === 'resueltas')"
                            @click="filtros.estado = 'resueltas'"
                        >
                            <Check class="mr-1 inline size-3.5" />Resueltos
                        </button>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm font-medium transicion-ui"
                        :class="
                            agrupar
                                ? 'border-[var(--mrl-primary)] bg-salvia text-primary'
                                : 'border-[var(--mrl-borde)] bg-[var(--mrl-surface)] text-[var(--mrl-texto-suave)] hover:text-[var(--mrl-texto)]'
                        "
                        :aria-pressed="agrupar"
                        @click="agrupar = !agrupar"
                    >
                        <Layers class="size-4" /> Agrupar por tipo
                    </button>
                </div>

                <!-- Chips por tipo de pendiente (abiertos) -->
                <div
                    v-if="por_tipo.length > 1"
                    class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0"
                    aria-label="Filtrar por tipo de pendiente"
                >
                    <button
                        v-for="g in por_tipo"
                        :key="g.clave"
                        type="button"
                        class="inline-flex min-h-8 shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium whitespace-nowrap transicion-ui"
                        :class="
                            filtros.tipo === g.clave
                                ? 'border-[var(--mrl-primary)] bg-salvia text-primary'
                                : 'border-[var(--mrl-borde)] bg-crema/40 text-[var(--mrl-texto)] hover:bg-crema'
                        "
                        :aria-pressed="filtros.tipo === g.clave"
                        @click="alternarTipo(g.clave)"
                    >
                        {{ g.etiqueta }}
                        <span
                            class="rounded-full bg-white/70 px-1.5 tabular-nums"
                            >{{ g.total }}</span
                        >
                    </button>
                </div>

                <div
                    data-tour="pendientes-filtros"
                    class="flex flex-wrap items-center gap-2"
                >
                    <div
                        class="flex w-full rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-1 sm:w-auto [&>button]:flex-1 sm:[&>button]:flex-none"
                        role="group"
                        aria-label="Urgencia"
                    >
                        <button
                            type="button"
                            :class="segmento(filtros.urgencia === '')"
                            @click="filtros.urgencia = ''"
                        >
                            Todos
                        </button>
                        <button
                            type="button"
                            :class="segmento(filtros.urgencia === 'urgentes')"
                            @click="filtros.urgencia = 'urgentes'"
                        >
                            Urgentes
                        </button>
                        <button
                            type="button"
                            :class="segmento(filtros.urgencia === 'vencidas')"
                            @click="filtros.urgencia = 'vencidas'"
                        >
                            Vencidos
                        </button>
                    </div>
                    <SelectSimple
                        v-model="filtros.etapa"
                        :opciones="
                            opciones.etapas.map((e) => ({
                                value: e.value,
                                label: e.etiqueta,
                            }))
                        "
                        opcion-vacia="Todas las etapas"
                        class="h-10 w-full min-w-0 flex-1 sm:w-52 sm:flex-none"
                        aria-label="Etapa"
                    />
                    <SelectSimple
                        v-if="opciones.sucursales.length > 1"
                        v-model="filtros.sucursal_id"
                        :opciones="
                            opciones.sucursales.map((s) => ({
                                value: String(s.id),
                                label: s.nombre,
                            }))
                        "
                        opcion-vacia="Todas las sucursales"
                        class="h-10 w-full min-w-0 flex-1 sm:w-56 sm:flex-none"
                        aria-label="Sucursal"
                    />
                </div>

                <div
                    v-if="!tareas.data.length"
                    class="flex flex-col items-center gap-3 rounded-3xl border border-dashed border-[var(--mrl-borde)] bg-crema/30 p-12 text-center"
                >
                    <span
                        class="flex size-16 items-center justify-center rounded-full bg-success-soft"
                    >
                        <Sparkles
                            v-if="filtros.estado === 'abiertas'"
                            class="size-8 text-[var(--mrl-success)]"
                        />
                        <CheckCircle2
                            v-else
                            class="size-8 text-[var(--mrl-success)]"
                        />
                    </span>
                    <p class="text-lg font-semibold text-[var(--mrl-texto)]">
                        {{ vacio.titulo }}
                    </p>
                    <p class="max-w-md text-sm text-[var(--mrl-texto-suave)]">
                        {{ vacio.texto }}
                    </p>
                </div>

                <section
                    v-for="seccion in secciones"
                    :key="seccion.clave"
                    class="flex flex-col gap-3"
                >
                    <h3
                        v-if="agrupar"
                        class="flex items-center gap-2 text-sm font-semibold text-[var(--mrl-texto)]"
                    >
                        {{ seccion.etiqueta }}
                        <span
                            class="rounded-full bg-crema px-2 py-0.5 text-xs tabular-nums"
                            >{{ seccion.filas.length }}</span
                        >
                    </h3>
                    <ul
                        data-tour="pendientes-lista"
                        class="flex entrada-escalonada flex-col gap-3"
                    >
                        <li v-for="tarea in seccion.filas" :key="tarea.id">
                            <article
                                class="group relative flex flex-col gap-3 overflow-hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-4 pl-5 shadow-sm transicion-ui hover:border-[var(--mrl-primary)]/40 hover:shadow-md"
                                :class="{
                                    'opacity-60': procesando === tarea.id,
                                }"
                            >
                                <span
                                    class="absolute inset-y-0 left-0 w-1.5"
                                    :style="{
                                        background:
                                            COLOR_PRIORIDAD[tarea.prioridad] ??
                                            COLOR_PRIORIDAD.media,
                                    }"
                                />
                                <div class="flex items-start gap-4">
                                    <!-- Foto oficial de la persona (o iniciales). -->
                                    <ColaboradorAvatar
                                        :nombre="
                                            tarea.candidato?.nombre ??
                                            tarea.colaborador?.nombre ??
                                            '?'
                                        "
                                        :foto-url="tarea.foto_url"
                                        tamano="lg"
                                        class="ring-[var(--mrl-primary)]/10"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex flex-wrap items-center gap-1.5"
                                        >
                                            <span
                                                v-if="tarea.read_at === null"
                                                class="size-2 rounded-full bg-[var(--mrl-accent)]"
                                                title="Sin ver"
                                            />
                                            <span
                                                v-if="tarea.semaforo"
                                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                                :class="
                                                    SEMAFORO[tarea.semaforo]
                                                        .clase
                                                "
                                                :title="`Abierto ${antiguedad(tarea)}`"
                                            >
                                                <span
                                                    class="size-1.5 rounded-full"
                                                    :class="
                                                        SEMAFORO[tarea.semaforo]
                                                            .punto
                                                    "
                                                />
                                                {{
                                                    SEMAFORO[tarea.semaforo]
                                                        .etiqueta
                                                }}
                                            </span>
                                            <span
                                                class="inline-flex items-center gap-1 rounded-full bg-[var(--mrl-fondo)] px-2 py-0.5 text-[11px] font-medium text-[var(--mrl-texto)]"
                                            >
                                                <Flame
                                                    v-if="
                                                        tarea.prioridad ===
                                                        'urgente'
                                                    "
                                                    class="size-3 text-[var(--mrl-danger)]"
                                                />
                                                {{ tarea.prioridad_etiqueta }}
                                            </span>
                                            <span
                                                class="rounded-full bg-[var(--mrl-primary)]/10 px-2 py-0.5 text-[11px] font-medium text-[var(--mrl-primary)]"
                                                >{{
                                                    tarea.etapa_etiqueta ??
                                                    tarea.tipo_etiqueta
                                                }}</span
                                            >
                                            <span
                                                v-if="tarea.informativo"
                                                class="rounded-full bg-crema px-2 py-0.5 text-[11px] font-medium text-[var(--mrl-texto)]"
                                                >Informativo</span
                                            >
                                            <span
                                                v-if="
                                                    !tarea.resolved_at &&
                                                    venceEn(tarea)
                                                "
                                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="venceEn(tarea)?.clase"
                                            >
                                                <CalendarClock class="size-3" />
                                                {{ venceEn(tarea)?.texto }}
                                            </span>
                                        </div>
                                        <p
                                            class="mt-1 font-semibold break-words text-[var(--mrl-texto)]"
                                        >
                                            {{ tarea.titulo }}
                                        </p>
                                        <p
                                            class="truncate text-xs text-[var(--mrl-texto-suave)]"
                                        >
                                            {{
                                                tarea.candidato?.nombre ??
                                                tarea.colaborador?.nombre ??
                                                '—'
                                            }}
                                            <template v-if="tarea.sucursal">
                                                · {{ tarea.sucursal }}</template
                                            >
                                            · {{ antiguedad(tarea) }}
                                        </p>
                                        <p
                                            v-if="tarea.descripcion"
                                            class="mt-1 line-clamp-2 text-sm text-[var(--mrl-texto-suave)]"
                                        >
                                            {{ tarea.descripcion }}
                                        </p>
                                        <p
                                            v-if="tarea.descartada"
                                            class="mt-2 rounded-lg bg-crema/60 px-2 py-1 text-xs text-[var(--mrl-texto)]"
                                        >
                                            Lo quitaste de tu lista{{
                                                tarea.motivo_descarte
                                                    ? `: «${tarea.motivo_descarte}»`
                                                    : '.'
                                            }}
                                        </p>
                                        <p
                                            v-if="
                                                tarea.resolved_at &&
                                                cierreAutomatico(tarea)
                                            "
                                            class="mt-2 rounded-lg bg-success-soft px-2 py-1 text-xs text-[var(--mrl-texto)]"
                                        >
                                            Se cerró solo:
                                            {{ cierreAutomatico(tarea) }}
                                        </p>
                                        <p
                                            v-else-if="tarea.resolved_at"
                                            class="mt-2 text-xs text-[var(--mrl-texto-suave)]"
                                        >
                                            Resuelto el
                                            {{
                                                formatearFecha(
                                                    tarea.resolved_at.slice(
                                                        0,
                                                        10,
                                                    ),
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Acciones: Resolver/Ir, Quitar de mi lista, Marcar como hecho, Restaurar -->
                                <div
                                    class="flex flex-wrap items-center justify-end gap-2"
                                >
                                    <template v-if="!tarea.resolved_at">
                                        <button
                                            v-if="tarea.descartada"
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded-xl border border-[var(--mrl-borde)] px-3 py-1.5 text-sm font-medium text-[var(--mrl-texto)] transicion-ui hover:bg-crema"
                                            :disabled="procesando === tarea.id"
                                            @click="
                                                enviar(
                                                    restaurar.url(tarea.id),
                                                    tarea.id,
                                                )
                                            "
                                        >
                                            <RotateCcw class="size-4" />
                                            Restaurar a mi lista
                                        </button>
                                        <template v-else>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 rounded-xl px-3 py-1.5 text-sm font-medium text-[var(--mrl-texto-suave)] transicion-ui hover:bg-crema hover:text-[var(--mrl-texto)]"
                                                :aria-expanded="
                                                    quitando === tarea.id
                                                "
                                                @click="abrirQuitar(tarea.id)"
                                            >
                                                <EyeOff class="size-4" />
                                                Quitar de mi lista
                                            </button>
                                            <button
                                                v-if="tarea.puede_marcar_hecho"
                                                type="button"
                                                class="inline-flex items-center gap-1 rounded-xl border border-[var(--mrl-borde)] px-3 py-1.5 text-sm font-medium text-[var(--mrl-texto)] transicion-ui hover:bg-success-soft"
                                                :disabled="
                                                    procesando === tarea.id
                                                "
                                                @click="
                                                    enviar(
                                                        hecho.url(tarea.id),
                                                        tarea.id,
                                                    )
                                                "
                                            >
                                                <Check class="size-4" />
                                                Marcar como hecho
                                            </button>
                                        </template>
                                    </template>
                                    <Link
                                        v-if="tarea.url"
                                        :href="tarea.url"
                                        class="inline-flex items-center gap-1 rounded-xl bg-[var(--mrl-primary)] px-3 py-1.5 text-sm font-medium text-white shadow-sm transicion-ui hover:opacity-90"
                                    >
                                        {{
                                            tarea.resolved_at
                                                ? 'Ir'
                                                : 'Resolver'
                                        }}
                                        <ArrowRight class="size-4" />
                                    </Link>
                                </div>

                                <!-- Quitar de mi lista: motivo opcional -->
                                <form
                                    v-if="quitando === tarea.id"
                                    class="flex flex-col gap-2 rounded-xl bg-crema/50 p-3 sm:flex-row sm:flex-wrap sm:items-center"
                                    @submit.prevent="confirmarQuitar(tarea)"
                                >
                                    <Input
                                        v-model="motivo"
                                        :maxlength="opciones.motivo_max"
                                        placeholder="Motivo (opcional): «ya lo atendí por teléfono», «no me corresponde»…"
                                        aria-label="Motivo para quitarlo de tu lista"
                                        class="flex-1 bg-white"
                                    />
                                    <div class="flex gap-2">
                                        <button
                                            type="submit"
                                            class="inline-flex items-center gap-1 rounded-xl bg-[var(--mrl-primary)] px-3 py-1.5 text-sm font-medium text-white"
                                            :disabled="procesando === tarea.id"
                                        >
                                            <EyeOff class="size-4" /> Quitar
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded-xl px-3 py-1.5 text-sm font-medium text-[var(--mrl-texto-suave)] hover:bg-white"
                                            @click="quitando = null"
                                        >
                                            <X class="size-4" /> Cancelar
                                        </button>
                                    </div>
                                    <p
                                        class="text-xs text-[var(--mrl-texto-suave)] sm:basis-full"
                                    >
                                        Solo sale de TU lista; a los demás les
                                        sigue llegando. Si vuelve a generarse
                                        por algo nuevo, reaparece.
                                    </p>
                                </form>
                            </article>
                        </li>
                    </ul>
                </section>

                <PaginacionTabla :datos="tareas" />
            </div>

            <!-- Gráficas -->
            <aside
                class="grid min-w-0 gap-4 md:grid-cols-2 xl:sticky xl:top-4 xl:flex xl:flex-col xl:self-start"
            >
                <section
                    class="rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
                >
                    <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                        Pendientes por etapa
                    </h2>
                    <p class="mb-4 text-xs text-[var(--mrl-texto-suave)]">
                        Toca una barra para filtrar
                    </p>
                    <p
                        v-if="!distribucion.por_etapa.length"
                        class="text-sm text-[var(--mrl-texto-suave)]"
                    >
                        Sin pendientes abiertos.
                    </p>
                    <ul class="flex flex-col gap-2">
                        <li v-for="e in distribucion.por_etapa" :key="e.clave">
                            <button
                                type="button"
                                class="group grid w-full grid-cols-[minmax(0,170px)_1fr_32px] items-center gap-3 rounded-lg p-1 text-left text-sm transition-colors hover:bg-[var(--mrl-fondo)]"
                                :class="
                                    filtros.etapa === e.clave
                                        ? 'bg-[var(--mrl-primary)]/5'
                                        : ''
                                "
                                :title="`${e.etiqueta}: ${e.total}`"
                                :aria-pressed="filtros.etapa === e.clave"
                                @click="alternarEtapa(e.clave)"
                            >
                                <span
                                    class="truncate text-[var(--mrl-texto)]"
                                    >{{ e.etiqueta }}</span
                                >
                                <span
                                    class="h-3 rounded-full bg-[var(--mrl-fondo)]"
                                >
                                    <span
                                        class="block h-full rounded-r-[4px] bg-[var(--mrl-primary)] transition-[width] duration-(--mov-lento) group-hover:opacity-80"
                                        :class="
                                            filtros.etapa &&
                                            filtros.etapa !== e.clave
                                                ? 'opacity-40'
                                                : ''
                                        "
                                        :style="{
                                            width: `${(e.total / maxEtapa) * 100}%`,
                                            minWidth: e.total ? '6px' : '0',
                                        }"
                                    />
                                </span>
                                <span
                                    class="text-right text-[var(--mrl-texto)] tabular-nums"
                                    >{{ e.total }}</span
                                >
                            </button>
                        </li>
                    </ul>
                </section>

                <section
                    class="rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
                >
                    <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                        Por prioridad
                    </h2>
                    <p class="mb-4 text-xs text-[var(--mrl-texto-suave)]">
                        {{ totalPrioridad }} abiertos
                    </p>
                    <div
                        class="flex h-4 gap-0.5 overflow-hidden rounded-full bg-[var(--mrl-fondo)]"
                    >
                        <span
                            v-for="p in distribucion.por_prioridad.filter(
                                (x) => x.total,
                            )"
                            :key="p.clave"
                            class="h-full transition-opacity first:rounded-l-full last:rounded-r-full hover:opacity-80"
                            :style="{
                                width: `${(p.total / Math.max(1, totalPrioridad)) * 100}%`,
                                background: COLOR_PRIORIDAD[p.clave],
                            }"
                            :title="`${p.etiqueta}: ${p.total}`"
                        />
                    </div>
                    <ul class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <li
                            v-for="p in distribucion.por_prioridad"
                            :key="p.clave"
                            class="flex items-center gap-2"
                        >
                            <span
                                class="size-2.5 rounded-full"
                                :style="{
                                    background: COLOR_PRIORIDAD[p.clave],
                                }"
                            />
                            <span class="text-[var(--mrl-texto-suave)]">{{
                                p.etiqueta
                            }}</span>
                            <span
                                class="ml-auto font-medium text-[var(--mrl-texto)] tabular-nums"
                                >{{ p.total }}</span
                            >
                        </li>
                    </ul>
                </section>
            </aside>
        </div>
    </div>
</template>
