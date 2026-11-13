<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Check, Download, FileSpreadsheet, Loader2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { vTablaTarjetas } from '@/lib/tablaTarjetas';
import type { Tono } from '@/lib/tonos';
import { formatoMoneda } from '@/lib/utils';
import { index } from '@/routes/administracion/puestos';
import { analizar, aplicar, formato } from '@/routes/salarios/importar';
import type { ImportacionTabulador } from '@/types';

/**
 * Carga del tabulador desde Excel (solo super administración): se sube el
 * formato, se revisa la vista previa fila por fila y solo entonces se
 * confirma con motivo. Nada se guarda antes de confirmar; ningún sueldo
 * real cambia.
 */
const props = defineProps<{ importacion: ImportacionTabulador | null }>();

const archivos = ref<File[]>([]);
const formArchivo = useForm<{ archivo: File | null }>({ archivo: null });
const formAplicar = useForm({ motivo: '' });

watch(archivos, (lista) => {
    const archivo = lista[0] ?? null;

    if (archivo) {
        formArchivo.archivo = archivo;
        formArchivo.post(analizar.url(), {
            forceFormData: true,
            onFinish: () => (archivos.value = []),
        });
    }
});

const errorAplicar = computed(
    () => (formAplicar.errors as Record<string, string>).importacion,
);

const ESTADOS: Record<string, { etiqueta: string; tono: Tono }> = {
    nuevo: { etiqueta: 'Nuevo', tono: 'info' },
    cambio: { etiqueta: 'Con cambios', tono: 'exito' },
    sin_cambio: { etiqueta: 'Sin cambios', tono: 'neutro' },
    error: { etiqueta: 'Error', tono: 'peligro' },
};

const aplicable = computed(
    () =>
        props.importacion !== null &&
        props.importacion.estado === 'previsualizado' &&
        props.importacion.filas_error === 0 &&
        props.importacion.filas_revision > 0,
);

function descartar(): void {
    router.get(index.url(), {}, { preserveScroll: true });
}
</script>

<template>
    <section class="flex flex-col gap-4 rounded-3xl border bg-card p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <span
                    class="grid size-10 shrink-0 place-items-center rounded-2xl bg-success-soft text-success"
                >
                    <FileSpreadsheet class="size-5" />
                </span>
                <div>
                    <h2 class="font-semibold">Cargar tabulador desde Excel</h2>
                    <p class="text-sm text-muted-foreground">
                        Descarga el formato, llénalo y súbelo. Verás una vista
                        previa antes de guardar.
                    </p>
                </div>
            </div>
            <Button variant="outline" as-child>
                <a :href="formato.url()"
                    ><Download class="size-4" /> Descargar formato</a
                >
            </Button>
        </div>

        <PeopleFileDropzone
            v-if="!importacion"
            v-model="archivos"
            accept=".xlsx"
            :max-size-mb="10"
            :loading="formArchivo.processing"
            label="Arrastra aquí el Excel del tabulador"
        />
        <InputError :message="formArchivo.errors.archivo" />

        <template v-if="importacion">
            <div
                class="flex flex-wrap items-center gap-2 rounded-2xl bg-crema/40 px-3 py-2.5 text-sm"
            >
                <FileSpreadsheet class="size-4 text-success" />
                <span class="font-medium">{{
                    importacion.nombre_archivo
                }}</span>
                <ChipEstado
                    :etiqueta="`${importacion.filas_revision} con cambios`"
                    tono="exito"
                />
                <ChipEstado
                    :etiqueta="`${importacion.filas_sin_cambios} sin cambios`"
                />
                <ChipEstado
                    v-if="importacion.filas_error"
                    :etiqueta="`${importacion.filas_error} con error`"
                    tono="peligro"
                />
                <Button
                    variant="ghost"
                    size="sm"
                    class="ml-auto"
                    @click="descartar"
                >
                    <X class="size-4" /> Cerrar vista previa
                </Button>
            </div>

            <div class="tabla-scroll rounded-2xl border">
                <table v-tabla-tarjetas class="w-full min-w-[640px] text-sm">
                    <thead class="bg-crema text-left text-xs">
                        <tr>
                            <th class="p-2.5">Fila</th>
                            <th class="p-2.5">Puesto</th>
                            <th class="p-2.5 text-right">Mínimo</th>
                            <th class="p-2.5 text-right">Medio</th>
                            <th class="p-2.5 text-right">Máximo</th>
                            <th class="p-2.5">Resultado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="fila in importacion.detalle"
                            :key="fila.fila"
                            class="border-t"
                        >
                            <td
                                class="p-2.5 text-muted-foreground tabular-nums"
                            >
                                {{ fila.fila }}
                            </td>
                            <td class="p-2.5 font-medium">{{ fila.puesto }}</td>
                            <td class="p-2.5 text-right tabular-nums">
                                {{
                                    fila.minimo === null
                                        ? '—'
                                        : formatoMoneda(fila.minimo)
                                }}
                            </td>
                            <td
                                class="p-2.5 text-right font-semibold tabular-nums"
                            >
                                {{
                                    fila.medio === null
                                        ? '—'
                                        : formatoMoneda(fila.medio)
                                }}
                            </td>
                            <td class="p-2.5 text-right tabular-nums">
                                {{
                                    fila.maximo === null
                                        ? '—'
                                        : formatoMoneda(fila.maximo)
                                }}
                            </td>
                            <td class="p-2.5">
                                <p
                                    v-if="fila.errores.length"
                                    class="text-xs text-destructive"
                                >
                                    {{ fila.errores.join(' ') }}
                                </p>
                                <ChipEstado
                                    v-else
                                    :etiqueta="
                                        ESTADOS[fila.estado]?.etiqueta ??
                                        fila.estado
                                    "
                                    :tono="
                                        ESTADOS[fila.estado]?.tono ?? 'neutro'
                                    "
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form
                v-if="aplicable"
                class="flex flex-col gap-2 sm:flex-row sm:items-start"
                @submit.prevent="formAplicar.post(aplicar.url(importacion.id))"
            >
                <div class="min-w-0 flex-1">
                    <Input
                        v-model="formAplicar.motivo"
                        class="h-11 rounded-xl"
                        placeholder="Motivo de la carga (obligatorio)"
                    />
                    <InputError :message="formAplicar.errors.motivo" />
                    <InputError :message="errorAplicar" />
                </div>
                <Button
                    type="submit"
                    class="h-11"
                    :disabled="formAplicar.processing"
                >
                    <Loader2
                        v-if="formAplicar.processing"
                        class="size-4 animate-spin"
                    />
                    <Check v-else class="size-4" />
                    Confirmar carga
                </Button>
            </form>
            <p
                v-else-if="importacion.filas_error"
                class="rounded-xl bg-danger-soft px-3 py-2 text-xs"
            >
                Corrige las filas con error en el Excel y vuelve a subirlo.
            </p>
        </template>
    </section>
</template>
