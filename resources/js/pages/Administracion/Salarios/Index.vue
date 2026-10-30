<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Banknote,
    Download,
    History,
    Lock,
    Pencil,
    TriangleAlert,
    Upload,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatoMoneda } from '@/lib/utils';
import { index as salariosIndex } from '@/routes/salarios';
import { excel, pdf } from '@/routes/salarios/exportar';
import {
    analizar,
    aplicar,
    formato,
} from '@/routes/salarios/importar';
import { guardar as guardarMatrizRuta } from '@/routes/salarios/matriz';
import { guardar as guardarTabuladorRuta } from '@/routes/salarios/tabulador';

type Renglon = {
    puesto_id: number;
    puesto: string;
    minimo: number | null;
    medio: number | null;
    maximo: number | null;
    fuente: string | null;
    fecha_actualizacion: string | null;
    version: number | null;
    colaboradores: number;
    compa_ratio: number | null;
};
type Colaborador = {
    id: number;
    nombre: string;
    sucursal: string | null;
    sueldo: number | null;
    compa_ratio: number | null;
    banda: Banda | null;
    banda_etiqueta: string | null;
    desempeno: string | null;
    desempeno_etiqueta: string;
    tope: number | null;
    margen: number | null;
    sugerido: number | 'bono' | null;
};
type Banda =
    'bajo_minimo' | 'minimo_a_medio' | 'medio_a_maximo' | 'sobre_maximo';
type FilaImportacion = {
    fila: number;
    puesto: string;
    minimo: number | null;
    medio: number | null;
    maximo: number | null;
    fuente: string;
    fecha_actualizacion: string | null;
    estado: 'nuevo' | 'cambio' | 'sin_cambio' | 'error';
    errores: string[];
};

const props = defineProps<{
    resumen: {
        puestos_total: number;
        puestos_con_tabulador: number;
        puestos_sin_tabulador: number;
        compa_ratio_promedio: number | null;
        bajo_minimo: number;
        sobre_maximo: number;
        margen: number | null;
        con_evaluacion: number;
        colaboradores: number;
        sin_sueldo: number;
    };
    tabulador: Renglon[];
    puestoId: number | null;
    rango: { minimo: number; medio: number; maximo: number } | null;
    colaboradores: Colaborador[];
    versiones: {
        version: number;
        minimo: number;
        medio: number;
        maximo: number;
        fuente: string;
        fecha_actualizacion: string;
        vigente_desde: string;
        vigente_hasta: string | null;
        origen: string;
        usuario: string | null;
    }[];
    matriz: Record<string, Record<string, number | string>> | null;
    historialMatriz: {
        id: number;
        vigente_desde: string;
        vigente_hasta: string | null;
        motivo: string | null;
        usuario: string | null;
    }[];
    sucursales: { id: number; nombre: string }[];
    sucursalId: number | null;
    puede: { editar: boolean; exportar: boolean; importar: boolean };
    importacion: {
        id: number;
        nombre_archivo: string;
        estado: string;
        total_filas: number;
        filas_revision: number;
        filas_sin_cambios: number;
        filas_error: number;
        detalle: FilaImportacion[];
    } | null;
}>();

const NIVELES = [
    { clave: 'sobresaliente', etiqueta: 'Sobresaliente' },
    { clave: 'satisfactorio', etiqueta: 'Satisfactorio' },
    { clave: 'bajo', etiqueta: 'Bajo' },
];
const BANDAS: { clave: Banda; etiqueta: string }[] = [
    { clave: 'bajo_minimo', etiqueta: 'Bajo mínimo' },
    { clave: 'minimo_a_medio', etiqueta: 'Mínimo a medio' },
    { clave: 'medio_a_maximo', etiqueta: 'Medio a máximo' },
    { clave: 'sobre_maximo', etiqueta: 'Sobre máximo' },
];
const CLASE_BANDA: Record<Banda, string> = {
    bajo_minimo: 'bg-danger-soft text-destructive',
    minimo_a_medio: 'bg-info-soft text-info',
    medio_a_maximo: 'bg-success-soft text-success',
    sobre_maximo: 'bg-warning-soft text-warning',
};

const entero = (valor: number | null): string =>
    valor === null ? 'Sin dato' : `${Math.round(valor)}%`;
const moneda = (valor: number | null): string =>
    valor === null ? '—' : formatoMoneda(valor);

const puestoSeleccionado = computed(
    () => props.tabulador.find((r) => r.puesto_id === props.puestoId) ?? null,
);

function navegar(cambios: {
    puesto?: number | null;
    sucursal?: number | null;
}): void {
    router.get(
        salariosIndex.url({
            query: {
                puesto: cambios.puesto ?? props.puestoId ?? undefined,
                sucursal:
                    (cambios.sucursal === undefined
                        ? props.sucursalId
                        : cambios.sucursal) ?? undefined,
            },
        }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

/* Captura en línea del tabulador. */
const editando = ref<number | null>(null);
const formTabulador = useForm({
    minimo: '' as string | number,
    medio: '' as string | number,
    maximo: '' as string | number,
    fuente: '',
    fecha_actualizacion: new Date().toLocaleDateString('en-CA'),
});

function editar(renglon: Renglon): void {
    formTabulador.clearErrors();
    formTabulador.minimo = renglon.minimo ?? '';
    formTabulador.medio = renglon.medio ?? '';
    formTabulador.maximo = renglon.maximo ?? '';
    formTabulador.fuente = renglon.fuente ?? '';
    formTabulador.fecha_actualizacion =
        renglon.fecha_actualizacion ?? new Date().toLocaleDateString('en-CA');
    editando.value = renglon.puesto_id;
}

function guardarRenglon(renglon: Renglon): void {
    formTabulador.put(guardarTabuladorRuta.url(renglon.puesto_id), {
        preserveScroll: true,
        onSuccess: () => {
            editando.value = null;
        },
    });
}

/* Barra de posición: escala común del puesto, con holgura a los lados. */
const escala = computed(() => {
    if (!props.rango) {
        return null;
    }

    const sueldos = props.colaboradores
        .map((c) => c.sueldo)
        .filter((s): s is number => s !== null);
    const desde = Math.min(props.rango.minimo * 0.85, ...sueldos);
    const hasta = Math.max(props.rango.maximo * 1.15, ...sueldos);

    return { desde, hasta: hasta === desde ? desde + 1 : hasta };
});

function posicion(valor: number): string {
    const e = escala.value;

    return e ? `${((valor - e.desde) / (e.hasta - e.desde)) * 100}%` : '0%';
}

function iniciales(nombre: string): string {
    return nombre
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0])
        .join('')
        .toUpperCase();
}

/* Matriz de mérito. */
const editandoMatriz = ref(false);
const celdas = reactive<Record<string, Record<string, string>>>({});
const motivoMatriz = ref('');
const erroresMatriz = ref<string | null>(null);

function textoCelda(valor: number | string | undefined): string {
    if (valor === undefined) {
        return '—';
    }

    return valor === 'bono' ? 'Bono' : `${valor}%`;
}

function abrirMatriz(): void {
    for (const nivel of NIVELES) {
        celdas[nivel.clave] = {};

        for (const banda of BANDAS) {
            const valor = props.matriz?.[nivel.clave]?.[banda.clave];

            celdas[nivel.clave][banda.clave] =
                valor === 'bono' ? 'Bono' : String(valor ?? 0);
        }
    }

    editandoMatriz.value = true;
}

function guardarMatriz(): void {
    const payload: Record<string, Record<string, string>> = {};

    for (const nivel of NIVELES) {
        payload[nivel.clave] = {};

        for (const banda of BANDAS) {
            const valor = celdas[nivel.clave][banda.clave].trim();

            payload[nivel.clave][banda.clave] =
                valor.toLowerCase() === 'bono' ? 'bono' : valor;
        }
    }

    router.put(
        guardarMatrizRuta.url(),
        { celdas: payload, motivo: motivoMatriz.value || null },
        {
            preserveScroll: true,
            onError: (errores) => {
                erroresMatriz.value = errores.celdas ?? 'Revisa los valores.';
            },
            onSuccess: () => {
                erroresMatriz.value = null;
                editandoMatriz.value = false;
                motivoMatriz.value = '';
            },
        },
    );
}

/* Importación (solo super_admin). */
const formArchivo = useForm<{ archivo: File | null }>({ archivo: null });
const formAplicar = useForm({ motivo: '' });

function elegirArchivo(evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;

    if (archivo) {
        formArchivo.archivo = archivo;
        formArchivo.post(analizar.url(), { forceFormData: true });
    }
}

const errorAplicar = computed(
    () => (formAplicar.errors as Record<string, string>).importacion,
);
const verVersiones = ref(false);
const consultaExportar = computed(() => ({
    query: {
        vista: 'posiciones',
        puesto: props.puestoId ?? undefined,
        sucursal: props.sucursalId ?? undefined,
    },
}));
</script>

<template>
    <Head title="Administración de salarios" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Administración de salarios"
            :icono="Banknote"
        >
            <span
                class="inline-flex items-center gap-1 rounded-full bg-danger-soft px-3 py-1 text-xs font-medium text-destructive"
            >
                <Lock class="size-3" /> Acceso: RH y Dirección General
            </span>
            <template v-if="puede.exportar">
                <Button variant="outline" size="sm" as-child>
                    <a :href="excel.url()">Excel</a>
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <a :href="pdf.url()">PDF</a>
                </Button>
            </template>
        </CrudPageHeader>

        <section
            class="grid grid-cols-2 gap-3 lg:grid-cols-5"
            aria-label="Resumen"
        >
            <TarjetaMetrica
                etiqueta="Puestos con tabulador"
                :valor="`${resumen.puestos_con_tabulador} de ${resumen.puestos_total}`"
                tono="primario"
                :detalle="`${resumen.puestos_sin_tabulador} por capturar`"
            />
            <TarjetaMetrica
                etiqueta="Compa-ratio promedio"
                :valor="entero(resumen.compa_ratio_promedio)"
                tono="info"
                detalle="Plantilla con tabulador"
            />
            <TarjetaMetrica
                etiqueta="Bajo el mínimo"
                :valor="resumen.bajo_minimo"
                tono="peligro"
                detalle="Riesgo de rotación"
            />
            <TarjetaMetrica
                etiqueta="Sobre el máximo"
                :valor="resumen.sobre_maximo"
                tono="alerta"
                detalle="Solo bono"
            />
            <TarjetaMetrica
                etiqueta="Margen para incrementos"
                :valor="resumen.margen === null ? 'Sin dato' : moneda(resumen.margen)"
                tono="oro"
                :detalle="
                    resumen.margen === null
                        ? 'Falta evaluación de desempeño'
                        : `${resumen.con_evaluacion} con evaluación`
                "
            />
        </section>

        <!-- Vista previa de la importación -->
        <section
            v-if="importacion"
            class="rounded-2xl border bg-card p-4 shadow-sm"
        >
            <h2 class="font-semibold">
                Vista previa · {{ importacion.nombre_archivo }}
            </h2>
            <p class="text-xs text-muted-foreground">
                {{ importacion.filas_revision }} con cambios ·
                {{ importacion.filas_sin_cambios }} sin cambios ·
                {{ importacion.filas_error }} con error. Ningún sueldo real se
                modifica.
            </p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-crema text-left text-xs">
                        <tr>
                            <th class="p-2">Fila</th>
                            <th class="p-2">Puesto</th>
                            <th class="p-2 text-right">Mínimo</th>
                            <th class="p-2 text-right">Medio</th>
                            <th class="p-2 text-right">Máximo</th>
                            <th class="p-2">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="fila in importacion.detalle"
                            :key="fila.fila"
                            class="border-t"
                        >
                            <td class="p-2">{{ fila.fila }}</td>
                            <td class="p-2">{{ fila.puesto }}</td>
                            <td class="p-2 text-right">
                                {{ moneda(fila.minimo) }}
                            </td>
                            <td class="p-2 text-right">
                                {{ moneda(fila.medio) }}
                            </td>
                            <td class="p-2 text-right">
                                {{ moneda(fila.maximo) }}
                            </td>
                            <td class="p-2">
                                <span v-if="fila.errores.length" class="text-destructive">
                                    {{ fila.errores.join(' ') }}
                                </span>
                                <span v-else>{{ fila.estado }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <form
                v-if="
                    importacion.estado === 'previsualizado' &&
                    importacion.filas_error === 0 &&
                    importacion.filas_revision > 0
                "
                class="mt-3 flex flex-wrap items-start gap-2"
                @submit.prevent="formAplicar.post(aplicar.url(importacion.id))"
            >
                <div class="min-w-64 flex-1">
                    <Input
                        v-model="formAplicar.motivo"
                        placeholder="Motivo de la carga"
                    />
                    <InputError :message="formAplicar.errors.motivo" />
                    <InputError :message="errorAplicar" />
                </div>
                <Button type="submit" :disabled="formAplicar.processing">
                    Confirmar carga
                </Button>
            </form>
        </section>

        <div class="grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
            <!-- Tabulador -->
            <section class="min-w-0 rounded-2xl border bg-card p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 class="font-semibold">Tabulador de sueldos mensuales</h2>
                        <p class="text-xs text-muted-foreground">
                            Captura el mínimo, medio y máximo de cada puesto.
                        </p>
                    </div>
                    <div v-if="puede.importar" class="flex gap-2">
                        <Button variant="outline" size="sm" as-child>
                            <a :href="formato.url()">
                                <Download class="size-3.5" /> Formato
                            </a>
                        </Button>
                        <Button variant="outline" size="sm" as-child>
                            <label class="cursor-pointer">
                                <Upload class="size-3.5" /> Importar Excel
                                <input
                                    type="file"
                                    accept=".xlsx"
                                    class="sr-only"
                                    @change="elegirArchivo"
                                />
                            </label>
                        </Button>
                    </div>
                </div>
                <InputError class="mt-1" :message="formArchivo.errors.archivo" />

                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-crema text-left text-xs">
                            <tr>
                                <th class="p-2">Puesto</th>
                                <th class="p-2 text-right">Mínimo</th>
                                <th class="p-2 text-right">Medio</th>
                                <th class="p-2 text-right">Máximo</th>
                                <th class="p-2 text-center">Colab.</th>
                                <th class="p-2 text-center">Compa-ratio</th>
                                <th v-if="puede.editar" class="p-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template
                                v-for="renglon in tabulador"
                                :key="renglon.puesto_id"
                            >
                                <tr
                                    class="cursor-pointer border-t hover:bg-muted/50"
                                    :class="
                                        renglon.puesto_id === puestoId &&
                                        'bg-salvia'
                                    "
                                    @click="navegar({ puesto: renglon.puesto_id })"
                                >
                                    <td class="p-2 font-medium">
                                        {{ renglon.puesto }}
                                    </td>
                                    <template v-if="renglon.version !== null">
                                        <td class="p-2 text-right">
                                            {{ moneda(renglon.minimo) }}
                                        </td>
                                        <td class="p-2 text-right font-semibold">
                                            {{ moneda(renglon.medio) }}
                                        </td>
                                        <td class="p-2 text-right">
                                            {{ moneda(renglon.maximo) }}
                                        </td>
                                    </template>
                                    <td
                                        v-else
                                        colspan="3"
                                        class="p-2 text-center text-xs text-warning"
                                    >
                                        Sin capturar
                                    </td>
                                    <td class="p-2 text-center">
                                        {{ renglon.colaboradores }}
                                    </td>
                                    <td class="p-2 text-center">
                                        {{
                                            renglon.compa_ratio === null
                                                ? '—'
                                                : entero(renglon.compa_ratio)
                                        }}
                                    </td>
                                    <td v-if="puede.editar" class="p-1 text-right">
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            class="size-7"
                                            :aria-label="`Capturar ${renglon.puesto}`"
                                            @click.stop="editar(renglon)"
                                        >
                                            <Pencil class="size-3.5" />
                                        </Button>
                                    </td>
                                </tr>
                                <tr
                                    v-if="editando === renglon.puesto_id"
                                    class="bg-muted/40"
                                >
                                    <td colspan="7" class="p-3">
                                        <form
                                            class="grid grid-cols-3 gap-2 sm:grid-cols-6"
                                            @submit.prevent="guardarRenglon(renglon)"
                                        >
                                            <label class="text-xs">
                                                Mínimo
                                                <Input
                                                    v-model="formTabulador.minimo"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                />
                                            </label>
                                            <label class="text-xs">
                                                Medio
                                                <Input
                                                    v-model="formTabulador.medio"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                />
                                            </label>
                                            <label class="text-xs">
                                                Máximo
                                                <Input
                                                    v-model="formTabulador.maximo"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                />
                                            </label>
                                            <label class="col-span-2 text-xs">
                                                Fuente del estudio
                                                <Input v-model="formTabulador.fuente" />
                                            </label>
                                            <label class="text-xs">
                                                Actualización
                                                <Input
                                                    v-model="formTabulador.fecha_actualizacion"
                                                    type="date"
                                                />
                                            </label>
                                            <div class="col-span-full">
                                                <InputError :message="formTabulador.errors.minimo" />
                                                <InputError :message="formTabulador.errors.medio" />
                                                <InputError :message="formTabulador.errors.maximo" />
                                                <InputError :message="formTabulador.errors.fuente" />
                                                <InputError :message="formTabulador.errors.fecha_actualizacion" />
                                            </div>
                                            <div class="col-span-full flex justify-end gap-2">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    @click="editando = null"
                                                >
                                                    Cancelar
                                                </Button>
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    :disabled="formTabulador.processing"
                                                >
                                                    Guardar versión
                                                </Button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-muted-foreground">
                    Guardar crea una versión nueva. Ningún sueldo real cambia.
                </p>
            </section>

            <div class="flex min-w-0 flex-col gap-4">
                <!-- Posición de cada colaborador -->
                <section class="rounded-2xl border bg-card p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="font-semibold">
                            {{ puestoSeleccionado?.puesto ?? 'Selecciona un puesto' }}
                            · posición de cada colaborador
                        </h2>
                        <div class="flex items-center gap-2">
                            <select
                                class="h-8 rounded-md border bg-background px-2 text-xs"
                                aria-label="Filtrar por sucursal"
                                :value="sucursalId ?? ''"
                                @change="
                                    navegar({
                                        sucursal:
                                            Number(
                                                ($event.target as HTMLSelectElement).value,
                                            ) || null,
                                    })
                                "
                            >
                                <option value="">Todas las sucursales</option>
                                <option
                                    v-for="sucursal in sucursales"
                                    :key="sucursal.id"
                                    :value="sucursal.id"
                                >
                                    {{ sucursal.nombre }}
                                </option>
                            </select>
                            <span class="rounded-full bg-salvia px-2 py-0.5 text-xs">
                                {{ colaboradores.length }} colaboradores
                            </span>
                        </div>
                    </div>

                    <div v-if="rango && escala" class="relative mx-3 mt-8 mb-10 h-3">
                        <div class="absolute inset-0 rounded-full bg-danger-soft"></div>
                        <div
                            class="absolute inset-y-0 bg-success-soft"
                            :style="{
                                left: posicion(rango.minimo),
                                right: `calc(100% - ${posicion(rango.maximo)})`,
                            }"
                        ></div>
                        <div
                            v-for="(valor, etiqueta) in {
                                Mínimo: rango.minimo,
                                Medio: rango.medio,
                                Máximo: rango.maximo,
                            }"
                            :key="etiqueta"
                            class="absolute top-[-4px] h-5 border-l-2 border-dashed border-foreground/60"
                            :style="{ left: posicion(valor) }"
                        >
                            <span
                                class="absolute top-5 -translate-x-1/2 text-center text-[10px] leading-tight whitespace-nowrap"
                            >
                                <b>{{ etiqueta }}</b><br />{{ moneda(valor) }}
                            </span>
                        </div>
                        <template v-for="persona in colaboradores" :key="persona.id">
                            <span
                                v-if="persona.sueldo !== null"
                                class="absolute top-1/2 flex size-6 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-primary text-[9px] font-bold text-primary-foreground ring-2 ring-card"
                                :title="`${persona.nombre}: ${moneda(persona.sueldo)}`"
                                :style="{ left: posicion(persona.sueldo) }"
                            >
                                {{ iniciales(persona.nombre) }}
                            </span>
                        </template>
                    </div>
                    <p
                        v-else
                        class="mt-3 rounded-lg bg-warning-soft p-2 text-xs text-warning"
                    >
                        Este puesto aún no tiene tabulador: captura su rango para
                        ver la posición.
                    </p>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-crema text-left text-xs">
                                <tr>
                                    <th class="p-2">Colaborador</th>
                                    <th class="p-2 text-right">Sueldo actual</th>
                                    <th class="p-2 text-center">Compa-ratio</th>
                                    <th class="p-2">Posición</th>
                                    <th class="p-2">Desempeño</th>
                                    <th class="p-2 text-right">Tope</th>
                                    <th class="p-2 text-right">Puede subir</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="persona in colaboradores"
                                    :key="persona.id"
                                    class="border-t"
                                >
                                    <td class="p-2">
                                        <p class="font-medium">{{ persona.nombre }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ persona.sucursal ?? 'Sin sucursal' }}
                                        </p>
                                    </td>
                                    <td class="p-2 text-right font-semibold">
                                        {{
                                            persona.sueldo === null
                                                ? 'Sin capturar'
                                                : moneda(persona.sueldo)
                                        }}
                                    </td>
                                    <td class="p-2 text-center">
                                        {{
                                            persona.compa_ratio === null
                                                ? '—'
                                                : entero(persona.compa_ratio)
                                        }}
                                    </td>
                                    <td class="p-2">
                                        <span
                                            v-if="persona.banda"
                                            class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                                            :class="CLASE_BANDA[persona.banda]"
                                        >
                                            {{ persona.banda_etiqueta }}
                                        </span>
                                        <span v-else class="text-xs text-muted-foreground">—</span>
                                    </td>
                                    <td class="p-2 text-xs">
                                        {{ persona.desempeno_etiqueta }}
                                    </td>
                                    <td class="p-2 text-right">
                                        {{ moneda(persona.tope) }}
                                    </td>
                                    <td class="p-2 text-right text-success">
                                        {{
                                            persona.margen === null
                                                ? '—'
                                                : moneda(persona.margen)
                                        }}
                                    </td>
                                </tr>
                                <tr v-if="colaboradores.length === 0">
                                    <td
                                        colspan="7"
                                        class="p-4 text-center text-xs text-muted-foreground"
                                    >
                                        Nadie vigente en este puesto con este filtro.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <Button
                            v-if="versiones.length"
                            variant="ghost"
                            size="sm"
                            @click="verVersiones = !verVersiones"
                        >
                            <History class="size-3.5" />
                            Versiones del tabulador ({{ versiones.length }})
                        </Button>
                        <span v-else></span>
                        <div v-if="puede.exportar" class="flex gap-2">
                            <Button variant="outline" size="sm" as-child>
                                <a :href="excel.url(consultaExportar)">Excel de posiciones</a>
                            </Button>
                            <Button variant="outline" size="sm" as-child>
                                <a :href="pdf.url(consultaExportar)">PDF de posiciones</a>
                            </Button>
                        </div>
                    </div>
                    <ul v-if="verVersiones" class="mt-2 divide-y text-xs">
                        <li
                            v-for="version in versiones"
                            :key="version.version"
                            class="py-1.5"
                        >
                            <b>v{{ version.version }}</b>
                            {{ moneda(version.minimo) }} /
                            {{ moneda(version.medio) }} /
                            {{ moneda(version.maximo) }} · {{ version.fuente }} ·
                            {{ version.vigente_desde }}
                            {{ version.vigente_hasta ? `a ${version.vigente_hasta}` : '(vigente)' }}
                            · {{ version.usuario ?? 'Sistema' }}
                        </li>
                    </ul>
                </section>

                <div class="grid grid-cols-1 gap-4 2xl:grid-cols-2">
                    <!-- Matriz de mérito -->
                    <section class="rounded-2xl border bg-card p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h2 class="font-semibold">
                                    Matriz de mérito · incremento sugerido
                                </h2>
                                <p class="text-xs text-muted-foreground">
                                    Son sugerencias: ningún aumento se aplica solo.
                                </p>
                            </div>
                            <Button
                                v-if="puede.editar && !editandoMatriz"
                                variant="outline"
                                size="sm"
                                @click="abrirMatriz"
                            >
                                Editar
                            </Button>
                        </div>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-center text-xs">
                                <thead class="bg-crema">
                                    <tr>
                                        <th class="p-2 text-left">Desempeño</th>
                                        <th
                                            v-for="banda in BANDAS"
                                            :key="banda.clave"
                                            class="p-2"
                                        >
                                            {{ banda.etiqueta }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="nivel in NIVELES"
                                        :key="nivel.clave"
                                        class="border-t"
                                    >
                                        <td class="p-2 text-left font-medium">
                                            {{ nivel.etiqueta }}
                                        </td>
                                        <td
                                            v-for="banda in BANDAS"
                                            :key="banda.clave"
                                            class="p-1"
                                        >
                                            <Input
                                                v-if="editandoMatriz"
                                                v-model="celdas[nivel.clave][banda.clave]"
                                                class="h-8 text-center text-xs"
                                                :aria-label="`${nivel.etiqueta}, ${banda.etiqueta}`"
                                            />
                                            <template v-else>
                                                {{ textoCelda(matriz?.[nivel.clave]?.[banda.clave]) }}
                                            </template>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="editandoMatriz" class="mt-2 flex flex-col gap-2">
                            <p class="text-xs text-muted-foreground">
                                Porcentaje de 0 a 100, o escribe «Bono».
                            </p>
                            <Input v-model="motivoMatriz" placeholder="Motivo (opcional)" />
                            <InputError :message="erroresMatriz ?? undefined" />
                            <div class="flex justify-end gap-2">
                                <Button variant="ghost" size="sm" @click="editandoMatriz = false">
                                    Cancelar
                                </Button>
                                <Button size="sm" @click="guardarMatriz">Guardar</Button>
                            </div>
                        </div>
                        <details class="mt-2 text-xs text-muted-foreground">
                            <summary class="cursor-pointer">Historial de la matriz</summary>
                            <ul class="mt-1 space-y-1">
                                <li v-for="version in historialMatriz" :key="version.id">
                                    {{ version.vigente_desde }}
                                    {{ version.vigente_hasta ? `a ${version.vigente_hasta}` : '(vigente)' }}
                                    · {{ version.usuario ?? 'Sistema' }}
                                    <template v-if="version.motivo"> · {{ version.motivo }}</template>
                                </li>
                            </ul>
                        </details>
                    </section>

                    <!-- Alertas -->
                    <section class="rounded-2xl border bg-crema/50 p-4 shadow-sm">
                        <h2 class="flex items-center gap-1 font-semibold">
                            <TriangleAlert class="size-4 text-warning" /> Alertas salariales
                        </h2>
                        <ul class="mt-2 space-y-2 text-sm">
                            <li>
                                <b class="text-destructive">{{ resumen.bajo_minimo }}</b>
                                bajo el mínimo: riesgo de rotación.
                            </li>
                            <li>
                                <b class="text-warning">{{ resumen.sobre_maximo }}</b>
                                sobre el máximo: solo procede bono.
                            </li>
                            <li>
                                <b>{{ resumen.puestos_sin_tabulador }}</b>
                                sin tabulador capturado (puestos).
                            </li>
                            <li v-if="resumen.sin_sueldo > 0">
                                <b>{{ resumen.sin_sueldo }}</b>
                                sin sueldo capturado en su expediente.
                            </li>
                            <li v-if="resumen.con_evaluacion === 0">
                                Sin evaluación de desempeño vigente: no se calculan
                                topes ni márgenes.
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>
    </div>
</template>
