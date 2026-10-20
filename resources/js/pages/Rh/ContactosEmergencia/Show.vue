<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, FileSpreadsheet } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import { Button } from '@/components/ui/button';
import { formatearFecha } from '@/lib/fechas';
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

const ESTADOS: Record<Estado, { texto: string; clase: string }> = {
    aplicable: { texto: 'Con cambios', clase: 'bg-success/10 text-success' },
    sin_cambios: {
        texto: 'Sin cambios',
        clase: 'bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)]',
    },
    revision: { texto: 'Ambiguo', clase: 'bg-warning-soft text-warning' },
    no_encontrado: {
        texto: 'No encontrado',
        clase: 'bg-warning-soft text-warning',
    },
    omitido: {
        texto: 'Omitido',
        clase: 'bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)]',
    },
    error: { texto: 'Error', clase: 'bg-destructive/10 text-destructive' },
};

const RESULTADOS: Record<
    NonNullable<FilaDetalle['resultado']>,
    { texto: string; clase: string }
> = {
    actualizado: { texto: 'Actualizado', clase: 'bg-success/10 text-success' },
    sin_cambios: ESTADOS.sin_cambios,
    omitido: ESTADOS.omitido,
    error: ESTADOS.error,
};

const yaAplicada = computed(() => props.importacion.estado === 'aplicado');

const cuenta = (estados: Estado[]) =>
    props.importacion.detalle.filter((f) => estados.includes(f.estado)).length;

type Filtro = 'todas' | 'aplicable' | 'pendiente' | 'sin_cambios' | 'omitido';

const FILTROS: { clave: Filtro; texto: string; estados: Estado[] | null }[] = [
    { clave: 'todas', texto: 'Todas', estados: null },
    { clave: 'aplicable', texto: 'Con cambios', estados: ['aplicable'] },
    {
        clave: 'pendiente',
        texto: 'Elegir colaborador',
        estados: ['revision', 'no_encontrado'],
    },
    { clave: 'sin_cambios', texto: 'Sin cambios', estados: ['sin_cambios'] },
    {
        clave: 'omitido',
        texto: 'Omitidas / errores',
        estados: ['omitido', 'error'],
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

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="importacion.nombre_archivo"
            :descripcion="
                yaAplicada
                    ? `Aplicada el ${formatearFecha(importacion.aplicado_en)} por ${importacion.aplicado_por ?? '—'}`
                    : 'Vista previa: todavía no se ha guardado nada. Revisa, elige a los colaboradores que no cuadraron y confirma.'
            "
            :icono="FileSpreadsheet"
        >
            <Button
                v-if="!yaAplicada"
                :disabled="seleccionadas.length === 0 || form.processing"
                @click="confirmar"
            >
                <CheckCircle2 class="size-4" />
                Confirmar {{ seleccionadas.length }} fila(s)
            </Button>
        </CrudPageHeader>

        <p class="text-xs text-muted-foreground">
            Subido por {{ importacion.subido_por ?? '—' }} el
            {{ formatearFecha(importacion.creado_en) }}.
            <template v-if="importacion.reanalisis_de">
                Este mismo archivo ya se aplicó antes (importación #{{
                    importacion.reanalisis_de
                }}): solo verás como cambio lo que difiere de hoy.
            </template>
        </p>

        <!-- Resumen final (después de confirmar) -->
        <section
            v-if="yaAplicada && importacion.resumen"
            class="grid gap-3 sm:grid-cols-3"
        >
            <div class="rounded-2xl bg-success-soft p-4">
                <p class="text-2xl font-semibold">
                    {{ importacion.resumen.actualizados }}
                </p>
                <p class="text-sm">Actualizados</p>
            </div>
            <div
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
            >
                <p class="text-2xl font-semibold">
                    {{ importacion.resumen.sin_cambios }}
                </p>
                <p class="text-sm">Sin cambios</p>
            </div>
            <div class="rounded-2xl bg-warning-soft p-4">
                <p class="text-2xl font-semibold">
                    {{ importacion.resumen.omitidos }}
                </p>
                <p class="text-sm">Omitidos (ver motivo en cada fila)</p>
            </div>
        </section>

        <!-- Conteos de la vista previa -->
        <section v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-2xl bg-success-soft p-4">
                <p class="text-2xl font-semibold">
                    {{ cuenta(['aplicable']) }}
                </p>
                <p class="text-sm">Con cambios</p>
            </div>
            <div class="rounded-2xl bg-warning-soft p-4">
                <p class="text-2xl font-semibold">
                    {{ cuenta(['revision', 'no_encontrado']) }}
                </p>
                <p class="text-sm">Elegir colaborador a mano</p>
            </div>
            <div
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
            >
                <p class="text-2xl font-semibold">
                    {{ cuenta(['sin_cambios']) }}
                </p>
                <p class="text-sm">Sin cambios</p>
            </div>
            <div
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
            >
                <p class="text-2xl font-semibold">
                    {{ cuenta(['omitido', 'error']) }}
                </p>
                <p class="text-sm">Omitidas / errores</p>
            </div>
            <div
                class="rounded-2xl p-4"
                :class="
                    pendientesDeConfirmar > 0
                        ? 'bg-warning-soft'
                        : 'border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]'
                "
            >
                <p class="text-2xl font-semibold">
                    {{ pendientesDeConfirmar }}
                </p>
                <p class="text-sm">
                    Teléfonos sin 10 dígitos por confirmar (si no, no se
                    guardan)
                </p>
            </div>
        </section>

        <details
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm"
        >
            <summary class="cursor-pointer font-medium">
                Columnas detectadas en el archivo
            </summary>
            <ul class="mt-2 grid gap-1 text-xs sm:grid-cols-2 xl:grid-cols-3">
                <li
                    v-for="(encabezado, campo) in importacion.columnas"
                    :key="campo"
                >
                    <span class="font-medium">{{
                        CAMPOS_EXCEL[campo] ?? campo
                    }}</span>
                    ← «{{ encabezado }}»
                </li>
            </ul>
            <p
                v-if="importacion.columnas_ignoradas.length"
                class="mt-2 text-xs text-muted-foreground"
            >
                Columnas ignoradas:
                {{ importacion.columnas_ignoradas.join(' · ') }}
            </p>
        </details>

        <div class="flex flex-wrap gap-2">
            <Button
                v-for="f in FILTROS"
                :key="f.clave"
                size="sm"
                :variant="filtro === f.clave ? 'default' : 'outline'"
                @click="filtro = f.clave"
            >
                {{ f.texto }}
                <span class="opacity-70">{{
                    f.estados ? cuenta(f.estados) : importacion.detalle.length
                }}</span>
            </Button>
        </div>

        <section
            class="overflow-x-auto rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        >
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-muted-foreground">
                    <tr>
                        <th v-if="!yaAplicada" class="py-2 pr-3">Aplicar</th>
                        <th class="py-2 pr-3">Fila</th>
                        <th class="py-2 pr-3">Nombre en el archivo</th>
                        <th class="py-2 pr-3">Colaborador</th>
                        <th class="py-2 pr-3">Estado</th>
                        <th class="py-2 pr-3">Qué cambia (actual → nuevo)</th>
                        <th class="py-2 pr-3">Advertencias</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="fila in filasVisibles"
                        :key="fila.fila"
                        class="border-t border-[var(--mrl-borde)] align-top"
                    >
                        <td v-if="!yaAplicada" class="py-2 pr-3">
                            <input
                                v-if="fila.estado === 'aplicable'"
                                type="checkbox"
                                class="size-4 accent-primary"
                                :aria-label="`Aplicar fila ${fila.fila}`"
                                :checked="seleccionadas.includes(fila.fila)"
                                @change="
                                    seleccionadas = alternar(
                                        seleccionadas,
                                        fila.fila,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                        </td>
                        <td class="py-2 pr-3">{{ fila.fila }}</td>
                        <td class="py-2 pr-3">
                            {{ fila.nombre_excel || '—' }}
                            <span
                                v-if="fila.sucursal_excel"
                                class="block text-xs text-muted-foreground"
                                >{{ fila.sucursal_excel }}</span
                            >
                        </td>
                        <td class="min-w-64 py-2 pr-3">
                            <template v-if="fila.nombre_sistema">
                                {{ fila.nombre_sistema }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ fila.sucursal_sistema ?? 'Sin sucursal'
                                    }}<template v-if="fila.match === 'manual'">
                                        · elegido a mano</template
                                    ></span
                                >
                            </template>
                            <div
                                v-else-if="
                                    !yaAplicada &&
                                    (fila.estado === 'revision' ||
                                        fila.estado === 'no_encontrado')
                                "
                                class="flex flex-col gap-2"
                            >
                                <div
                                    v-if="fila.sugerencias.length"
                                    class="flex flex-wrap gap-1"
                                >
                                    <Button
                                        v-for="s in fila.sugerencias"
                                        :key="s.id"
                                        size="sm"
                                        variant="outline"
                                        :disabled="asignando !== null"
                                        @click="asignar(fila.fila, s.id)"
                                    >
                                        {{ s.nombre }}
                                        <span class="opacity-70">{{
                                            s.sucursal ?? 'Sin sucursal'
                                        }}</span>
                                    </Button>
                                </div>
                                <BuscadorColaborador
                                    v-model="elegidos[fila.fila]"
                                    :buscar-url="buscarUrl"
                                />
                                <Button
                                    v-if="elegidos[fila.fila]"
                                    size="sm"
                                    :disabled="asignando !== null"
                                    @click="
                                        asignar(
                                            fila.fila,
                                            elegidos[fila.fila]!.id,
                                        )
                                    "
                                >
                                    Usar este colaborador
                                </Button>
                            </div>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="py-2 pr-3">
                            <span
                                v-if="yaAplicada && fila.resultado"
                                class="rounded-full px-2 py-0.5 text-xs"
                                :class="RESULTADOS[fila.resultado].clase"
                                >{{ RESULTADOS[fila.resultado].texto }}</span
                            >
                            <span
                                v-else
                                class="rounded-full px-2 py-0.5 text-xs"
                                :class="ESTADOS[fila.estado].clase"
                                >{{ ESTADOS[fila.estado].texto }}</span
                            >
                            <span
                                v-if="motivoDe(fila)"
                                class="mt-1 block max-w-64 text-xs text-muted-foreground"
                                >{{ motivoDe(fila) }}</span
                            >
                        </td>
                        <td class="py-2 pr-3">
                            <ul
                                v-if="cambiosDe(fila).length"
                                class="flex flex-col gap-0.5 text-xs"
                            >
                                <li
                                    v-for="c in cambiosDe(fila)"
                                    :key="c.columna"
                                >
                                    <span class="font-medium">{{
                                        c.etiqueta
                                    }}</span
                                    >:
                                    <span class="text-muted-foreground">{{
                                        c.cambio.antes ?? '(vacío)'
                                    }}</span>
                                    →
                                    <span
                                        :class="
                                            c.cambio.invalido
                                                ? 'text-warning'
                                                : ''
                                        "
                                        >{{ c.cambio.despues }}</span
                                    >
                                </li>
                            </ul>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                            <label
                                v-if="
                                    !yaAplicada &&
                                    fila.estado === 'aplicable' &&
                                    fila.requiere_confirmacion
                                "
                                class="mt-2 flex items-center gap-2 text-xs text-warning"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="confirmadas.includes(fila.fila)"
                                    @change="
                                        confirmadas = alternar(
                                            confirmadas,
                                            fila.fila,
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />
                                Guardar el teléfono tal cual aunque no tenga 10
                                dígitos
                            </label>
                        </td>
                        <td class="py-2 pr-3">
                            <ul
                                v-if="fila.advertencias.length"
                                class="flex flex-col gap-0.5 text-xs text-warning"
                            >
                                <li
                                    v-for="a in fila.advertencias"
                                    :key="a"
                                    class="flex gap-1"
                                >
                                    <AlertTriangle
                                        class="mt-0.5 size-3 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span>{{ a }}</span>
                                </li>
                            </ul>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </td>
                    </tr>
                    <tr v-if="filasVisibles.length === 0">
                        <td
                            colspan="7"
                            class="py-6 text-center text-sm text-muted-foreground"
                        >
                            No hay filas en este filtro.
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
