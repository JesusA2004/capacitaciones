<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { aplicar as aplicarImportacion } from '@/routes/rh/contactos-emergencia';

type Cambio = { antes: string | null; despues: string };

type FilaDetalle = {
    fila: number;
    nombre_excel: string;
    sucursal_excel: string;
    colaborador_id: number | null;
    nombre_sistema: string | null;
    estado: 'aplicable' | 'sin_cambios' | 'revision' | 'no_encontrado' | 'error';
    motivo: string | null;
    cambios: Record<string, Cambio>;
    aplicado: boolean;
};

type Importacion = {
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

const props = defineProps<{ importacion: Importacion }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Contactos de emergencia', href: '/rh/contactos-emergencia' },
            { title: 'Revisión', href: '' },
        ],
    },
});

const ETIQUETAS: Record<string, string> = {
    telefono: 'Teléfono personal',
    contacto_emergencia_nombre: 'Contacto de emergencia',
    contacto_emergencia_parentesco: 'Parentesco',
    contacto_emergencia_telefono: 'Teléfono del contacto',
    contacto_emergencia_direccion: 'Dirección del contacto',
    condicion_medica: 'Condición médica',
    alergias: 'Alergias',
};

const ESTADOS: Record<FilaDetalle['estado'], { texto: string; clase: string }> = {
    aplicable: { texto: 'Se aplicará', clase: 'bg-success/10 text-success' },
    sin_cambios: {
        texto: 'Sin cambios',
        clase: 'bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)]',
    },
    revision: { texto: 'Revisar', clase: 'bg-warning-soft text-warning' },
    no_encontrado: { texto: 'No encontrado', clase: 'bg-warning-soft text-warning' },
    error: { texto: 'Error', clase: 'bg-destructive/10 text-destructive' },
};

const aplicables = computed(
    () => props.importacion.detalle.filter((f) => f.estado === 'aplicable').length,
);
const yaAplicada = computed(() => props.importacion.estado === 'aplicado');

function cambiosDe(fila: FilaDetalle): { etiqueta: string; cambio: Cambio }[] {
    return Object.entries(fila.cambios).map(([columna, cambio]) => ({
        etiqueta: ETIQUETAS[columna] ?? columna,
        cambio,
    }));
}

const form = useForm({});

function aplicar() {
    if (
        !window.confirm(
            `¿Aplicar ${aplicables.value} cambio(s) a la ficha de los colaboradores? Esta acción queda en la auditoría.`,
        )
    ) {
        return;
    }

    form.post(aplicarImportacion.url(props.importacion.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Revisión de contactos de emergencia" />

    <div class="pagina-ancha flex flex-col gap-5">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">
                    {{ importacion.nombre_archivo }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ importacion.total_filas }} fila(s) ·
                    {{ aplicables }} con cambios ·
                    {{ importacion.filas_revision }} por revisar ·
                    {{ importacion.filas_no_encontradas }} no encontradas ·
                    {{ importacion.filas_sin_cambios }} sin cambios
                </p>
            </div>
            <div class="flex items-center gap-3">
                <span
                    v-if="yaAplicada"
                    class="rounded-full bg-success/10 px-3 py-1 text-xs text-success"
                >
                    Aplicada el {{ formatearFecha(importacion.aplicado_en) }} ·
                    {{ importacion.filas_aplicadas }} colaborador(es)
                </span>
                <Button
                    v-else
                    :disabled="aplicables === 0 || form.processing"
                    @click="aplicar"
                >
                    Aplicar {{ aplicables }} cambio(s)
                </Button>
            </div>
        </header>

        <section
            class="overflow-x-auto rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        >
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-muted-foreground">
                    <tr>
                        <th class="py-2 pr-3">#</th>
                        <th class="py-2 pr-3">Nombre (archivo)</th>
                        <th class="py-2 pr-3">Colaborador</th>
                        <th class="py-2 pr-3">Estado</th>
                        <th class="py-2 pr-3">Cambios</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="fila in importacion.detalle"
                        :key="fila.fila"
                        class="border-t border-[var(--mrl-borde)] align-top"
                    >
                        <td class="py-2 pr-3">{{ fila.fila }}</td>
                        <td class="py-2 pr-3">
                            {{ fila.nombre_excel || '—' }}
                            <span
                                v-if="fila.sucursal_excel"
                                class="block text-xs text-muted-foreground"
                                >{{ fila.sucursal_excel }}</span
                            >
                        </td>
                        <td class="py-2 pr-3">{{ fila.nombre_sistema ?? '—' }}</td>
                        <td class="py-2 pr-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs"
                                :class="ESTADOS[fila.estado].clase"
                                >{{ ESTADOS[fila.estado].texto }}</span
                            >
                            <span
                                v-if="fila.aplicado"
                                class="ml-1 text-xs text-success"
                                >✓</span
                            >
                        </td>
                        <td class="py-2 pr-3">
                            <ul
                                v-if="cambiosDe(fila).length"
                                class="flex flex-col gap-0.5 text-xs"
                            >
                                <li
                                    v-for="c in cambiosDe(fila)"
                                    :key="c.etiqueta"
                                >
                                    <span class="font-medium">{{
                                        c.etiqueta
                                    }}</span
                                    >:
                                    <span class="text-muted-foreground">{{
                                        c.cambio.antes ?? '(vacío)'
                                    }}</span>
                                    →
                                    <span>{{ c.cambio.despues }}</span>
                                </li>
                            </ul>
                            <span
                                v-else-if="fila.motivo"
                                class="text-xs text-muted-foreground"
                                >{{ fila.motivo }}</span
                            >
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
