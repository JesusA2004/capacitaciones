<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Building, MapPin, Phone } from '@lucide/vue';
import { computed } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import DashboardChartCard from '@/components/Dashboard/DashboardChartCard.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import { dashboard } from '@/routes';
import { index } from '@/routes/administracion/sucursales';
import { index as indexVacantes } from '@/routes/rh/vacantes';

/**
 * Detalle de sucursal = su HEADCOUNT (plantilla autorizada vs. ocupada por
 * puesto, docs/HEADCOUNT_Y_VACANTES.md), quién la dirige (Gerente de
 * Sucursal) y cómo se reparte su gente. Las vacantes concretas se ven en
 * RH > Vacantes.
 */
type PlantillaPorPuesto = {
    puesto_id: number;
    puesto: string;
    plantilla_autorizada: number;
    plantilla_actual: number;
    faltante: number;
};

const props = defineProps<{
    sucursal: {
        id: number;
        nombre: string;
        clave: string;
        ciudad: string | null;
        estado: string | null;
        direccion: string | null;
        telefono: string | null;
        activo: boolean;
        empresa: { id: number; nombre: string } | null;
    };
    gerente: { id: number; nombre: string; puesto: string; foto_url: string | null } | null;
    plantillaPorPuesto: PlantillaPorPuesto[];
    porDepartamento: { etiqueta: string; valor: number }[];
    totales: {
        plantilla_autorizada: number;
        plantilla_actual: number;
        vacantes: number;
        cobertura: number;
        fuera_de_plantilla: number;
    };
    departamentos: number;
}>();

defineOptions({
    layout: (pageProps: { sucursal: { nombre: string } }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Sucursales', href: index.url() },
            { title: pageProps.sucursal.nombre, href: '#' },
        ],
    }),
});

const { getInitials } = useInitials();

const indicadores = computed(() => [
    { etiqueta: 'Plantilla autorizada', valor: props.totales.plantilla_autorizada },
    { etiqueta: 'Ocupada', valor: props.totales.plantilla_actual },
    { etiqueta: 'Vacantes', valor: props.totales.vacantes, alerta: props.totales.vacantes > 0 },
    { etiqueta: 'Cobertura', valor: `${props.totales.cobertura}%` },
]);

const donaCobertura = computed(() => [
    { etiqueta: 'Ocupadas', valor: Math.min(props.totales.plantilla_actual, props.totales.plantilla_autorizada) },
    { etiqueta: 'Vacantes', valor: props.totales.vacantes },
]);

const puestos = computed(() =>
    [...props.plantillaPorPuesto]
        .filter((fila) => fila.plantilla_autorizada > 0)
        .sort((a, b) => b.plantilla_autorizada - a.plantilla_autorizada),
);

function porcentaje(fila: PlantillaPorPuesto): number {
    return fila.plantilla_autorizada > 0 ? Math.min(100, Math.round((fila.plantilla_actual / fila.plantilla_autorizada) * 100)) : 100;
}
</script>

<template>
    <Head :title="sucursal.nombre" />

    <div class="mx-auto flex w-full max-w-screen-2xl min-w-0 flex-col gap-4 p-3 sm:p-4 lg:px-6">
        <!-- Encabezado de detalle: nombre + datos de contacto + gerente -->
        <header class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex min-w-0 items-start gap-2">
                <Button variant="ghost" size="icon-sm" as-child class="mt-0.5 shrink-0">
                    <Link :href="index.url()" aria-label="Volver a sucursales"><ArrowLeft class="size-4" /></Link>
                </Button>
                <div class="min-w-0">
                    <h1 class="flex flex-wrap items-center gap-2 text-xl font-semibold">
                        <Building class="size-5 text-muted-foreground" />
                        {{ sucursal.nombre }}
                        <EstadoBadge :estado="sucursal.activo ? 'activo' : 'inactivo'" />
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ sucursal.clave }}<template v-if="sucursal.empresa"> · {{ sucursal.empresa.nombre }}</template>
                    </p>
                    <p class="mt-1 flex items-start gap-1.5 text-sm">
                        <MapPin class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <span class="break-words">{{ sucursal.direccion ?? 'Domicilio sin capturar' }}</span>
                    </p>
                    <p v-if="sucursal.telefono" class="flex items-center gap-1.5 text-sm">
                        <Phone class="size-4 shrink-0 text-muted-foreground" />
                        <a :href="`tel:${sucursal.telefono.split('/')[0].replace(/\s/g, '')}`" class="hover:underline">{{ sucursal.telefono }}</a>
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 rounded-xl border bg-card px-3 py-2 lg:min-w-64">
                <Avatar class="size-10 shrink-0">
                    <AvatarImage v-if="gerente?.foto_url" :src="gerente.foto_url" :alt="gerente ? `Foto de ${gerente.nombre}` : ''" />
                    <AvatarFallback>{{ gerente ? getInitials(gerente.nombre) : '—' }}</AvatarFallback>
                </Avatar>
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">Gerente de Sucursal</p>
                    <p class="text-sm font-medium break-words">{{ gerente?.nombre ?? 'Sin gerente asignado' }}</p>
                </div>
            </div>
        </header>

        <!-- Indicadores compactos (4: nunca queda uno huérfano) -->
        <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div v-for="item in indicadores" :key="item.etiqueta" class="rounded-lg border bg-card px-3 py-2">
                <dt class="text-xs text-muted-foreground">{{ item.etiqueta }}</dt>
                <dd class="text-xl font-semibold tabular-nums" :class="item.alerta && 'text-[var(--warning)]'">{{ item.valor }}</dd>
            </div>
        </dl>

        <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
            <!-- Plantilla por puesto: barra = ocupadas / autorizadas -->
            <section class="flex min-w-0 flex-col rounded-xl border bg-card" aria-labelledby="plantilla-puesto">
                <header class="flex items-center justify-between gap-2 border-b px-4 py-2.5">
                    <h2 id="plantilla-puesto" class="text-sm font-semibold">Plantilla por puesto</h2>
                    <Link v-if="totales.vacantes > 0" :href="indexVacantes.url({ query: { sucursal_id: sucursal.id } })" class="text-xs text-primary hover:underline">
                        Ver vacantes
                    </Link>
                </header>
                <p v-if="puestos.length === 0" class="px-4 py-8 text-center text-sm text-muted-foreground">
                    Esta sucursal todavía no tiene plantilla autorizada.
                </p>
                <ul v-else class="divide-y px-4">
                    <li v-for="fila in puestos" :key="fila.puesto_id" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 py-2.5">
                        <p class="min-w-0 text-sm font-medium break-words">{{ fila.puesto }}</p>
                        <p class="text-right text-sm tabular-nums">
                            <span class="font-semibold">{{ fila.plantilla_actual }}</span>
                            <span class="text-muted-foreground"> / {{ fila.plantilla_autorizada }}</span>
                        </p>
                        <div
                            class="col-span-2 h-2 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            :aria-label="`${fila.puesto}: ${fila.plantilla_actual} de ${fila.plantilla_autorizada} ocupadas`"
                            :aria-valuenow="porcentaje(fila)"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        >
                            <div class="h-full rounded-full" :class="fila.faltante > 0 ? 'bg-[var(--warning)]' : 'bg-[var(--success)]'" :style="{ width: `${porcentaje(fila)}%` }" />
                        </div>
                        <p v-if="fila.faltante > 0" class="col-span-2 text-xs text-[var(--warning)]">
                            {{ fila.faltante }} {{ fila.faltante === 1 ? 'vacante' : 'vacantes' }}
                        </p>
                    </li>
                </ul>
                <p v-if="totales.fuera_de_plantilla > 0" class="border-t px-4 py-2.5 text-xs text-muted-foreground">
                    {{ totales.fuera_de_plantilla }}
                    {{ totales.fuera_de_plantilla === 1 ? 'persona está' : 'personas están' }} en puestos sin plantilla autorizada en esta
                    sucursal (no cuentan en la cobertura). Revisa su sucursal en el expediente.
                </p>
            </section>

            <div class="grid min-w-0 gap-4 sm:grid-cols-2 lg:grid-cols-1">
                <DashboardChartCard
                    title="Cobertura de plantilla"
                    :description="`${totales.plantilla_actual} de ${totales.plantilla_autorizada} plazas ocupadas`"
                    type="donut"
                    :data="donaCobertura"
                    label-key="etiqueta"
                    value-key="valor"
                    :colors="['var(--success)', 'var(--warning)']"
                    :height="180"
                    empty-title="Sin plantilla autorizada"
                />
                <DashboardChartCard
                    title="Personas por departamento"
                    :description="`${departamentos} ${departamentos === 1 ? 'departamento' : 'departamentos'}`"
                    type="donut"
                    :data="porDepartamento"
                    label-key="etiqueta"
                    value-key="valor"
                    :height="180"
                    empty-title="Sin colaboradores activos"
                />
            </div>
        </div>
    </div>
</template>
