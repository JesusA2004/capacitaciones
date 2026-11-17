<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BadgeCheck,
    CalendarClock,
    CalendarRange,
    ClipboardCheck,
    FileSignature,
    Flag,
    GraduationCap,
    Hourglass,
    PencilLine,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    UserCheck,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onMounted, ref } from 'vue';
import BotonCorregir from '@/components/correcciones/BotonCorregir.vue';
import EditarFechasDialog from '@/components/Rh/Expediente/FechasLaborales/EditarFechasDialog.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { pedirCorreccion } from '@/lib/correcciones';
import { formatearFecha, formatearFechaLarga } from '@/lib/fechas';
import { fechasLaborales } from '@/routes/rh/expedientes';
import { index as capturaMasiva } from '@/routes/rh/fechas-laborales';
import type { AlertaFechasLaborales, ResumenFechasLaborales } from '@/types';

/**
 * Sección «Fechas laborales» del expediente (docs/FECHAS_LABORALES.md):
 * línea de tiempo, fechas capturadas y calculadas, periodo hereda/específico
 * y avisos de lo que quedó desfasado. Carga sus datos aparte (JSON) para no
 * engordar la carga del expediente.
 */
const props = defineProps<{ colaboradorId: number }>();

const resumen = ref<ResumenFechasLaborales | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const editar = ref(false);
const campoInicial = ref<string | null>(null);

async function cargar(): Promise<void> {
    cargando.value = resumen.value === null;
    error.value = null;

    try {
        resumen.value = await pedirCorreccion<ResumenFechasLaborales>(
            fechasLaborales.url(props.colaboradorId),
        );
    } catch (e) {
        error.value =
            e instanceof Error
                ? e.message
                : 'No se pudieron cargar las fechas.';
    } finally {
        cargando.value = false;
    }
}

onMounted(() => void cargar());

function abrirEdicion(campo: string | null = null): void {
    campoInicial.value = campo;
    editar.value = true;
}

// ───────── Línea de tiempo ─────────

interface Hito {
    clave: string;
    etiqueta: string;
    fecha: string;
    icono: Component;
    tono: 'bronce' | 'salvia' | 'info' | 'oro' | 'menta' | 'danger';
    detalle?: string;
    hecho: boolean;
}

const TONOS: Record<Hito['tono'], string> = {
    bronce: 'bg-crema text-bronce ring-bronce/30',
    salvia: 'bg-success-soft text-success ring-success/30',
    info: 'bg-info-soft text-info ring-info/30',
    oro: 'bg-warning-soft text-warning ring-warning/30',
    menta: 'bg-success-soft text-success ring-success/30',
    danger: 'bg-danger-soft text-danger ring-danger/30',
};

const hitos = computed<Hito[]>(() => {
    const r = resumen.value;

    if (!r) {
        return [];
    }

    const hoy = r.hoy;
    const lista: Hito[] = [];
    const agregar = (
        h: Omit<Hito, 'hecho' | 'fecha'> & { fecha: string | null },
    ): void => {
        if (h.fecha) {
            lista.push({ ...h, fecha: h.fecha, hecho: h.fecha <= hoy });
        }
    };

    agregar({
        clave: 'ingreso',
        etiqueta: 'Ingreso',
        fecha: r.capturadas.fecha_ingreso,
        icono: UserCheck,
        tono: 'bronce',
    });
    agregar({
        clave: 'imss',
        etiqueta: 'Alta IMSS',
        fecha: r.capturadas.fecha_alta_imss,
        icono: ShieldCheck,
        tono: 'salvia',
    });
    agregar({
        clave: 'induccion',
        etiqueta: 'Inducción',
        fecha: r.induccion.fecha,
        icono: GraduationCap,
        tono: 'salvia',
    });
    agregar({
        clave: 'capacitacion',
        etiqueta: 'Inicia capacitación inicial',
        fecha: r.inicio_capacitacion,
        icono: Sparkles,
        tono: 'info',
    });

    for (const s of r.seguimientos) {
        agregar({
            clave: `seg-${s.dias}`,
            etiqueta: s.nombre,
            fecha: s.fecha,
            icono: ClipboardCheck,
            tono: 'menta',
            detalle: s.estado_etiqueta,
        });
    }

    agregar({
        clave: 'evaluacion',
        etiqueta: 'Se habilita la evaluación',
        fecha: r.evaluacion_desde,
        icono: CalendarClock,
        tono: 'oro',
    });
    agregar({
        clave: 'fin',
        etiqueta: 'Fin del periodo de prueba',
        fecha: r.fin_periodo,
        icono: Flag,
        tono: 'danger',
    });

    if (r.contrato?.fecha_fin && r.contrato.fecha_fin !== r.fin_periodo) {
        agregar({
            clave: 'contrato',
            etiqueta: 'Fin del contrato registrado',
            fecha: r.contrato.fecha_fin,
            icono: FileSignature,
            tono: 'bronce',
        });
    }

    return lista.sort((a, b) => a.fecha.localeCompare(b.fecha));
});

const rango = computed(() => {
    const fechas = hitos.value.map((h) => h.fecha);

    if (fechas.length < 2) {
        return null;
    }

    const inicio = Date.parse(fechas[0]);
    const fin = Date.parse(fechas[fechas.length - 1]);

    return fin > inicio ? { inicio, fin } : null;
});

function posicion(fecha: string): number {
    if (!rango.value) {
        return 0;
    }

    const p =
        (Date.parse(fecha) - rango.value.inicio) /
        (rango.value.fin - rango.value.inicio);

    return Math.min(100, Math.max(0, p * 100));
}

const posicionHoy = computed(() => {
    if (!rango.value || !resumen.value) {
        return null;
    }

    const hoy = Date.parse(resumen.value.hoy);

    return hoy < rango.value.inicio || hoy > rango.value.fin
        ? null
        : posicion(resumen.value.hoy);
});

// ───────── Avisos ─────────

const CLASE_ALERTA: Record<AlertaFechasLaborales['nivel'], string> = {
    critico: 'border-danger/30 bg-danger-soft text-danger',
    alto: 'border-warning/30 bg-warning-soft text-warning',
    medio: 'border-info/25 bg-info-soft text-info',
};

const periodoChip = computed(() => {
    const p = resumen.value?.periodo;

    if (!p) {
        return null;
    }

    return {
        especifico: {
            texto: `Específico: ${p.etiqueta}`,
            clase: 'bg-crema text-bronce ring-1 ring-bronce/30',
        },
        puesto: {
            texto: `Hereda del puesto: ${p.etiqueta}`,
            clase: 'bg-success-soft text-success ring-1 ring-success/25',
        },
        sin_definir: {
            texto: 'Periodo sin definir',
            clase: 'bg-danger-soft text-danger ring-1 ring-danger/25',
        },
    }[p.origen];
});

const ORIGEN_INDUCCION: Record<string, string> = {
    capturada: 'Capturada por RH',
    sistema: 'De su evaluación en PEOPLE',
    falta: 'Falta capturarla',
};

const tarjetas = computed(() => {
    const r = resumen.value;

    if (!r) {
        return [];
    }

    return [
        {
            clave: 'fecha_ingreso',
            etiqueta: 'Fecha de ingreso',
            valor: r.capturadas.fecha_ingreso,
            nota: 'Inicio de labores · antigüedad y vacaciones',
            icono: UserCheck,
        },
        {
            clave: 'periodo_prueba_inicio',
            etiqueta: 'Inicio de capacitación inicial',
            valor: r.inicio_capacitacion,
            nota:
                r.inicio_capacitacion_origen === 'capturada'
                    ? 'Capturada por RH · aquí corren los días'
                    : 'Igual a la fecha de ingreso · aquí corren los días',
            icono: Sparkles,
        },
        {
            clave: 'fecha_induccion',
            etiqueta: 'Fecha de inducción',
            valor: r.induccion.fecha,
            nota: ORIGEN_INDUCCION[r.induccion.origen],
            icono: GraduationCap,
        },
        {
            clave: 'fecha_alta_imss',
            etiqueta: 'Alta IMSS',
            valor: r.capturadas.fecha_alta_imss,
            nota: 'Fecha del alta ante el IMSS',
            icono: ShieldCheck,
        },
    ];
});

const progreso = computed(() => {
    const r = resumen.value;

    if (!r || r.dias_transcurridos === null || r.dias_restantes === null) {
        return null;
    }

    const total = r.dias_transcurridos + r.dias_restantes;

    return total > 0
        ? Math.min(
              100,
              Math.max(0, Math.round((r.dias_transcurridos / total) * 100)),
          )
        : null;
});
</script>

<template>
    <section class="flex flex-col gap-4" data-fechas-laborales>
        <div v-if="cargando" class="grid gap-3">
            <Skeleton class="h-28 rounded-3xl" />
            <Skeleton class="h-40 rounded-3xl" />
        </div>

        <p
            v-else-if="error"
            class="flex items-center gap-2 rounded-2xl bg-danger-soft px-4 py-3 text-sm text-danger"
            role="alert"
        >
            <AlertTriangle class="size-4" /> {{ error }}
            <Button size="sm" variant="outline" class="ml-auto" @click="cargar">
                <RefreshCw class="size-3.5" /> Reintentar
            </Button>
        </p>

        <template v-else-if="resumen">
            <!-- Encabezado -->
            <header
                class="flex entrada-suave flex-col gap-4 rounded-3xl border border-border bg-gradient-to-br from-crema/70 via-card to-salvia/40 p-5 shadow-xs md:flex-row md:items-center"
            >
                <div class="min-w-0 flex-1">
                    <h2
                        class="flex items-center gap-2 font-display text-xl font-semibold tracking-tight"
                    >
                        <CalendarRange class="size-5 text-bronce" /> Fechas
                        laborales
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Una sola fuente para el periodo de prueba, su evaluación
                        y los Seguimientos.
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                        <span
                            v-if="periodoChip"
                            class="rounded-full px-2.5 py-1 font-medium"
                            :class="periodoChip.clase"
                            data-chip-periodo
                            >{{ periodoChip.texto }}</span
                        >
                        <span
                            v-if="
                                resumen.periodo.origen === 'especifico' &&
                                resumen.periodo.puesto_etiqueta
                            "
                            class="rounded-full bg-muted px-2.5 py-1 text-muted-foreground"
                            >El puesto marca
                            {{ resumen.periodo.puesto_etiqueta }}</span
                        >
                        <span
                            v-if="resumen.importado"
                            class="rounded-full bg-muted px-2.5 py-1 text-muted-foreground"
                            >Plantilla sin candidato (importada)</span
                        >
                        <span
                            v-if="resumen.faltantes.length > 0"
                            class="rounded-full bg-warning-soft px-2.5 py-1 font-medium text-warning"
                            >Faltan
                            {{ resumen.faltantes.length }} fecha(s)</span
                        >
                    </div>
                </div>
                <div class="flex flex-col items-start gap-3 md:items-end">
                    <div
                        v-if="
                            progreso !== null &&
                            resumen.dias_restantes !== null &&
                            resumen.dias_restantes >= 0
                        "
                        class="w-full md:w-64"
                    >
                        <div
                            class="flex justify-between text-xs text-muted-foreground"
                        >
                            <span>Día {{ resumen.dias_transcurridos }}</span>
                            <span
                                >Faltan {{ resumen.dias_restantes }} días</span
                            >
                        </div>
                        <div
                            class="mt-1 h-2 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full bg-gradient-to-r from-bronce to-oro transition-all"
                                :style="{ width: `${progreso}%` }"
                            />
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="resumen.puede_editar"
                            size="sm"
                            variant="outline"
                            as-child
                        >
                            <Link :href="capturaMasiva()">Captura masiva</Link>
                        </Button>
                        <Button
                            v-if="resumen.puede_editar"
                            size="sm"
                            data-editar-fechas
                            @click="abrirEdicion()"
                        >
                            <PencilLine class="size-3.5" /> Editar fechas
                        </Button>
                    </div>
                </div>
            </header>

            <!-- Avisos -->
            <ul
                v-if="resumen.alertas.length > 0"
                class="flex flex-col gap-2"
                data-alertas-fechas
            >
                <li
                    v-for="a in resumen.alertas"
                    :key="a.codigo"
                    class="flex flex-col gap-2 rounded-2xl border px-4 py-3 sm:flex-row sm:items-center"
                    :class="CLASE_ALERTA[a.nivel]"
                >
                    <AlertTriangle class="hidden size-5 shrink-0 sm:block" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">{{ a.titulo }}</p>
                        <p class="text-xs text-foreground/75">
                            {{ a.detalle }}
                        </p>
                    </div>
                    <BotonCorregir
                        v-if="
                            a.accion === 'corregir_contrato' &&
                            a.contrato_id !== null
                        "
                        dominio="contratacion"
                        :sujeto-id="a.contrato_id"
                        campo="fecha_fin"
                        :etiqueta="a.accion_etiqueta ?? 'Actualizar contrato'"
                        variante="secondary"
                        @corregido="cargar"
                    />
                    <Button
                        v-else-if="a.accion === 'ir_contratos' && a.url"
                        size="sm"
                        variant="secondary"
                        as-child
                    >
                        <Link :href="a.url">{{ a.accion_etiqueta }}</Link>
                    </Button>
                    <Button
                        v-else-if="
                            a.accion === 'editar_fechas' && resumen.puede_editar
                        "
                        size="sm"
                        variant="secondary"
                        @click="
                            abrirEdicion(resumen.faltantes[0]?.campo ?? null)
                        "
                    >
                        {{ a.accion_etiqueta }}
                    </Button>
                    <a
                        v-if="a.accion === 'corregir_contrato' && a.url"
                        :href="a.url"
                        class="text-xs font-medium underline-offset-2 hover:underline"
                        >Ver contratación</a
                    >
                </li>
            </ul>

            <!-- Línea de tiempo -->
            <section
                class="rounded-3xl border border-border bg-card p-5 shadow-xs"
            >
                <h3 class="mb-4 text-sm font-semibold">Línea de tiempo</h3>
                <p
                    v-if="hitos.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Faltan fechas: captura al menos la fecha de ingreso para ver
                    su línea de tiempo.
                </p>

                <!-- Escritorio: proporcional -->
                <div
                    v-if="rango"
                    class="relative mx-16 hidden h-44 md:block"
                    aria-hidden="true"
                >
                    <div
                        class="absolute top-1/2 right-0 left-0 h-1 -translate-y-1/2 rounded-full bg-gradient-to-r from-crema via-salvia to-info-soft"
                    />
                    <div
                        v-if="posicionHoy !== null"
                        class="absolute top-4 bottom-4 w-px bg-primary/60"
                        :style="{ left: `${posicionHoy}%` }"
                    >
                        <span
                            class="absolute -top-4 -translate-x-1/2 rounded-full bg-primary px-1.5 text-[10px] font-semibold text-primary-foreground"
                            >Hoy</span
                        >
                    </div>
                    <div
                        v-for="(h, i) in hitos"
                        :key="h.clave"
                        class="absolute top-1/2 flex -translate-x-1/2 flex-col items-center"
                        :class="
                            i % 2 === 0
                                ? '-translate-y-[calc(100%-0.75rem)]'
                                : '-translate-y-3 flex-col-reverse'
                        "
                        :style="{ left: `${posicion(h.fecha)}%` }"
                    >
                        <div
                            class="mb-1 w-28 text-center leading-tight"
                            :class="i % 2 === 0 ? '' : 'mt-1 mb-0'"
                        >
                            <p class="truncate text-[11px] font-semibold">
                                {{ h.etiqueta }}
                            </p>
                            <p
                                class="text-[10px] text-muted-foreground tabular-nums"
                            >
                                {{ formatearFecha(h.fecha) }}
                            </p>
                        </div>
                        <span
                            class="flex size-6 items-center justify-center rounded-full ring-2"
                            :class="[
                                TONOS[h.tono],
                                h.hecho ? '' : 'opacity-70',
                            ]"
                        >
                            <component :is="h.icono" class="size-3.5" />
                        </span>
                    </div>
                </div>

                <!-- Lista (móvil y lectores de pantalla) -->
                <ol
                    class="flex flex-col gap-2"
                    :class="rango ? 'md:sr-only' : ''"
                >
                    <li
                        v-for="h in hitos"
                        :key="`l-${h.clave}`"
                        class="flex items-center gap-3"
                    >
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-full ring-2"
                            :class="TONOS[h.tono]"
                        >
                            <component :is="h.icono" class="size-3.5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">{{ h.etiqueta }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatearFechaLarga(h.fecha)
                                }}<template v-if="h.detalle">
                                    · {{ h.detalle }}</template
                                >
                            </p>
                        </div>
                        <BadgeCheck
                            v-if="h.hecho"
                            class="size-4 text-success"
                        />
                        <Hourglass
                            v-else
                            class="size-4 text-muted-foreground"
                        />
                    </li>
                </ol>
            </section>

            <!-- Fechas capturadas -->
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article
                    v-for="t in tarjetas"
                    :key="t.clave"
                    class="rounded-2xl border bg-card p-4 shadow-xs"
                    :class="
                        t.valor
                            ? 'border-border'
                            : 'border-dashed border-warning/50'
                    "
                >
                    <p
                        class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
                    >
                        <component :is="t.icono" class="size-3.5" />
                        {{ t.etiqueta }}
                    </p>
                    <p
                        class="mt-1 font-display text-lg font-semibold tabular-nums"
                    >
                        {{ t.valor ? formatearFecha(t.valor) : 'Falta' }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-muted-foreground">
                        {{ t.nota }}
                    </p>
                    <button
                        v-if="!t.valor && resumen.puede_editar"
                        type="button"
                        class="mt-2 text-xs font-medium text-bronce underline-offset-2 hover:underline"
                        @click="abrirEdicion(t.clave)"
                    >
                        Capturar
                    </button>
                </article>
            </div>

            <!-- Calculadas + contrato -->
            <div class="grid gap-3 lg:grid-cols-3">
                <article
                    class="rounded-2xl border border-border bg-card p-4 shadow-xs"
                >
                    <p class="text-xs font-medium text-muted-foreground">
                        Fin de capacitación inicial / periodo de prueba
                    </p>
                    <p
                        class="mt-1 font-display text-lg font-semibold tabular-nums"
                    >
                        {{
                            resumen.fin_periodo
                                ? formatearFecha(resumen.fin_periodo)
                                : 'Sin calcular'
                        }}
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        Inicio de capacitación +
                        {{ resumen.periodo.etiqueta.toLowerCase() }} − 1 día
                    </p>
                </article>
                <article
                    class="rounded-2xl border border-border bg-card p-4 shadow-xs"
                >
                    <p class="text-xs font-medium text-muted-foreground">
                        Evaluación de periodo de prueba
                    </p>
                    <p
                        class="mt-1 font-display text-lg font-semibold tabular-nums"
                    >
                        {{
                            resumen.evaluacion_desde
                                ? `Desde ${formatearFecha(resumen.evaluacion_desde)}`
                                : 'Sin calcular'
                        }}
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        <template v-if="resumen.evaluacion_periodo_prueba">
                            {{
                                resumen.evaluacion_periodo_prueba
                                    .estado_etiqueta
                            }}
                            · vence
                            {{
                                formatearFecha(
                                    resumen.evaluacion_periodo_prueba
                                        .fecha_limite,
                                )
                            }}
                        </template>
                        <template v-else
                            >15 días hábiles antes del fin del periodo</template
                        >
                    </p>
                </article>
                <article
                    class="rounded-2xl border border-border bg-card p-4 shadow-xs"
                >
                    <p
                        class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
                    >
                        <FileSignature class="size-3.5" /> Contrato vigente
                    </p>
                    <template v-if="resumen.contrato">
                        <p class="mt-1 text-sm font-semibold">
                            {{ resumen.contrato.tipo_etiqueta }}
                        </p>
                        <p class="text-xs text-muted-foreground tabular-nums">
                            {{ formatearFecha(resumen.contrato.fecha_inicio) }}
                            <template v-if="resumen.contrato.fecha_fin">
                                →
                                {{
                                    formatearFecha(resumen.contrato.fecha_fin)
                                }}</template
                            >
                        </p>
                        <span
                            class="mt-2 inline-block rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="
                                resumen.contrato.firmado
                                    ? 'bg-success-soft text-success'
                                    : 'bg-muted text-muted-foreground'
                            "
                            >{{
                                resumen.contrato.firmado
                                    ? 'Firmado'
                                    : 'Sin firmar'
                            }}</span
                        >
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            Sus fechas se editan por el contrato, nunca aquí.
                        </p>
                    </template>
                    <p v-else class="mt-1 text-sm text-muted-foreground">
                        Sin contrato vigente registrado en PEOPLE.
                    </p>
                </article>
            </div>

            <p class="text-xs text-muted-foreground">
                Cada cambio queda en «Cambios de datos → Correcciones auditadas»
                con quién, cuándo, antes/después y motivo.
            </p>

            <EditarFechasDialog
                v-if="editar"
                v-model:open="editar"
                :colaborador-id="colaboradorId"
                :periodo="resumen.periodo"
                :campo-inicial="campoInicial"
                @guardado="cargar"
            />
        </template>
    </section>
</template>
