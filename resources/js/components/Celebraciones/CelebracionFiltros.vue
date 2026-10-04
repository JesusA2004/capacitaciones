<script setup lang="ts">
import { FilterX } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import type { FiltrosCelebracion, OpcionCelebracion } from '@/types';

/**
 * Barra de filtros de Cumpleaños y Aniversarios (misma apariencia en ambos):
 * el buscador siempre visible y lo menos frecuente (empresa, sucursal,
 * departamento, estatus, rango de "Próximos") en un panel lateral con
 * contador de filtros activos. Sin tarjeta alrededor: los controles llevan
 * su propio borde y nada más.
 *
 * Solo muestra los campos cuyos catálogos recibe (Cumpleaños no filtra por
 * empresa; Aniversarios no filtra por estatus).
 */
const props = defineProps<{
    filtros: FiltrosCelebracion;
    rango: { desde: string; hasta: string };
    catalogos: {
        empresas?: OpcionCelebracion[];
        sucursales: OpcionCelebracion[];
        departamentos: OpcionCelebracion[];
        colaboradores?: OpcionCelebracion[];
        estatus?: { valor: string; etiqueta: string }[];
    };
}>();

const emit = defineEmits<{
    aplicar: [
        filtros: FiltrosCelebracion,
        rango: { desde: string; hasta: string },
    ];
}>();

const opcionesColaboradores = computed(() =>
    (props.catalogos.colaboradores ?? []).map((c) => ({
        value: String(c.id),
        label: c.nombre,
    })),
);
const busqueda = ref(props.filtros.busqueda);
const borrador = ref<FiltrosCelebracion>({ ...props.filtros });
const rangoBorrador = ref({ ...props.rango });
const sheetAbierto = ref(false);
let temporizador: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.filtros,
    (valor) => {
        borrador.value = { ...valor };
    },
);

watch(sheetAbierto, (abierto) => {
    if (abierto) {
        borrador.value = { ...props.filtros };
        rangoBorrador.value = { ...props.rango };
    }
});

watch(busqueda, (valor) => {
    clearTimeout(temporizador);
    temporizador = setTimeout(
        () =>
            emit('aplicar', { ...props.filtros, busqueda: valor }, props.rango),
        350,
    );
});

const activos = computed(
    () =>
        (
            [
                'empresa_id',
                'sucursal_id',
                'departamento_id',
                'colaborador_id',
                'estatus',
            ] as const
        ).filter((campo) => props.filtros[campo] !== '').length,
);

function aplicar() {
    const rango =
        rangoBorrador.value.desde &&
        rangoBorrador.value.hasta &&
        rangoBorrador.value.hasta >= rangoBorrador.value.desde
            ? rangoBorrador.value
            : props.rango;
    emit('aplicar', { ...borrador.value, busqueda: busqueda.value }, rango);
}

function limpiar() {
    busqueda.value = '';
    clearTimeout(temporizador);
    emit(
        'aplicar',
        {
            busqueda: '',
            empresa_id: '',
            sucursal_id: '',
            departamento_id: '',
            colaborador_id: '',
            estatus: '',
        },
        props.rango,
    );
}
</script>

<template>
    <div
        class="flex min-w-0 flex-1 items-center gap-2 sm:flex-none"
        role="search"
    >
        <CrudSearchInput
            v-model="busqueda"
            placeholder="Buscar persona…"
            class="min-w-0 flex-1 sm:w-64 sm:flex-none"
        />

        <CrudFilterSheet
            v-model:open="sheetAbierto"
            :contador-activos="activos"
            @aplicar="aplicar"
            @limpiar="limpiar"
        >
            <div v-if="catalogos.empresas" class="grid gap-1.5">
                <Label for="filtro-empresa">Empresa</Label>
                <SelectSimple
                    id="filtro-empresa"
                    v-model="borrador.empresa_id"
                    class="w-full"
                    :opciones="
                        catalogos.empresas.map((e) => ({
                            value: String(e.id),
                            label: e.nombre,
                        }))
                    "
                    opcion-vacia="Todas las empresas"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="filtro-sucursal">Sucursal</Label>
                <SelectSimple
                    id="filtro-sucursal"
                    v-model="borrador.sucursal_id"
                    class="w-full"
                    :opciones="
                        catalogos.sucursales.map((s) => ({
                            value: String(s.id),
                            label: s.nombre,
                        }))
                    "
                    opcion-vacia="Todas las sucursales"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="filtro-departamento">Departamento</Label>
                <SelectSimple
                    id="filtro-departamento"
                    v-model="borrador.departamento_id"
                    class="w-full"
                    :opciones="
                        catalogos.departamentos.map((d) => ({
                            value: String(d.id),
                            label: d.nombre,
                        }))
                    "
                    opcion-vacia="Todos los departamentos"
                />
            </div>
            <div v-if="catalogos.colaboradores" class="grid gap-1.5">
                <Label for="filtro-colaborador">Colaborador</Label>
                <Combobox
                    id="filtro-colaborador"
                    v-model="borrador.colaborador_id"
                    :items="opcionesColaboradores"
                    placeholder="Todos"
                    empty-text="Sin colaboradores con estos filtros."
                />
            </div>
            <div v-if="catalogos.estatus" class="grid gap-1.5">
                <Label for="filtro-estatus">Estatus</Label>
                <SelectSimple
                    id="filtro-estatus"
                    v-model="borrador.estatus"
                    class="w-full"
                    :opciones="
                        catalogos.estatus.map((e) => ({
                            value: e.valor,
                            label: e.etiqueta,
                        }))
                    "
                    opcion-vacia="Activos"
                />
            </div>

            <fieldset class="grid gap-1.5">
                <legend class="mb-1.5 text-sm font-medium">
                    Rango de «Próximos»
                </legend>
                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-1">
                        <Label
                            for="filtro-desde"
                            class="text-xs text-muted-foreground"
                            >Desde</Label
                        >
                        <DatePicker
                            v-model="rangoBorrador.desde"
                            id="filtro-desde"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label
                            for="filtro-hasta"
                            class="text-xs text-muted-foreground"
                            >Hasta</Label
                        >
                        <DatePicker
                            v-model="rangoBorrador.hasta"
                            id="filtro-hasta"
                            :min-value="rangoBorrador.desde"
                        />
                    </div>
                </div>
            </fieldset>
        </CrudFilterSheet>

        <Button
            v-if="activos > 0 || filtros.busqueda"
            variant="ghost"
            size="icon-sm"
            aria-label="Limpiar filtros"
            title="Limpiar filtros"
            @click="limpiar"
        >
            <FilterX class="size-4" />
        </Button>
    </div>
</template>
