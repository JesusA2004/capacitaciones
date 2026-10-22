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
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
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
        seleccion.value = data.motivo_no_elegible
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
        preparacion.value.motivo_no_elegible === null &&
        seleccion.value.length > 0 &&
        !excedeMaximo.value,
);

function alternar(id: number, marcado: boolean) {
    seleccion.value = marcado
        ? [...new Set([...seleccion.value, id])]
        : seleccion.value.filter((x) => x !== id);
}

async function verVistaPrevia() {
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
        toast.error(
            e instanceof Error
                ? e.message
                : 'No se pudo preparar la vista previa.',
        );
    } finally {
        previsualizando.value = false;
    }
}

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

        <div class="grid gap-6 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
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
                        <AlertTitle>No se puede generar la carta</AlertTitle>
                        <AlertDescription>
                            {{ preparacion.motivo_no_elegible }}
                            <Link
                                v-if="preparacion.carta_abierta"
                                :href="preparacion.carta_abierta.url"
                                class="mt-1 block font-medium text-primary underline"
                            >
                                Ver carta
                                {{ preparacion.carta_abierta.folio }}
                            </Link>
                        </AlertDescription>
                    </Alert>

                    <div v-else class="flex flex-col gap-3">
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

                        <Button
                            :disabled="!puedePrevisualizar || previsualizando"
                            @click="verVistaPrevia"
                        >
                            <Loader2
                                v-if="previsualizando"
                                class="size-4 animate-spin"
                            />
                            <Eye v-else class="size-4" />
                            3. Ver vista previa
                        </Button>
                    </div>
                </template>
            </section>

            <!-- Vista previa centrada -->
            <section
                class="flex min-h-[60vh] flex-col items-center gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-crema/30 p-4 sm:p-6"
            >
                <div
                    v-if="previsualizando"
                    class="mx-auto w-full max-w-4xl"
                    aria-label="Preparando vista previa"
                >
                    <EsqueletoDocumento />
                </div>

                <template v-else-if="vistaPreviaUrl">
                    <p class="text-sm text-muted-foreground">
                        Así se verá la carta. La marca «VISTA PREVIA» no aparece
                        en la carta generada.
                    </p>
                    <iframe
                        :src="vistaPreviaUrl"
                        title="Vista previa de la carta compromiso"
                        class="mx-auto h-[78vh] w-full max-w-4xl rounded-xl border border-[var(--mrl-borde)] bg-card shadow-sm"
                    />
                    <Alert v-if="errorGenerar" class="max-w-4xl">
                        <Info class="size-4" />
                        <AlertDescription>{{ errorGenerar }}</AlertDescription>
                    </Alert>
                    <div
                        class="flex w-full max-w-4xl flex-col gap-2 sm:flex-row sm:justify-end"
                    >
                        <Button
                            variant="ghost"
                            :disabled="generando"
                            @click="limpiarVistaPrevia"
                        >
                            Cambiar documentos
                        </Button>
                        <Button :disabled="generando" @click="generar">
                            <Loader2
                                v-if="generando"
                                class="size-4 animate-spin"
                            />
                            <FileSignature v-else class="size-4" />
                            Generar carta compromiso
                        </Button>
                    </div>
                </template>

                <div
                    v-else
                    class="m-auto flex max-w-md flex-col items-center gap-2 text-center text-sm text-muted-foreground"
                >
                    <FilePenLine class="size-10 text-muted-foreground/60" />
                    <p>
                        Aquí verás la carta exactamente como se generará, antes
                        de guardarla en el expediente.
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
</template>
