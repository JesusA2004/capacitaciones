<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Ban,
    CheckCircle2,
    ChevronDown,
    FileSpreadsheet,
    MinusCircle,
    PhoneOff,
    UserSearch,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatearFecha } from '@/lib/fechas';
import type { Tono } from '@/lib/tonos';
import { dashboard } from '@/routes';
import {
    aplicar as aplicarImportacion,
    colaboradores as buscarColaboradores,
    index as indexContactos,
} from '@/routes/rh/contactos-emergencia';
import { asignar as asignarFila } from '@/routes/rh/contactos-emergencia/filas';
import type { ColaboradorBusqueda } from '@/types';

type Cambio = {
    antes: string | null;
    despues: string;
    /** Teléfono que no quedó en 10 dígitos: se guarda solo si se confirma. */
    invalido?: boolean;
    cifrado?: boolean;
};

type Estado =
    | 'aplicable'
    | 'sin_cambios'
    | 'revision'
    | 'no_encontrado'
    | 'omitido'
    | 'error';

type FilaDetalle = {
    fila: number;
    nombre_excel: string;
    sucursal_excel: string;
    colaborador_id: number | null;
    nombre_sistema: string | null;
    sucursal_sistema: string | null;
    match: 'automatico' | 'manual' | null;
    estado: Estado;
    motivo: string | null;
    cambios: Record<string, Cambio>;
    advertencias: string[];
    requiere_confirmacion: boolean;
    sugerencias: { id: number; nombre: string; sucursal: string | null }[];
    aplicado: boolean;
    resultado: 'actualizado' | 'sin_cambios' | 'omitido' | 'error' | null;
    motivo_resultado: string | null;
};

type Importacion = {
    id: number;
    nombre_archivo: string;
    estado: 'previsualizado' | 'aplicado';
    total_filas: number;
    filas_aplicadas: number;
    detalle: FilaDetalle[];
    columnas: Record<string, string>;
    columnas_ignoradas: string[];
    resumen: {
        actualizados: number;
        sin_cambios: number;
        omitidos: number;
    } | null;
    reanalisis_de: number | null;
    subido_por: string | null;
    creado_en: string | null;
    aplicado_por: string | null;
    aplicado_en: string | null;
};

const props = defineProps<{ importacion: Importacion }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Contactos de emergencia', href: indexContactos() },
            { title: 'Vista previa', href: '' },
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

/** Campo del Excel (clave del importador) → nombre corto. */
const CAMPOS_EXCEL: Record<string, string> = {
    nombre_completo: 'Nombre completo',
    sucursal: 'Sucursal',
    telefono: 'Teléfono personal',
    condicion_medica: 'Condición médica',
    alergias: 'Alergias',
    contacto_nombre: 'Contacto de emergencia',
    parentesco: 'Parentesco',
    contacto_telefono: 'Teléfono del contacto',
    contacto_direccion: 'Dirección del contacto',
};

type InfoEstado = { texto: string; tono: Tono; icono: Component };

const ESTADOS: Record<Estado, InfoEstado> = {
    aplicable: { texto: 'Con cambios', tono: 'exito', icono: CheckCircle2 },
    sin_cambios: { texto: 'Sin cambios', tono: 'neutro', icono: MinusCircle },
    revision: { texto: 'Ambiguo', tono: 'alerta', icono: UserSearch },
    no_encontrado: {
        texto: 'No encontrado',
        tono: 'alerta',
        icono: UserSearch,
    },
    omitido: { texto: 'Omitido', tono: 'neutro', icono: MinusCircle },
    error: { texto: 'Error', tono: 'peligro', icono: Ban },
};

const RESULTADOS: Record<NonNullable<FilaDetalle['resultado']>, InfoEstado> = {
    actualizado: {
        texto: 'Actualizado',
        tono: 'exito',
        icono: CheckCircle2,
    },
    sin_cambios: ESTADOS.sin_cambios,
    omitido: ESTADOS.omitido,
    error: ESTADOS.error,
};

function infoDe(fila: FilaDetalle): InfoEstado {
    return yaAplicada.value && fila.resultado
        ? RESULTADOS[fila.resultado]
        : ESTADOS[fila.estado];
}

const verColumnas = ref(false);

const normalizar = (t: string) =>
    t
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();

/** ¿El nombre del Excel es el mismo que el del sistema (sin acentos ni mayúsculas)? */
function mismoNombre(fila: FilaDetalle): boolean {
    return (
        normalizar(fila.nombre_excel ?? '') ===
        normalizar(fila.nombre_sistema ?? '')
    );
}

const yaAplicada = computed(() => props.importacion.estado === 'aplicado');

const cuenta = (estados: Estado[]) =>
    props.importacion.detalle.filter((f) => estados.includes(f.estado)).length;

type Filtro = 'todas' | 'aplicable' | 'pendiente' | 'sin_cambios' | 'omitido';

const FILTROS: {
    clave: Filtro;
    texto: string;
    estados: Estado[] | null;
    tono: Tono;
    icono: Component;
}[] = [
    {
        clave: 'todas',
        texto: 'Todas',
        estados: null,
        tono: 'primario',
        icono: FileSpreadsheet,
    },
    {
        clave: 'aplicable',
        texto: 'Con cambios',
        estados: ['aplicable'],
        tono: 'exito',
        icono: CheckCircle2,
    },
    {
        clave: 'pendiente',
        texto: 'Elegir colaborador',
        estados: ['revision', 'no_encontrado'],
        tono: 'alerta',
        icono: UserSearch,
    },
    {
        clave: 'sin_cambios',
        texto: 'Sin cambios',
        estados: ['sin_cambios'],
        tono: 'neutro',
        icono: MinusCircle,
    },
    {
        clave: 'omitido',
        texto: 'Omitidas / errores',
        estados: ['omitido', 'error'],
        tono: 'peligro',
        icono: Ban,
    },
];

const filtro = ref<Filtro>('todas');

const filasVisibles = computed(() => {
    const estados = FILTROS.find((f) => f.clave === filtro.value)?.estados;

    return estados
        ? props.importacion.detalle.filter((f) => estados.includes(f.estado))
        : props.importacion.detalle;
});

/** Filas aplicables marcadas para confirmar (por defecto, todas). */
const seleccionadas = ref<number[]>([]);
/** Filas con teléfono sin 10 dígitos que RH confirma guardar tal cual. */
const confirmadas = ref<number[]>([]);

watch(
    () => props.importacion.detalle,
    (detalle) => {
        seleccionadas.value = detalle
            .filter((f) => f.estado === 'aplicable')
            .map((f) => f.fila);
        confirmadas.value = confirmadas.value.filter((n) =>
            detalle.some((f) => f.fila === n && f.requiere_confirmacion),
        );
    },
    { immediate: true },
);

const pendientesDeConfirmar = computed(
    () =>
        props.importacion.detalle.filter(
            (f) =>
                f.requiere_confirmacion &&
                seleccionadas.value.includes(f.fila) &&
                !confirmadas.value.includes(f.fila),
        ).length,
);

function alternar(lista: number[], fila: number, activo: boolean): number[] {
    return activo
        ? [...new Set([...lista, fila])]
        : lista.filter((n) => n !== fila);
}

/** Ya aplicada: motivo del resultado; en vista previa: por qué no se aplica. */
function motivoDe(fila: FilaDetalle): string | null {
    if (yaAplicada.value) {
        return fila.motivo_resultado;
    }

    return fila.estado === 'aplicable' ? null : fila.motivo;
}

function cambiosDe(fila: FilaDetalle) {
    return Object.entries(fila.cambios).map(([columna, cambio]) => ({
        columna,
        etiqueta: ETIQUETAS[columna] ?? columna,
        cambio,
    }));
}

const form = useForm({});

function confirmar() {
    const n = seleccionadas.value.length;

    if (
        !window.confirm(
            `¿Guardar los cambios de ${n} colaborador(es)? Lo demás no se toca. Queda registrado quién importó y cuándo.`,
        )
    ) {
        return;
    }

    form.transform(() => ({
        filas: seleccionadas.value,
        confirmadas: confirmadas.value,
    })).post(aplicarImportacion.url(props.importacion.id), {
        preserveScroll: true,
    });
}

const elegidos = ref<Record<number, ColaboradorBusqueda | null>>({});
const asignando = ref<number | null>(null);

function asignar(fila: number, colaboradorId: number) {
    asignando.value = fila;
    router.post(
        asignarFila.url({ importacion: props.importacion.id, fila }),
        { colaborador_id: colaboradorId },
        {
            preserveScroll: true,
            onSuccess: () => {
                elegidos.value[fila] = null;
            },
            onFinish: () => {
                asignando.value = null;
            },
        },
    );
}

const buscarUrl = (texto: string) =>
    buscarColaboradores.url({ query: { q: texto } });
</script>

<template>
    <Head title="Vista previa de contactos de emergencia" />

    <div
        class="pagina-ancha flex flex-col gap-5"
        :class="!yaAplicada && 'pb-28 md:pb-8'"
    >
        <!-- Encabezado: archivo, estado y la acción principal -->
        <header
            class="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-4 shadow-sm sm:flex-row sm:items-center sm:p-5"
        >
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <span
                    class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-success-soft text-success"
                >
                    <FileSpreadsheet class="size-6" />
                </span>
                <div class="min-w-0">
                    <h1
                        class="truncate text-lg font-semibold sm:text-xl"
                        :title="importacion.nombre_archivo"
                    >
                        {{ importacion.nombre_archivo }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ importacion.subido_por ?? '—' }} ·
                        {{ formatearFecha(importacion.creado_en) }}
                    </p>
                </div>
            </div>
            <ChipEstado
                v-if="yaAplicada"
                :etiqueta="`Aplicada ${formatearFecha(importacion.aplicado_en)}`"
                tono="exito"
                :icono="CheckCircle2"
                tamano="md"
                :titulo="`Por ${importacion.aplicado_por ?? '—'}`"
            />
            <template v-else>
                <ChipEstado
                    etiqueta="Vista previa · nada guardado"
                    tono="oro"
                    tamano="md"
                    class="hidden lg:inline-flex"
                />
                <Button
                    size="lg"
                    class="hidden h-11 md:inline-flex"
                    :disabled="seleccionadas.length === 0 || form.processing"
                    @click="confirmar"
                >
                    <Spinner v-if="form.processing" />
                    <CheckCircle2 v-else class="size-5" />
                    Guardar {{ seleccionadas.length }}
                    {{ seleccionadas.length === 1 ? 'fila' : 'filas' }}
                </Button>
            </template>
        </header>

        <p
            v-if="importacion.reanalisis_de"
            class="flex items-start gap-2 rounded-xl bg-info-soft px-3 py-2 text-sm text-info"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            Este archivo ya se aplicó (importación #{{
                importacion.reanalisis_de
            }}): solo se marca como cambio lo que difiere de hoy.
        </p>

        <!-- Resumen final -->
        <section
            v-if="yaAplicada && importacion.resumen"
            class="grid grid-cols-3 gap-2.5 sm:gap-3"
        >
            <TarjetaMetrica
                etiqueta="Actualizados"
                :valor="importacion.resumen.actualizados"
                :icono="CheckCircle2"
                tono="exito"
            />
            <TarjetaMetrica
                etiqueta="Sin cambios"
                :valor="importacion.resumen.sin_cambios"
                :icono="MinusCircle"
            />
            <TarjetaMetrica
                etiqueta="Omitidos"
                :valor="importacion.resumen.omitidos"
                :icono="Ban"
                tono="alerta"
            />
        </section>

        <!-- Conteos (también filtran) -->
        <section
            class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-3 xl:grid-cols-6"
            aria-label="Filtrar filas"
        >
            <TarjetaMetrica
                v-for="f in FILTROS"
                :key="f.clave"
                :etiqueta="f.texto"
                :valor="
                    f.estados ? cuenta(f.estados) : importacion.detalle.length
                "
                :icono="f.icono"
                :tono="f.tono"
                clicable
                :activa="filtro === f.clave"
                @click="filtro = f.clave"
            />
            <TarjetaMetrica
                v-if="!yaAplicada"
                etiqueta="Teléfonos por confirmar"
                :valor="pendientesDeConfirmar"
                :icono="PhoneOff"
                :tono="pendientesDeConfirmar > 0 ? 'alerta' : 'neutro'"
                detalle="Sin 10 dígitos"
            />
        </section>

        <!-- Columnas detectadas -->
        <div class="rounded-2xl border border-border/60 bg-card">
            <button
                type="button"
                class="flex w-full items-center gap-2 px-4 py-3 text-left text-sm font-medium"
                :aria-expanded="verColumnas"
                @click="verColumnas = !verColumnas"
            >
                Columnas detectadas
                <span class="text-muted-foreground tabular-nums"
                    >({{ Object.keys(importacion.columnas).length }})</span
                >
                <ChevronDown
                    class="ml-auto size-4 transition-transform"
                    :class="verColumnas && 'rotate-180'"
                />
            </button>
            <div v-if="verColumnas" class="flex flex-col gap-2 px-4 pb-4">
                <ul class="grid gap-1.5 text-xs sm:grid-cols-2 xl:grid-cols-3">
                    <li
                        v-for="(encabezado, campo) in importacion.columnas"
                        :key="campo"
                        class="flex min-w-0 items-center gap-1.5 rounded-lg bg-[var(--mrl-fondo)] px-2.5 py-1.5"
                    >
                        <span class="shrink-0 font-semibold">{{
                            CAMPOS_EXCEL[campo] ?? campo
                        }}</span>
                        <ArrowRight
                            class="size-3 shrink-0 rotate-180 text-muted-foreground"
                        />
                        <span
                            class="truncate text-muted-foreground"
                            :title="encabezado"
                            >{{ encabezado }}</span
                        >
                    </li>
                </ul>
                <p
                    v-if="importacion.columnas_ignoradas.length"
                    class="text-xs text-muted-foreground"
                >
                    Ignoradas:
                    {{ importacion.columnas_ignoradas.join(' · ') }}
                </p>
            </div>
        </div>

        <!-- Filas -->
        <EmptyState
            v-if="filasVisibles.length === 0"
            class="rounded-2xl border border-dashed border-border bg-card"
            :icono="FileSpreadsheet"
            titulo="Nada en este filtro"
            descripcion="Elige otra tarjeta de arriba para ver más filas."
        />
        <ul v-else class="grid grid-cols-1 gap-3 xl:grid-cols-2">
            <li
                v-for="fila in filasVisibles"
                :key="fila.fila"
                class="flex min-w-0 flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm"
                :class="
                    !yaAplicada &&
                    fila.estado === 'aplicable' &&
                    seleccionadas.includes(fila.fila)
                        ? 'border-success/50'
                        : 'border-border/60'
                "
            >
                <!-- Quién: Excel → sistema -->
                <div class="flex items-start gap-3">
                    <label
                        v-if="!yaAplicada && fila.estado === 'aplicable'"
                        class="-m-1 flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg hover:bg-accent"
                    >
                        <input
                            type="checkbox"
                            class="size-5 accent-primary"
                            :aria-label="`Aplicar fila ${fila.fila}`"
                            :checked="seleccionadas.includes(fila.fila)"
                            @change="
                                seleccionadas = alternar(
                                    seleccionadas,
                                    fila.fila,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                    </label>
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >Fila {{ fila.fila
                                }}<template v-if="fila.sucursal_excel">
                                    · {{ fila.sucursal_excel }}</template
                                ></span
                            >
                            <ChipEstado
                                class="ml-auto"
                                :etiqueta="infoDe(fila).texto"
                                :tono="infoDe(fila).tono"
                                :icono="infoDe(fila).icono"
                            />
                        </div>
                        <p class="text-base leading-snug font-semibold">
                            {{ fila.nombre_excel || '—' }}
                        </p>
                        <p
                            v-if="fila.nombre_sistema"
                            class="flex min-w-0 flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground"
                        >
                            <ArrowRight class="size-3.5 shrink-0" />
                            <span
                                v-if="
                                    fila.match === 'manual' ||
                                    mismoNombre(fila) === false
                                "
                                class="font-medium text-foreground"
                                >{{ fila.nombre_sistema }}</span
                            >
                            <span v-else>Coincide</span>
                            <span
                                >· {{ fila.sucursal_sistema ?? 'Sin sucursal'
                                }}{{
                                    fila.match === 'manual'
                                        ? ' · elegido a mano'
                                        : ''
                                }}</span
                            >
                        </p>
                    </div>
                </div>

                <p
                    v-if="motivoDe(fila)"
                    class="rounded-lg bg-[var(--mrl-fondo)] px-3 py-2 text-xs text-muted-foreground"
                >
                    {{ motivoDe(fila) }}
                </p>

                <!-- Elegir colaborador a mano -->
                <div
                    v-if="
                        !fila.nombre_sistema &&
                        !yaAplicada &&
                        (fila.estado === 'revision' ||
                            fila.estado === 'no_encontrado')
                    "
                    class="flex flex-col gap-2 rounded-xl bg-warning-soft/60 p-3"
                >
                    <p class="text-xs font-semibold text-warning">¿Quién es?</p>
                    <div
                        v-if="fila.sugerencias.length"
                        class="flex flex-wrap gap-2"
                    >
                        <Button
                            v-for="s in fila.sugerencias"
                            :key="s.id"
                            variant="outline"
                            class="h-auto min-h-10 justify-start py-1.5 text-left whitespace-normal"
                            :disabled="asignando !== null"
                            @click="asignar(fila.fila, s.id)"
                        >
                            <Spinner v-if="asignando === fila.fila" />
                            <span class="flex flex-col">
                                <span>{{ s.nombre }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    s.sucursal ?? 'Sin sucursal'
                                }}</span>
                            </span>
                        </Button>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <div class="min-w-0 flex-1">
                            <BuscadorColaborador
                                v-model="elegidos[fila.fila]"
                                :buscar-url="buscarUrl"
                            />
                        </div>
                        <Button
                            v-if="elegidos[fila.fila]"
                            class="h-10"
                            :disabled="asignando !== null"
                            @click="asignar(fila.fila, elegidos[fila.fila]!.id)"
                        >
                            <Spinner v-if="asignando === fila.fila" />
                            Usar este
                        </Button>
                    </div>
                </div>

                <!-- Qué cambia -->
                <ul
                    v-if="cambiosDe(fila).length"
                    class="flex flex-col divide-y divide-border/50 rounded-xl border border-border/60 text-sm"
                >
                    <li
                        v-for="c in cambiosDe(fila)"
                        :key="c.columna"
                        class="flex flex-col gap-0.5 px-3 py-2 sm:flex-row sm:items-baseline sm:gap-3"
                    >
                        <span
                            class="shrink-0 text-xs font-semibold text-muted-foreground sm:w-40"
                            >{{ c.etiqueta }}</span
                        >
                        <span
                            class="flex min-w-0 flex-wrap items-center gap-x-1.5"
                        >
                            <span
                                class="text-muted-foreground line-through decoration-muted-foreground/40"
                                >{{ c.cambio.antes ?? '(vacío)' }}</span
                            >
                            <ArrowRight
                                class="size-3.5 shrink-0 text-muted-foreground"
                            />
                            <span
                                class="font-medium break-words"
                                :class="c.cambio.invalido && 'text-warning'"
                                >{{ c.cambio.despues }}</span
                            >
                        </span>
                    </li>
                </ul>

                <label
                    v-if="
                        !yaAplicada &&
                        fila.estado === 'aplicable' &&
                        fila.requiere_confirmacion
                    "
                    class="flex cursor-pointer items-center gap-2 rounded-xl bg-warning-soft px-3 py-2 text-sm text-warning"
                >
                    <input
                        type="checkbox"
                        class="size-5 accent-primary"
                        :checked="confirmadas.includes(fila.fila)"
                        @change="
                            confirmadas = alternar(
                                confirmadas,
                                fila.fila,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                    Guardar el teléfono aunque no tenga 10 dígitos
                </label>

                <ul
                    v-if="fila.advertencias.length"
                    class="flex flex-wrap gap-1.5"
                >
                    <li
                        v-for="a in fila.advertencias"
                        :key="a"
                        class="inline-flex max-w-full items-start gap-1 rounded-full bg-warning-soft px-2.5 py-0.5 text-xs text-warning"
                    >
                        <AlertTriangle
                            class="mt-0.5 size-3 shrink-0"
                            aria-hidden="true"
                        />
                        <span>{{ a }}</span>
                    </li>
                </ul>
            </li>
        </ul>
    </div>

    <!-- Móvil: guardar siempre a la mano -->
    <div
        v-if="!yaAplicada"
        class="fixed inset-x-0 bottom-16 z-30 border-t border-border/60 bg-card/95 p-3 shadow-lg backdrop-blur md:hidden"
    >
        <Button
            size="lg"
            class="h-12 w-full text-base"
            :disabled="seleccionadas.length === 0 || form.processing"
            @click="confirmar"
        >
            <Spinner v-if="form.processing" />
            <CheckCircle2 v-else class="size-5" />
            Guardar {{ seleccionadas.length }}
            {{ seleccionadas.length === 1 ? 'fila' : 'filas' }}
        </Button>
    </div>
</template>
