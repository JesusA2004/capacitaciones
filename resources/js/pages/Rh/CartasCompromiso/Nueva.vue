<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarClock,
    Eye,
    FilePenLine,
    FileSignature,
    Info,
    Loader2,
    Maximize2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import EsqueletoDocumento from '@/components/people/EsqueletoDocumento.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { formatearFecha } from '@/lib/fechas';
import { getJson, vistaPreviaPost } from '@/lib/http';
import { dashboard } from '@/routes';
import {
    colaboradores,
    index,
    preparar,
    store,
    vistaPrevia,
} from '@/routes/rh/cartas-compromiso';
import type {
    ColaboradorBusqueda,
    PersonaCartaCompromiso,
    PreparacionCartaCompromiso,
    ReglasCartaCompromiso,
} from '@/types';

const props = defineProps<{
    colaboradorInicial: PersonaCartaCompromiso | null;
    reglas: ReglasCartaCompromiso;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cartas compromiso', href: index() },
            { title: 'Generar carta', href: '' },
        ],
    },
});

const persona = ref<ColaboradorBusqueda | null>(props.colaboradorInicial);
const preparacion = ref<PreparacionCartaCompromiso | null>(null);
const cargando = ref(false);
const seleccion = ref<number[]>([]);
const vistaPreviaUrl = ref<string | null>(null);
const previsualizando = ref(false);
const generando = ref(false);
const errorGenerar = ref<string | null>(null);

function limpiarVistaPrevia() {
    if (vistaPreviaUrl.value !== null) {
        URL.revokeObjectURL(vistaPreviaUrl.value);
        vistaPreviaUrl.value = null;
    }
}

onBeforeUnmount(limpiarVistaPrevia);

async function cargarPersona(c: ColaboradorBusqueda | null) {
    limpiarVistaPrevia();
    preparacion.value = null;
    seleccion.value = [];
    errorGenerar.value = null;

    if (c === null) {
        return;
    }

    cargando.value = true;

    try {
        const { data } = await getJson<{ data: PreparacionCartaCompromiso }>(
            preparar.url(c.id),
        );
        preparacion.value = data;
        // Se proponen todos sus pendientes (hasta el máximo de una carta).
        seleccion.value = !data.puede_ver_carta
            ? []
            : data.pendientes
                  .slice(0, data.max_documentos)
                  .map((p) => p.document_type_id);
    } catch {
        toast.error('No se pudieron consultar los documentos de esta persona.');
    } finally {
        cargando.value = false;
    }
}

watch(persona, (c) => void cargarPersona(c), { immediate: true });

// Cambiar los documentos invalida la vista previa: lo que se genera es
// exactamente lo que se vio.
watch(seleccion, limpiarVistaPrevia, { deep: true });

const excedeMaximo = computed(
    () =>
        preparacion.value !== null &&
        seleccion.value.length > preparacion.value.max_documentos,
);

const puedePrevisualizar = computed(
    () =>
        preparacion.value !== null &&
        preparacion.value.puede_ver_carta &&
        seleccion.value.length > 0 &&
        !excedeMaximo.value,
);

/** Solo consulta: se ve la carta pero aún no se puede generar. */
const puedeGenerar = computed(
    () =>
        preparacion.value !== null &&
        preparacion.value.motivo_no_elegible === null,
);

// Carta que ya tiene abierta: «Ver carta» en el visor centrado.
const cartaAbiertaVisible = ref(false);

function alternar(id: number, marcado: boolean) {
    seleccion.value = marcado
        ? [...new Set([...seleccion.value, id])]
        : seleccion.value.filter((x) => x !== id);
}

// Visor centrado (DocumentPreviewDialog) con la carta tal cual se generará.
const visorAbierto = ref(false);

/** Pide al backend el PDF de vista previa (no guarda nada). */
async function pedirVistaPrevia() {
    if (!persona.value || !puedePrevisualizar.value) {
        return;
    }

    previsualizando.value = true;
    errorGenerar.value = null;

    try {
        const url = await vistaPreviaPost(vistaPrevia.url(persona.value.id), {
            documentos: seleccion.value,
        });
        limpiarVistaPrevia();
        vistaPreviaUrl.value = url;
    } catch (e) {
        visorAbierto.value = false;
        toast.error(
            e instanceof Error
                ? e.message
                : 'No se pudo preparar la vista previa.',
        );
    } finally {
        previsualizando.value = false;
    }
}

/** «Ver carta»: abre el visor de inmediato (esqueleto) y trae el PDF. */
async function verCarta() {
    if (!puedePrevisualizar.value || previsualizando.value) {
        return;
    }

    visorAbierto.value = true;

    if (vistaPreviaUrl.value === null) {
        await pedirVistaPrevia();
    }
}

async function regenerarVistaPrevia() {
    limpiarVistaPrevia();
    await pedirVistaPrevia();
}

function cambiarDocumentos() {
    visorAbierto.value = false;
    limpiarVistaPrevia();
}

const nombreVistaPrevia = computed(
    () =>
        `Vista previa — carta compromiso de ${persona.value?.nombre ?? 'la persona'}.pdf`,
);

function generar() {
    if (!persona.value || vistaPreviaUrl.value === null) {
        return;
    }

    router.post(
        store.url(persona.value.id),
        { documentos: seleccion.value },
        {
            onStart: () => {
                generando.value = true;
            },
            onFinish: () => {
                generando.value = false;
            },
            onError: (errores) => {
                errorGenerar.value =
                    Object.values(errores)[0] ?? 'No se pudo generar la carta.';
            },
        },
    );
}
</script>

<template>
    <Head title="Generar carta compromiso" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Generar carta compromiso"
            descripcion="Elige a la persona de nuevo ingreso y los documentos que le faltan; revisa la vista previa y genera la carta para que la firme."
            :icono="FilePenLine"
            detalle
        >
            <Button variant="ghost" as-child>
                <Link :href="index.url()">
                    <ArrowLeft class="size-4" /> Volver a la lista
                </Link>
            </Button>
        </CrudPageHeader>

        <div
            class="grid gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]"
        >
            <!-- Paso 1 y 2: persona y documentos -->
            <section
                class="flex flex-col gap-5 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
            >
                <div class="flex flex-col gap-1.5">
                    <Label>1. Persona de nuevo ingreso</Label>
                    <BuscadorColaborador
                        v-model="persona"
                        :buscar-url="
                            (texto) =>
                                colaboradores.url({ query: { q: texto } })
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Personas en contratación u onboarding de tu alcance. Haz
                        clic para ver la lista completa y escribe para filtrar.
                    </p>
                </div>

                <div
                    v-if="cargando"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" /> Consultando sus
                    documentos…
                </div>

                <template v-else-if="preparacion">
                    <Alert v-if="preparacion.motivo_no_elegible">
                        <Info class="size-4" />
                        <AlertTitle>{{
                            preparacion.puede_ver_carta
                                ? 'Todavía no se puede generar'
                                : 'No se puede generar la carta'
                        }}</AlertTitle>
                        <AlertDescription>
                            {{ preparacion.motivo_no_elegible }}
                            <span
                                v-if="preparacion.puede_ver_carta"
                                class="mt-1 block"
                            >
                                Aun así puedes ver cómo quedaría la carta con
                                los documentos que marques.
                            </span>
                            <span
                                v-if="preparacion.carta_abierta"
                                class="mt-2 flex flex-wrap gap-2"
                            >
                                <Button
                                    size="sm"
                                    @click="cartaAbiertaVisible = true"
                                >
                                    <Eye class="size-4" /> Ver carta
                                    {{ preparacion.carta_abierta.folio }}
                                </Button>
                                <Button size="sm" variant="outline" as-child>
                                    <Link :href="preparacion.carta_abierta.url"
                                        >Abrir su detalle</Link
                                    >
                                </Button>
                            </span>
                        </AlertDescription>
                    </Alert>

                    <div
                        v-if="preparacion.puede_ver_carta"
                        class="flex flex-col gap-3"
                    >
                        <Label
                            >2. Documentos que se compromete a entregar</Label
                        >
                        <p class="text-xs text-muted-foreground">
                            Le faltan {{ preparacion.pendientes.length }} de sus
                            documentos obligatorios. Una carta cubre como máximo
                            {{ preparacion.max_documentos }}.
                        </p>
                        <label
                            v-for="p in preparacion.pendientes"
                            :key="p.document_type_id"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border border-[var(--mrl-borde)] bg-crema/30 px-3 py-2.5 text-sm"
                        >
                            <Checkbox
                                class="mt-0.5"
                                :model-value="
                                    seleccion.includes(p.document_type_id)
                                "
                                @update:model-value="
                                    (v) => alternar(p.document_type_id, !!v)
                                "
                            />
                            <span class="min-w-0">
                                <span class="block font-medium">{{
                                    p.nombre
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ p.estado_etiqueta }}</span
                                >
                            </span>
                        </label>
                        <p v-if="excedeMaximo" class="text-sm text-destructive">
                            Elige como máximo
                            {{ preparacion.max_documentos }} documentos.
                        </p>

                        <div
                            class="flex items-start gap-3 rounded-xl bg-salvia/50 px-3 py-2.5 text-sm"
                        >
                            <CalendarClock
                                class="mt-0.5 size-4 shrink-0 text-primary"
                            />
                            <span>
                                Plazo de {{ preparacion.dias_plazo }} días
                                naturales: debe entregarlos a más tardar el
                                <strong>{{
                                    formatearFecha(preparacion.fecha_limite)
                                }}</strong
                                >. Si vence, su app queda limitada a subir esos
                                documentos.
                            </span>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label>3. Revisa la carta antes de asignarla</Label>
                            <Button
                                size="lg"
                                class="w-full"
                                data-tour="carta-ver"
                                :disabled="
                                    !puedePrevisualizar || previsualizando
                                "
                                @click="verCarta"
                            >
                                <Loader2
                                    v-if="previsualizando"
                                    class="size-4 animate-spin"
                                />
                                <Eye v-else class="size-4" />
                                Ver carta
                            </Button>
                            <p
                                v-if="!puedePrevisualizar"
                                class="text-xs text-muted-foreground"
                            >
                                Marca al menos un documento (máximo
                                {{ preparacion.max_documentos }}) para ver la
                                carta.
                            </p>
                            <p
                                v-else-if="puedeGenerar"
                                class="text-xs text-muted-foreground"
                            >
                                Se abre la carta exactamente como se generará.
                                Todavía no se guarda nada: la generas desde la
                                misma vista previa.
                            </p>
                            <p v-else class="text-xs text-muted-foreground">
                                Solo consulta: ves cómo quedaría la carta, pero
                                todavía no se puede generar.
                            </p>
                        </div>
                    </div>
                </template>
            </section>

            <!-- Vista previa centrada -->
            <section
                class="flex min-h-64 flex-col items-center gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-crema/30 p-4 sm:p-6"
            >
                <div
                    v-if="previsualizando && !visorAbierto"
                    class="mx-auto w-full max-w-4xl"
                    aria-label="Preparando vista previa"
                >
                    <EsqueletoDocumento />
                </div>

                <template v-else-if="vistaPreviaUrl">
                    <div
                        class="flex w-full max-w-4xl items-start gap-2 rounded-xl bg-warning-soft px-3 py-2 text-sm text-warning"
                    >
                        <Info class="mt-0.5 size-4 shrink-0" />
                        <span>
                            <strong
                                >Vista previa — aún no se ha generado.</strong
                            >
                            La marca «VISTA PREVIA» no aparece en la carta
                            generada.
                        </span>
                    </div>
                    <iframe
                        :src="vistaPreviaUrl"
                        title="Vista previa de la carta compromiso"
                        class="mx-auto hidden h-[78vh] w-full max-w-4xl rounded-xl border border-[var(--mrl-borde)] bg-card shadow-sm md:block"
                    />
                    <p
                        v-if="!puedeGenerar && preparacion?.motivo_no_elegible"
                        class="w-full max-w-4xl text-sm text-muted-foreground"
                    >
                        Solo consulta: {{ preparacion.motivo_no_elegible }}
                    </p>
                    <Alert v-if="errorGenerar" class="max-w-4xl">
                        <Info class="size-4" />
                        <AlertDescription>{{ errorGenerar }}</AlertDescription>
                    </Alert>
                    <div
                        class="flex w-full max-w-4xl flex-col gap-2 sm:flex-row sm:flex-wrap sm:justify-end"
                    >
                        <Button
                            variant="ghost"
                            :disabled="generando"
                            @click="cambiarDocumentos"
                        >
                            Cambiar documentos
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="generando"
                            @click="verCarta"
                        >
                            <Maximize2 class="size-4" /> Ver carta en grande
                        </Button>
                        <Button
                            v-if="puedeGenerar"
                            :disabled="generando"
                            @click="generar"
                        >
                            <Loader2
                                v-if="generando"
                                class="size-4 animate-spin"
                            />
                            <FileSignature v-else class="size-4" />
                            Generar y asignar carta
                        </Button>
                    </div>
                </template>

                <div
                    v-else
                    class="m-auto flex max-w-md flex-col items-center gap-3 text-center text-sm text-muted-foreground"
                >
                    <FilePenLine class="size-10 text-muted-foreground/60" />
                    <p>
                        Aquí verás la carta exactamente como se generará, antes
                        de guardarla en el expediente.
                    </p>
                    <Button
                        v-if="puedePrevisualizar"
                        size="lg"
                        :disabled="previsualizando"
                        @click="verCarta"
                    >
                        <Eye class="size-4" /> Ver carta
                    </Button>
                    <p
                        v-else-if="preparacion && !preparacion.puede_ver_carta"
                        class="text-xs"
                    >
                        A esta persona no se le puede preparar una carta nueva:
                        revisa el motivo en el panel de la izquierda.
                    </p>
                    <p v-else class="text-xs">
                        Elige a la persona y marca los documentos que le faltan;
                        luego pulsa «Ver carta».
                    </p>
                    <p class="text-xs">
                        Al generarla se guarda en el expediente, se le avisa a
                        la persona (web y app) que debe firmarla y podrás
                        imprimirla y subir el escaneo firmado.
                    </p>
                </div>
            </section>
        </div>
    </div>

    <!-- «Ver carta»: la carta tal cual se generará, centrada y en grande. -->
    <DocumentPreviewDialog
        v-model:open="visorAbierto"
        :preview-url="vistaPreviaUrl"
        :download-url="vistaPreviaUrl"
        :nombre="nombreVistaPrevia"
        mime="application/pdf"
        tipo="pdf"
        :cargando="previsualizando"
        titulo-carga="Vista previa de la carta compromiso"
        recargable
        @recargar="regenerarVistaPrevia"
    >
        <template #pie>
            <section
                class="flex flex-col gap-3 border-t border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 py-3 sm:px-5"
                aria-label="Generar la carta"
            >
                <div
                    class="flex items-start gap-2 rounded-xl bg-warning-soft px-3 py-2 text-sm text-warning"
                >
                    <Info class="mt-0.5 size-4 shrink-0" />
                    <span>
                        <strong>Vista previa — aún no se ha generado.</strong>
                        Nada se ha guardado todavía; la marca «VISTA PREVIA» no
                        aparece en la carta generada.
                    </span>
                </div>
                <p
                    v-if="!puedeGenerar && preparacion?.motivo_no_elegible"
                    class="text-sm text-muted-foreground"
                >
                    <strong>Solo consulta.</strong>
                    {{ preparacion.motivo_no_elegible }}
                </p>
                <p v-if="errorGenerar" class="text-sm text-destructive">
                    {{ errorGenerar }}
                </p>
                <div
                    class="flex flex-col-reverse gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end"
                >
                    <p
                        v-if="preparacion"
                        class="text-xs text-muted-foreground sm:mr-auto"
                    >
                        {{ seleccion.length }}
                        {{
                            seleccion.length === 1 ? 'documento' : 'documentos'
                        }}
                        · plazo hasta el
                        {{ formatearFecha(preparacion.fecha_limite) }}
                    </p>
                    <Button
                        variant="ghost"
                        :disabled="generando"
                        @click="cambiarDocumentos"
                    >
                        Cambiar documentos
                    </Button>
                    <Button
                        v-if="puedeGenerar"
                        :disabled="
                            generando || previsualizando || !vistaPreviaUrl
                        "
                        @click="generar"
                    >
                        <Loader2 v-if="generando" class="size-4 animate-spin" />
                        <FileSignature v-else class="size-4" />
                        Generar y asignar carta
                    </Button>
                </div>
            </section>
        </template>
    </DocumentPreviewDialog>

    <!-- La carta que ya tiene abierta (no se puede generar otra). -->
    <DocumentPreviewDialog
        v-if="preparacion?.carta_abierta"
        v-model:open="cartaAbiertaVisible"
        :preview-url="preparacion.carta_abierta.pdf_url"
        :download-url="preparacion.carta_abierta.pdf_url"
        :nombre="`Carta compromiso ${preparacion.carta_abierta.folio ?? ''}.pdf`"
        mime="application/pdf"
        tipo="pdf"
        :estado="preparacion.carta_abierta.estado_etiqueta"
    />
</template>
