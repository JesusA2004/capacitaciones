<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarClock,
    CircleCheck,
    CircleDashed,
    FilePenLine,
    FileSignature,
    Printer,
    Upload,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatearFecha, formatearFechaHora } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index, subirFirmada } from '@/routes/rh/cartas-compromiso';
import type { CartaCompromisoDetalle } from '@/types';

const props = defineProps<{ carta: CartaCompromisoDetalle }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cartas compromiso', href: index() },
            { title: 'Detalle', href: '' },
        ],
    },
});

const VARIANTE_ESTADO = {
    vigente: 'info',
    cumplida: 'success',
    vencida: 'destructive',
    sustituida: 'secondary',
} as const;

const plazo = computed(() => {
    const c = props.carta;

    if (c.estado !== 'vigente') {
        return `Fecha límite: ${formatearFecha(c.fecha_limite)}`;
    }

    if (c.dias_restantes === 0) {
        return `Vence hoy (${formatearFecha(c.fecha_limite)})`;
    }

    return `Vence en ${c.dias_restantes} ${c.dias_restantes === 1 ? 'día' : 'días'} (${formatearFecha(c.fecha_limite)})`;
});

// ───── Visores ─────
const cartaAbierta = ref(false);
const firmadaAbierta = ref(false);

// ───── Subir carta firmada ─────
const subirAbierto = ref(false);
const archivoUrl = ref<string | null>(null);
const archivoTipo = ref<string>('');
const form = useForm<{ archivo: File | null; confirmo_firma: boolean }>({
    archivo: null,
    confirmo_firma: false,
});

function limpiarArchivo() {
    if (archivoUrl.value !== null) {
        URL.revokeObjectURL(archivoUrl.value);
    }

    archivoUrl.value = null;
    archivoTipo.value = '';
}

onBeforeUnmount(limpiarArchivo);

function abrirSubir() {
    form.reset();
    form.clearErrors();
    limpiarArchivo();
    subirAbierto.value = true;
}

function elegirArchivo(evento: Event) {
    const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    limpiarArchivo();
    form.archivo = archivo;

    if (
        archivo &&
        (archivo.type.startsWith('image/') ||
            archivo.type === 'application/pdf')
    ) {
        archivoUrl.value = URL.createObjectURL(archivo);
        archivoTipo.value = archivo.type;
    }
}

function enviarFirmada() {
    form.post(subirFirmada.url(props.carta.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            subirAbierto.value = false;
            limpiarArchivo();
        },
    });
}

const extensionFirmada = computed(() => {
    const mime = props.carta.archivo_firmado?.mime ?? '';

    return mime.startsWith('image/') ? mime.replace('image/', '') : 'pdf';
});
</script>

<template>
    <Head :title="`Carta compromiso ${carta.folio ?? ''}`" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            :titulo="`Carta compromiso ${carta.folio ?? ''}`"
            :descripcion="carta.colaborador?.nombre ?? ''"
            :icono="FilePenLine"
            detalle
        >
            <div data-tour="carta-acciones" class="flex flex-wrap gap-2">
                <Button variant="ghost" as-child>
                    <Link :href="index.url()">
                        <ArrowLeft class="size-4" /> Lista
                    </Link>
                </Button>
                <Button
                    v-if="carta.puede.descargar"
                    variant="outline"
                    @click="cartaAbierta = true"
                >
                    <Printer class="size-4" /> Ver / imprimir carta
                </Button>
                <Button v-if="carta.puede.subir_firmada" @click="abrirSubir">
                    <Upload class="size-4" /> Subir carta firmada
                </Button>
                <Button
                    v-if="carta.archivo_firmado && carta.puede.descargar"
                    variant="outline"
                    @click="firmadaAbierta = true"
                >
                    <FileSignature class="size-4" /> Ver carta firmada
                </Button>
            </div>
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <Badge :variant="VARIANTE_ESTADO[carta.estado]">{{
                carta.estado_etiqueta
            }}</Badge>
            <Badge :variant="carta.firmada ? 'success' : 'warning'">{{
                carta.firmada ? 'Firmada' : 'Pendiente de firma'
            }}</Badge>
            <span
                class="text-sm"
                :class="
                    carta.por_vencer
                        ? 'font-medium text-warning'
                        : 'text-muted-foreground'
                "
                >{{ plazo }}</span
            >
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
            <div class="flex flex-col gap-6">
                <section
                    class="grid gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5 text-sm sm:grid-cols-2"
                >
                    <div>
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Persona
                        </p>
                        <p class="font-semibold">
                            {{ carta.colaborador?.nombre }}
                        </p>
                        <p class="text-muted-foreground">
                            {{
                                [
                                    carta.colaborador?.numero_empleado,
                                    carta.colaborador?.puesto,
                                    carta.colaborador?.sucursal,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Generada por
                        </p>
                        <p class="font-semibold">
                            {{ carta.generada_por ?? '—' }}
                        </p>
                        <p class="text-muted-foreground">
                            {{ formatearFechaHora(carta.generada_en) }}
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Plazo
                        </p>
                        <p class="font-semibold">
                            {{ carta.dias_plazo }} días naturales
                        </p>
                        <p class="text-muted-foreground">
                            Límite: {{ formatearFecha(carta.fecha_limite) }}
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            Firma
                        </p>
                        <p class="font-semibold">
                            {{
                                carta.firmada_en
                                    ? formatearFechaHora(carta.firmada_en)
                                    : 'Pendiente'
                            }}
                        </p>
                        <p class="text-muted-foreground">
                            {{
                                carta.firma_registrada_por
                                    ? `Subida por ${carta.firma_registrada_por}`
                                    : 'Imprime la carta, que la firme con su huella y sube el escaneo.'
                            }}
                        </p>
                    </div>
                </section>

                <section
                    data-tour="carta-documentos"
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <h3 class="mb-3 font-semibold">Documentos comprometidos</h3>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="d in carta.documentos_detalle"
                            :key="d.document_type_id"
                            class="flex items-center gap-3 rounded-xl bg-crema/30 px-3 py-2.5 text-sm"
                        >
                            <CircleCheck
                                v-if="d.entregado"
                                class="size-4 shrink-0 text-success"
                            />
                            <CircleDashed
                                v-else
                                class="size-4 shrink-0 text-warning"
                            />
                            <span class="flex-1 font-medium">{{
                                d.nombre
                            }}</span>
                            <Badge
                                :variant="d.entregado ? 'success' : 'warning'"
                            >
                                {{ d.entregado ? 'Entregado' : 'Por entregar' }}
                            </Badge>
                        </li>
                    </ul>
                </section>
            </div>

            <section
                data-tour="carta-linea-tiempo"
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
            >
                <h3 class="mb-4 font-semibold">Línea de tiempo</h3>
                <ol
                    class="relative flex flex-col gap-5 border-l border-[var(--mrl-borde)] pl-5"
                >
                    <li
                        v-for="e in carta.eventos"
                        :key="e.clave"
                        class="relative"
                    >
                        <span
                            class="absolute top-1 -left-[1.6rem] size-3 rounded-full border-2 border-card"
                            :class="
                                e.clave === 'vencida'
                                    ? 'bg-destructive'
                                    : e.clave === 'sustituida'
                                      ? 'bg-muted-foreground'
                                      : 'bg-primary'
                            "
                        />
                        <p class="text-sm font-medium">{{ e.titulo }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatearFechaHora(e.fecha) }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ e.detalle }}
                        </p>
                    </li>
                    <li v-if="carta.estado === 'vigente'" class="relative">
                        <span
                            class="absolute top-1 -left-[1.6rem] size-3 rounded-full border-2 border-[var(--mrl-borde)] bg-card"
                        />
                        <p
                            class="flex items-center gap-1.5 text-sm font-medium"
                        >
                            <CalendarClock class="size-4 text-warning" /> Vence
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ formatearFecha(carta.fecha_limite) }}
                        </p>
                    </li>
                </ol>
            </section>
        </div>
    </div>

    <DocumentPreviewDialog
        v-if="carta.puede.descargar"
        v-model:open="cartaAbierta"
        :preview-url="carta.pdf_url"
        :download-url="carta.pdf_url"
        :nombre="`Carta compromiso ${carta.folio ?? ''}.pdf`"
        tipo="pdf"
    />

    <DocumentPreviewDialog
        v-if="carta.archivo_firmado && carta.puede.descargar"
        v-model:open="firmadaAbierta"
        :preview-url="carta.archivo_firmado.url"
        :download-url="carta.archivo_firmado.url"
        :nombre="`Carta compromiso firmada ${carta.folio ?? ''}.${extensionFirmada}`"
        :mime="carta.archivo_firmado.mime"
    />

    <Dialog v-model:open="subirAbierto">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Subir carta firmada</DialogTitle>
                <DialogDescription>
                    Escaneo o foto de la carta {{ carta.folio }} firmada y con
                    la huella de {{ carta.colaborador?.nombre }}. Queda en su
                    expediente.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="enviarFirmada">
                <div class="flex flex-col gap-1.5">
                    <Label for="archivo-firmada">Archivo (PDF o imagen)</Label>
                    <input
                        id="archivo-firmada"
                        type="file"
                        accept="application/pdf,image/*"
                        class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:text-primary"
                        @change="elegirArchivo"
                    />
                    <InputError :message="form.errors.archivo" />
                </div>

                <div
                    v-if="archivoUrl"
                    class="overflow-hidden rounded-xl border border-[var(--mrl-borde)] bg-crema/30"
                >
                    <img
                        v-if="archivoTipo.startsWith('image/')"
                        :src="archivoUrl"
                        alt="Vista previa de la carta firmada"
                        class="mx-auto max-h-[60vh] object-contain"
                    />
                    <iframe
                        v-else
                        :src="archivoUrl"
                        title="Vista previa de la carta firmada"
                        class="h-[60vh] w-full bg-card"
                    />
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <Checkbox
                        class="mt-0.5"
                        :model-value="form.confirmo_firma"
                        @update:model-value="(v) => (form.confirmo_firma = !!v)"
                    />
                    Confirmo que la carta tiene la firma autógrafa y la huella
                    de la persona.
                </label>
                <InputError :message="form.errors.confirmo_firma" />

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        @click="subirAbierto = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        :disabled="
                            !form.archivo ||
                            !form.confirmo_firma ||
                            form.processing
                        "
                    >
                        <Upload class="size-4" /> Subir carta firmada
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
