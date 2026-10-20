<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    BadgeCheck,
    CalendarClock,
    ClipboardCheck,
    FileSignature,
    Hourglass,
    ListFilter,
    PencilLine,
    Printer,
    RotateCcw,
    Star,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import BotonVistaPrevia from '@/components/people/BotonVistaPrevia.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Button } from '@/components/ui/button';
import type { Tono } from '@/lib/tonos';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/evaluaciones';
import type { RespuestaPaginada } from '@/types';

/**
 * Evaluación de capacitación inicial: se abre 15 días antes de que venza
 * el contrato de capacitación; el gerente la captura, RH la autoriza y, si
 * acredita, se genera el contrato indeterminado (una sola vez).
 */
type Evaluacion = {
    id: number;
    colaborador: { id: number; nombre: string; numero_empleado: string | null };
    puesto: string | null;
    sucursal: string | null;
    estado: 'pendiente' | 'capturada' | 'devuelta' | 'autorizada';
    estado_etiqueta: string;
    fecha_limite: string | null;
    dias_restantes: number | null;
    calificacion: string | number | null;
    resultado: string | null;
    decision_renovar: boolean | null;
    contrato_renovacion_id: number | null;
    documento_renovacion: { id: number; nombre: string; url: string } | null;
    url: string;
};

const documentoActivo = ref<{ id: number; nombre: string; url: string } | null>(
    null,
);

/** Imprime el PDF dentro de PEOPLE (iframe oculto), sin abrir otra pestaña. */
function imprimir(url: string) {
    const marco = document.createElement('iframe');
    marco.style.position = 'fixed';
    marco.style.width = '0';
    marco.style.height = '0';
    marco.style.border = '0';
    marco.src = url;
    marco.onload = () => {
        marco.contentWindow?.focus();
        marco.contentWindow?.print();
        setTimeout(() => marco.remove(), 60_000);
    };
    document.body.appendChild(marco);
}

const props = defineProps<{
    evaluaciones: RespuestaPaginada<Evaluacion>;
    estados: { value: string; label: string }[];
    filtros: { estado: string | null };
    puedeAutorizar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Evaluación de capacitación inicial', href: index() },
        ],
    },
});

const ESTILO: Record<
    Evaluacion['estado'],
    { icono: Component; tono: Tono; accion: string; iconoAccion: Component }
> = {
    pendiente: {
        icono: Hourglass,
        tono: 'alerta',
        accion: 'Capturar evaluación',
        iconoAccion: PencilLine,
    },
    devuelta: {
        icono: RotateCcw,
        tono: 'peligro',
        accion: 'Corregir captura',
        iconoAccion: PencilLine,
    },
    capturada: {
        icono: ClipboardCheck,
        tono: 'info',
        accion: 'Revisar y autorizar',
        iconoAccion: ClipboardCheck,
    },
    autorizada: {
        icono: BadgeCheck,
        tono: 'exito',
        accion: 'Ver resultado',
        iconoAccion: ArrowRight,
    },
};

const ICONO_FILTRO: Record<string, Component> = {
    pendiente: Hourglass,
    capturada: ClipboardCheck,
    devuelta: RotateCcw,
    autorizada: BadgeCheck,
};

function urgente(e: Evaluacion): boolean {
    return e.estado !== 'autorizada' && (e.dias_restantes ?? 99) <= 5;
}

/** «Faltan 3 días», «Vence hoy», «Venció hace 5 días». */
function textoDias(dias: number | null): string {
    if (dias === null) {
        return 'Sin fecha límite';
    }

    if (dias === 0) {
        return 'Vence hoy';
    }

    if (dias < 0) {
        return Math.abs(dias) === 1
            ? 'Venció hace 1 día'
            : `Venció hace ${Math.abs(dias)} días`;
    }

    return dias === 1 ? 'Falta 1 día' : `Faltan ${dias} días`;
}

const conteo = computed(() => ({
    total: props.evaluaciones.total,
    urgentes: props.evaluaciones.data.filter(urgente).length,
}));

function filtrar(estado: string | null) {
    router.get(index.url(), estado ? { estado } : {}, { preserveScroll: true });
}

const fecha = (valor: string | null) =>
    valor
        ? new Date(`${valor}T12:00:00`).toLocaleDateString('es-MX', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';
</script>

<template>
    <Head title="Evaluación de capacitación inicial" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Evaluación de capacitación inicial"
            :icono="CalendarClock"
        />

        <div class="flex flex-col gap-2">
            <div class="flex items-center gap-1.5">
                <h2 class="text-sm font-semibold text-muted-foreground">
                    Evaluaciones de capacitación inicial
                </h2>
                <AyudaBoton
                    titulo="¿Cómo funciona?"
                    texto="Se abre sola 15 días antes de que termine la capacitación. El gerente la captura, RH la autoriza y, si acredita, se prepara el contrato indeterminado (una sola vez)."
                />
            </div>
            <div
                class="-mx-1 scroll-x-limpio flex gap-2 px-1 pb-1 sm:flex-wrap"
                role="toolbar"
                aria-label="Filtrar por estado"
            >
                <button
                    type="button"
                    class="inline-flex h-10 shrink-0 items-center gap-2 rounded-full border px-4 text-sm font-medium transition"
                    :class="
                        !filtros.estado
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border bg-card hover:bg-muted'
                    "
                    :aria-pressed="!filtros.estado"
                    @click="filtrar(null)"
                >
                    <ListFilter class="size-4" />
                    Todas
                    <span
                        class="rounded-full bg-card/20 px-1.5 text-xs tabular-nums"
                        >{{ conteo.total }}</span
                    >
                </button>
                <button
                    v-for="estado in estados"
                    :key="estado.value"
                    type="button"
                    class="inline-flex h-10 shrink-0 items-center gap-2 rounded-full border px-4 text-sm font-medium transition"
                    :class="
                        filtros.estado === estado.value
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border bg-card hover:bg-muted'
                    "
                    :aria-pressed="filtros.estado === estado.value"
                    @click="filtrar(estado.value)"
                >
                    <component
                        :is="ICONO_FILTRO[estado.value] ?? ClipboardCheck"
                        class="size-4"
                    />
                    {{ estado.label }}
                </button>
            </div>
        </div>

        <p
            v-if="conteo.urgentes"
            class="flex items-center gap-3 rounded-2xl bg-danger-soft px-4 py-3 text-sm"
            role="status"
        >
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-card text-destructive"
            >
                <AlertTriangle class="size-5" />
            </span>
            <span>
                <strong class="text-lg text-destructive tabular-nums">{{
                    conteo.urgentes
                }}</strong>
                {{
                    conteo.urgentes === 1
                        ? 'evaluación vence'
                        : 'evaluaciones vencen'
                }}
                en 5 días o menos (o ya venció).
            </span>
        </p>

        <EmptyState
            v-if="evaluaciones.data.length === 0"
            :icono="CalendarClock"
            titulo="Sin evaluaciones por ahora"
            descripcion="Aparecen solas 15 días antes de que termine el contrato de capacitación de cada colaborador."
        />

        <ul
            v-else
            class="rejilla-auto"
            style="--rejilla-min: 21rem"
            aria-label="Evaluaciones"
        >
            <li
                v-for="evaluacion in evaluaciones.data"
                :key="evaluacion.id"
                class="flex min-w-0 flex-col overflow-hidden rounded-2xl border bg-card shadow-sm"
                :class="
                    urgente(evaluacion)
                        ? 'border-destructive/40'
                        : 'border-border/60'
                "
            >
                <div class="flex items-start gap-3 p-4">
                    <ColaboradorAvatar
                        :nombre="evaluacion.colaborador.nombre"
                    />
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="evaluacion.url"
                            class="block truncate text-base font-semibold hover:underline"
                            >{{ evaluacion.colaborador.nombre }}</Link
                        >
                        <p class="truncate text-sm text-muted-foreground">
                            {{ evaluacion.puesto ?? 'Sin puesto' }} ·
                            {{ evaluacion.sucursal ?? 'Sin sucursal' }}
                        </p>
                        <ChipEstado
                            class="mt-2"
                            :etiqueta="evaluacion.estado_etiqueta"
                            :tono="ESTILO[evaluacion.estado].tono"
                            :icono="ESTILO[evaluacion.estado].icono"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 px-4 pb-4">
                    <div
                        class="flex flex-col gap-1 rounded-xl p-3"
                        :class="
                            urgente(evaluacion)
                                ? 'bg-danger-soft'
                                : 'bg-[var(--mrl-fondo)]'
                        "
                    >
                        <span
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <CalendarClock class="size-3.5" />
                            {{ fecha(evaluacion.fecha_limite) }}
                        </span>
                        <span
                            class="text-sm font-semibold"
                            :class="urgente(evaluacion) && 'text-destructive'"
                            >{{ textoDias(evaluacion.dias_restantes) }}</span
                        >
                    </div>
                    <div
                        class="flex flex-col gap-1 rounded-xl bg-[var(--mrl-fondo)] p-3"
                    >
                        <span
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Star class="size-3.5" />
                            Calificación
                        </span>
                        <span
                            class="text-2xl leading-none font-bold tabular-nums"
                            :class="
                                evaluacion.calificacion === null &&
                                'text-muted-foreground/60'
                            "
                            >{{ evaluacion.calificacion ?? '—' }}</span
                        >
                    </div>
                </div>

                <div
                    v-if="evaluacion.contrato_renovacion_id"
                    class="mx-4 mb-4 flex flex-col gap-3 rounded-xl bg-success-soft p-3"
                >
                    <p
                        class="flex items-center gap-2 text-sm font-semibold text-success"
                    >
                        <FileSignature class="size-5" />
                        Aprobada · contrato indeterminado listo
                    </p>
                    <div
                        v-if="evaluacion.documento_renovacion"
                        class="grid grid-cols-2 gap-2"
                    >
                        <BotonVistaPrevia
                            class="h-10"
                            texto="Ver contrato"
                            @click="
                                documentoActivo =
                                    evaluacion.documento_renovacion
                            "
                        />
                        <Button
                            class="h-10"
                            @click="
                                imprimir(evaluacion.documento_renovacion.url)
                            "
                        >
                            <Printer class="size-4" /> Imprimir
                        </Button>
                    </div>
                </div>

                <footer
                    class="mt-auto flex flex-col gap-2 border-t border-border/50 bg-[var(--mrl-fondo)]/60 p-3"
                >
                    <p
                        v-if="evaluacion.decision_renovar === false"
                        class="text-center text-xs font-medium text-muted-foreground"
                    >
                        Decisión: no se renueva
                    </p>
                    <Button
                        :variant="
                            evaluacion.estado === 'autorizada'
                                ? 'outline'
                                : 'default'
                        "
                        class="h-11 w-full sm:h-10"
                        as-child
                    >
                        <Link :href="evaluacion.url">
                            <component
                                :is="ESTILO[evaluacion.estado].iconoAccion"
                                class="size-4"
                            />
                            {{ ESTILO[evaluacion.estado].accion }}
                        </Link>
                    </Button>
                </footer>
            </li>
        </ul>
    </div>

    <DocumentPreviewDialog
        :open="documentoActivo !== null"
        :preview-url="documentoActivo?.url ?? null"
        :nombre="documentoActivo?.nombre ?? 'Contrato indeterminado.pdf'"
        tipo="pdf"
        @update:open="
            (abierto: boolean) => !abierto && (documentoActivo = null)
        "
    />
</template>
