<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    BadgeDollarSign,
    CalendarDays,
    ExternalLink,
    FileText,
    ImageIcon,
    Megaphone,
    Plus,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CrudActionMenu from '@/components/DataTable/CrudActionMenu.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import CampanaReclutamientoFormDialog from '@/components/Rh/CampanaReclutamientoFormDialog.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { formatoMoneda } from '@/lib/utils';
import { dashboard } from '@/routes';
import { destroy, index } from '@/routes/rh/campanas';
import type {
    CampanaReclutamientoItem,
    CampanasTotales,
    OpcionesCampanas,
    RespuestaPaginada,
} from '@/types';

const props = defineProps<{
    campanas: RespuestaPaginada<CampanaReclutamientoItem>;
    totales: CampanasTotales;
    filtros: {
        mes: number;
        anio: number;
        empresa_id?: string;
        sucursal_id?: string;
        departamento_id?: string;
        puesto_id?: string;
        canal?: string;
    };
    opciones: OpcionesCampanas;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Campañas', href: index.url() },
        ],
    },
});

const meses = [
    { value: '1', etiqueta: 'Enero' },
    { value: '2', etiqueta: 'Febrero' },
    { value: '3', etiqueta: 'Marzo' },
    { value: '4', etiqueta: 'Abril' },
    { value: '5', etiqueta: 'Mayo' },
    { value: '6', etiqueta: 'Junio' },
    { value: '7', etiqueta: 'Julio' },
    { value: '8', etiqueta: 'Agosto' },
    { value: '9', etiqueta: 'Septiembre' },
    { value: '10', etiqueta: 'Octubre' },
    { value: '11', etiqueta: 'Noviembre' },
    { value: '12', etiqueta: 'Diciembre' },
];

const anioActual = new Date().getFullYear();
const anios = Array.from({ length: 5 }, (_, i) => String(anioActual - i));

const { filtros, aplicar, limpiar } = useFiltros(index.url(), {
    mes: String(props.filtros.mes),
    anio: String(props.filtros.anio),
    empresa_id: props.filtros.empresa_id ?? '',
    sucursal_id: props.filtros.sucursal_id ?? '',
    departamento_id: props.filtros.departamento_id ?? '',
    puesto_id: props.filtros.puesto_id ?? '',
    canal: props.filtros.canal ?? '',
});
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const sucursalesFiltradas = computed(() =>
    filtros.empresa_id
        ? props.opciones.sucursales.filter(
              (s) => String(s.empresa_id) === filtros.empresa_id,
          )
        : props.opciones.sucursales,
);

const puestosFiltrados = computed(() =>
    filtros.departamento_id
        ? props.opciones.puestos.filter(
              (p) => String(p.departamento_id) === filtros.departamento_id,
          )
        : props.opciones.puestos,
);

function nombreCanal(valor: string): string {
    return (
        props.opciones.canales.find((c) => c.value === valor)?.etiqueta ?? valor
    );
}

const fecha = (valor: string | null) =>
    valor
        ? new Date(`${valor.slice(0, 10)}T12:00:00`).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' })
        : null;

function rangoFechas(campana: CampanaReclutamientoItem): string {
    const inicio = fecha(campana.fecha_inicio);

    if (!inicio) {
        return `${meses.find((m) => m.value === String(campana.mes))?.etiqueta ?? ''} ${campana.anio}`;
    }

    return campana.fecha_fin ? `${inicio} – ${fecha(campana.fecha_fin)}` : `Desde ${inicio}`;
}

/** Embudo de la campaña (paleta pastel; solo datos reales de candidatos). */
function embudo(campana: CampanaReclutamientoItem) {
    const r = campana.resultado;

    return [
        { etiqueta: 'Candidatos', valor: r?.candidatos ?? 0, barra: 'bg-esmeralda/70' },
        { etiqueta: 'Contactados', valor: r?.contactados ?? 0, barra: 'bg-esmeralda/60' },
        { etiqueta: 'Entrevistas', valor: r?.entrevistas ?? 0, barra: 'bg-esmeralda/50' },
        { etiqueta: 'Psicométricos', valor: r?.psicometricos ?? 0, barra: 'bg-oro/70' },
        { etiqueta: 'Socioeconómico', valor: r?.socioeconomicos ?? 0, barra: 'bg-oro/55' },
        { etiqueta: 'Contratados', valor: r?.contratados ?? 0, barra: 'bg-success/70' },
    ];
}

/** Ancho de la barra de cada paso respecto a los candidatos (mínimo visible si hay dato). */
function anchoPaso(campana: CampanaReclutamientoItem, valor: number): number {
    const total = campana.resultado?.candidatos ?? 0;

    if (total <= 0 || valor <= 0) {
        return 0;
    }

    return Math.max(4, Math.min(100, Math.round((valor / total) * 100)));
}

/** Adjunto (Meta Ads, factura, reporte) que se ve DENTRO de PEOPLE. */
const adjuntoActivo = ref<{ nombre: string; mime: string; url: string } | null>(null);

const numero = (valor: number) => valor.toLocaleString('es-MX');

function irAPagina(pagina: number) {
    router.get(index.url(), { ...filtros, page: pagina }, { preserveScroll: true, preserveState: true });
}
const dialogAbierto = ref(false);
const campanaSeleccionada = ref<CampanaReclutamientoItem | null>(null);

function abrirCrear() {
    campanaSeleccionada.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(campana: CampanaReclutamientoItem) {
    campanaSeleccionada.value = campana;
    dialogAbierto.value = true;
}

async function eliminar(campana: CampanaReclutamientoItem) {
    const confirmado = await confirmarEliminacion(
        `la campaña de «${nombreCanal(campana.canal)}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(campana.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('La campaña se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar la campaña.'),
    });
}
</script>

<template>
    <Head title="Campañas de reclutamiento" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Campañas de reclutamiento"
            descripcion="Cada campaña parte de una vacante real: qué se publicó, cuánto se gastó y qué produjo (candidatos, entrevistas, contratados)."
            :icono="Megaphone"
        >
            <Button data-tour="campanas-nueva" @click="abrirCrear">
                <Plus class="size-4" />
                Nueva campaña
            </Button>
        </CrudPageHeader>

        <!-- Totales concretos del periodo filtrado. -->
        <p
            v-if="totales.campanas > 0"
            data-tour="campanas-resumen"
            class="rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground"
        >
            <span class="font-semibold text-foreground">{{
                formatoMoneda(totales.gasto)
            }}</span>
            en {{ totales.campanas }}
            {{ totales.campanas === 1 ? 'campaña' : 'campañas' }} ·
            <span class="font-semibold text-foreground">{{
                totales.contratados
            }}</span>
            {{
                totales.contratados === 1
                    ? 'colaborador contratado'
                    : 'colaboradores contratados'
            }}
            <template v-if="totales.costo_por_colaborador !== null">
                ·
                <span class="font-semibold text-foreground">{{
                    formatoMoneda(totales.costo_por_colaborador)
                }}</span>
                por colaborador
            </template>
        </p>

        <div data-tour="campanas-filtros" class="flex flex-wrap gap-2">
            <Select
                :model-value="filtros.mes"
                @update:model-value="
                    (v) => {
                        filtros.mes = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-36">
                    <SelectValue placeholder="Mes" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in meses"
                        :key="opcion.value"
                        :value="opcion.value"
                        >{{ opcion.etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.anio"
                @update:model-value="
                    (v) => {
                        filtros.anio = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-28">
                    <SelectValue placeholder="Año" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in anios"
                        :key="opcion"
                        :value="opcion"
                        >{{ opcion }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.canal"
                @update:model-value="
                    (v) => {
                        filtros.canal = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-40">
                    <SelectValue placeholder="Canal" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.canales"
                        :key="opcion.value"
                        :value="opcion.value"
                        >{{ opcion.etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.empresa_id"
                @update:model-value="
                    (v) => {
                        filtros.empresa_id = String(v ?? '');
                        filtros.sucursal_id = '';
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-44">
                    <SelectValue placeholder="Empresa" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.empresas"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.sucursal_id"
                @update:model-value="
                    (v) => {
                        filtros.sucursal_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-44">
                    <SelectValue placeholder="Sucursal" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in sucursalesFiltradas"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.departamento_id"
                @update:model-value="
                    (v) => {
                        filtros.departamento_id = String(v ?? '');
                        filtros.puesto_id = '';
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-44">
                    <SelectValue placeholder="Departamento" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.departamentos"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.puesto_id"
                @update:model-value="
                    (v) => {
                        filtros.puesto_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-44">
                    <SelectValue placeholder="Puesto" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in puestosFiltrados"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

        <CrudEmptyState
            v-if="!campanas.data.length"
            :icono="Megaphone"
            titulo="Todavía no hay campañas en este periodo"
            descripcion="Registra la campaña de una vacante real para medir candidatos, entrevistas, contratados y costo por contratación."
        >
            <Button size="sm" @click="abrirCrear">
                <Plus class="size-4" />
                Registrar campaña
            </Button>
        </CrudEmptyState>

        <div v-else data-tour="tabla" class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
            <article
                v-for="campana in campanas.data"
                :key="campana.id"
                class="flex flex-col overflow-hidden rounded-3xl border border-border/60 bg-card shadow-sm transition hover:shadow-md"
            >
                <header
                    class="flex items-start gap-3 border-b border-border/60 bg-gradient-to-r from-crema/70 to-salvia/70 px-5 py-4 text-foreground"
                >
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-card/80 text-primary shadow-sm">
                        <Megaphone class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold tracking-wider text-bronce uppercase">
                            {{ nombreCanal(campana.canal) }}
                        </p>
                        <p class="truncate text-base font-semibold">
                            {{ campana.nombre || campana.puesto?.nombre || 'Campaña general' }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ campana.puesto?.nombre ?? 'Sin puesto' }} ·
                            {{ campana.sucursal?.nombre ?? 'Sin sucursal' }}
                            <template v-if="campana.vacante_id"> · Vacante #{{ campana.vacante_id }}</template>
                        </p>
                    </div>
                    <CrudActionMenu>
                        <DropdownMenuItem @select="abrirEditar(campana)">Editar</DropdownMenuItem>
                        <DropdownMenuItem variant="destructive" @select="eliminar(campana)">Eliminar</DropdownMenuItem>
                    </CrudActionMenu>
                </header>

                <div class="flex flex-1 flex-col gap-4 p-5">
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span class="inline-flex items-center gap-1"><CalendarDays class="size-3.5" />{{ rangoFechas(campana) }}</span>
                        <span v-if="campana.responsable" class="inline-flex items-center gap-1"><UserRound class="size-3.5" />{{ campana.responsable.name }} {{ campana.responsable.apellidos ?? '' }}</span>
                        <span v-if="campana.sueldo_publicado" class="inline-flex items-center gap-1"><BadgeDollarSign class="size-3.5" />Sueldo publicado {{ formatoMoneda(Number(campana.sueldo_publicado)) }}</span>
                    </div>

                    <!-- Presupuesto vs gasto -->
                    <div class="grid gap-1.5">
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="font-semibold">{{ formatoMoneda(Number(campana.monto)) }} <span class="font-normal text-muted-foreground">gastado</span></span>
                            <span v-if="campana.presupuesto" class="text-xs text-muted-foreground">de {{ formatoMoneda(Number(campana.presupuesto)) }}</span>
                        </div>
                        <div v-if="campana.presupuesto" class="h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full transition-all"
                                :class="Number(campana.monto) > Number(campana.presupuesto) ? 'bg-destructive' : 'bg-oro'"
                                :style="{ width: `${Math.min(100, (Number(campana.monto) / Math.max(1, Number(campana.presupuesto))) * 100)}%` }"
                            />
                        </div>
                    </div>

                    <!-- Embudo -->
                    <!-- Embudo en filas: etiqueta completa, cifra y barra
                         proporcional a los candidatos (nada apretado). -->
                    <ul class="grid gap-1.5" aria-label="Embudo de la campaña">
                        <li
                            v-for="paso in embudo(campana)"
                            :key="paso.etiqueta"
                            class="grid grid-cols-[7.5rem_minmax(0,1fr)_2.5rem] items-center gap-3 text-sm"
                        >
                            <span class="truncate text-muted-foreground">{{ paso.etiqueta }}</span>
                            <span class="h-2.5 overflow-hidden rounded-full bg-muted">
                                <span
                                    class="block h-full rounded-full transition-all"
                                    :class="paso.barra"
                                    :style="{ width: `${anchoPaso(campana, paso.valor)}%` }"
                                />
                            </span>
                            <span class="text-right font-semibold tabular-nums">{{ paso.valor }}</span>
                        </li>
                    </ul>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">Costo por candidato</dt>
                            <dd class="font-semibold">{{ campana.resultado?.costo_por_candidato != null ? formatoMoneda(campana.resultado.costo_por_candidato) : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Costo por contratación</dt>
                            <dd class="font-semibold">{{ campana.resultado?.costo_por_colaborador != null ? formatoMoneda(campana.resultado.costo_por_colaborador) : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Conversión</dt>
                            <dd class="font-semibold">{{ campana.resultado?.conversion != null ? `${campana.resultado.conversion}%` : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Días de cobertura</dt>
                            <dd class="font-semibold">{{ campana.resultado?.dias_cobertura != null ? `${campana.resultado.dias_cobertura} días` : '—' }}</dd>
                        </div>
                        <template v-if="campana.resultado?.impresiones != null || campana.resultado?.clics != null">
                            <div>
                                <dt class="text-xs text-muted-foreground">Impresiones · clics</dt>
                                <dd class="font-semibold">
                                    {{ campana.resultado?.impresiones != null ? numero(campana.resultado.impresiones) : '—' }} ·
                                    {{ campana.resultado?.clics != null ? numero(campana.resultado.clics) : '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Costo por clic</dt>
                                <dd class="font-semibold">{{ campana.resultado?.costo_por_clic != null ? formatoMoneda(campana.resultado.costo_por_clic) : '—' }}</dd>
                            </div>
                        </template>
                    </dl>

                    <p v-if="campana.copy" class="line-clamp-2 rounded-xl bg-muted/50 px-3 py-2 text-xs text-muted-foreground italic">
                        “{{ campana.copy }}”
                    </p>

                    <div class="mt-auto flex flex-wrap items-center gap-2 border-t border-border/60 pt-3">
                        <a
                            v-if="campana.url"
                            :href="campana.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-xs hover:bg-muted"
                            ><ExternalLink class="size-3.5" /> Ver anuncio</a
                        >
                        <button
                            v-for="adjunto in campana.adjuntos_lista"
                            :key="adjunto.id"
                            type="button"
                            class="inline-flex min-h-9 max-w-44 items-center gap-1 rounded-lg border px-2 py-1 text-xs hover:bg-muted"
                            @click="adjuntoActivo = adjunto"
                        >
                            <component :is="adjunto.mime.startsWith('image/') ? ImageIcon : FileText" class="size-3.5 shrink-0" />
                            <span class="truncate">{{ adjunto.nombre }}</span>
                        </button>
                        <Button variant="ghost" size="sm" class="ml-auto" @click="abrirEditar(campana)">Editar</Button>
                    </div>
                </div>
            </article>
        </div>

        <div v-if="campanas.last_page > 1" class="flex items-center justify-center gap-3">
            <Button variant="outline" size="sm" :disabled="campanas.current_page <= 1" @click="irAPagina(campanas.current_page - 1)">Anterior</Button>
            <span class="text-sm text-muted-foreground">Página {{ campanas.current_page }} de {{ campanas.last_page }}</span>
            <Button variant="outline" size="sm" :disabled="campanas.current_page >= campanas.last_page" @click="irAPagina(campanas.current_page + 1)">Siguiente</Button>
        </div>
    </div>

    <DocumentPreviewDialog
        :open="adjuntoActivo !== null"
        :preview-url="adjuntoActivo?.url ?? null"
        :nombre="adjuntoActivo?.nombre ?? ''"
        :tipo="adjuntoActivo ? (adjuntoActivo.mime === 'application/pdf' ? 'pdf' : adjuntoActivo.mime.split('/').pop()) : null"
        @update:open="(abierto: boolean) => !abierto && (adjuntoActivo = null)"
    />

    <CampanaReclutamientoFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :campana="campanaSeleccionada"
        :opciones="opciones"
        :key="campanaSeleccionada?.id ?? 'nueva'"
    />
</template>
