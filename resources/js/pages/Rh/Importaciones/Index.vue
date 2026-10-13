<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { FileSpreadsheet, FileUp, Trash2 } from '@lucide/vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import rutasImportaciones, {
    destroy as quitarImportacion,
    show as mostrarImportacion,
} from '@/routes/rh/importaciones';

type ImportacionResumen = {
    id: number;
    nombre_archivo: string;
    estado: string;
    total_filas: number;
    filas_aplicadas: number;
    filas_revision: number;
    filas_sin_cambios: number;
    filas_no_encontradas: number;
    filas_error: number;
    subido_por: string | null;
    aplicado_por: string | null;
    aplicado_en: string | null;
    creado_en: string | null;
};

defineProps<{
    importaciones: {
        data: ImportacionResumen[];
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Importaciones RH', href: '' },
        ],
    },
});

const form = useForm<{ archivo: File[] }>({ archivo: [] });

/** Solo las no aplicadas: las aplicadas son el historial de lo que cambió. */
function quitar(i: ImportacionResumen) {
    if (
        !window.confirm(
            `¿Quitar «${i.nombre_archivo}»? No se aplicó, así que no cambió nada de ningún colaborador.`,
        )
    ) {
        return;
    }

    router.delete(quitarImportacion.url(i.id), { preserveScroll: true });
}

function analizar() {
    if (form.archivo.length === 0) {
        return;
    }

    form.transform((datos) => ({ archivo: datos.archivo[0] })).post(
        rutasImportaciones.analizar.url(),
        {
            forceFormData: true,
            onSuccess: () => form.reset(),
        },
    );
}
</script>

<template>
    <Head title="Importaciones RH" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Importaciones RH"
            descripcion="Salarios y vacaciones históricas desde el reporte oficial de trabajadores."
            :icono="FileSpreadsheet"
        />

        <section
            class="flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        >
            <h2 class="text-sm font-semibold">
                Importar salarios y vacaciones
            </h2>
            <p class="text-xs text-muted-foreground">
                Sube el REPORTE_TRABAJADORES_VAC.xlsx oficial. Primero se
                analiza (preview): nada se aplica hasta que lo confirmes en la
                pantalla de revisión.
            </p>

            <form class="flex flex-col gap-3" @submit.prevent="analizar">
                <PeopleFileDropzone
                    v-model="form.archivo"
                    accept=".xlsx,.xls"
                    label="Arrastra el archivo .xlsx aquí"
                    hint="Solo REPORTE_TRABAJADORES_VAC.xlsx"
                />
                <InputError :message="form.errors.archivo" />
                <div>
                    <Button
                        type="submit"
                        :disabled="form.archivo.length === 0 || form.processing"
                    >
                        <FileUp class="size-4" /> Analizar archivo
                    </Button>
                </div>
            </form>
        </section>

        <section
            class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        >
            <h2 class="text-sm font-semibold">Importaciones anteriores</h2>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-3">Archivo</th>
                            <th class="py-2 pr-3">Estado</th>
                            <th class="py-2 pr-3">Filas</th>
                            <th class="py-2 pr-3">Aplicadas</th>
                            <th class="py-2 pr-3">Revisión</th>
                            <th class="py-2 pr-3">No encontradas</th>
                            <th class="py-2 pr-3">Subido por</th>
                            <th class="py-2 pr-3">Fecha</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="i in importaciones.data"
                            :key="i.id"
                            class="border-t border-[var(--mrl-borde)]"
                        >
                            <td class="py-2 pr-3">{{ i.nombre_archivo }}</td>
                            <td class="py-2 pr-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :class="
                                        i.estado === 'aplicado'
                                            ? 'bg-success/10 text-success'
                                            : 'bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)]'
                                    "
                                    >{{
                                        i.estado === 'aplicado'
                                            ? 'Aplicada'
                                            : 'Previsualizada'
                                    }}</span
                                >
                            </td>
                            <td class="py-2 pr-3">{{ i.total_filas }}</td>
                            <td class="py-2 pr-3">{{ i.filas_aplicadas }}</td>
                            <td class="py-2 pr-3">{{ i.filas_revision }}</td>
                            <td class="py-2 pr-3">
                                {{ i.filas_no_encontradas }}
                            </td>
                            <td class="py-2 pr-3">{{ i.subido_por ?? '—' }}</td>
                            <td class="py-2 pr-3">
                                {{ formatearFecha(i.creado_en) }}
                            </td>
                            <td class="py-2 text-right">
                                <div class="flex justify-end gap-2">
                                    <Link :href="mostrarImportacion.url(i.id)">
                                        <Button variant="outline" size="sm"
                                            >Ver detalle</Button
                                        >
                                    </Link>
                                    <Button
                                        v-if="i.estado !== 'aplicado'"
                                        variant="ghost"
                                        size="sm"
                                        class="text-destructive"
                                        :aria-label="`Quitar ${i.nombre_archivo}`"
                                        @click="quitar(i)"
                                    >
                                        <Trash2 class="size-4" /> Quitar
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="importaciones.data.length === 0">
                            <td
                                colspan="9"
                                class="py-6 text-center text-sm text-muted-foreground"
                            >
                                Todavía no se ha subido ningún archivo.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
