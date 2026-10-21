<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Eye,
    FilePlus2,
    FileSignature,
    FileText,
    FileWarning,
    PencilLine,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import BotonVistaPrevia from '@/components/people/BotonVistaPrevia.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useVistaPreviaPdf } from '@/composables/useVistaPreviaPdf';
import { formatearFecha } from '@/lib/fechas';
import type { Tono } from '@/lib/tonos';
import {
    generar as generarRuta,
    vistaPrevia as vistaPreviaRuta,
} from '@/routes/rh/contratos';
import type {
    DocumentoCorrespondiente,
    EstadoContratoCorrespondiente,
    PlanContratos,
} from '@/types';

/**
 * Contratación desde el expediente (mismo plan que el módulo «Contratos»):
 *  - [Vista previa] SIEMPRE, aunque falten datos: el PDF sale con lo que hay
 *    y cada faltante impreso como «PENDIENTE: <dato>» (marca de agua
 *    «VISTA PREVIA — NO VÁLIDA PARA FIRMA»). Nada se guarda.
 *  - [Completar datos] lleva a la edición de datos personales.
 *  - [Generar contrato] solo cuando el plan es válido (el backend vuelve a
 *    exigir todo: generación estricta).
 *  - [Ver generado] / [Ver contrato firmado] cuando ya existen.
 */
const props = defineProps<{
    plan: PlanContratos & { puede_generar: boolean };
}>();

const emit = defineEmits<{ 'completar-datos': [] }>();

const ESTADOS: Record<
    EstadoContratoCorrespondiente,
    { tono: Tono; icono: Component }
> = {
    generado: { tono: 'exito', icono: CheckCircle2 },
    completo: { tono: 'exito', icono: CheckCircle2 },
    por_generar: { tono: 'info', icono: FilePlus2 },
    faltan_datos: { tono: 'alerta', icono: AlertTriangle },
    formato_no_listo: { tono: 'neutro', icono: FileWarning },
    pendiente_evaluacion: { tono: 'oro', icono: Clock },
    no_aplica: { tono: 'neutro', icono: FileText },
};

const visor = useVistaPreviaPdf(undefined, {
    avisoFaltantes:
        'Vista previa con datos pendientes: cada uno aparece como «PENDIENTE: …». Complétalos para poder generar el contrato oficial.',
});

function verPrevia(doc: DocumentoCorrespondiente) {
    void visor.abrir([
        {
            clave: doc.clave,
            nombre: `${doc.nombre} · ${props.plan.nombre}`,
            url: vistaPreviaRuta.url(
                { colaborador: props.plan.id, clave: doc.clave },
                { query: { pendientes: 1 } },
            ),
        },
    ]);
}

const archivo = ref<{ url: string; nombre: string; mime: string | null; estado: string } | null>(null);

function verGenerado(doc: DocumentoCorrespondiente) {
    if (doc.descargar_url) {
        archivo.value = {
            url: doc.descargar_url,
            nombre: `${doc.nombre}.pdf`,
            mime: 'application/pdf',
            estado: 'Generado · en expediente',
        };
    }
}

function verFirmado(doc: DocumentoCorrespondiente) {
    if (doc.firmado_cargado) {
        archivo.value = {
            url: doc.firmado_cargado.url,
            nombre: doc.firmado_cargado.nombre || `${doc.nombre} firmado`,
            mime: doc.firmado_cargado.mime,
            estado: 'Contrato firmado',
        };
    }
}

const generando = ref(false);

function generar() {
    generando.value = true;
    router.post(
        generarRuta.url(props.plan.id),
        {},
        { preserveScroll: true, onFinish: () => (generando.value = false) },
    );
}

const totalDocumentos = computed(() =>
    props.plan.contratos.reduce((n, e) => n + e.documentos.length, 0),
);
const generados = computed(() =>
    props.plan.contratos.reduce(
        (n, e) => n + e.documentos.filter((d) => d.estado === 'generado').length,
        0,
    ),
);
const firmados = computed(() =>
    props.plan.contratos.reduce(
        (n, e) => n + e.documentos.filter((d) => d.firmado_cargado).length,
        0,
    ),
);
const tonoPlan = computed(() => ESTADOS[props.plan.estado]?.tono ?? 'neutro');
</script>

<template>
    <div class="flex flex-col gap-4" data-contratacion-expediente>
        <!-- Cabecera: estado + acciones globales -->
        <div
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex min-w-0 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <FileSignature class="size-5 text-primary" />
                    <h3 class="text-base font-semibold">Contratos</h3>
                    <ChipEstado
                        :etiqueta="plan.estado_etiqueta"
                        :tono="tonoPlan"
                        :icono="ESTADOS[plan.estado]?.icono"
                    />
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ generados }}/{{ totalDocumentos }} generados ·
                    {{ firmados }} firmado{{ firmados === 1 ? '' : 's' }}
                    <template v-if="plan.fin_periodo_prueba">
                        · periodo de prueba al
                        {{ formatearFecha(plan.fin_periodo_prueba) }}
                    </template>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="plan.faltantes.length > 0 || plan.bloqueos.length > 0"
                    size="sm"
                    variant="outline"
                    data-accion="completar-datos"
                    @click="emit('completar-datos')"
                >
                    <PencilLine class="size-3.5" /> Completar datos
                </Button>
                <Button
                    v-if="plan.puede_generar && plan.generable"
                    size="sm"
                    data-accion="generar-contrato"
                    :disabled="generando"
                    @click="generar"
                >
                    <Spinner v-if="generando" />
                    <FilePlus2 v-else class="size-3.5" />
                    Generar contrato
                </Button>
            </div>
        </div>

        <!-- Lo que falta, en chips (sin párrafos) -->
        <div
            v-if="plan.faltantes.length > 0 || plan.bloqueos.length > 0"
            class="flex flex-col gap-2 rounded-2xl border border-warning/30 bg-warning-soft/40 p-4"
        >
            <p class="flex items-center gap-2 text-sm font-semibold text-warning">
                <AlertTriangle class="size-4" />
                Para generar el contrato oficial falta:
            </p>
            <div class="flex flex-wrap gap-1.5">
                <span
                    v-for="f in plan.faltantes"
                    :key="f.base"
                    class="rounded-full bg-card px-2.5 py-0.5 text-xs font-medium text-warning"
                    >{{ f.etiqueta }}</span
                >
                <span
                    v-for="b in plan.bloqueos"
                    :key="b.etiqueta"
                    class="rounded-full bg-card px-2.5 py-0.5 text-xs font-medium text-warning"
                    :title="b.detalle"
                    >{{ b.etiqueta }}</span
                >
            </div>
            <p
                v-if="plan.contratos.length > 0"
                class="text-xs text-muted-foreground"
            >
                Puedes ver la vista previa igual: cada dato pendiente aparece
                marcado como «PENDIENTE».
            </p>
        </div>

        <p
            v-if="plan.contratos.length === 0"
            class="rounded-2xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground"
        >
            {{ plan.motivo ?? 'Aún no hay contratos que mostrar.' }}
        </p>

        <!-- Etapas y documentos -->
        <section
            v-for="etapa in plan.contratos"
            :key="etapa.etapa"
            class="rounded-2xl border border-border/60 bg-card"
        >
            <header
                class="flex flex-wrap items-center justify-between gap-2 border-b border-border/50 px-4 py-3"
            >
                <div>
                    <p class="text-sm font-semibold">{{ etapa.etiqueta }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ etapa.tipo_etiqueta }} ·
                        {{ formatearFecha(etapa.fecha_inicio) }}
                        <template v-if="etapa.fecha_fin">
                            → {{ formatearFecha(etapa.fecha_fin) }}</template
                        >
                    </p>
                </div>
                <ChipEstado
                    :etiqueta="etapa.estado_contrato ?? 'Sin registrar'"
                    :tono="etapa.contrato_id ? 'exito' : 'neutro'"
                />
            </header>
            <ul class="divide-y divide-border/50">
                <li
                    v-for="doc in etapa.documentos"
                    :key="doc.clave"
                    class="flex flex-col gap-2 px-4 py-3 lg:flex-row lg:items-center lg:justify-between"
                    :data-documento="doc.clave"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <FileText class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <div class="min-w-0">
                            <p class="text-sm font-medium">{{ doc.nombre }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                <ChipEstado
                                    :etiqueta="doc.estado_etiqueta"
                                    :tono="ESTADOS[doc.estado]?.tono ?? 'neutro'"
                                    :icono="ESTADOS[doc.estado]?.icono"
                                />
                                <ChipEstado
                                    v-if="doc.firmado_cargado"
                                    etiqueta="Firmado"
                                    tono="exito"
                                    :icono="FileSignature"
                                />
                                <span
                                    v-if="doc.estado === 'faltan_datos'"
                                    class="text-xs text-warning"
                                    >{{ doc.faltantes.length }} dato{{
                                        doc.faltantes.length === 1 ? '' : 's'
                                    }}
                                    pendiente{{ doc.faltantes.length === 1 ? '' : 's' }}</span
                                >
                                <span
                                    v-else-if="doc.motivo && doc.estado !== 'generado'"
                                    class="text-xs text-muted-foreground"
                                    >{{ doc.motivo }}</span
                                >
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:shrink-0 lg:justify-end">
                        <BotonVistaPrevia
                            v-if="doc.estado === 'por_generar' || doc.estado === 'faltan_datos'"
                            size="sm"
                            :cargando="visor.generandoClave(doc.clave)"
                            data-accion="vista-previa"
                            @click="verPrevia(doc)"
                        />
                        <Button
                            v-if="doc.descargar_url"
                            size="sm"
                            variant="outline"
                            @click="verGenerado(doc)"
                        >
                            <Eye class="size-3.5" /> Ver generado
                        </Button>
                        <Button
                            v-if="doc.firmado_cargado"
                            size="sm"
                            variant="secondary"
                            @click="verFirmado(doc)"
                        >
                            <FileSignature class="size-3.5" /> Ver contrato firmado
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <DocumentPreviewDialog
            :open="visor.abierto.value"
            :archivos="visor.archivos.value"
            :indice="visor.indice.value"
            :cargando="visor.cargando.value"
            :titulo-carga="visor.tituloCarga.value"
            @update:open="visor.alCambiarAbierto"
            @update:indice="(i: number) => (visor.indice.value = i)"
        />
        <DocumentPreviewDialog
            :open="archivo !== null"
            :preview-url="archivo?.url ?? null"
            :nombre="archivo?.nombre ?? ''"
            :mime="archivo?.mime ?? null"
            :estado="archivo?.estado ?? null"
            @update:open="(v: boolean) => !v && (archivo = null)"
        />
    </div>
</template>
