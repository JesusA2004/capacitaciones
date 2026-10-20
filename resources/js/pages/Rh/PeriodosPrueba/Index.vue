<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarClock,
    CalendarDays,
    CheckCircle2,
    ClipboardCheck,
    FileSignature,
    FileWarning,
    Hourglass,
    Settings2,
    UserRound,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { useFiltros } from '@/composables/useFiltros';
import type { Tono } from '@/lib/tonos';
import { index as indexContratos } from '@/routes/rh/contratos';
import { index as indexEvaluaciones } from '@/routes/rh/evaluaciones';
import { index as indexPeriodos } from '@/routes/rh/periodos-prueba';
import type {
    PersonaPeriodoBase,
    PersonaPeriodoPrueba,
    RespuestaPaginada,
} from '@/types';

const props = defineProps<{
    filas: RespuestaPaginada<PersonaPeriodoPrueba>;
    resumen: {
        en_periodo: number;
        en_alerta: number;
        sin_contrato: number;
        sin_duracion: number;
    };
    sinDuracion: PersonaPeriodoBase[];
    diasAlerta: number;
    filtros: { busqueda?: string; solo_alerta?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: '/dashboard' },
            { title: 'Periodos por vencer', href: '/rh/periodos-prueba' },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    indexPeriodos.url(),
    {
        busqueda: props.filtros.busqueda ?? '',
        solo_alerta: props.filtros.solo_alerta ?? '',
    },
);

function filtrarAlerta(valor: '' | '1') {
    filtros.solo_alerta = valor;
    aplicar();
}

function formatoFecha(fecha: string | null): string {
    if (!fecha) {
        return '—';
    }

    const [a, m, d] = fecha.split('-');

    return `${d}/${m}/${a}`;
}

function contrato(fila: PersonaPeriodoPrueba): {
    etiqueta: string;
    tono: Tono;
    icono: typeof CheckCircle2;
} {
    if (fila.contrato_generado) {
        return {
            etiqueta: 'Contrato generado',
            tono: 'exito',
            icono: CheckCircle2,
        };
    }

    if (fila.tiene_contrato) {
        return {
            etiqueta: 'Contrato sin documento',
            tono: 'info',
            icono: FileSignature,
        };
    }

    return { etiqueta: 'Sin contrato', tono: 'peligro', icono: FileWarning };
}

/** Avance del periodo (0–100) para la barra de cada tarjeta. */
function avance(fila: PersonaPeriodoPrueba): number {
    const total = fila.dias_transcurridos + fila.dias_restantes;

    return total > 0
        ? Math.min(100, Math.round((fila.dias_transcurridos / total) * 100))
        : 100;
}

const ayuda = computed(
    () =>
        `Quién sigue en su periodo de prueba, calculado por su fecha de ingreso y la duración de su puesto (aunque su contrato aún no esté en el sistema). Entra en alerta cuando vence en ${props.diasAlerta} días naturales o menos.`,
);
</script>

<template>
    <Head title="Periodos por vencer" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader titulo="Periodos por vencer" :icono="CalendarClock">
            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                <Button variant="outline" class="flex-1 sm:flex-none" as-child>
                    <Link :href="indexEvaluaciones()">
                        <ClipboardCheck class="size-4" />
                        Evaluaciones
                    </Link>
                </Button>
                <Button variant="outline" class="flex-1 sm:flex-none" as-child>
                    <Link :href="indexContratos()">
                        <FileSignature class="size-4" />
                        Contratos
                    </Link>
                </Button>
            </div>
        </CrudPageHeader>

        <section aria-label="Resumen" class="flex flex-col gap-2">
            <div class="flex items-center gap-1.5">
                <h2 class="text-sm font-semibold text-muted-foreground">
                    Periodos de prueba
                </h2>
                <AyudaBoton titulo="Periodos por vencer" :texto="ayuda" />
            </div>
            <div class="grid grid-cols-2 gap-2.5 sm:gap-3 lg:grid-cols-4">
                <TarjetaMetrica
                    etiqueta="En periodo de prueba"
                    :valor="resumen.en_periodo"
                    :icono="Users"
                    tono="primario"
                    clicable
                    :activa="filtros.solo_alerta !== '1'"
                    @click="filtrarAlerta('')"
                />
                <TarjetaMetrica
                    etiqueta="En alerta"
                    :valor="resumen.en_alerta"
                    :icono="AlertTriangle"
                    tono="alerta"
                    :detalle="`Vencen en ${diasAlerta} días o menos`"
                    clicable
                    :activa="filtros.solo_alerta === '1'"
                    @click="filtrarAlerta('1')"
                />
                <TarjetaMetrica
                    etiqueta="Sin contrato"
                    :valor="resumen.sin_contrato"
                    :icono="FileWarning"
                    tono="peligro"
                />
                <TarjetaMetrica
                    etiqueta="Puesto sin duración"
                    :valor="resumen.sin_duracion"
                    :icono="Settings2"
                    tono="info"
                />
            </div>
        </section>

        <CrudToolbar
            :model-value="filtros.busqueda"
            placeholder="Buscar colaborador o número de empleado…"
            :contador-filtros-activos="filtros.solo_alerta ? 1 : 0"
            @update:model-value="
                (valor) => {
                    filtros.busqueda = valor;
                    aplicarConDebounce();
                }
            "
            @limpiar="limpiar"
        />

        <EmptyState
            v-if="!filas.data.length"
            :icono="CalendarClock"
            titulo="Nadie en periodo de prueba"
            descripcion="No hay colaboradores dentro de su periodo de prueba con esos filtros."
        />

        <ul
            v-else
            class="rejilla-auto"
            style="--rejilla-min: 20rem"
            aria-label="Colaboradores en periodo de prueba"
        >
            <li
                v-for="fila in filas.data"
                :key="fila.id"
                class="flex min-w-0 flex-col overflow-hidden rounded-2xl border bg-card shadow-sm"
                :class="fila.alerta ? 'border-warning/50' : 'border-border/60'"
            >
                <div class="flex items-start gap-3 p-4">
                    <ColaboradorAvatar :nombre="fila.nombre" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-base font-semibold">
                            {{ fila.nombre }}
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ fila.puesto ?? 'Sin puesto' }} ·
                            {{ fila.sucursal ?? 'Sin sucursal' }}
                        </p>
                    </div>
                    <div
                        class="flex shrink-0 flex-col items-center rounded-2xl px-3 py-1.5"
                        :class="
                            fila.alerta
                                ? 'bg-warning-soft text-warning'
                                : 'bg-salvia text-primary'
                        "
                        :title="`Vence el ${formatoFecha(fila.fin_periodo)}`"
                    >
                        <span
                            class="text-2xl leading-none font-bold tabular-nums"
                            >{{ fila.dias_restantes }}</span
                        >
                        <span class="text-[11px] font-medium">
                            {{ fila.dias_restantes === 1 ? 'día' : 'días' }}
                        </span>
                    </div>
                </div>

                <div class="flex flex-col gap-3 px-4 pb-4">
                    <div class="flex flex-col gap-1.5">
                        <div
                            class="h-2 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            :aria-valuenow="avance(fila)"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-label="Avance del periodo de prueba"
                        >
                            <div
                                class="h-full rounded-full"
                                :class="
                                    fila.alerta ? 'bg-warning' : 'bg-primary'
                                "
                                :style="{ width: `${avance(fila)}%` }"
                            />
                        </div>
                        <div
                            class="flex justify-between gap-2 text-xs text-muted-foreground tabular-nums"
                        >
                            <span class="inline-flex items-center gap-1">
                                <CalendarDays class="size-3.5" />
                                {{ formatoFecha(fila.fecha_ingreso) }}
                            </span>
                            <span
                                class="inline-flex items-center gap-1 font-semibold"
                                :class="fila.alerta && 'text-warning'"
                                :title="fila.fuente_etiqueta"
                            >
                                <Hourglass class="size-3.5" />
                                {{
                                    fila.dias_restantes === 0
                                        ? 'Vence hoy'
                                        : formatoFecha(fila.fin_periodo)
                                }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <ChipEstado
                            :etiqueta="contrato(fila).etiqueta"
                            :tono="contrato(fila).tono"
                            :icono="contrato(fila).icono"
                        />
                        <ChipEstado
                            :etiqueta="
                                fila.evaluacion
                                    ? `Evaluación: ${fila.evaluacion.estado_etiqueta}`
                                    : 'Sin evaluación'
                            "
                            :tono="fila.evaluacion ? 'info' : 'neutro'"
                            :icono="ClipboardCheck"
                        />
                    </div>

                    <p
                        v-if="fila.jefe"
                        class="flex items-center gap-1.5 truncate text-xs text-muted-foreground"
                    >
                        <UserRound class="size-3.5 shrink-0" />
                        Jefe: {{ fila.jefe }}
                    </p>
                </div>

                <footer
                    class="mt-auto border-t border-border/50 bg-[var(--mrl-fondo)]/60 p-3"
                >
                    <Button variant="outline" class="h-10 w-full" as-child>
                        <Link :href="fila.ciclo_url">
                            <CalendarClock class="size-4" />
                            Abrir ciclo laboral
                        </Link>
                    </Button>
                </footer>
            </li>
        </ul>

        <PaginacionTabla :datos="filas" />

        <section
            v-if="sinDuracion.length"
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-crema/60 p-4"
        >
            <div class="flex items-center gap-2">
                <Settings2 class="size-4 text-bronce" />
                <h2 class="text-sm font-semibold text-bronce">
                    Puesto sin duración de prueba ({{ sinDuracion.length }})
                </h2>
                <AyudaBoton
                    titulo="Puesto sin duración"
                    texto="No se puede calcular cuándo vence su periodo de prueba. Configura la duración del puesto en Organización → Puestos y tabulador."
                />
            </div>
            <ul class="rejilla-auto gap-2" style="--rejilla-min: 16rem">
                <li v-for="p in sinDuracion" :key="p.id">
                    <Link
                        :href="p.ciclo_url"
                        class="flex tarjeta-interactiva items-center gap-3 rounded-xl border border-border/60 bg-card p-3"
                    >
                        <ColaboradorAvatar :nombre="p.nombre" tamano="sm" />
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium">{{
                                p.nombre
                            }}</span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                                >{{ p.puesto ?? 'Sin puesto' }} · ingreso
                                {{ formatoFecha(p.fecha_ingreso) }}</span
                            >
                        </span>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
