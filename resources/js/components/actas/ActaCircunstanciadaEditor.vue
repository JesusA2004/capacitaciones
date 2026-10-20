<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    FileCheck2,
    FileSearch,
    PenLine,
    Save,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ActaAnexos from '@/components/actas/ActaAnexos.vue';
import ActaCircunstanciadaCampos from '@/components/actas/ActaCircunstanciadaCampos.vue';
import ActaCircunstanciadaResumen from '@/components/actas/ActaCircunstanciadaResumen.vue';
import ActaPasos from '@/components/actas/ActaPasos.vue';
import BotonVistaPrevia from '@/components/people/BotonVistaPrevia.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    ErrorDocumento,
    useDocumentosProceso,
} from '@/composables/useDocumentosProceso';
import {
    cargaActa,
    faltantesActa,
    formularioDesdeActa,
    PASOS_ACTA,
} from '@/lib/actas';
import { update, vistaPrevia } from '@/routes/rh/actas';
import type { ActaDetalle, CatalogosActa } from '@/types';

/**
 * Borrador del acta administrativa circunstanciada: captura por pasos
 * (1 Incidencia … 6 Revisión). Cada cambio de paso guarda el borrador; la
 * vista previa es el PDF REAL (mismo servicio que el definitivo) y el
 * definitivo congela el PDF en el expediente.
 */
const props = defineProps<{
    acta: ActaDetalle;
    catalogos: CatalogosActa;
    pasoInicial?: number;
}>();

const paso = ref<number>(Math.min(Math.max(props.pasoInicial ?? 1, 1), 6));
const form = useForm(formularioDesdeActa(props.acta));
const trabajador = computed(() => ({
    id: props.acta.colaborador.id,
    nombre: props.acta.colaborador.nombre,
}));
const faltantes = computed(() => faltantesActa(form.data()));
// Pasos 1 a 4 se capturan en ActaCircunstanciadaCampos.
const pasoCaptura = computed(() => Math.min(paso.value, 4) as 1 | 2 | 3 | 4);
const titulo = computed(
    () => PASOS_ACTA.find((p) => p.numero === paso.value)?.titulo ?? '',
);

/** Errores de cada paso: si el backend rechaza algo, se regresa a ese paso. */
const CAMPOS_POR_PASO: [number, string[]][] = [
    [1, ['fecha', 'hora', 'lugar', 'circunstanciada.incidencia']],
    [2, ['hechos', 'circunstanciada.disposiciones']],
    [
        3,
        [
            'circunstanciada.jefe.colaborador_id',
            'circunstanciada.testigos_hechos',
            'circunstanciada.testigos_asistencia',
        ],
    ],
    [4, ['circunstanciada']],
];

function pasoDeError(errores: Record<string, string>): number | null {
    const claves = Object.keys(errores);

    for (const [p, campos] of CAMPOS_POR_PASO) {
        if (claves.some((k) => campos.some((c) => k.startsWith(c)))) {
            return p;
        }
    }

    return null;
}

/** Guarda el borrador (si cambió) y después ejecuta `luego`. */
function guardar(luego?: () => void) {
    if (!form.isDirty) {
        luego?.();

        return;
    }

    form.transform((datos) => cargaActa(datos)).put(update.url(props.acta.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            form.defaults();
            luego?.();
        },
        onError: (errores) => {
            const p = pasoDeError(errores);

            if (p !== null) {
                paso.value = p;
            }
        },
    });
}

function ir(p: number) {
    if (p === paso.value) {
        return;
    }

    guardar(() => {
        paso.value = p;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

// ───── Vista previa: el PDF real, sin guardar documento ─────
const previaAbierta = ref(false);
const versionPrevia = ref(0);
const urlPrevia = computed(() =>
    vistaPrevia.url(props.acta.id, { query: { v: versionPrevia.value } }),
);

/** Guardando antes de abrir la vista previa: el botón muestra spinner. */
const preparandoPrevia = ref(false);

watch(
    () => form.processing,
    (procesando) => {
        if (!procesando) {
            preparandoPrevia.value = false;
        }
    },
);

function abrirPrevia() {
    preparandoPrevia.value = true;
    guardar(() => {
        preparandoPrevia.value = false;
        versionPrevia.value = Date.now();
        previaAbierta.value = true;
    });
}

// ───── Acta definitiva ─────
const confirmar = ref(false);
const generando = ref(false);
const api = useDocumentosProceso();

function pedirDefinitiva() {
    guardar(() => {
        confirmar.value = true;
    });
}

async function generarDefinitiva() {
    generando.value = true;

    try {
        await api.generar('acta', props.acta.id, {
            clave: 'acta_administrativa',
            proceso: 'acta',
        });
        confirmar.value = false;
        toast.success('Acta definitiva generada y guardada en el expediente.');
        router.reload();
    } catch (e) {
        toast.error(
            e instanceof ErrorDocumento
                ? e.message
                : 'No se pudo generar el acta. Intenta de nuevo.',
        );
    } finally {
        generando.value = false;
    }
}
</script>

<template>
    <section
        class="flex flex-col gap-5 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 sm:p-5"
    >
        <ActaPasos :actual="paso" :deshabilitado="form.processing" @ir="ir" />

        <div class="flex items-center justify-between gap-2">
            <h2 class="text-base font-semibold">{{ paso }}. {{ titulo }}</h2>
            <span
                v-if="form.isDirty"
                class="inline-flex items-center gap-1 text-xs text-muted-foreground"
            >
                <PenLine class="size-3.5" /> Cambios sin guardar
            </span>
        </div>

        <div :key="paso" class="entrada-suave">
            <ActaCircunstanciadaCampos
                v-if="paso <= 4"
                v-model="form"
                :paso="pasoCaptura"
                :errores="form.errors"
                :catalogos="catalogos"
                :trabajador="trabajador"
                :responsable="acta.circunstanciada?.responsable ?? null"
            />

            <div v-else-if="paso === 5" class="flex flex-col gap-3">
                <p class="text-xs text-muted-foreground">
                    Cada anexo se relaciona en el acta con su descripción, fecha
                    o identificación y su relación con los hechos. Si no hay, se
                    imprime «No se incorporaron anexos a la presente acta.».
                </p>
                <ActaAnexos :acta="acta" editable />
            </div>

            <div v-else class="flex flex-col gap-5">
                <ActaCircunstanciadaResumen
                    :acta="acta"
                    :catalogos="catalogos"
                />

                <div
                    v-if="faltantes.length"
                    class="flex flex-col gap-1.5 rounded-xl bg-warning-soft p-3 text-sm"
                >
                    <p class="flex items-center gap-1.5 font-medium">
                        <TriangleAlert class="size-4" /> Falta para generar el
                        acta
                    </p>
                    <ul class="list-disc pl-6 text-xs">
                        <li v-for="f in faltantes" :key="f">{{ f }}</li>
                    </ul>
                </div>

                <div
                    class="flex flex-col gap-1 rounded-xl border border-[var(--mrl-borde)] bg-crema/40 p-3 text-xs text-muted-foreground"
                >
                    <p>
                        Las firmas se recabarán manualmente sobre el documento
                        impreso.
                    </p>
                    <p>
                        Al generar el acta definitiva, el PDF quedará congelado
                        en el expediente. Verifica la vista previa antes de
                        continuar.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <BotonVistaPrevia
                        texto="Vista previa PDF"
                        :icono="FileSearch"
                        :cargando="preparandoPrevia"
                        :deshabilitado="faltantes.length > 0 || form.processing"
                        @click="abrirPrevia"
                    />
                    <Button
                        :disabled="faltantes.length > 0 || form.processing"
                        @click="pedirDefinitiva"
                    >
                        <FileCheck2 class="size-4" /> Generar acta definitiva
                    </Button>
                </div>
            </div>
        </div>

        <div
            class="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--mrl-borde)] pt-4"
        >
            <Button
                v-if="paso > 1"
                variant="ghost"
                :disabled="form.processing"
                @click="ir(paso - 1)"
            >
                <ChevronLeft class="size-4" /> Atrás
            </Button>
            <span v-else />
            <div class="flex gap-2">
                <Button
                    v-if="paso <= 4"
                    variant="outline"
                    :disabled="!form.isDirty || form.processing"
                    @click="guardar()"
                >
                    <Spinner v-if="form.processing" class="size-4" /><Save
                        v-else
                        class="size-4"
                    />
                    Guardar borrador
                </Button>
                <Button
                    v-if="paso < 6"
                    :disabled="form.processing"
                    @click="ir(paso + 1)"
                >
                    Siguiente <ChevronRight class="size-4" />
                </Button>
            </div>
        </div>
    </section>

    <DocumentPreviewDialog
        v-model:open="previaAbierta"
        :preview-url="urlPrevia"
        :download-url="urlPrevia"
        :nombre="`Vista previa ${acta.folio ?? ''}`"
        tipo="pdf"
    />

    <Dialog v-model:open="confirmar">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Generar acta definitiva</DialogTitle>
                <DialogDescription>
                    Al generar el acta definitiva, el PDF quedará congelado en
                    el expediente. Verifica la vista previa antes de continuar.
                </DialogDescription>
            </DialogHeader>
            <p class="text-sm text-muted-foreground">
                Después ya no se edita: los cambios se registran como
                seguimiento. Las firmas se recaban a mano sobre el impreso.
            </p>
            <DialogFooter class="gap-2">
                <Button
                    variant="ghost"
                    :disabled="generando"
                    @click="confirmar = false"
                    >Revisar de nuevo</Button
                >
                <Button :disabled="generando" @click="generarDefinitiva">
                    <Spinner v-if="generando" class="size-4" /><FileCheck2
                        v-else
                        class="size-4"
                    />
                    Generar acta definitiva
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
