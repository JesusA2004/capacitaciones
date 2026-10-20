<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    ChevronDown,
    Clock,
    Download,
    Eye,
    FileSpreadsheet,
    FileUp,
    HeartPulse,
    SearchX,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { ref } from 'vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import rutasContactos, {
    destroy as quitarImportacion,
    plantilla as plantillaContactos,
    show as mostrarImportacion,
} from '@/routes/rh/contactos-emergencia';

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
    /** Encabezados oficiales (fila 1) que debe traer el Excel. */
    columnas: string[];
    importaciones: {
        data: ImportacionResumen[];
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Contactos de emergencia', href: '' },
        ],
    },
});

const form = useForm<{ archivo: File[] }>({ archivo: [] });
const verColumnas = ref(false);

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
        rutasContactos.analizar.url(),
        {
            forceFormData: true,
            onSuccess: () => form.reset(),
        },
    );
}
</script>

<template>
    <Head title="Contactos de emergencia" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader titulo="Contactos de emergencia" :icono="HeartPulse">
            <Button variant="outline" class="w-full sm:w-auto" as-child>
                <a :href="plantillaContactos.url()" download>
                    <Download class="size-4" /> Plantilla vacía
                </a>
            </Button>
        </CrudPageHeader>

        <div
            class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]"
        >
            <!-- Subir -->
            <section
                class="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-4 shadow-sm sm:p-5"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-salvia text-primary"
                    >
                        <FileSpreadsheet class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="flex items-center gap-1.5 font-semibold">
                            Subir Excel
                            <AyudaBoton
                                titulo="Cómo funciona"
                                texto="Una fila por colaborador (por ejemplo, el Excel de Google Forms). Se empareja por nombre completo exacto —sin importar acentos, mayúsculas ni espacios— y la sucursal desambigua homónimos. Solo rellena colaboradores que ya existen; nunca crea personas. El contacto de emergencia también sirve como beneficiario del contrato si no hay uno aparte."
                            />
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Primero ves la vista previa; nada se guarda sin
                            confirmar.
                        </p>
                    </div>
                </div>

                <form class="flex flex-col gap-3" @submit.prevent="analizar">
                    <PeopleFileDropzone
                        v-model="form.archivo"
                        accept=".xlsx,.xls"
                        label="Arrastra el Excel aquí"
                        hint="Una fila por colaborador"
                    />
                    <InputError :message="form.errors.archivo" />
                    <Button
                        type="submit"
                        size="lg"
                        class="h-11 w-full"
                        :disabled="form.archivo.length === 0 || form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        <FileUp v-else class="size-5" />
                        {{
                            form.processing
                                ? 'Analizando…'
                                : 'Subir y ver vista previa'
                        }}
                    </Button>
                </form>

                <div class="rounded-xl bg-[var(--mrl-fondo)]">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm font-medium"
                        :aria-expanded="verColumnas"
                        @click="verColumnas = !verColumnas"
                    >
                        Columnas que debe traer ({{ columnas.length }})
                        <ChevronDown
                            class="ml-auto size-4 transition-transform"
                            :class="verColumnas && 'rotate-180'"
                        />
                    </button>
                    <ol
                        v-if="verColumnas"
                        class="flex flex-col gap-1 px-3 pb-3 text-xs"
                    >
                        <li
                            v-for="(c, n) in columnas"
                            :key="c"
                            class="flex gap-2"
                        >
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full bg-card font-semibold text-bronce tabular-nums"
                                >{{ n + 1 }}</span
                            >
                            <span class="pt-0.5">{{ c }}</span>
                        </li>
                        <li class="pt-1 text-muted-foreground">
                            En cualquier orden; las columnas extra se ignoran.
                        </li>
                    </ol>
                </div>
            </section>

            <!-- Cargas anteriores -->
            <section class="flex min-w-0 flex-col gap-3">
                <h2 class="text-sm font-semibold text-muted-foreground">
                    Cargas anteriores
                </h2>

                <EmptyState
                    v-if="importaciones.data.length === 0"
                    class="rounded-2xl border border-dashed border-border bg-card"
                    :icono="FileSpreadsheet"
                    titulo="Aún no hay cargas"
                    descripcion="Cuando subas un Excel aparecerá aquí con su resultado."
                />

                <ul v-else class="grid grid-cols-1 gap-3 2xl:grid-cols-2">
                    <li
                        v-for="i in importaciones.data"
                        :key="i.id"
                        class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-success-soft text-success"
                            >
                                <FileSpreadsheet class="size-5" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p
                                    class="truncate font-semibold"
                                    :title="i.nombre_archivo"
                                >
                                    {{ i.nombre_archivo }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ i.subido_por ?? '—' }} ·
                                    {{ formatearFecha(i.creado_en) }}
                                </p>
                            </div>
                            <ChipEstado
                                :etiqueta="
                                    i.estado === 'aplicado'
                                        ? 'Aplicada'
                                        : 'Sin aplicar'
                                "
                                :tono="
                                    i.estado === 'aplicado' ? 'exito' : 'oro'
                                "
                                :icono="
                                    i.estado === 'aplicado'
                                        ? CheckCircle2
                                        : Clock
                                "
                            />
                        </div>

                        <div class="grid grid-cols-4 gap-2 text-center">
                            <div class="rounded-xl bg-[var(--mrl-fondo)] p-2">
                                <p class="text-xl font-bold tabular-nums">
                                    {{ i.total_filas }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    Filas
                                </p>
                            </div>
                            <div class="rounded-xl bg-success-soft p-2">
                                <p
                                    class="text-xl font-bold text-success tabular-nums"
                                >
                                    {{ i.filas_aplicadas }}
                                </p>
                                <p class="text-[11px] text-success">
                                    Aplicadas
                                </p>
                            </div>
                            <div class="rounded-xl bg-warning-soft p-2">
                                <p
                                    class="flex items-center justify-center gap-1 text-xl font-bold text-warning tabular-nums"
                                >
                                    <TriangleAlert class="size-3.5" />{{
                                        i.filas_revision
                                    }}
                                </p>
                                <p class="text-[11px] text-warning">Revisión</p>
                            </div>
                            <div class="rounded-xl bg-danger-soft p-2">
                                <p
                                    class="flex items-center justify-center gap-1 text-xl font-bold text-destructive tabular-nums"
                                >
                                    <SearchX class="size-3.5" />{{
                                        i.filas_no_encontradas
                                    }}
                                </p>
                                <p class="text-[11px] text-destructive">
                                    No hallados
                                </p>
                            </div>
                        </div>

                        <p
                            v-if="i.aplicado_por"
                            class="text-xs text-muted-foreground"
                        >
                            Aplicada por {{ i.aplicado_por }} ·
                            {{ formatearFecha(i.aplicado_en) }}
                        </p>

                        <div class="flex gap-2">
                            <Button class="h-10 flex-1" as-child>
                                <Link :href="mostrarImportacion.url(i.id)">
                                    <Eye class="size-4" />
                                    {{
                                        i.estado === 'aplicado'
                                            ? 'Ver detalle'
                                            : 'Revisar y aplicar'
                                    }}
                                </Link>
                            </Button>
                            <Button
                                v-if="i.estado !== 'aplicado'"
                                variant="outline-destructive"
                                class="h-10"
                                :aria-label="`Quitar ${i.nombre_archivo}`"
                                @click="quitar(i)"
                            >
                                <Trash2 class="size-4" />
                                <span class="hidden sm:inline">Quitar</span>
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
