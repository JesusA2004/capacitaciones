<script setup lang="ts">
import { computed } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { Label } from '@/components/ui/label';
import { useNavegacionRotacion } from '@/lib/rotacion';
import type { FiltrosRotacionTablero, OpcionesRotacion } from '@/types';

/**
 * Filtros de alcance y de detalle de Rotación. Se dibujan en línea (escritorio)
 * o dentro de una hoja (móvil): mismos campos, mismo comportamiento. Cada
 * cambio recarga el tablero conservando la sección y el desglose.
 */
const props = defineProps<{
    filtros: FiltrosRotacionTablero;
    opciones: OpcionesRotacion;
    campos: (
        | 'empresa'
        | 'sucursal'
        | 'area'
        | 'puesto'
        | 'clasificacion'
        | 'causal'
        | 'antiguedad'
        | 'fuente'
    )[];
    /** En la hoja móvil, una columna; en línea, rejilla flexible. */
    apilado?: boolean;
}>();

const nav = useNavegacionRotacion();

const sucursales = computed(() =>
    props.opciones.sucursales
        .filter(
            (s) =>
                props.filtros.empresa_id === null ||
                s.empresa_id === props.filtros.empresa_id,
        )
        .map((s) => ({ value: s.id, label: s.nombre })),
);

const empresas = computed(() =>
    props.opciones.empresas.map((e) => ({ value: e.id, label: e.nombre })),
);
const areas = computed(() =>
    props.opciones.departamentos.map((d) => ({ value: d.id, label: d.nombre })),
);
const puestos = computed(() =>
    props.opciones.puestos.map((p) => ({ value: p.id, label: p.nombre })),
);
const causales = computed(() =>
    props.opciones.causales.map((c) => ({
        value: c.id,
        label: c.nombre,
        descripcion: c.activo ? null : 'Inactiva',
    })),
);

const FUENTES = [
    { value: 'todas', label: 'Todas las fuentes' },
    { value: 'sistema', label: 'Solo sistema' },
    { value: 'historico', label: 'Solo histórico' },
];

function cambiarEmpresa(valor: number | null): void {
    const sucursal = props.opciones.sucursales.find(
        (s) => s.id === props.filtros.sucursal_id,
    );
    const sigue = valor === null || (sucursal && sucursal.empresa_id === valor);

    nav.actualizar({
        empresa_id: valor,
        sucursal_id: sigue ? props.filtros.sucursal_id : null,
    });
}

function cambiar(clave: string, valor: string | number | null): void {
    nav.actualizar({ [clave]: valor === 'todas' ? null : valor });
}
</script>

<template>
    <div
        :class="
            apilado
                ? 'grid gap-3'
                : 'grid grid-cols-2 gap-2 lg:grid-cols-[repeat(auto-fill,minmax(11rem,1fr))]'
        "
    >
        <div v-if="campos.includes('empresa')" class="min-w-0">
            <Label class="mb-1 text-xs text-muted-foreground">Empresa</Label>
            <SelectSimple
                :model-value="filtros.empresa_id"
                :opciones="empresas"
                numerico
                opcion-vacia="Todas"
                placeholder="Todas"
                aria-label="Empresa"
                @update:model-value="cambiarEmpresa"
            />
        </div>
        <div v-if="campos.includes('sucursal')" class="min-w-0">
            <Label class="mb-1 text-xs text-muted-foreground">Sucursal</Label>
            <SelectSimple
                :model-value="filtros.sucursal_id"
                :opciones="sucursales"
                numerico
                opcion-vacia="Todas"
                placeholder="Todas"
                aria-label="Sucursal"
                @update:model-value="(v) => cambiar('sucursal_id', v)"
            />
        </div>
        <div v-if="campos.includes('area')" class="min-w-0">
            <Label class="mb-1 text-xs text-muted-foreground">Área</Label>
            <SelectSimple
                :model-value="filtros.departamento_id"
                :opciones="areas"
                numerico
                opcion-vacia="Todas"
                placeholder="Todas"
                aria-label="Área"
                @update:model-value="(v) => cambiar('departamento_id', v)"
            />
        </div>
        <div v-if="campos.includes('puesto')" class="min-w-0">
            <Label class="mb-1 text-xs text-muted-foreground">Puesto</Label>
            <SelectSimple
                :model-value="filtros.puesto_id"
                :opciones="puestos"
                numerico
                opcion-vacia="Todos"
                placeholder="Todos"
                aria-label="Puesto"
                @update:model-value="(v) => cambiar('puesto_id', v)"
            />
        </div>
        <div v-if="campos.includes('clasificacion')" class="min-w-0">
            <Label class="mb-1 text-xs text-muted-foreground"
                >Tipo de salida</Label
            >
            <SelectSimple
                :model-value="filtros.clasificacion"
                :opciones="opciones.clasificaciones"
                opcion-vacia="Todos"
                placeholder="Todos"
                aria-label="Tipo de salida"
                @update:model-value="(v) => cambiar('clasificacion', v)"
            />
        </div>
        <div v-if="campos.includes('causal')" class="min-w-0">
            <Label class="mb-1 text-xs text-muted-foreground"
                >Causal homologada</Label
            >
            <SelectSimple
                :model-value="filtros.causal_analitica_id"
                :opciones="causales"
                numerico
                opcion-vacia="Todas"
                placeholder="Todas"
                aria-label="Causal homologada"
                @update:model-value="(v) => cambiar('causal_analitica_id', v)"
            />
        </div>
        <div v-if="campos.includes('antiguedad')" class="min-w-0">
            <Label class="mb-1 text-xs text-muted-foreground">Antigüedad</Label>
            <SelectSimple
                :model-value="filtros.antiguedad"
                :opciones="opciones.antiguedades"
                opcion-vacia="Todas"
                placeholder="Todas"
                aria-label="Antigüedad"
                @update:model-value="(v) => cambiar('antiguedad', v)"
            />
        </div>
        <div v-if="campos.includes('fuente')" class="min-w-0">
            <Label class="mb-1 text-xs text-muted-foreground">Fuente</Label>
            <SelectSimple
                :model-value="filtros.fuente"
                :opciones="FUENTES"
                aria-label="Fuente de los datos"
                @update:model-value="(v) => cambiar('fuente', v)"
            />
        </div>
    </div>
</template>
