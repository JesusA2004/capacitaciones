<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Pencil, Search } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import PanelFiniquito from '@/components/bajas/PanelFiniquito.vue';
import PestanasBajas from '@/components/bajas/PestanasBajas.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { parametrosPorPaginaActuales } from '@/composables/usePaginacion';
import { claseForma } from '@/lib/bajas';
import { formatearFecha } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import rutasBajas from '@/routes/rh/bajas';
import rutasFiniquitos from '@/routes/rh/bajas/finiquitos';
import type {
    DetalleFiniquitoBaja,
    FilaFiniquitoBaja,
    PestanasBajas as Pestanas,
} from '@/types';

/**
 * Bajas → «Finiquitos» (PDF Etapa 1, F1–F4): localiza y procesa cada finiquito
 * sin entrar al expediente; es el mismo registro que muestra la baja. Montos,
 * estados y permisos los decide el backend (FiniquitoService, Policy).
 */
type Paginador = {
    data: FilaFiniquitoBaja[];
    current_page: number;
    last_page: number;
    per_page: number;
    from?: number | null;
    to?: number | null;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

const props = defineProps<{
    pestanas: Pestanas;
    finiquitos: Paginador;
    resumen: {
        calculados: number;
        revisados: number;
        firmados: number;
        pagados: number;
        monto_pendiente: number;
    };
    seleccionado: DetalleFiniquitoBaja | null;
    filtros: {
        busqueda: string;
        estado: string | null;
        sucursal_id: number | null;
        tipo: string | null;
    };
    sucursales: { value: number; label: string }[];
    formas_pago: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Bajas', href: rutasBajas.index() },
            { title: 'Finiquitos', href: '' },
        ],
    },
});

// Importes de finiquito con centavos: son los que se firman y se pagan.
const formateador = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    minimumFractionDigits: 2,
});
const moneda = (valor: number): string => formateador.format(valor);

const page = usePage();
const errores = computed(() =>
    Object.values((page.props.errors ?? {}) as Record<string, string>),
);

const busqueda = ref(props.filtros.busqueda);
const estado = ref(props.filtros.estado ?? '');
const sucursal = ref<number | null>(props.filtros.sucursal_id);
const tipo = ref(props.filtros.tipo ?? '');

function parametros(extra: Record<string, unknown> = {}) {
    return {
        ...parametrosPorPaginaActuales(),
        busqueda: busqueda.value || undefined,
        estado: estado.value || undefined,
        sucursal_id: sucursal.value ?? undefined,
        tipo: tipo.value || undefined,
        ...extra,
    };
}

function filtrar() {
    router.get(rutasFiniquitos.index.url(), parametros(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

watch(busqueda, useDebounceFn(filtrar, 300));
watch([estado, sucursal, tipo], filtrar);

function abrir(id: number) {
    router.get(rutasFiniquitos.index.url(), parametros({ finiquito: id }), {
        preserveState: true,
        preserveScroll: true,
        only: ['seleccionado'],
    });
}

const etapas = [
    { clave: 'calculado', etiqueta: 'Calculado' },
    { clave: 'revisado', etiqueta: 'Revisado' },
    { clave: 'firmado', etiqueta: 'Firmado' },
    { clave: 'pagado', etiqueta: 'Pagado' },
];

function etapaAlcanzada(fila: FilaFiniquitoBaja, clave: string): boolean {
    return (
        etapas.findIndex((e) => e.clave === clave) <=
        etapas.findIndex((e) => e.clave === fila.estado_flujo)
    );
}
</script>

<template>
    <Head title="Finiquitos" />

    <div class="pagina-ancha flex flex-col gap-5">
        <PestanasBajas :pestanas="pestanas" actual="finiquitos" />

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <TarjetaMetrica
                etiqueta="Calculados"
                :valor="resumen.calculados"
                detalle="Por revisar"
                tono="alerta"
                clicable
                :activa="estado === 'calculado'"
                @click="estado = estado === 'calculado' ? '' : 'calculado'"
            />
            <TarjetaMetrica
                etiqueta="Revisados"
                :valor="resumen.revisados"
                detalle="Listos para firma"
                tono="info"
                clicable
                :activa="estado === 'revisado'"
                @click="estado = estado === 'revisado' ? '' : 'revisado'"
            />
            <TarjetaMetrica
                etiqueta="Firmados"
                :valor="resumen.firmados"
                detalle="Pendientes de pago"
                tono="primario"
                clicable
                :activa="estado === 'firmado'"
                @click="estado = estado === 'firmado' ? '' : 'firmado'"
            />
            <TarjetaMetrica
                etiqueta="Pagados"
                :valor="resumen.pagados"
                detalle="En el periodo"
                tono="exito"
                clicable
                :activa="estado === 'pagado'"
                @click="estado = estado === 'pagado' ? '' : 'pagado'"
            />
            <TarjetaMetrica
                etiqueta="Monto pendiente de pago"
                :valor="moneda(resumen.monto_pendiente)"
                detalle="Finiquitos no pagados"
                tono="oro"
            />
        </div>

        <section
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
        >
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="busqueda"
                        class="pl-9"
                        placeholder="Buscar colaborador"
                        aria-label="Buscar colaborador"
                    />
                </div>
                <SelectSimple
                    v-model="estado"
                    :opciones="
                        etapas.map((e) => ({
                            value: e.clave,
                            label: e.etiqueta,
                        }))
                    "
                    opcion-vacia="Todos los estatus"
                    placeholder="Todos los estatus"
                />
                <SelectSimple
                    v-model="sucursal"
                    :opciones="sucursales"
                    numerico
                    opcion-vacia="Todas las sucursales"
                    placeholder="Todas las sucursales"
                />
                <SelectSimple
                    v-model="tipo"
                    :opciones="[
                        { value: 'finiquito', label: 'Finiquito' },
                        { value: 'liquidacion', label: 'Liquidación' },
                    ]"
                    opcion-vacia="Finiquito y liquidación"
                    placeholder="Finiquito y liquidación"
                />
            </div>

            <p
                v-if="!finiquitos.data.length"
                class="px-4 py-10 text-center text-sm text-muted-foreground"
            >
                No hay finiquitos con estos filtros.
            </p>

            <div v-else class="tabla-scroll">
                <table class="w-full text-left text-sm">
                    <thead
                        class="bg-crema/40 text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="min-w-56 px-3 py-2 font-medium">
                                Colaborador
                            </th>
                            <th class="px-3 py-2 font-medium">
                                Forma de salida
                            </th>
                            <th class="px-3 py-2 font-medium">Tipo</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Monto
                            </th>
                            <th class="px-3 py-2 font-medium">Estatus</th>
                            <th class="px-3 py-2 font-medium">Fecha de pago</th>
                            <th class="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="f in finiquitos.data"
                            :key="f.id"
                            :class="
                                cn(
                                    'border-b border-border/60 last:border-0',
                                    seleccionado?.id === f.id && 'bg-menta/40',
                                )
                            "
                        >
                            <td class="px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <ColaboradorAvatar
                                        :nombre="f.colaborador"
                                        :foto-url="f.foto_url"
                                    />
                                    <div class="min-w-0">
                                        <p class="font-medium">
                                            {{ f.colaborador }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ f.sucursal ?? '—' }} ·
                                            {{ f.puesto ?? '—' }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                <span
                                    class="inline-flex items-center gap-1.5 font-medium"
                                >
                                    <span
                                        :class="
                                            cn(
                                                'size-2.5 rounded-full',
                                                claseForma(f.forma_token).fondo,
                                            )
                                        "
                                    />{{ f.forma_etiqueta ?? '—' }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <ChipEstado
                                    :etiqueta="f.tipo_etiqueta"
                                    :tono="
                                        f.tipo === 'liquidacion'
                                            ? 'oro'
                                            : 'info'
                                    "
                                />
                            </td>
                            <td
                                class="px-3 py-2 text-right font-semibold tabular-nums"
                            >
                                {{ moneda(f.monto) }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="e in etapas"
                                        :key="e.clave"
                                        :class="
                                            cn(
                                                'rounded-full px-1.5 py-0.5 text-[0.7rem]',
                                                etapaAlcanzada(f, e.clave)
                                                    ? 'bg-info-soft font-semibold text-info'
                                                    : 'bg-muted text-muted-foreground',
                                            )
                                        "
                                        >{{ e.etiqueta }}</span
                                    >
                                </div>
                                <p
                                    v-if="f.subestado"
                                    class="mt-0.5 text-xs text-muted-foreground"
                                >
                                    Subestado: {{ f.subestado }}
                                </p>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                {{ formatearFecha(f.fecha_pago) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="abrir(f.id)"
                                    ><Pencil class="size-3.5" />Editar</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <PaginacionTabla :datos="finiquitos" clave="bajas-finiquitos" />
        </section>

        <div
            v-if="errores.length"
            class="rounded-2xl bg-danger-soft px-4 py-3 text-sm text-destructive"
            role="alert"
        >
            <p v-for="(e, i) in errores" :key="i">{{ e }}</p>
        </div>

        <PanelFiniquito
            v-if="seleccionado"
            :finiquito="seleccionado"
            :formas-pago="formas_pago"
        />
    </div>
</template>
