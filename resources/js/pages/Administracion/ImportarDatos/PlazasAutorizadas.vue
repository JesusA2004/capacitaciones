<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Download, LayoutGrid, Upload } from '@lucide/vue';
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
    index,
    plantilla,
} from '@/routes/administracion/importar-datos/plazas-autorizadas';
import type { SegmentoPoblacion } from '@/types/cicloLaboral';

type Poblacion = {
    total: SegmentoPoblacion;
    corporativo: SegmentoPoblacion;
    sucursales: SegmentoPoblacion;
};
type Fila = {
    fila: number;
    sucursal: string;
    puesto: string;
    anterior: number | null;
    nuevo: number | null;
    ocupadas: number | null;
    estado: 'nuevo' | 'cambio' | 'sin_cambio' | 'error';
    errores: string[];
    avisos: string[];
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
    metadatos: {
        columnas_ignoradas: string[];
        no_incluidas: { sucursal: string; puesto: string; plazas: number }[];
        conciliacion: { antes: Poblacion; despues: Poblacion };
    };
};

const props = defineProps<{
    poblacion: Poblacion;
    importacion: Importacion | null;
    recientes: {
        id: number;
        nombre_archivo: string;
        estado: string;
        subido_por: string;
        aplicado_por: string | null;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Importar datos', href: importarDatosIndex() },
            { title: 'Plazas autorizadas', href: '' },
        ],
    },
});

const formArchivo = useForm<{ archivo: File | null }>({ archivo: null });
const formAplicar = useForm({ motivo: '' });

function alElegir(evento: Event) {
    formArchivo.archivo =
        (evento.target as HTMLInputElement).files?.[0] ?? null;
}

function enviarArchivo() {
    formArchivo.post(analizar().url, { forceFormData: true });
}

function confirmar() {
    if (props.importacion) {
        formAplicar.post(aplicar(props.importacion.id).url, {
            preserveScroll: true,
        });
    }
}

const ETIQUETA_ESTADO: Record<Fila['estado'], string> = {
    nuevo: 'Nueva',
    cambio: 'Cambia',
    sin_cambio: 'Sin cambio',
    error: 'Error',
};
const CLASE_ESTADO: Record<Fila['estado'], string> = {
    nuevo: 'bg-info-soft text-info',
    cambio: 'bg-warning-soft text-warning',
    sin_cambio: 'bg-muted text-muted-foreground',
    error: 'bg-destructive/10 text-destructive',
};

const SEGMENTOS = [
    { clave: 'corporativo', etiqueta: 'Corporativo' },
    { clave: 'sucursales', etiqueta: 'Sucursales' },
    { clave: 'total', etiqueta: 'Total' },
] as const;

const renglonesConciliacion = computed(() => {
    const conciliacion = props.importacion?.metadatos.conciliacion;

    return conciliacion
        ? SEGMENTOS.map((s) => ({
              ...s,
              antes: conciliacion.antes[s.clave],
              despues: conciliacion.despues[s.clave],
          }))
        : [];
});

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
    <Head title="Plazas autorizadas" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader titulo="Plazas autorizadas" :icono="LayoutGrid" />

        <section
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
            aria-label="Plantilla actual"
        >
            <TarjetaMetrica
                etiqueta="Autorizadas"
                :valor="poblacion.total.autorizadas"
                tono="primario"
                :detalle="`Corporativo ${poblacion.corporativo.autorizadas} · Sucursales ${poblacion.sucursales.autorizadas}`"
            />
            <TarjetaMetrica
                etiqueta="Personas activas"
                :valor="poblacion.total.activos"
                tono="info"
                :detalle="`Corporativo ${poblacion.corporativo.activos} · Sucursales ${poblacion.sucursales.activos}`"
            />
            <TarjetaMetrica
                etiqueta="Vacantes"
                :valor="poblacion.total.vacantes"
                tono="oro"
                :detalle="`Corporativo ${poblacion.corporativo.vacantes} · Sucursales ${poblacion.sucursales.vacantes}`"
            />
            <TarjetaMetrica
                etiqueta="Sin plaza"
                :valor="
                    poblacion.total.excedentes +
                    poblacion.total.fuera_de_plantilla
                "
                tono="alerta"
                :detalle="`${poblacion.total.excedentes} excedentes · ${poblacion.total.fuera_de_plantilla} fuera de plantilla`"
            />
        </section>

        <section
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 shadow-sm"
        >
            <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                Subir catálogo autorizado
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Una fila por sucursal y puesto, con Corporativo como una
                ubicación más. Las plazas que no vengan en el archivo no se
                modifican.
            </p>
            <form
                class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center"
                @submit.prevent="enviarArchivo"
            >
                <input
                    type="file"
                    accept=".xlsx"
                    aria-label="Archivo de plazas autorizadas"
                    class="min-w-0 text-sm"
                    @change="alElegir"
                />
                <Button
                    type="submit"
                    :disabled="!formArchivo.archivo || formArchivo.processing"
                >
                    <Upload class="size-4" /> Analizar
                </Button>
                <Button variant="outline" as-child>
                    <a :href="plantilla().url">
                        <Download class="size-4" /> Descargar formato
                    </a>
                </Button>
            </form>
            <InputError :message="formArchivo.errors.archivo" class="mt-2" />
        </section>

        <section
            v-if="importacion"
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 shadow-sm"
        >
            <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                {{ importacion.nombre_archivo }}
                <span class="font-normal text-muted-foreground">
                    ·
                    {{
                        importacion.estado === 'aplicado'
                            ? `aplicada (${importacion.filas_aplicadas} filas)`
                            : 'vista previa, nada guardado todavía'
                    }}
                </span>
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ importacion.total_filas }} filas:
                {{ importacion.filas_revision }} con cambio,
                {{ importacion.filas_sin_cambios }} sin cambio y
                {{ importacion.filas_error }} con error.
            </p>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[32rem] text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1.5 pr-3 font-medium">
                                Conciliación
                            </th>
                            <th class="py-1.5 pr-3 font-medium">Autorizadas</th>
                            <th class="py-1.5 pr-3 font-medium">Ocupadas</th>
                            <th class="py-1.5 pr-3 font-medium">Vacantes</th>
                            <th class="py-1.5 font-medium">Sin plaza</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--mrl-borde)]">
                        <tr v-for="r in renglonesConciliacion" :key="r.clave">
                            <td class="py-1.5 pr-3 font-medium">
                                {{ r.etiqueta }}
                            </td>
                            <td class="py-1.5 pr-3 tabular-nums">
                                {{ r.antes.autorizadas }} →
                                {{ r.despues.autorizadas }}
                            </td>
                            <td class="py-1.5 pr-3 tabular-nums">
                                {{ r.antes.ocupadas }} →
                                {{ r.despues.ocupadas }}
                            </td>
                            <td class="py-1.5 pr-3 tabular-nums">
                                {{ r.antes.vacantes }} →
                                {{ r.despues.vacantes }}
                            </td>
                            <td class="py-1.5 tabular-nums">
                                {{
                                    r.antes.excedentes +
                                    r.antes.fuera_de_plantilla
                                }}
                                →
                                {{
                                    r.despues.excedentes +
                                    r.despues.fuera_de_plantilla
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[44rem] text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1.5 pr-3 font-medium">Fila</th>
                            <th class="py-1.5 pr-3 font-medium">Sucursal</th>
                            <th class="py-1.5 pr-3 font-medium">Puesto</th>
                            <th class="py-1.5 pr-3 font-medium">Actual</th>
                            <th class="py-1.5 pr-3 font-medium">Nueva</th>
                            <th class="py-1.5 pr-3 font-medium">Personas</th>
                            <th class="py-1.5 font-medium">Resultado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--mrl-borde)]">
                        <tr
                            v-for="f in importacion.detalle"
                            :key="f.fila"
                            class="align-top"
                        >
                            <td class="py-1.5 pr-3 tabular-nums">
                                {{ f.fila }}
                            </td>
                            <td class="py-1.5 pr-3">{{ f.sucursal }}</td>
                            <td class="py-1.5 pr-3">{{ f.puesto }}</td>
                            <td class="py-1.5 pr-3 tabular-nums">
                                {{ f.anterior ?? '—' }}
                            </td>
                            <td class="py-1.5 pr-3 tabular-nums">
                                {{ f.nuevo ?? '—' }}
                            </td>
                            <td class="py-1.5 pr-3 tabular-nums">
                                {{ f.ocupadas ?? '—' }}
                            </td>
                            <td class="py-1.5">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="CLASE_ESTADO[f.estado]"
                                    >{{ ETIQUETA_ESTADO[f.estado] }}</span
                                >
                                <p
                                    v-for="e in f.errores"
                                    :key="e"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ e }}
                                </p>
                                <p
                                    v-for="a in f.avisos"
                                    :key="a"
                                    class="mt-1 text-xs text-warning"
                                >
                                    {{ a }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p
                v-if="importacion.metadatos.columnas_ignoradas.length > 0"
                class="mt-3 text-sm text-muted-foreground"
            >
                Columnas que no se leyeron:
                {{ importacion.metadatos.columnas_ignoradas.join(', ') }}.
            </p>

            <div
                v-if="importacion.metadatos.no_incluidas.length > 0"
                class="mt-3 rounded-xl bg-crema/40 p-3 text-sm"
            >
                <p class="font-medium">
                    Plazas vigentes que el archivo no menciona (se conservan):
                </p>
                <ul class="mt-1 list-inside list-disc text-muted-foreground">
                    <li
                        v-for="n in importacion.metadatos.no_incluidas"
                        :key="`${n.sucursal}-${n.puesto}`"
                    >
                        {{ n.sucursal }} / {{ n.puesto }}: {{ n.plazas }}
                    </li>
                </ul>
            </div>

            <form
                v-if="puedeAplicar"
                class="mt-4 flex flex-col gap-2"
                @submit.prevent="confirmar"
            >
                <label for="motivo" class="text-sm font-medium">
                    ¿Quién autorizó estas plazas o por qué cambian?
                </label>
                <Textarea id="motivo" v-model="formAplicar.motivo" rows="2" />
                <InputError :message="formAplicar.errors.motivo" />
                <div>
                    <Button type="submit" :disabled="formAplicar.processing">
                        Confirmar y aplicar
                    </Button>
                </div>
            </form>
            <p
                v-else-if="
                    importacion.estado === 'previsualizado' &&
                    importacion.filas_error > 0
                "
                class="mt-4 text-sm text-destructive"
            >
                Corrige las filas con error y vuelve a subir el archivo.
            </p>
            <InputError :message="errorAplicar" class="mt-2" />
        </section>

        <section
            v-if="recientes.length > 0"
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 shadow-sm"
        >
            <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                Importaciones recientes
            </h2>
            <ul class="mt-2 divide-y divide-[var(--mrl-borde)] text-sm">
                <li
                    v-for="r in recientes"
                    :key="r.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                >
                    <Link
                        :href="index({ query: { importacion: r.id } }).url"
                        class="min-w-0 truncate text-primary hover:underline"
                        >{{ r.nombre_archivo }}</Link
                    >
                    <span class="text-xs text-muted-foreground">
                        {{
                            r.estado === 'aplicado'
                                ? `Aplicada por ${r.aplicado_por ?? '—'}`
                                : `Vista previa de ${r.subido_por}`
                        }}
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
