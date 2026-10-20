<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarClock,
    FileWarning,
    Settings2,
    Users,
} from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFiltros } from '@/composables/useFiltros';
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

function alternarAlerta() {
    filtros.solo_alerta = filtros.solo_alerta === '1' ? '' : '1';
    aplicar();
}

function formatoFecha(fecha: string | null): string {
    if (!fecha) {
        return '—';
    }

    const [a, m, d] = fecha.split('-');

    return `${d}/${m}/${a}`;
}

function textoRestante(dias: number): string {
    if (dias === 0) {
        return 'Vence hoy';
    }

    return dias === 1 ? 'Falta 1 día' : `Faltan ${dias} días`;
}
</script>

<template>
    <Head title="Periodos por vencer" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Periodos por vencer"
            :descripcion="`Quién sigue dentro de su periodo de prueba, calculado por su fecha de ingreso y la duración de su puesto (aunque su contrato aún no esté en el sistema). Alerta cuando vence en ${diasAlerta} días naturales o menos.`"
            :icono="CalendarClock"
        >
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="indexEvaluaciones()">Evaluaciones</Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="indexContratos()">Contratos</Link>
                </Button>
            </div>
        </CrudPageHeader>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <MetricCard
                etiqueta="En periodo de prueba"
                :valor="resumen.en_periodo"
                :icono="Users"
            />
            <MetricCard
                etiqueta="En alerta"
                :valor="resumen.en_alerta"
                :icono="AlertTriangle"
                color-clase="bg-warning-soft text-warning"
            />
            <MetricCard
                etiqueta="Sin contrato registrado"
                :valor="resumen.sin_contrato"
                :icono="FileWarning"
                color-clase="bg-danger-soft text-destructive"
            />
            <MetricCard
                etiqueta="Puesto sin duración"
                :valor="resumen.sin_duracion"
                :icono="Settings2"
                color-clase="bg-info-soft text-info"
            />
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <CrudToolbar
                :model-value="filtros.busqueda"
                placeholder="Buscar por colaborador o número de empleado..."
                :contador-filtros-activos="filtros.solo_alerta ? 1 : 0"
                @update:model-value="
                    (valor) => {
                        filtros.busqueda = valor;
                        aplicarConDebounce();
                    }
                "
                @limpiar="limpiar"
            />
            <Button
                :variant="filtros.solo_alerta === '1' ? 'default' : 'outline'"
                size="sm"
                @click="alternarAlerta"
            >
                <AlertTriangle class="size-4" />
                Solo en alerta
            </Button>
        </div>

        <EmptyState
            v-if="!filas.data.length"
            :icono="CalendarClock"
            titulo="Nadie en periodo de prueba"
            descripcion="No hay colaboradores dentro de su periodo de prueba con esos filtros."
        />

        <div v-else class="tabla-scroll rounded-2xl border border-border/60">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Colaborador</TableHead>
                        <TableHead>Ingreso</TableHead>
                        <TableHead>Vence</TableHead>
                        <TableHead>Contrato</TableHead>
                        <TableHead>Evaluación</TableHead>
                        <TableHead class="text-right">Ciclo</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="fila in filas.data" :key="fila.id">
                        <TableCell>
                            <p class="font-medium">{{ fila.nombre }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ fila.puesto ?? 'Sin puesto' }} ·
                                {{ fila.sucursal ?? 'Sin sucursal' }}
                            </p>
                            <p
                                v-if="fila.jefe"
                                class="text-xs text-muted-foreground"
                            >
                                Jefe: {{ fila.jefe }}
                            </p>
                        </TableCell>
                        <TableCell class="text-xs">
                            {{ formatoFecha(fila.fecha_ingreso) }}
                            <p class="text-muted-foreground">
                                Hace {{ fila.dias_transcurridos }} días
                            </p>
                        </TableCell>
                        <TableCell class="text-xs">
                            <p class="font-medium">
                                {{ formatoFecha(fila.fin_periodo) }}
                            </p>
                            <Badge
                                :variant="fila.alerta ? 'warning' : 'secondary'"
                                >{{ textoRestante(fila.dias_restantes) }}</Badge
                            >
                            <p class="mt-1 text-muted-foreground">
                                {{ fila.fuente_etiqueta }}
                            </p>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    fila.contrato_generado
                                        ? 'success'
                                        : fila.tiene_contrato
                                          ? 'info'
                                          : 'destructive'
                                "
                            >
                                {{
                                    fila.contrato_generado
                                        ? 'Generado'
                                        : fila.tiene_contrato
                                          ? 'Registrado sin documento'
                                          : 'Sin contrato'
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-xs">
                            {{
                                fila.evaluacion?.estado_etiqueta ??
                                'Aún no se abre'
                            }}
                        </TableCell>
                        <TableCell class="text-right">
                            <Button variant="ghost" size="sm" as-child>
                                <Link :href="fila.ciclo_url">Abrir</Link>
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <PaginacionTabla :datos="filas" />

        <section
            v-if="sinDuracion.length"
            class="rounded-2xl border border-border/60 bg-crema p-4"
        >
            <p class="text-sm font-medium">
                Ingreso reciente en un puesto sin duración de periodo de prueba
            </p>
            <p class="mb-2 text-xs text-muted-foreground">
                No se puede calcular su vencimiento: configura la duración del
                puesto en Organización → Puestos y tabulador.
            </p>
            <ul class="flex flex-col gap-1 text-sm">
                <li v-for="p in sinDuracion" :key="p.id">
                    <Link :href="p.ciclo_url" class="hover:underline">{{
                        p.nombre
                    }}</Link>
                    <span class="text-xs text-muted-foreground">
                        · {{ p.puesto ?? 'Sin puesto' }} · ingreso
                        {{ formatoFecha(p.fecha_ingreso) }}</span
                    >
                </li>
            </ul>
        </section>
    </div>
</template>
