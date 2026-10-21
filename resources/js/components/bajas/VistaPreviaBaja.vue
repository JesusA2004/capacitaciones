<script setup lang="ts">
import { Calculator, FileText, Paperclip } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import BotonVistaPrevia from '@/components/people/BotonVistaPrevia.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { useDocumentosProceso } from '@/composables/useDocumentosProceso';
import { useVistaPreviaPdf } from '@/composables/useVistaPreviaPdf';
import type { PdfPorVer } from '@/composables/useVistaPreviaPdf';
import rutasBajas from '@/routes/rh/bajas';
import type { CamposBaja, DocumentoVistaPrevia } from '@/types';

/**
 * «Antes de registrar»: lo que se generará al registrar la baja —la
 * renuncia / el aviso de la causal (VistaPreviaFlujoService, flujo «baja»)
 * y el FINIQUITO propuesto (BajaService::vistaPreviaFiniquito)— visto en el
 * visor único como galería, con flechas para pasar de uno a otro. El backend
 * arma todo en memoria: nada se guarda hasta confirmar. En rescisión no se
 * genera documento (el aviso de rescisión se adjunta después).
 */
const props = defineProps<{
    colaboradorId: number | null;
    campos: CamposBaja;
    /** Tipo de baja del cierre que corresponde a la forma elegida. */
    tipoBaja: string;
    disabled?: boolean;
}>();

const api = useDocumentosProceso();
const visor = useVistaPreviaPdf();
const documentos = ref<DocumentoVistaPrevia[]>([]);
let temporizador: ReturnType<typeof setTimeout> | null = null;
let consulta = 0;

const listo = computed(
    () => props.colaboradorId !== null && Boolean(props.campos.forma_salida),
);

const datosFlujo = computed(() => ({
    tipo_baja: props.tipoBaja,
    causal_rotacion_id: props.campos.causal_rotacion_id ?? undefined,
    fecha_efectiva: props.campos.fecha_baja,
    motivo: props.campos.comentarios,
}));

const urlFiniquito = computed(() =>
    props.colaboradorId === null || !props.campos.forma_salida
        ? null
        : rutasBajas.previa.finiquito.url({
              query: {
                  colaborador_id: props.colaboradorId,
                  forma_salida: props.campos.forma_salida,
                  causal_rotacion_id:
                      props.campos.causal_rotacion_id ?? undefined,
                  fecha_baja: props.campos.fecha_baja || undefined,
              },
          }),
);

async function cargarDocumentos() {
    const actual = ++consulta;

    if (!listo.value || !props.tipoBaja || props.colaboradorId === null) {
        documentos.value = [];

        return;
    }

    try {
        const lista = await api.previaFlujo(
            'baja',
            props.colaboradorId,
            datosFlujo.value,
        );

        if (actual === consulta) {
            documentos.value = lista;
        }
    } catch {
        if (actual === consulta) {
            documentos.value = [];
        }
    }
}

watch(
    () => [
        props.colaboradorId,
        props.tipoBaja,
        JSON.stringify(datosFlujo.value),
    ],
    () => {
        if (temporizador) {
            clearTimeout(temporizador);
        }

        temporizador = setTimeout(() => void cargarDocumentos(), 300);
    },
    { immediate: true },
);

onBeforeUnmount(() => temporizador && clearTimeout(temporizador));

const porVer = computed<PdfPorVer[]>(() => [
    ...documentos.value,
    ...(urlFiniquito.value
        ? [
              {
                  clave: 'finiquito',
                  nombre: 'Finiquito propuesto',
                  url: urlFiniquito.value,
              },
          ]
        : []),
]);

function ver(clave: string | null = null) {
    const lista =
        clave === null
            ? porVer.value
            : porVer.value.filter((d) => d.clave === clave);
    void visor.abrir(lista);
}
</script>

<template>
    <section
        v-if="listo"
        class="grid gap-3 rounded-2xl border border-oro/40 bg-crema p-4"
        aria-labelledby="previa-baja-titulo"
    >
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <p
                    id="previa-baja-titulo"
                    class="text-xs font-semibold tracking-wide text-bronce uppercase"
                >
                    Se genera al registrar
                </p>
                <p class="text-sm text-muted-foreground">
                    Revísalo antes de confirmar: nada se guarda todavía.
                </p>
            </div>
            <BotonVistaPrevia
                class="w-full shrink-0 sm:w-auto"
                size="lg"
                variant="default"
                :texto="
                    porVer.length > 1
                        ? `Vista previa (${porVer.length})`
                        : 'Vista previa'
                "
                :cargando="visor.cargando.value"
                :deshabilitado="disabled || porVer.length === 0"
                @click="ver()"
            />
        </div>

        <ul class="grid gap-2">
            <li v-for="d in documentos" :key="d.clave">
                <button
                    type="button"
                    class="flex w-full tarjeta-interactiva items-center gap-3 rounded-xl border border-border/60 bg-card px-3 py-2.5 text-left text-sm"
                    :disabled="disabled || visor.cargando.value"
                    @click="ver(d.clave)"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-salvia text-primary"
                        aria-hidden="true"
                        ><FileText class="size-4"
                    /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{
                            d.nombre
                        }}</span>
                        <span class="text-xs text-muted-foreground"
                            >Se genera listo para imprimir y firmar</span
                        >
                    </span>
                </button>
            </li>
            <li v-if="urlFiniquito">
                <button
                    type="button"
                    class="flex w-full tarjeta-interactiva items-center gap-3 rounded-xl border border-border/60 bg-card px-3 py-2.5 text-left text-sm"
                    :disabled="disabled || visor.cargando.value"
                    @click="ver('finiquito')"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-crema text-bronce"
                        aria-hidden="true"
                        ><Calculator class="size-4"
                    /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium"
                            >Finiquito propuesto</span
                        >
                        <span class="text-xs text-muted-foreground"
                            >Con el salario registrado; RH lo ajusta</span
                        >
                    </span>
                </button>
            </li>
            <li
                v-if="campos.forma_salida === 'RE'"
                class="flex items-center gap-3 rounded-xl border border-dashed border-border px-3 py-2.5 text-sm"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                    aria-hidden="true"
                    ><Paperclip class="size-4"
                /></span>
                <span class="min-w-0 flex-1">
                    <span class="block font-medium">Aviso de rescisión</span>
                    <span class="text-xs text-muted-foreground"
                        >No se genera: se adjunta firmado después</span
                    >
                </span>
            </li>
        </ul>
    </section>

    <DocumentPreviewDialog
        :open="visor.abierto.value"
        :archivos="visor.archivos.value"
        :indice="visor.indice.value"
        :cargando="visor.cargando.value"
        :titulo-carga="visor.tituloCarga.value"
        recargable
        @update:open="visor.alCambiarAbierto"
        @recargar="visor.recargar"
        @update:indice="(i: number) => (visor.indice.value = i)"
    />
</template>
