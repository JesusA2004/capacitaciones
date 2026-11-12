<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ExternalLink, Eye, FilePenLine, FileSignature } from '@lucide/vue';
import { computed, ref } from 'vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { usePermisos } from '@/composables/usePermisos';
import { formatearFecha } from '@/lib/fechas';
import { create, show } from '@/routes/rh/cartas-compromiso';
import type { EstadoCartaCompromiso } from '@/types';

/**
 * Carta compromiso de documentos pendientes del colaborador (la más
 * reciente): folio, estado, fecha límite, documentos comprometidos y su
 * PDF. La arma App\Services\Expedientes\CartaCompromisoService::paraExpediente
 * como prop `cartaCompromiso` de la página del expediente.
 *
 * En el expediente de RH (no en «Mi expediente») ofrece además abrirla en
 * «Cartas compromiso» (aprobar, subir la firmada) o generar una nueva para
 * esta persona. Los botones son solo UX: el backend vuelve a autorizar
 * (CartaCompromisoPolicy).
 */
export type CartaCompromisoExpediente = {
    id: number;
    folio: string | null;
    estado: EstadoCartaCompromiso;
    estado_etiqueta: string;
    fecha_limite: string;
    dias_restantes: number;
    documentos: { document_type_id: number; nombre: string }[];
    generada_en: string | null;
    motivo_rechazo: string | null;
    cumplida_en: string | null;
    pdf_url: string;
};

const props = withDefaults(
    defineProps<{
        esPropio?: boolean;
        colaboradorId?: number | null;
        /** Le falta al menos un documento requerido (para ofrecer generar la carta). */
        faltanDocumentos?: boolean;
    }>(),
    { esPropio: false, colaboradorId: null, faltanDocumentos: false },
);

const page = usePage<{ cartaCompromiso?: CartaCompromisoExpediente | null }>();
const carta = computed(() => page.props.cartaCompromiso ?? null);
const { tienePermiso } = usePermisos();
/** «Ver carta»: PDF en el visor centrado (no en otra pestaña). */
const visorAbierto = ref(false);

const claseEstado = computed(() => {
    switch (carta.value?.estado) {
        case 'cumplida':
            return 'bg-success-soft text-success';
        case 'vencida':
            return 'bg-danger-soft text-danger';
        case 'vigente':
        case 'pendiente_aprobacion':
            return 'bg-warning-soft text-warning';
        default:
            return 'bg-muted text-muted-foreground';
    }
});

const plazo = computed(() => {
    if (!carta.value) {
        return null;
    }

    if (carta.value.estado === 'pendiente_aprobacion') {
        return 'El plazo empieza a correr cuando RH la apruebe';
    }

    if (carta.value.estado !== 'vigente') {
        return null;
    }

    return carta.value.dias_restantes === 0
        ? 'Vence hoy'
        : `Faltan ${carta.value.dias_restantes} día(s)`;
});

const sinAprobar = computed(
    () =>
        carta.value?.estado === 'pendiente_aprobacion' ||
        carta.value?.estado === 'rechazada',
);

/** Expediente de RH: abrir la carta en su módulo. */
const puedeAbrirEnModulo = computed(
    () =>
        !props.esPropio &&
        carta.value !== null &&
        tienePermiso('cartas_compromiso.ver'),
);

/**
 * Expediente de RH sin carta abierta: generar una (la pantalla valida si la
 * persona es elegible y explica por qué no, si no lo es).
 */
const puedeGenerar = computed(
    () =>
        !props.esPropio &&
        props.colaboradorId !== null &&
        tienePermiso('cartas_compromiso.generar') &&
        (carta.value === null ||
            carta.value.estado === 'cumplida' ||
            carta.value.estado === 'rechazada'),
);
</script>

<template>
    <Card v-if="carta" class="rounded-2xl border-border/60 bg-crema">
        <CardContent class="flex flex-col gap-3 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                    >
                        <FileSignature class="size-4" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold">
                            Carta compromiso de documentos
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Folio {{ carta.folio ?? '—' }}
                            <template v-if="!sinAprobar">
                                · Fecha límite
                                {{ formatearFecha(carta.fecha_limite) }}
                            </template>
                            <template v-if="plazo"> · {{ plazo }}</template>
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="claseEstado"
                    >
                        {{ carta.estado_etiqueta }}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="visorAbierto = true"
                    >
                        <Eye class="size-4" /> Ver carta
                    </Button>
                    <Button
                        v-if="puedeAbrirEnModulo"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="show.url(carta.id)">
                            <ExternalLink class="size-4" />
                            {{
                                carta.estado === 'pendiente_aprobacion'
                                    ? 'Revisar y aprobar'
                                    : 'Abrir en Cartas compromiso'
                            }}
                        </Link>
                    </Button>
                    <Button
                        v-if="puedeGenerar && colaboradorId !== null"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="
                                create.url({
                                    query: { colaborador: colaboradorId },
                                })
                            "
                        >
                            <FilePenLine class="size-4" /> Generar carta nueva
                        </Link>
                    </Button>
                </div>
            </div>
            <ul class="flex flex-wrap gap-2">
                <li
                    v-for="documento in carta.documentos"
                    :key="documento.document_type_id"
                    class="rounded-lg border border-border/60 bg-card px-2.5 py-1 text-xs"
                >
                    {{ documento.nombre }}
                </li>
            </ul>
            <p
                v-if="carta.estado === 'pendiente_aprobacion'"
                class="text-xs text-muted-foreground"
            >
                {{
                    esPropio
                        ? 'Recursos Humanos revisará tu solicitud. Mientras tanto, sube tus documentos en cuanto los tengas.'
                        : 'La pidió la propia persona: falta la aprobación de Recursos Humanos para que corra su plazo.'
                }}
            </p>
            <p
                v-else-if="carta.estado === 'rechazada'"
                class="text-xs text-muted-foreground"
            >
                Recursos Humanos no la aprobó<template
                    v-if="carta.motivo_rechazo"
                    >. Motivo: {{ carta.motivo_rechazo }}</template
                >.
            </p>
            <p
                v-else-if="carta.estado === 'vencida'"
                class="text-xs text-muted-foreground"
            >
                El plazo venció con documentos pendientes: en la app solo puede
                subir esos documentos hasta completarlos.
            </p>
        </CardContent>

        <DocumentPreviewDialog
            v-model:open="visorAbierto"
            :preview-url="carta.pdf_url"
            :download-url="carta.pdf_url"
            :nombre="`Carta compromiso ${carta.folio ?? ''}.pdf`"
            mime="application/pdf"
            tipo="pdf"
            :estado="carta.estado_etiqueta"
        />
    </Card>
    <!-- RH, sin carta todavía: generarla desde el expediente. -->
    <div
        v-else-if="puedeGenerar && faltanDocumentos && colaboradorId !== null"
        class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-dashed border-border/60 bg-crema/40 px-5 py-3 text-sm"
    >
        <p class="text-muted-foreground">
            ¿Le faltan documentos obligatorios? Genérale una carta compromiso
            con plazo para entregarlos (solo nuevo ingreso).
        </p>
        <Button variant="outline" size="sm" as-child>
            <Link :href="create.url({ query: { colaborador: colaboradorId } })">
                <FilePenLine class="size-4" /> Generar carta compromiso
            </Link>
        </Button>
    </div>
</template>
