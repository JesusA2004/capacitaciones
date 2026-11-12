<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    Download,
    ExternalLink,
    FileSignature,
    FileText,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { formatearFecha, formatearFechaHora } from '@/lib/fechas';
import { postJson } from '@/lib/http';
import { tonoDocumentoLaboral } from '@/lib/misDocumentos';
import { index as indexPortal } from '@/routes/portal';
import {
    apertura,
    descargar,
    firmar,
    index as rutaIndex,
    ver,
} from '@/routes/portal/documentos-laborales';
import type { DocumentoLaboralPropio } from '@/types';

/**
 * Detalle de un documento laboral propio con su PDF en línea y la firma
 * digital, igual que la app: el visor confirma la apertura al servidor
 * (sin ella no se habilita la firma) y el colaborador acepta con «Leí el
 * documento y acepto su contenido». No hay firma dibujada.
 */
const props = defineProps<{ documento: DocumentoLaboralPropio }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mi portal', href: indexPortal() },
            { title: 'Mis documentos laborales', href: rutaIndex() },
        ],
    },
});

const { confirmarAccion, mostrarError } = useAlertas();

const urlVer = computed(() => ver.url(props.documento.id));
const aperturaVerificada = ref(false);
const acepto = ref(false);
const firmando = ref(false);
let aperturaEnCurso = false;

/** El PDF ya se mostró en el visor: se registra la apertura (solo si toca firmar). */
async function pdfCargado() {
    if (
        !props.documento.puede_firmar ||
        aperturaVerificada.value ||
        aperturaEnCurso
    ) {
        return;
    }

    aperturaEnCurso = true;

    try {
        await postJson(apertura.url(props.documento.id), {});
        aperturaVerificada.value = true;
    } catch {
        mostrarError(
            'No pudimos confirmar que abriste el documento. Recarga la página.',
        );
    } finally {
        aperturaEnCurso = false;
    }
}

async function firmarDocumento() {
    const confirmado = await confirmarAccion(
        'Firmar documento',
        `Vas a firmar digitalmente «${props.documento.titulo}». Quedan registrados la fecha, tu navegador y la huella del documento.`,
        'Firmar',
    );

    if (!confirmado) {
        return;
    }

    firmando.value = true;
    router.post(
        firmar.url(props.documento.id),
        { acepto: true },
        {
            preserveScroll: true,
            onError: (errores) => {
                // El servidor manda: se vuelve a pedir la apertura.
                aperturaVerificada.value = false;
                acepto.value = false;
                mostrarError(
                    errores.documento ??
                        errores.acepto ??
                        'No fue posible firmar el documento.',
                );
            },
            onSuccess: () => {
                acepto.value = false;
                router.reload({ only: ['documento'] });
            },
            onFinish: () => {
                firmando.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="documento.titulo" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader :titulo="documento.titulo" :icono="FileText" detalle />

        <div class="flex flex-wrap items-center gap-2">
            <ChipEstado
                tamano="md"
                :etiqueta="
                    documento.puede_firmar
                        ? 'Por firmar'
                        : (documento.estado_etiqueta ?? 'Disponible')
                "
                :tono="tonoDocumentoLaboral(documento)"
            />
            <span class="text-sm text-muted-foreground">
                Emitido {{ formatearFecha(documento.generado_en) }}
            </span>
            <span
                v-if="documento.firmado_digital_en"
                class="text-sm text-muted-foreground"
            >
                · Firmado {{ formatearFechaHora(documento.firmado_digital_en) }}
            </span>
        </div>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div
                class="overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm"
            >
                <iframe
                    :key="`${documento.id}-${documento.estado}`"
                    :src="urlVer"
                    :title="documento.titulo"
                    class="h-[70vh] min-h-[420px] w-full"
                    @load="pdfCargado"
                />
            </div>

            <aside class="flex flex-col gap-3">
                <div
                    v-if="documento.puede_firmar"
                    class="flex flex-col gap-3 rounded-2xl border border-warning/50 bg-card p-4 shadow-sm"
                >
                    <p class="flex items-center gap-2 font-semibold">
                        <FileSignature class="size-4 text-warning" />
                        Firma digital
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Lee el documento completo antes de firmar.
                    </p>
                    <p
                        v-if="!aperturaVerificada"
                        class="rounded-lg bg-crema px-3 py-2 text-xs text-muted-foreground"
                    >
                        Espera a que el documento se muestre para habilitar la
                        firma.
                    </p>
                    <label
                        class="flex items-start gap-2 text-sm"
                        :class="aperturaVerificada ? '' : 'opacity-50'"
                    >
                        <Casilla
                            v-model="acepto"
                            :disabled="!aperturaVerificada"
                            class="mt-0.5"
                        />
                        Leí el documento y acepto su contenido.
                    </label>
                    <Button
                        :disabled="!acepto || !aperturaVerificada || firmando"
                        @click="firmarDocumento"
                    >
                        <FileSignature class="size-4" />
                        Firmar / Aceptar
                    </Button>
                </div>

                <div
                    v-else-if="documento.firmado_digital_en"
                    class="flex items-center gap-2 rounded-2xl bg-success-soft p-4 text-sm font-medium text-success"
                >
                    <CheckCircle2 class="size-4" />
                    Ya lo firmaste.
                </div>

                <p
                    v-if="
                        documento.requiere_firma_fisica ||
                        documento.original_fisico
                    "
                    class="rounded-2xl bg-muted px-4 py-3 text-xs text-muted-foreground"
                >
                    El original impreso lo gestiona Recursos Humanos.
                </p>

                <Button variant="outline" as-child>
                    <a :href="urlVer" target="_blank" rel="noopener">
                        <ExternalLink class="size-4" />
                        Abrir en otra pestaña
                    </a>
                </Button>
                <Button
                    v-if="documento.puede_descargar"
                    variant="outline"
                    as-child
                >
                    <a :href="descargar.url(documento.id)">
                        <Download class="size-4" />
                        Descargar PDF
                    </a>
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="rutaIndex()">
                        <ArrowLeft class="size-4" />
                        Mis documentos
                    </Link>
                </Button>
            </aside>
        </div>
    </div>
</template>
