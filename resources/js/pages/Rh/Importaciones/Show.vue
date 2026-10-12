<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { FileSpreadsheet } from '@lucide/vue';
import { computed, ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index as indexImportaciones, aplicar } from '@/routes/rh/importaciones';

type FilaDetalle = {
    fila: number;
    nombre_excel: string;
    sucursal_excel: string;
    colaborador_id: number | null;
    nombre_sistema: string | null;
    sucursal_sistema: string | null;
    estado: 'aplicable' | 'revision' | 'sin_cambios' | 'no_encontrado' | 'error' | 'aplicado';
    cambia_salario: boolean;
    salario_diario_actual: number | null;
    salario_diario_excel: number | null;
    antiguedad_anios: number | null;
    cumple_un_anio: boolean | null;
    ajuste_vacaciones_propuesto: number;
    motivo_ajuste_vacaciones: string | null;
    motivo_vacaciones_no_aplican: string | null;
    motivo: string | null;
    aplicado: boolean;
};

const props = defineProps<{
    importacion: {
        id: number;
        nombre_archivo: string;
        estado: string;
        total_filas: number;
        filas_aplicadas: number;
        filas_revision: number;
        filas_sin_cambios: number;
        filas_no_encontradas: number;
        filas_error: number;
        detalle: FilaDetalle[];
        aplicado_en: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Importaciones RH', href: indexImportaciones() },
            { title: props.importacion.nombre_archivo, href: '' },
        ],
    },
});

const filtro = ref<'todas' | FilaDetalle['estado']>('todas');

const filasFiltradas = computed(() =>
    filtro.value === 'todas'
        ? props.importacion.detalle
        : props.importacion.detalle.filter((f) => f.estado === filtro.value),
);

const etiquetaEstado: Record<string, string> = {
    aplicable: 'Aplicable',
    revision: 'Requiere revisión',
    sin_cambios: 'Sin cambios',
    no_encontrado: 'No encontrado',
    error: 'Error',
};

const aplicables = computed(
    () => props.importacion.detalle.filter((f) => f.estado === 'aplicable').length,
);

const form = useForm({});

function confirmarAplicar() {
    if (! window.confirm(`¿Aplicar ${aplicables.value} fila(s)? Esta acción actualiza salarios y registra ajustes de vacaciones.`)) {
        return;
    }

    form.post(aplicar.url(props.importacion.id));
}
</script>

<template>
    <Head :title="`Importación · ${importacion.nombre_archivo}`" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="importacion.nombre_archivo"
            :descripcion="importacion.estado === 'aplicado' ? `Aplicada el ${formatearFecha(importacion.aplicado_en)}` : 'Previsualización — revisa antes de aplicar'"
            :icono="FileSpreadsheet"
        >
            <Button
                v-if="importacion.estado !== 'aplicado'"
                :disabled="aplicables === 0 || form.processing"
                @click="confirmarAplicar"
            >
                Aplicar {{ aplicables }} fila(s)
            </Button>
        </CrudPageHeader>

        <section class="grid gap-3 sm:grid-cols-5">
            <button
                v-for="(valor, clave) in {
                    todas: importacion.total_filas,
                    aplicable: importacion.detalle.filter((f) => f.estado === 'aplicable').length,
                    revision: importacion.filas_revision,
                    sin_cambios: importacion.filas_sin_cambios,
                    no_encontrado: importacion.filas_no_encontradas,
                }"
                :key="clave"
                type="button"
                class="rounded-2xl border p-3 text-left"
                :class="
                    filtro === clave
                        ? 'border-[var(--mrl-primary)] bg-[var(--mrl-primary)]/10'
                        : 'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]'
                "
                @click="filtro = clave as typeof filtro"
            >
                <p class="text-2xl font-semibold tabular-nums">{{ valor }}</p>
                <p class="text-xs text-muted-foreground">
                    {{ clave === 'todas' ? 'Total de filas' : etiquetaEstado[clave] }}
                </p>
            </button>
        </section>

        <section class="overflow-x-auto rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]">
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-muted-foreground">
                    <tr>
                        <th class="p-3">Fila</th>
                        <th class="p-3">Nombre (Excel)</th>
                        <th class="p-3">Colaborador (PEOPLE)</th>
                        <th class="p-3">Sucursal</th>
                        <th class="p-3">Salario diario</th>
                        <th class="p-3">Antigüedad</th>
                        <th class="p-3">Vacaciones</th>
                        <th class="p-3">Estado</th>
                        <th class="p-3">Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="f in filasFiltradas"
                        :key="f.fila"
                        class="border-t border-[var(--mrl-borde)] align-top"
                    >
                        <td class="p-3 tabular-nums">{{ f.fila }}</td>
                        <td class="p-3">{{ f.nombre_excel }}</td>
                        <td class="p-3">{{ f.nombre_sistema ?? '—' }}</td>
                        <td class="p-3">{{ f.sucursal_sistema ?? f.sucursal_excel }}</td>
                        <td class="p-3">
                            <template v-if="f.salario_diario_actual !== null">
                                <span v-if="f.cambia_salario">
                                    ${{ f.salario_diario_actual }} → ${{ f.salario_diario_excel }}
                                </span>
                                <span v-else>${{ f.salario_diario_actual }}</span>
                            </template>
                            <span v-else>—</span>
                        </td>
                        <td class="p-3">
                            <template v-if="f.antiguedad_anios !== null">
                                {{ f.antiguedad_anios }} año(s)
                            </template>
                        </td>
                        <td class="p-3">
                            <span v-if="f.motivo_vacaciones_no_aplican" class="text-xs text-[var(--mrl-texto-suave)]">
                                No aplican aún
                            </span>
                            <span v-else-if="f.ajuste_vacaciones_propuesto !== 0">
                                {{ f.ajuste_vacaciones_propuesto }} días (histórico)
                            </span>
                            <span v-else>—</span>
                        </td>
                        <td class="p-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs"
                                :class="{
                                    'bg-success/10 text-success': f.estado === 'aplicable',
                                    'bg-destructive/10 text-destructive': f.estado === 'no_encontrado' || f.estado === 'error',
                                    'bg-[var(--mrl-gold)]/10 text-[var(--mrl-gold-oscuro)]': f.estado === 'revision',
                                    'bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)]': f.estado === 'sin_cambios',
                                }"
                            >{{ f.aplicado ? 'Aplicado' : etiquetaEstado[f.estado] }}</span>
                        </td>
                        <td class="p-3 text-xs text-muted-foreground">{{ f.motivo ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
