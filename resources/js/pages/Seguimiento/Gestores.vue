<script setup lang="ts">
import { Deferred, Head, Link } from '@inertiajs/vue3';
import {
    Banknote,
    Building2,
    Grid3x3,
    HandCoins,
    Link2,
    Search,
    UserX,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import AvisoReportes from '@/components/Seguimiento/AvisoReportes.vue';
import BarrasSemanas from '@/components/Seguimiento/BarrasSemanas.vue';
import EsqueletoTablero from '@/components/Seguimiento/EsqueletoTablero.vue';
import SelectorPeriodo from '@/components/Seguimiento/SelectorPeriodo.vue';
import TarjetaKpi from '@/components/Seguimiento/TarjetaKpi.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import { Input } from '@/components/ui/input';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { usePermisos } from '@/composables/usePermisos';
import { useSeguimientoPeriodo } from '@/composables/useSeguimientoPeriodo';
import {
    formatoMoneda,
    formatoPorcentaje,
    tonoMora,
} from '@/lib/seguimiento';
import { dashboard } from '@/routes';
import { index as indexSeguimiento } from '@/routes/seguimiento';
import { index as indexGestores, show as showGestor } from '@/routes/seguimiento/gestores';
import { index as indexNineBox } from '@/routes/seguimiento/nine-box';
import { index as indexVinculos } from '@/routes/seguimiento/vinculos';
import type {
    FiltrosSeguimiento,
    PeriodoSeguimiento,
    RespuestaSeguimiento,
    TableroGestores,
} from '@/types';

const props = defineProps<{
    filtros: FiltrosSeguimiento;
    sucursalId: number | null;
    configurado: boolean;
    puedeVincular: boolean;
    veTodo: boolean;
    datos?: RespuestaSeguimiento<{
        periodo: PeriodoSeguimiento;
        tablero: TableroGestores | null;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Seguimiento', href: indexSeguimiento() },
            { title: 'Por gestor', href: '' },
        ],
    },
});

const { cargando, ir } = useSeguimientoPeriodo(() => indexGestores.url());
const { tienePermiso } = usePermisos();

const periodo = computed(() => props.datos?.datos?.periodo ?? null);
const tablero = computed(() => props.datos?.datos?.tablero ?? null);
const busqueda = ref('');
const soloSinVincular = ref(false);

const indiceSemana = computed(() => {
    const id = tablero.value?.semana_id ?? null;

    return id === null ? null : (tablero.value?.semanas.findIndex((s) => s.id === id) ?? null);
});

const alcanceTexto = computed(() => {
    const semana = tablero.value?.semanas.find((s) => s.id === tablero.value?.semana_id);

    return semana
        ? `${semana.etiqueta} de ${periodo.value?.mes?.etiqueta ?? ''}`
        : (periodo.value?.mes?.etiqueta ?? '');
});

const gestoresFiltrados = computed(() => {
    const termino = busqueda.value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();

    return (tablero.value?.gestores ?? []).filter((g) => {
        if (soloSinVincular.value && g.vinculado) {
            return false;
        }

        if (termino === '') {
            return true;
        }

        return `${g.nombre} ${g.sucursal ?? ''} ${g.branch ?? ''} ${g.puesto ?? ''}`
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .includes(termino);
    });
});

function cambiar(mes: number, semana: number | null): void {
    ir({ mes, semana, sucursal: props.sucursalId });
}

function cambiarSucursal(valor: number | string | null): void {
    ir({
        mes: periodo.value?.mes?.id ?? null,
        semana: tablero.value?.semana_id ?? null,
        sucursal: valor === null || valor === '' ? null : Number(valor),
    });
}

const opcionesSucursal = computed(() => tablero.value?.opciones_sucursal ?? []);
</script>

<template>
    <Head title="Seguimiento por gestor" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Seguimiento por gestor"
            descripcion="Colocación, recuperación, cartera y mora de cada gestor, por semana y por mes."
            :icono="Users"
        >
            <Button variant="outline" as-child>
                <Link :href="indexSeguimiento({ query: filtros.mes ? { mes: filtros.mes } : {} })">
                    <Building2 class="size-4" /> Por sucursal
                </Link>
            </Button>
            <Button v-if="$page.props.features.nine_box && tienePermiso('nine_box.ver')" variant="outline" as-child>
                <Link :href="indexNineBox()"> <Grid3x3 class="size-4" /> Nine Box </Link>
            </Button>
            <Button v-if="puedeVincular" variant="outline" as-child>
                <Link :href="indexVinculos()">
                    <Link2 class="size-4" /> Vínculos con Reportes
                </Link>
            </Button>
        </CrudPageHeader>

        <Deferred data="datos">
            <template #fallback>
                <EsqueletoTablero />
            </template>

            <AvisoReportes v-if="datos?.error" :mensaje="datos.error" />

            <template v-else-if="periodo">
                <SelectorPeriodo
                    :meses="periodo.meses"
                    :mes-id="periodo.mes?.id ?? null"
                    :semanas="tablero?.semanas ?? []"
                    :semana-id="tablero?.semana_id ?? null"
                    :deshabilitado="cargando"
                    @cambiar="cambiar"
                />

                <CambioSuave>
                    <EsqueletoTablero v-if="cargando" key="cargando" :con-selector="false" />

                    <EmptyState
                        v-else-if="!tablero || tablero.estado === 'sin_datos'"
                        key="sin-datos"
                        :icono="Users"
                        titulo="Reportes aún no tiene datos de este mes"
                        :descripcion="tablero?.mensaje ?? 'Cuando Reportes genere la radiografía del mes, aquí aparecerán sus gestores.'"
                    />

                    <div v-else :key="`g-${periodo.mes?.id}-${tablero.semana_id}-${sucursalId}`" class="flex flex-col gap-5">
                        <section class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-5">
                            <TarjetaKpi
                                etiqueta="Gestores con actividad"
                                :valor="tablero.totales.gestores"
                                :icono="Users"
                                tono="menta"
                                :nota="alcanceTexto"
                            />
                            <TarjetaKpi
                                etiqueta="Colocación"
                                :valor="tablero.totales.colocacion"
                                :icono="HandCoins"
                                prefijo="$"
                                tono="salvia"
                                :nota="alcanceTexto"
                            />
                            <TarjetaKpi
                                etiqueta="Recuperación"
                                :valor="tablero.totales.recuperacion"
                                :icono="Banknote"
                                prefijo="$"
                                :nota="alcanceTexto"
                            />
                            <TarjetaKpi
                                etiqueta="Cartera de los gestores"
                                :valor="tablero.totales.cartera"
                                :icono="Wallet"
                                prefijo="$"
                                tono="crema"
                                nota="Al cierre del mes"
                            />
                            <TarjetaKpi
                                class="col-span-2 lg:col-span-1"
                                etiqueta="Sin vincular a PEOPLE"
                                :valor="tablero.totales.sin_vincular"
                                :icono="UserX"
                                nota="Se ubican por la sucursal de Reportes"
                            />
                        </section>

                        <section class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4">
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="relative w-full sm:w-72">
                                    <Search
                                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <Input
                                        v-model="busqueda"
                                        class="pl-9"
                                        placeholder="Buscar gestor, sucursal o ruta…"
                                        aria-label="Buscar gestor"
                                    />
                                </div>
                                <ComboboxBuscable
                                    class="w-full sm:w-64"
                                    :model-value="sucursalId"
                                    :opciones="opcionesSucursal"
                                    numerico
                                    limpiable
                                    clase-boton="bg-card"
                                    placeholder="Todas las sucursales"
                                    aria-label="Filtrar por sucursal"
                                    @update:model-value="cambiarSucursal"
                                />
                                <label class="flex cursor-pointer items-center gap-2 text-sm text-muted-foreground">
                                    <input v-model="soloSinVincular" type="checkbox" class="size-4 accent-primary" />
                                    Solo sin vincular
                                </label>
                                <p class="ml-auto text-xs text-muted-foreground">
                                    {{ gestoresFiltrados.length }} de {{ tablero.gestores.length }} gestores
                                </p>
                            </div>

                            <EmptyState
                                v-if="gestoresFiltrados.length === 0"
                                :icono="Users"
                                titulo="Sin gestores para mostrar"
                                descripcion="Ningún gestor de tu alcance coincide con los filtros en este periodo."
                            />

                            <div v-else class="tabla-scroll">
                                <table class="w-full min-w-[820px] text-left text-sm">
                                    <thead class="text-xs text-muted-foreground">
                                        <tr class="border-b border-border">
                                            <th class="w-10 py-2 pr-2 font-medium">#</th>
                                            <th class="py-2 pr-3 font-medium">Gestor</th>
                                            <th class="py-2 pr-3 font-medium">Sucursal</th>
                                            <th class="py-2 pr-3 text-right font-medium">Colocación</th>
                                            <th class="py-2 pr-3 text-right font-medium">Recuperación</th>
                                            <th class="py-2 pr-3 text-right font-medium">Cartera</th>
                                            <th class="py-2 pr-3 text-right font-medium">Mora</th>
                                            <th class="py-2 font-medium">Semanas</th>
                                        </tr>
                                    </thead>
                                    <ListaAnimada tag="tbody" :cantidad="gestoresFiltrados.length">
                                        <tr
                                            v-for="(g, i) in gestoresFiltrados"
                                            :key="g.reportes_id"
                                            class="border-b border-border/60 transicion-ui last:border-0 hover:bg-crema/40"
                                        >
                                            <td class="py-2.5 pr-2 text-xs text-muted-foreground tabular-nums">{{ i + 1 }}</td>
                                            <td class="py-2.5 pr-3">
                                                <Link
                                                    :href="showGestor(g.reportes_id, { query: { mes: periodo.mes?.id ?? null } })"
                                                    class="font-medium hover:text-primary hover:underline"
                                                    >{{ g.nombre }}</Link
                                                >
                                                <p class="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                                    <span v-if="g.puesto">{{ g.puesto }}</span>
                                                    <span
                                                        v-if="!g.vinculado"
                                                        class="rounded-full bg-warning-soft px-1.5 text-warning"
                                                        >Sin vincular</span
                                                    >
                                                </p>
                                            </td>
                                            <td class="py-2.5 pr-3">
                                                <p>{{ g.sucursal ?? '—' }}</p>
                                                <p v-if="g.branch" class="text-[11px] text-muted-foreground">{{ g.branch }}</p>
                                            </td>
                                            <td class="py-2.5 pr-3 text-right tabular-nums">
                                                {{ formatoMoneda(g.colocacion) }}
                                                <p class="text-[11px] text-muted-foreground">{{ g.operaciones ?? 0 }} créditos</p>
                                            </td>
                                            <td class="py-2.5 pr-3 text-right tabular-nums">
                                                {{ formatoMoneda(g.recuperacion) }}
                                            </td>
                                            <td class="py-2.5 pr-3 text-right tabular-nums">
                                                {{ formatoMoneda(g.cartera) }}
                                            </td>
                                            <td class="py-2.5 pr-3 text-right">
                                                <span
                                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium tabular-nums"
                                                    :class="tonoMora(g.mora)"
                                                    >{{ formatoPorcentaje(g.mora) }}</span
                                                >
                                            </td>
                                            <td class="py-2.5">
                                                <BarrasSemanas
                                                    :valores="g.semanas.map((s) => s.colocacion)"
                                                    :etiquetas="tablero.semanas.map((s) => s.etiqueta)"
                                                    :resaltada="indiceSemana"
                                                />
                                            </td>
                                        </tr>
                                    </ListaAnimada>
                                </table>
                            </div>
                        </section>

                        <p class="text-xs text-muted-foreground">
                            Fuente: Reportes. Un gestor sin vincular se ubica por la sucursal que Reportes le asignó en
                            el mes. <span v-if="tablero.calculado_en">Calculado: {{ tablero.calculado_en }}.</span>
                        </p>
                    </div>
                </CambioSuave>
            </template>
        </Deferred>
    </div>
</template>
