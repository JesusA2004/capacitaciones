<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ClipboardList, Download, Upload } from '@lucide/vue';
import { computed } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { index as importarDatosIndex } from '@/routes/administracion/importar-datos';
import {
    analizar,
    aplicar,
    plantilla,
} from '@/routes/administracion/importar-datos/altas-historicas';

type Fila = {
    fila: number;
    nombre: string;
    fecha: string | null;
    sucursal: string;
    puesto: string;
    estado: 'nuevo' | 'duplicado' | 'ya_en_sistema' | 'error';
    motivo: string | null;
};
type Importacion = {
    id: number;
    nombre_archivo: string;
    estado: 'previsualizado' | 'aplicado';
    total_filas: number;
    filas_aplicadas: number;
    filas_revision: number;
    filas_sin_cambios: number;
    filas_error: number;
    detalle: Fila[];
    metadatos: { periodo?: { desde: string | null; hasta: string | null } };
};

const props = defineProps<{
    importacion: Importacion | null;
    recientes: {
        id: number;
        nombre_archivo: string;
        estado: string;
        filas_aplicadas: number;
        aplicado_por: string | null;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Importar datos', href: importarDatosIndex() },
            { title: 'Altas históricas', href: '' },
        ],
    },
});

const formArchivo = useForm<{ archivo: File | null }>({ archivo: null });
const formAplicar = useForm({ motivo: '' });

function alElegir(evento: Event) {
    formArchivo.archivo =
        (evento.target as HTMLInputElement).files?.[0] ?? null;
}

const ETIQUETA: Record<Fila['estado'], string> = {
    nuevo: 'Nueva',
    duplicado: 'Ya cargada',
    ya_en_sistema: 'Ya en el sistema',
    error: 'Error',
};
const CLASE: Record<Fila['estado'], string> = {
    nuevo: 'bg-info-soft text-info',
    duplicado: 'bg-muted text-muted-foreground',
    ya_en_sistema: 'bg-muted text-muted-foreground',
    error: 'bg-destructive/10 text-destructive',
};

const puedeAplicar = computed(
    () =>
        props.importacion?.estado === 'previsualizado' &&
        props.importacion.filas_error === 0 &&
        props.importacion.filas_revision > 0,
);

const errorAplicar = computed(
    () => (formAplicar.errors as Record<string, string>).importacion,
);
</script>

<template>
    <Head title="Altas históricas" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Altas históricas"
            descripcion="Altas confirmadas de periodos anteriores al sistema. No cambia fechas de ingreso ni deduce altas a partir de bajas."
            :icono="ClipboardList"
        />

        <section class="rounded-2xl border border-border/60 bg-card p-5">
            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="
                    formArchivo.post(analizar().url, { forceFormData: true })
                "
            >
                <label class="grid gap-1 text-sm">
                    Archivo de Excel (.xlsx)
                    <input
                        type="file"
                        accept=".xlsx"
                        class="text-sm"
                        @change="alElegir"
                    />
                </label>
                <Button
                    type="submit"
                    :disabled="formArchivo.processing || !formArchivo.archivo"
                >
                    <Upload class="size-4" /> Revisar archivo
                </Button>
                <Button as-child variant="outline">
                    <a :href="plantilla().url">
                        <Download class="size-4" /> Descargar formato
                    </a>
                </Button>
            </form>
            <InputError class="mt-2" :message="formArchivo.errors.archivo" />
        </section>

        <template v-if="importacion">
            <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <TarjetaMetrica
                    etiqueta="Filas"
                    :valor="importacion.total_filas"
                />
                <TarjetaMetrica
                    etiqueta="Nuevas"
                    :valor="importacion.filas_revision"
                />
                <TarjetaMetrica
                    etiqueta="Ya registradas"
                    :valor="importacion.filas_sin_cambios"
                />
                <TarjetaMetrica
                    etiqueta="Con error"
                    :valor="importacion.filas_error"
                    :tono="importacion.filas_error > 0 ? 'peligro' : 'neutro'"
                />
            </section>

            <section
                class="overflow-x-auto rounded-2xl border border-border/60 bg-card"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Fila</th>
                            <th class="px-4 py-2 font-medium">Nombre</th>
                            <th class="px-4 py-2 font-medium">Fecha de alta</th>
                            <th class="px-4 py-2 font-medium">Sucursal</th>
                            <th class="px-4 py-2 font-medium">Puesto</th>
                            <th class="px-4 py-2 font-medium">Resultado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="f in importacion.detalle"
                            :key="f.fila"
                            class="border-t border-border/40"
                        >
                            <td class="px-4 py-2">{{ f.fila }}</td>
                            <td class="px-4 py-2">{{ f.nombre || '—' }}</td>
                            <td class="px-4 py-2">{{ f.fecha ?? '—' }}</td>
                            <td class="px-4 py-2">{{ f.sucursal || '—' }}</td>
                            <td class="px-4 py-2">{{ f.puesto || '—' }}</td>
                            <td class="px-4 py-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :class="CLASE[f.estado]"
                                >
                                    {{ ETIQUETA[f.estado] }}
                                </span>
                                <span
                                    v-if="f.motivo"
                                    class="ml-2 text-xs text-muted-foreground"
                                >
                                    {{ f.motivo }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section
                v-if="importacion.estado === 'previsualizado'"
                class="rounded-2xl border border-border/60 bg-card p-5"
            >
                <label class="grid gap-1 text-sm">
                    ¿De dónde salen estas altas?
                    <Textarea
                        v-model="formAplicar.motivo"
                        rows="2"
                        placeholder="Ej. Plantilla de RH de 2025 entregada por la Gerencia"
                    />
                </label>
                <InputError class="mt-1" :message="formAplicar.errors.motivo" />
                <InputError class="mt-1" :message="errorAplicar" />
                <p
                    v-if="importacion.filas_error > 0"
                    class="mt-2 text-sm text-destructive"
                >
                    Corrige las filas con error y vuelve a subir el archivo.
                </p>
                <Button
                    class="mt-3"
                    :disabled="!puedeAplicar || formAplicar.processing"
                    @click="
                        formAplicar.post(aplicar(importacion!.id).url, {
                            preserveScroll: true,
                        })
                    "
                >
                    Confirmar importación
                </Button>
            </section>
            <p v-else class="text-sm text-muted-foreground">
                Importación aplicada: {{ importacion.filas_aplicadas }} alta(s)
                registradas.
            </p>
        </template>

        <section
            v-if="recientes.length"
            class="rounded-2xl border border-border/60 bg-card p-5"
        >
            <h2 class="mb-2 text-sm font-semibold">Importaciones recientes</h2>
            <ul class="space-y-1 text-sm">
                <li v-for="r in recientes" :key="r.id">
                    {{ r.nombre_archivo }} · {{ r.estado }} ·
                    {{ r.filas_aplicadas }} alta(s)
                    <span v-if="r.aplicado_por">· {{ r.aplicado_por }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
