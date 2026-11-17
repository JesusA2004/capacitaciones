<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Activity,
    ClipboardCheck,
    FolderOpen,
    Gauge,
    History,
    Lightbulb,
    Loader2,
    Sparkles,
    Target,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import { Button } from '@/components/ui/button';
import NumeroAnimado from '@/components/ui/NumeroAnimado.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { usePermisos } from '@/composables/usePermisos';
import { VueApexCharts } from '@/lib/apexDiferido';
import { getJson } from '@/lib/http';
import { ESTILO_CUADRANTE, textoMetodo, tonoPuntaje } from '@/lib/nineBox';
import {
    formatoCompacto,
    formatoMoneda,
    formatoPorcentaje,
} from '@/lib/seguimiento';
import { cn } from '@/lib/utils';
import { show as showExpediente } from '@/routes/rh/expedientes';
import { show as showGestor } from '@/routes/seguimiento/gestores';
import { store as guardarCalibracion } from '@/routes/seguimiento/nine-box/calibraciones';
import { show as detallePersona } from '@/routes/seguimiento/nine-box/personas';
import type {
    CuadranteNineBox,
    DetallePersonaNineBox,
    NivelNineBox,
    PeriodoNineBox,
    PersonaNineBox,
} from '@/types';

/**
 * Panel lateral de una persona de la Nine Box: por qué está en su cuadrante
 * (desempeño desglosado y potencial), su tendencia semanal, sus
 * «Seguimientos» técnicos (15/30/60…) y la calibración del potencial con
 * su historial (nunca se borra).
 */
const props = defineProps<{
    persona: PersonaNineBox | null;
    cuadrante: CuadranteNineBox | null;
    periodo: PeriodoNineBox | null;
    niveles: { value: string; label: string }[];
}>();

const abierto = defineModel<boolean>('abierto', { required: true });

const { tienePermiso } = usePermisos();

const detalle = ref<DetallePersonaNineBox | null>(null);
const cargandoDetalle = ref(false);
const errorDetalle = ref<string | null>(null);

async function cargarDetalle(colaboradorId: number | null): Promise<void> {
    detalle.value = null;
    errorDetalle.value = null;

    if (colaboradorId === null) {
        return;
    }

    cargandoDetalle.value = true;

    try {
        detalle.value = await getJson<DetallePersonaNineBox>(detallePersona.url(colaboradorId));
    } catch {
        errorDetalle.value = 'No pudimos traer sus evaluaciones técnicas. Intenta de nuevo.';
    } finally {
        cargandoDetalle.value = false;
    }
}

watch(
    () => [props.persona?.id, abierto.value] as const,
    ([id, visible], anterior) => {
        if (visible && id !== undefined && (id !== anterior?.[0] || !anterior?.[1])) {
            void cargarDetalle(props.persona?.colaborador_id ?? null);
            nivel.value = props.persona?.potencial.calibracion?.heredada === false
                ? props.persona.potencial.calibracion.nivel
                : null;
            comentario.value = '';
            errores.value = {};
        }
    },
    { immediate: true },
);

// ── Calibración ───────────────────────────────────────────────────────────
const nivel = ref<NivelNineBox | null>(null);
const comentario = ref('');
const enviando = ref(false);
const errores = ref<Record<string, string>>({});

const DESCRIPCION_NIVEL: Record<NivelNineBox, string> = {
    bajo: 'Se mantiene en su puesto actual',
    medio: 'Puede crecer con acompañamiento',
    alto: 'Listo para retos mayores',
};

function calibrar(): void {
    if (!props.persona?.colaborador_id || !props.periodo || nivel.value === null) {
        return;
    }

    enviando.value = true;
    router.post(
        guardarCalibracion.url(),
        {
            colaborador_id: props.persona.colaborador_id,
            periodo_tipo: props.periodo.tipo,
            periodo_clave: props.periodo.clave,
            nivel: nivel.value,
            comentario: comentario.value,
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['datos'],
            onError: (e) => {
                errores.value = e;
            },
            onSuccess: () => {
                comentario.value = '';
                errores.value = {};
                void cargarDetalle(props.persona?.colaborador_id ?? null);
            },
            onFinish: () => {
                enviando.value = false;
            },
        },
    );
}

// ── Gráfica semanal ───────────────────────────────────────────────────────
const serie = computed(() => [
    { name: 'Colocación', data: props.persona?.semanas.map((s) => s.colocacion) ?? [] },
    { name: 'Recuperación', data: props.persona?.semanas.map((s) => s.recuperacion) ?? [] },
]);

const opciones = computed(() => ({
    chart: { toolbar: { show: false }, fontFamily: 'inherit', zoom: { enabled: false }, sparkline: { enabled: false } },
    colors: ['var(--chart-1)', 'var(--chart-2)'],
    stroke: { curve: 'smooth' as const, width: 2.5 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.02 } },
    dataLabels: { enabled: false },
    markers: { size: 3 },
    legend: { position: 'top' as const, horizontalAlign: 'right' as const, fontSize: '11px' },
    grid: { borderColor: 'var(--border)', strokeDashArray: 4, padding: { left: 4, right: 4 } },
    xaxis: {
        categories: props.persona?.semanas.map((s) => s.etiqueta) ?? [],
        labels: { style: { fontSize: '10px' } },
    },
    yaxis: { labels: { formatter: (v: number) => formatoCompacto(v), style: { fontSize: '10px' } } },
    tooltip: { y: { formatter: (v: number) => formatoMoneda(v) } },
}));

// ── Anillos de puntaje ────────────────────────────────────────────────────
const CIRCUNFERENCIA = 2 * Math.PI * 30;

function trazo(puntaje: number | null): string {
    const avance = Math.max(0, Math.min(100, puntaje ?? 0)) / 100;

    return `${CIRCUNFERENCIA * avance} ${CIRCUNFERENCIA}`;
}

function valorComponente(valor: number | null, formato: 'moneda' | 'porcentaje'): string {
    return formato === 'moneda' ? formatoMoneda(valor) : formatoPorcentaje(valor);
}

const fecha = new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });

function formatoFecha(valor: string | null): string {
    return valor ? fecha.format(new Date(valor)) : '';
}

const mesReportes = computed(() => props.periodo?.meses_ids[props.periodo.meses_ids.length - 1] ?? null);
</script>

<template>
    <Sheet v-model:open="abierto">
        <SheetContent class="w-full gap-0 p-0 sm:max-w-xl">
            <template v-if="persona">
                <!-- Encabezado con el color del cuadrante -->
                <SheetHeader
                    :class="cn('gap-3 border-b border-border p-5 pr-12', cuadrante ? ESTILO_CUADRANTE[cuadrante.clave].fondo : 'bg-crema')"
                >
                    <div class="flex items-center gap-4">
                        <ColaboradorAvatar :nombre="persona.nombre" :foto-url="persona.foto_url" tamano="lg" class="aparece-zoom" />
                        <div class="min-w-0">
                            <SheetTitle class="text-lg leading-tight">{{ persona.nombre }}</SheetTitle>
                            <SheetDescription class="text-xs">
                                {{ [persona.puesto, persona.sucursal ?? persona.branch].filter(Boolean).join(' · ') }}
                            </SheetDescription>
                            <span
                                v-if="cuadrante"
                                class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-card/80 px-2.5 py-1 text-xs font-semibold shadow-xs"
                                :class="ESTILO_CUADRANTE[cuadrante.clave].acento"
                            >
                                <Sparkles class="size-3.5" /> {{ cuadrante.etiqueta }}
                            </span>
                            <span
                                v-else
                                class="mt-2 inline-flex rounded-full bg-card/80 px-2.5 py-1 text-xs font-medium text-warning"
                            >
                                {{ persona.estado === 'sin_vincular' ? 'Sin vincular a PEOPLE' : 'Por calibrar' }}
                            </span>
                        </div>
                    </div>
                    <p v-if="cuadrante" class="flex gap-2 rounded-xl bg-card/70 p-3 text-xs leading-relaxed">
                        <Lightbulb class="mt-0.5 size-4 shrink-0 text-oro" aria-hidden="true" />
                        <span><span class="font-semibold">Acción sugerida:</span> {{ cuadrante.accion }}</span>
                    </p>
                </SheetHeader>

                <div class="flex flex-col gap-5 p-5">
                    <!-- Puntajes -->
                    <section class="grid grid-cols-2 gap-3 entrada-escalonada">
                        <div
                            v-for="eje in [
                                { titulo: 'Desempeño', puntaje: persona.desempeno.puntaje, nivel: persona.desempeno.nivel, icono: Gauge },
                                { titulo: 'Potencial', puntaje: persona.potencial.puntaje, nivel: persona.potencial.nivel, icono: Target },
                            ]"
                            :key="eje.titulo"
                            class="flex items-center gap-3 rounded-2xl border border-border bg-card p-3"
                        >
                            <svg viewBox="0 0 72 72" class="size-16 shrink-0 -rotate-90" aria-hidden="true">
                                <circle cx="36" cy="36" r="30" fill="none" stroke-width="7" class="stroke-muted" />
                                <circle
                                    cx="36"
                                    cy="36"
                                    r="30"
                                    fill="none"
                                    stroke-width="7"
                                    stroke-linecap="round"
                                    class="stroke-primary transition-[stroke-dasharray] duration-700 ease-out"
                                    :stroke-dasharray="trazo(eje.puntaje)"
                                />
                            </svg>
                            <div class="min-w-0">
                                <p class="flex items-center gap-1 text-xs text-muted-foreground">
                                    <component :is="eje.icono" class="size-3.5" /> {{ eje.titulo }}
                                </p>
                                <p class="text-2xl font-semibold tabular-nums" :class="tonoPuntaje(eje.puntaje)">
                                    <NumeroAnimado v-if="eje.puntaje !== null" :valor="eje.puntaje" :decimales="0" />
                                    <span v-else>—</span>
                                </p>
                                <p class="text-[11px] text-muted-foreground capitalize">{{ eje.nivel ?? 'Sin dato' }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- Desglose del desempeño -->
                    <section class="flex flex-col gap-2">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <Gauge class="size-4 text-bronce" /> Desempeño en {{ periodo?.etiqueta }}
                        </h3>
                        <div
                            v-for="c in persona.desempeno.componentes"
                            :key="c.clave"
                            class="rounded-xl border border-border/70 bg-card px-3 py-2"
                        >
                            <div class="flex items-baseline justify-between gap-2 text-sm">
                                <span class="font-medium">{{ c.etiqueta }}</span>
                                <span class="tabular-nums">{{ valorComponente(c.valor, c.formato) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full bg-esmeralda transition-[width] duration-700 ease-out"
                                    :style="{ width: `${c.puntaje ?? 0}%` }"
                                />
                            </div>
                            <p class="mt-1 flex justify-between gap-2 text-[11px] text-muted-foreground">
                                <span>{{ textoMetodo(c) }} · peso {{ c.peso }}</span>
                                <span class="tabular-nums">{{ c.puntaje === null ? 'Sin dato' : `${Math.round(c.puntaje)} pts` }}</span>
                            </p>
                        </div>
                    </section>

                    <!-- Tendencia semanal -->
                    <section class="rounded-2xl border border-border bg-card p-3">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <Activity class="size-4 text-bronce" /> Tendencia semanal
                        </h3>
                        <VueApexCharts type="area" height="200" :options="opciones" :series="serie" />
                    </section>

                    <!-- Potencial -->
                    <section class="flex flex-col gap-2">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <ClipboardCheck class="size-4 text-bronce" /> Potencial
                        </h3>
                        <p v-if="!persona.vinculado" class="rounded-xl bg-warning-soft p-3 text-xs">
                            Este gestor de Reportes aún no está vinculado con un colaborador de PEOPLE: sin vínculo no
                            tiene evaluaciones técnicas ni calibración.
                        </p>
                        <template v-else>
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div class="rounded-xl bg-crema/60 p-3">
                                    <p class="text-muted-foreground">Promedio de Seguimientos</p>
                                    <p class="text-lg font-semibold tabular-nums">
                                        {{ persona.potencial.evaluaciones === null ? '—' : Math.round(persona.potencial.evaluaciones) }}
                                    </p>
                                </div>
                                <div class="rounded-xl bg-salvia/60 p-3">
                                    <p class="text-muted-foreground">Calibración vigente</p>
                                    <p class="text-lg font-semibold">
                                        {{ persona.potencial.calibracion?.etiqueta ?? 'Sin calibrar' }}
                                    </p>
                                    <p v-if="persona.potencial.calibracion?.heredada" class="text-[11px] text-muted-foreground">
                                        De {{ persona.potencial.calibracion.periodo }}
                                    </p>
                                </div>
                            </div>

                            <!-- Seguimientos técnicos -->
                            <div class="rounded-xl border border-border/70 bg-card p-3">
                                <p class="mb-2 text-xs font-medium text-muted-foreground">Evaluaciones técnicas</p>
                                <p v-if="cargandoDetalle" class="flex items-center gap-2 text-xs text-muted-foreground">
                                    <Loader2 class="size-3.5 animate-spin" /> Cargando…
                                </p>
                                <p v-else-if="errorDetalle" class="text-xs text-danger">{{ errorDetalle }}</p>
                                <p v-else-if="!detalle?.evaluaciones.length" class="text-xs text-muted-foreground">
                                    Aún no tiene Seguimientos técnicos programados.
                                </p>
                                <ol v-else class="relative flex flex-col gap-2 border-l border-border pl-4">
                                    <li v-for="e in detalle.evaluaciones" :key="e.id" class="relative entrada-suave">
                                        <span
                                            class="absolute top-1.5 -left-[21px] size-2.5 rounded-full ring-2 ring-card"
                                            :class="
                                                e.estado === 'contestada'
                                                    ? e.aprobada
                                                        ? 'bg-success'
                                                        : 'bg-danger'
                                                    : e.vencida
                                                      ? 'bg-oro'
                                                      : 'bg-border'
                                            "
                                        />
                                        <div class="flex items-baseline justify-between gap-2 text-sm">
                                            <span class="font-medium">{{ e.nombre }}</span>
                                            <span
                                                v-if="e.calificacion !== null"
                                                class="font-semibold tabular-nums"
                                                :class="tonoPuntaje(e.calificacion)"
                                                >{{ Math.round(e.calificacion) }}</span
                                            >
                                            <span v-else class="text-[11px]" :class="e.vencida ? 'text-warning' : 'text-muted-foreground'">
                                                {{ e.vencida ? 'Vencida' : e.estado_etiqueta }}
                                            </span>
                                        </div>
                                        <p class="text-[11px] text-muted-foreground">
                                            {{ e.contestada_en ? `Contestada ${formatoFecha(e.contestada_en)}` : `Programada ${formatoFecha(e.fecha_programada)}` }}
                                        </p>
                                    </li>
                                </ol>
                            </div>

                            <!-- Formulario de calibración -->
                            <form
                                v-if="persona.puede_calibrar && periodo"
                                class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-3"
                                @submit.prevent="calibrar"
                            >
                                <p class="text-sm font-semibold">Calibrar potencial · {{ periodo.etiqueta }}</p>
                                <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Potencial">
                                    <button
                                        v-for="n in niveles"
                                        :key="n.value"
                                        type="button"
                                        role="radio"
                                        :aria-checked="nivel === n.value"
                                        class="flex cursor-pointer flex-col items-start gap-0.5 rounded-xl border p-2.5 text-left transicion-ui"
                                        :class="
                                            nivel === n.value
                                                ? 'border-primary bg-salvia shadow-sm'
                                                : 'border-border hover:border-primary/50 hover:bg-crema/60'
                                        "
                                        @click="nivel = n.value as NivelNineBox"
                                    >
                                        <span class="text-sm font-semibold">{{ n.label }}</span>
                                        <span class="text-[11px] leading-tight text-muted-foreground">{{
                                            DESCRIPCION_NIVEL[n.value as NivelNineBox]
                                        }}</span>
                                    </button>
                                </div>
                                <p v-if="errores.nivel" class="text-xs text-danger">{{ errores.nivel }}</p>
                                <Textarea
                                    v-model="comentario"
                                    rows="3"
                                    maxlength="1000"
                                    placeholder="¿Por qué? Ejemplos concretos de lo que has visto (obligatorio)."
                                    aria-label="Comentario de la calibración"
                                />
                                <p v-if="errores.comentario || errores.periodo_clave || errores.colaborador_id" class="text-xs text-danger">
                                    {{ errores.comentario ?? errores.periodo_clave ?? errores.colaborador_id }}
                                </p>
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-[11px] text-muted-foreground">Queda en el historial; nunca se borra.</p>
                                    <Button type="submit" :disabled="enviando || nivel === null || comentario.trim().length < 10">
                                        <Loader2 v-if="enviando" class="size-4 animate-spin" /> Guardar calibración
                                    </Button>
                                </div>
                            </form>

                            <!-- Historial -->
                            <div v-if="detalle?.calibraciones.length" class="rounded-xl border border-border/70 bg-card p-3">
                                <p class="mb-2 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                    <History class="size-3.5" /> Historial de calibraciones
                                </p>
                                <ul class="flex flex-col gap-2">
                                    <li v-for="c in detalle.calibraciones" :key="c.id" class="text-xs entrada-suave">
                                        <p>
                                            <span class="font-semibold">{{ c.etiqueta }}</span>
                                            <span class="text-muted-foreground"> · {{ c.periodo }}</span>
                                        </p>
                                        <p class="text-muted-foreground">«{{ c.comentario }}»</p>
                                        <p class="text-[11px] text-muted-foreground">
                                            {{ c.capturada_por ?? 'Sistema' }} · {{ formatoFecha(c.capturada_en) }}
                                        </p>
                                    </li>
                                </ul>
                            </div>
                        </template>
                    </section>

                    <!-- Accesos cruzados -->
                    <div class="flex flex-wrap gap-2 border-t border-border pt-4">
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="showGestor(persona.id, { query: mesReportes ? { mes: mesReportes } : {} })">
                                <Activity class="size-4" /> Detalle en Seguimiento
                            </Link>
                        </Button>
                        <Button
                            v-if="persona.colaborador_id && tienePermiso('expedientes.ver')"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link :href="showExpediente(persona.colaborador_id)">
                                <FolderOpen class="size-4" /> Expediente
                            </Link>
                        </Button>
                    </div>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
