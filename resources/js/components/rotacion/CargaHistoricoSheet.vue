<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, Download, Upload } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import SelectorVista from '@/components/ui/SelectorVista.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import { numero } from '@/lib/rotacion';
import { vTablaTarjetas } from '@/lib/tablaTarjetas';
import type { Tono } from '@/lib/tonos';
import {
    analizar as analizarRuta,
    aplicar as aplicarRuta,
    formato as formatoRuta,
} from '@/routes/reportes/rotacion/tablero/historico';
import type {
    EstadoFilaImportacionHistorico,
    ImportacionHistoricoRotacion,
} from '@/types';

/**
 * Carga del histórico individual de bajas (solo rotacion.cargar): formato →
 * subir → vista previa (errores bloquean la fila; avisos se cargan para
 * revisión) → confirmar con motivo. No crea colaboradores, sucursales ni
 * puestos.
 */
const props = defineProps<{
    importacion: ImportacionHistoricoRotacion | null;
}>();

const abierto = defineModel<boolean>('open', { required: true });

const archivos = ref<File[]>([]);
const errorArchivo = ref('');
const analizando = ref(false);

function analizar(): void {
    const archivo = archivos.value[0];

    if (!archivo) {
        errorArchivo.value = 'Selecciona el archivo de Excel.';

        return;
    }

    router.post(
        analizarRuta.url(),
        { archivo },
        {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => {
                analizando.value = true;
                errorArchivo.value = '';
            },
            onFinish: () => {
                analizando.value = false;
            },
            onError: (e) => {
                errorArchivo.value = e.archivo ?? Object.values(e)[0] ?? '';
            },
            onSuccess: () => {
                archivos.value = [];
            },
        },
    );
}

/* --- Vista previa ------------------------------------------------------- */

const ESTADOS: Record<
    EstadoFilaImportacionHistorico,
    { etiqueta: string; tono: Tono }
> = {
    nuevo: { etiqueta: 'Nueva', tono: 'exito' },
    duplicado_sistema: { etiqueta: 'Ya en el sistema (se liga)', tono: 'info' },
    sin_cambio: { etiqueta: 'Ya cargada', tono: 'neutro' },
    error: { etiqueta: 'Error', tono: 'peligro' },
};

type Vista = 'todas' | 'errores' | 'avisos';
const vista = ref<Vista>('todas');

const filas = computed(() => props.importacion?.detalle ?? []);
const conteo = computed(() => ({
    nuevas: filas.value.filter((f) => f.estado === 'nuevo').length,
    ligadas: filas.value.filter((f) => f.estado === 'duplicado_sistema').length,
    sinCambio: filas.value.filter((f) => f.estado === 'sin_cambio').length,
    errores: filas.value.filter((f) => f.estado === 'error').length,
    avisos: filas.value.filter((f) => f.avisos.length > 0).length,
}));
const visibles = computed(() =>
    vista.value === 'errores'
        ? filas.value.filter((f) => f.estado === 'error')
        : vista.value === 'avisos'
          ? filas.value.filter((f) => f.avisos.length > 0)
          : filas.value,
);
const vistas = computed(() => [
    { valor: 'todas' as const, etiqueta: 'Todas' },
    {
        valor: 'errores' as const,
        etiqueta: 'Con error',
        contador: conteo.value.errores,
    },
    {
        valor: 'avisos' as const,
        etiqueta: 'Con aviso',
        contador: conteo.value.avisos,
    },
]);

const pendiente = computed(
    () => props.importacion !== null && props.importacion.estado !== 'aplicado',
);
const porCargar = computed(() => conteo.value.nuevas + conteo.value.ligadas);

const motivo = ref('');
const errorMotivo = ref('');
const aplicando = ref(false);

watch(
    () => props.importacion?.id,
    (id) => {
        if (id) {
            abierto.value = true;
            vista.value = conteo.value.errores > 0 ? 'errores' : 'todas';
            motivo.value = '';
        }
    },
    { immediate: true },
);

function aplicar(): void {
    if (!props.importacion) {
        return;
    }

    router.post(
        aplicarRuta.url(props.importacion.id),
        { motivo: motivo.value },
        {
            preserveScroll: true,
            onStart: () => {
                aplicando.value = true;
                errorMotivo.value = '';
            },
            onFinish: () => {
                aplicando.value = false;
            },
            onError: (e) => {
                errorMotivo.value = e.motivo ?? Object.values(e)[0] ?? '';
            },
            onSuccess: () => {
                abierto.value = false;
            },
        },
    );
}
</script>

<template>
    <Sheet v-model:open="abierto">
        <SheetContent class="w-full sm:max-w-3xl">
            <SheetHeader>
                <SheetTitle>Cargar histórico de bajas</SheetTitle>
                <SheetDescription>
                    Una fila por baja de periodos anteriores. Nada se guarda
                    hasta que confirmes la vista previa.
                </SheetDescription>
            </SheetHeader>

            <div class="flex flex-col gap-5 px-4 pb-6">
                <ol class="flex flex-col gap-4">
                    <li class="flex flex-col gap-2">
                        <p class="text-sm font-medium">
                            1. Descarga el formato
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Columnas: nombre completo, número de empleado,
                            sucursal, puesto, área, fecha de ingreso, último día
                            laborado, motivo original y causal homologada.
                        </p>
                        <Button variant="outline" class="w-fit" as-child>
                            <a :href="formatoRuta.url()"
                                ><Download class="size-4" />Formato de Excel</a
                            >
                        </Button>
                    </li>
                    <li class="flex flex-col gap-2">
                        <p class="text-sm font-medium">2. Súbelo lleno</p>
                        <PeopleFileDropzone
                            v-model="archivos"
                            accept=".xlsx"
                            :max-size-mb="10"
                            :loading="analizando"
                            label="Arrastra aquí el Excel del histórico"
                            @error="(m) => (errorArchivo = m)"
                        />
                        <InputError :message="errorArchivo" />
                        <Button
                            class="w-fit"
                            :disabled="!archivos.length || analizando"
                            @click="analizar"
                        >
                            <Spinner v-if="analizando" class="size-4" />
                            <Upload v-else class="size-4" />
                            Revisar archivo
                        </Button>
                    </li>
                </ol>

                <section
                    v-if="importacion"
                    class="flex flex-col gap-3 border-t border-border/60 pt-4"
                    aria-labelledby="titulo-vista-previa"
                >
                    <div>
                        <h3
                            id="titulo-vista-previa"
                            class="text-sm font-medium"
                        >
                            3. Vista previa
                            <span class="font-normal text-muted-foreground"
                                >· {{ importacion.nombre_archivo }}</span
                            >
                        </h3>
                        <p
                            v-if="!pendiente"
                            class="mt-1 flex items-center gap-1.5 text-xs text-success"
                        >
                            <CheckCircle2 class="size-3.5" /> Esta carga ya se
                            aplicó.
                        </p>
                    </div>

                    <dl class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                        <div class="rounded-xl bg-success-soft p-2.5">
                            <dt>Nuevas</dt>
                            <dd class="text-lg font-bold tabular-nums">
                                {{ numero(conteo.nuevas) }}
                            </dd>
                        </div>
                        <div class="rounded-xl bg-info-soft p-2.5">
                            <dt>Se ligan al sistema</dt>
                            <dd class="text-lg font-bold tabular-nums">
                                {{ numero(conteo.ligadas) }}
                            </dd>
                        </div>
                        <div class="rounded-xl bg-muted p-2.5">
                            <dt>Ya cargadas</dt>
                            <dd class="text-lg font-bold tabular-nums">
                                {{ numero(conteo.sinCambio) }}
                            </dd>
                        </div>
                        <div class="rounded-xl bg-danger-soft p-2.5">
                            <dt>Con error</dt>
                            <dd class="text-lg font-bold tabular-nums">
                                {{ numero(conteo.errores) }}
                            </dd>
                        </div>
                    </dl>

                    <SelectorVista
                        v-model="vista"
                        :opciones="vistas"
                        etiqueta="Filas de la vista previa"
                    />

                    <div
                        class="max-h-[45svh] tabla-scroll rounded-2xl border border-border/60"
                    >
                        <table
                            v-tabla-tarjetas
                            class="w-full text-left text-xs"
                        >
                            <thead
                                class="sticky top-0 bg-crema text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-2 py-2 font-medium">Fila</th>
                                    <th class="px-2 py-2 font-medium">
                                        Nombre
                                    </th>
                                    <th class="px-2 py-2 font-medium">
                                        Sucursal
                                    </th>
                                    <th class="px-2 py-2 font-medium">
                                        Último día
                                    </th>
                                    <th class="px-2 py-2 font-medium">
                                        Causal
                                    </th>
                                    <th class="px-2 py-2 font-medium">
                                        Estado
                                    </th>
                                    <th class="px-2 py-2 font-medium">
                                        Observaciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="f in visibles"
                                    :key="f.fila"
                                    class="border-t border-border/40 align-top"
                                >
                                    <td class="px-2 py-1.5 tabular-nums">
                                        {{ f.fila }}
                                    </td>
                                    <td class="px-2 py-1.5">
                                        {{ f.nombre || '—' }}
                                        <span
                                            v-if="f.numero_empleado"
                                            class="block text-[11px] text-muted-foreground"
                                            >No. {{ f.numero_empleado }}</span
                                        >
                                    </td>
                                    <td class="px-2 py-1.5">
                                        {{ f.sucursal || '—' }}
                                    </td>
                                    <td class="px-2 py-1.5 whitespace-nowrap">
                                        {{ formatearFecha(f.fecha_baja) }}
                                    </td>
                                    <td class="px-2 py-1.5">{{ f.causal }}</td>
                                    <td class="px-2 py-1.5">
                                        <ChipEstado
                                            :etiqueta="
                                                ESTADOS[f.estado].etiqueta
                                            "
                                            :tono="ESTADOS[f.estado].tono"
                                        />
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <p
                                            v-for="e in f.errores"
                                            :key="e"
                                            class="text-destructive"
                                        >
                                            {{ e }}
                                        </p>
                                        <p
                                            v-for="a in f.avisos"
                                            :key="a"
                                            class="text-warning"
                                        >
                                            {{ a }}
                                        </p>
                                        <span
                                            v-if="
                                                !f.errores.length &&
                                                !f.avisos.length
                                            "
                                            class="text-muted-foreground"
                                            >—</span
                                        >
                                    </td>
                                </tr>
                                <tr v-if="!visibles.length">
                                    <td
                                        colspan="7"
                                        class="px-2 py-6 text-center text-muted-foreground"
                                    >
                                        No hay filas en esta vista.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <form
                        v-if="pendiente"
                        class="flex flex-col gap-3 rounded-2xl bg-crema/40 p-3"
                        @submit.prevent="aplicar"
                    >
                        <p
                            v-if="conteo.errores"
                            class="flex items-start gap-1.5 text-xs text-warning"
                        >
                            <AlertTriangle class="mt-px size-3.5 shrink-0" />
                            Las {{ conteo.errores }} fila(s) con error no se
                            cargan. Corrígelas en el Excel y vuelve a subirlo si
                            las necesitas.
                        </p>
                        <div>
                            <Label for="motivo-carga" class="mb-1.5"
                                >Motivo de la carga</Label
                            >
                            <Textarea
                                id="motivo-carga"
                                v-model="motivo"
                                rows="2"
                                maxlength="400"
                                placeholder="Por ejemplo: histórico 2024–2025 entregado por RH."
                            />
                            <InputError :message="errorMotivo" />
                        </div>
                        <Button
                            type="submit"
                            :disabled="
                                aplicando ||
                                porCargar === 0 ||
                                motivo.trim().length < 5
                            "
                        >
                            <Spinner v-if="aplicando" class="size-4" />
                            Confirmar carga de {{ numero(porCargar) }}
                            {{ porCargar === 1 ? 'baja' : 'bajas' }}
                        </Button>
                    </form>
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>
