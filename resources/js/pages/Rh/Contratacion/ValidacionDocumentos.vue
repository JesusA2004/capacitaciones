<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Download,
    Eye,
    FolderOpen,
    RotateCcw,
    X,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import InputError from '@/components/InputError.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useFiltros } from '@/composables/useFiltros';
import type { ArchivoPrevisualizable, RespuestaPaginada } from '@/types';

type DocumentoFila = {
    id: number;
    colaborador: {
        id: number | null;
        nombre: string;
        numero_empleado: string | null;
        sucursal: string | null;
        puesto: string | null;
    };
    tipo: string;
    archivo: string | null;
    mime: string | null;
    version: number;
    estado: string;
    estado_etiqueta: string;
    subido_por: string | null;
    cargado_en: string | null;
    expediente_url: string | null;
    descargar_url: string;
    aprobar_url: string;
    rechazar_url: string;
    correccion_url: string;
};

const props = defineProps<{
    documentos: RespuestaPaginada<DocumentoFila>;
    filtros: { busqueda?: string; sucursal_id?: string };
    puedeAprobar: boolean;
    /** documentos.rechazar (misma regla que EmployeeDocumentPolicy::rechazar). */
    puedeRechazar?: boolean;
    /** documentos.revisar (EmployeeDocumentPolicy::pedirCorreccion). */
    puedeSolicitarCorreccion?: boolean;
}>();

const puedeDecidir = computed(
    () =>
        props.puedeAprobar ||
        (props.puedeRechazar ?? false) ||
        (props.puedeSolicitarCorreccion ?? false),
);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: '/dashboard' },
            {
                title: 'Validación de documentos',
                href: '/rh/validacion-documentos',
            },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(
    '/rh/validacion-documentos',
    {
        busqueda: props.filtros.busqueda ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
    },
);

const ESTADOS: Record<string, 'warning' | 'info' | 'secondary'> = {
    cargado: 'info',
    en_revision: 'warning',
    cambio_solicitado: 'secondary',
};

function fechaCorta(valor: string | null): string {
    return valor
        ? new Date(valor).toLocaleDateString('es-MX', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';
}

function aprobar(fila: DocumentoFila) {
    router.post(fila.aprobar_url, {}, { preserveScroll: true });
}

// Rechazar / solicitar nueva versión comparten el diálogo de motivo, pero
// postean a endpoints distintos y con un campo distinto (rejection_reason vs
// comments) — ambos son endpoints ya existentes de rh.documentos.*.
const dialogo = ref<{
    tipo: 'rechazar' | 'correccion';
    fila: DocumentoFila;
} | null>(null);

const form = useForm<{ motivo: string }>({ motivo: '' });

function abrir(tipo: 'rechazar' | 'correccion', fila: DocumentoFila) {
    form.reset();
    form.clearErrors();
    dialogo.value = { tipo, fila };
}

function confirmar() {
    if (dialogo.value === null) {
        return;
    }

    const { tipo, fila } = dialogo.value;
    const url = tipo === 'rechazar' ? fila.rechazar_url : fila.correccion_url;
    const campo = tipo === 'rechazar' ? 'rejection_reason' : 'comments';

    router.post(
        url,
        { [campo]: form.motivo },
        {
            preserveScroll: true,
            onSuccess: () => {
                dialogo.value = null;
                form.reset();
            },
            onError: (errores) => {
                form.setError(
                    'motivo',
                    errores[campo] ?? 'Revisa el motivo capturado.',
                );
            },
        },
    );
}

// ── Visor centrado: revisar y decidir sin salir de la bandeja ────────────
// «Ver» abre el documento en grande (PDF/imagen inline con zoom) y desde ahí
// se aprueba, rechaza o pide nueva versión con los MISMOS endpoints
// rh.documentos.* que usa el expediente (la Policy decide en el backend).
// Al decidir, el documento sale de la bandeja y el visor queda en el
// siguiente pendiente.
const visorAbierto = ref(false);
const indiceVisor = ref(0);

const archivosVisor = computed<ArchivoPrevisualizable[]>(() =>
    props.documentos.data.map((fila) => ({
        clave: String(fila.id),
        nombre: fila.tipo,
        previewUrl: fila.descargar_url,
        downloadUrl: fila.descargar_url,
        mime: fila.mime,
        // El nombre real del archivo trae la extensión: sirve de respaldo
        // cuando el mime no alcanza para saber si es PDF o imagen.
        tipo: fila.archivo,
        etiqueta: fila.colaborador.nombre,
        version: fila.version,
        estado: fila.estado_etiqueta,
        subidoPor: fila.subido_por,
        fecha: fila.cargado_en,
    })),
);

const filaVisor = computed<DocumentoFila | null>(
    () => props.documentos.data[indiceVisor.value] ?? null,
);

/** Decisión en curso dentro del visor (motivo en línea, sin otro diálogo). */
const decisionVisor = ref<'rechazar' | 'correccion' | null>(null);
const formVisor = useForm<{ motivo: string }>({ motivo: '' });
const cajaMotivoVisor = ref<HTMLElement | null>(null);

function verDocumento(fila: DocumentoFila) {
    const indice = props.documentos.data.findIndex((f) => f.id === fila.id);
    indiceVisor.value = Math.max(indice, 0);
    visorAbierto.value = true;
}

function irAlDocumento(indice: number) {
    const total = props.documentos.data.length;

    if (indice >= 0 && indice < total) {
        indiceVisor.value = indice;
    }
}

function cancelarDecision() {
    decisionVisor.value = null;
    formVisor.reset();
    formVisor.clearErrors();
}

async function pedirMotivo(tipo: 'rechazar' | 'correccion') {
    formVisor.clearErrors();
    decisionVisor.value = tipo;
    await nextTick();
    cajaMotivoVisor.value?.querySelector('textarea')?.focus();
}

// Cambiar de documento descarta un motivo a medio escribir de otro.
watch(indiceVisor, cancelarDecision);

// La bandeja se recarga tras cada decisión: el documento decidido ya no
// aparece y el mismo índice apunta al siguiente pendiente. Si se acabó la
// página, se queda en el último; si ya no hay ninguno, se cierra.
watch(
    () => props.documentos.data.length,
    (total) => {
        if (total === 0) {
            visorAbierto.value = false;

            return;
        }

        indiceVisor.value = Math.min(indiceVisor.value, total - 1);
    },
);

function despuesDeDecidir(idDecidido: number) {
    cancelarDecision();

    // Por si el documento siguiera en la lista (otro estado «por revisar»),
    // se avanza al siguiente en vez de quedarse en el mismo.
    if (filaVisor.value?.id === idDecidido) {
        irAlDocumento(indiceVisor.value + 1);
    }
}

function aprobarEnVisor() {
    const fila = filaVisor.value;

    if (fila === null || formVisor.processing) {
        return;
    }

    formVisor
        .transform(() => ({}))
        .post(fila.aprobar_url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => despuesDeDecidir(fila.id),
        });
}

function confirmarDecisionVisor() {
    const fila = filaVisor.value;
    const tipo = decisionVisor.value;

    if (fila === null || tipo === null || formVisor.processing) {
        return;
    }

    const url = tipo === 'rechazar' ? fila.rechazar_url : fila.correccion_url;
    const campo = tipo === 'rechazar' ? 'rejection_reason' : 'comments';

    formVisor
        .transform((datos) => ({ [campo]: datos.motivo }))
        .post(url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => despuesDeDecidir(fila.id),
            onError: (errores) => {
                formVisor.setError(
                    'motivo',
                    (errores as Record<string, string>)[campo] ??
                        'Revisa el motivo capturado.',
                );
            },
        });
}
</script>

<template>
    <Head title="Validación de documentos" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Validación de documentos"
            descripcion="Bandeja de documentos de expediente con archivo cargado esperando tu revisión. Apruébalos, recházalos con motivo o pide una nueva versión."
            :icono="ClipboardList"
        />

        <CrudToolbar
            :model-value="filtros.busqueda"
            placeholder="Buscar por colaborador o número de empleado..."
            @update:model-value="
                (valor) => {
                    filtros.busqueda = valor;
                    aplicarConDebounce();
                }
            "
            :contador-filtros-activos="0"
            @limpiar="limpiar"
        />

        <EmptyState
            v-if="!documentos.data.length"
            :icono="ClipboardList"
            titulo="Sin documentos por revisar"
            descripcion="No hay documentos de expediente pendientes de validación dentro de tu alcance."
        />

        <div v-else class="tabla-scroll rounded-2xl border border-border/60">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Colaborador</TableHead>
                        <TableHead>Documento</TableHead>
                        <TableHead class="hidden text-center lg:table-cell"
                            >Versión</TableHead
                        >
                        <TableHead class="hidden lg:table-cell"
                            >Cargado</TableHead
                        >
                        <TableHead>Estado</TableHead>
                        <TableHead class="text-right">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="fila in documentos.data" :key="fila.id">
                        <TableCell>
                            <Link
                                v-if="fila.expediente_url"
                                :href="fila.expediente_url"
                                class="font-medium text-primary hover:underline"
                            >
                                {{ fila.colaborador.nombre }}
                            </Link>
                            <span v-else class="font-medium">{{
                                fila.colaborador.nombre
                            }}</span>
                            <p class="text-xs text-muted-foreground">
                                {{ fila.colaborador.sucursal ?? '—' }}
                            </p>
                        </TableCell>
                        <TableCell>
                            <button
                                type="button"
                                class="text-left font-medium hover:text-primary hover:underline"
                                title="Ver el documento"
                                @click="verDocumento(fila)"
                            >
                                {{ fila.tipo }}
                            </button>
                            <p
                                class="text-[11px] text-muted-foreground lg:hidden"
                            >
                                v{{ fila.version }} ·
                                {{ fechaCorta(fila.cargado_en) }}
                            </p>
                        </TableCell>
                        <TableCell
                            class="hidden text-center tabular-nums lg:table-cell"
                            >v{{ fila.version }}</TableCell
                        >
                        <TableCell class="hidden lg:table-cell">
                            <p class="text-xs">
                                {{ fechaCorta(fila.cargado_en) }}
                            </p>
                            <p
                                v-if="fila.subido_por"
                                class="text-[11px] text-muted-foreground"
                            >
                                {{ fila.subido_por }}
                            </p>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="ESTADOS[fila.estado] ?? 'secondary'"
                                >{{ fila.estado_etiqueta }}</Badge
                            >
                        </TableCell>
                        <TableCell>
                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    title="Ver el documento"
                                    aria-label="Ver el documento"
                                    @click="verDocumento(fila)"
                                >
                                    <Eye class="size-4" />
                                    <span class="hidden xl:inline">Ver</span>
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                    title="Descargar"
                                >
                                    <a
                                        :href="fila.descargar_url"
                                        target="_blank"
                                    >
                                        <Download class="size-4" />
                                    </a>
                                </Button>
                                <template v-if="puedeDecidir">
                                    <Button
                                        v-if="puedeAprobar"
                                        variant="ghost"
                                        size="sm"
                                        class="text-success"
                                        title="Aprobar"
                                        @click="aprobar(fila)"
                                    >
                                        <Check class="size-4" />
                                    </Button>
                                    <Button
                                        v-if="puedeSolicitarCorreccion"
                                        variant="ghost"
                                        size="sm"
                                        class="text-warning"
                                        title="Solicitar nueva versión"
                                        @click="abrir('correccion', fila)"
                                    >
                                        <RotateCcw class="size-4" />
                                    </Button>
                                    <Button
                                        v-if="puedeRechazar"
                                        variant="ghost"
                                        size="sm"
                                        class="text-destructive"
                                        title="Rechazar"
                                        @click="abrir('rechazar', fila)"
                                    >
                                        <X class="size-4" />
                                    </Button>
                                </template>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <PaginacionTabla :datos="documentos" />
    </div>

    <Dialog
        :open="dialogo !== null"
        @update:open="
            (abierto) => {
                if (!abierto) dialogo = null;
            }
        "
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    {{
                        dialogo?.tipo === 'rechazar'
                            ? 'Rechazar documento'
                            : 'Solicitar nueva versión'
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        dialogo?.tipo === 'rechazar'
                            ? 'El documento se marca como rechazado y el colaborador recibe el motivo.'
                            : 'Se le pide al colaborador que corrija y vuelva a subir el documento.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label for="motivo-documento">Motivo</Label>
                <Textarea
                    id="motivo-documento"
                    v-model="form.motivo"
                    rows="3"
                    placeholder="Explica por qué, para que el colaborador sepa qué corregir…"
                />
                <InputError :message="form.errors.motivo" />
            </div>

            <DialogFooter>
                <Button variant="outline" @click="dialogo = null"
                    >Cancelar</Button
                >
                <Button
                    :variant="
                        dialogo?.tipo === 'rechazar' ? 'destructive' : 'default'
                    "
                    :disabled="form.processing"
                    @click="confirmar"
                >
                    {{
                        dialogo?.tipo === 'rechazar'
                            ? 'Rechazar'
                            : 'Solicitar corrección'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Visor centrado: el documento en grande y la decisión debajo. -->
    <DocumentPreviewDialog
        v-model:open="visorAbierto"
        v-model:indice="indiceVisor"
        :archivos="archivosVisor"
    >
        <template #pie>
            <section
                v-if="filaVisor"
                class="flex flex-col gap-3 border-t border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 py-3 sm:px-5"
                aria-label="Revisión del documento"
            >
                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm"
                >
                    <div class="mr-auto min-w-0">
                        <p class="truncate font-semibold">
                            {{ filaVisor.colaborador.nombre }}
                            <span
                                v-if="filaVisor.colaborador.numero_empleado"
                                class="font-normal text-muted-foreground"
                                >· #{{
                                    filaVisor.colaborador.numero_empleado
                                }}</span
                            >
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ filaVisor.colaborador.puesto ?? 'Sin puesto' }}
                            ·
                            {{
                                filaVisor.colaborador.sucursal ?? 'Sin sucursal'
                            }}
                            · {{ filaVisor.tipo }} v{{ filaVisor.version }}
                        </p>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="indiceVisor <= 0"
                            aria-label="Documento anterior"
                            @click="irAlDocumento(indiceVisor - 1)"
                        >
                            <ChevronLeft class="size-4" />
                            <span class="hidden sm:inline">Anterior</span>
                        </Button>
                        <span
                            class="rounded-full bg-crema px-2.5 py-1 text-xs font-medium whitespace-nowrap text-[var(--mrl-petroleo)] tabular-nums"
                            :title="`${documentos.total} pendientes en total`"
                        >
                            {{ indiceVisor + 1 }} de
                            {{ documentos.data.length }}
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="
                                indiceVisor >= documentos.data.length - 1
                            "
                            aria-label="Documento siguiente"
                            @click="irAlDocumento(indiceVisor + 1)"
                        >
                            <span class="hidden sm:inline">Siguiente</span>
                            <ChevronRight class="size-4" />
                        </Button>
                    </div>

                    <Button
                        v-if="filaVisor.expediente_url"
                        size="sm"
                        variant="link"
                        as-child
                    >
                        <Link :href="filaVisor.expediente_url">
                            <FolderOpen class="size-4" />
                            Abrir expediente
                        </Link>
                    </Button>
                </div>

                <p
                    v-if="documentos.last_page > 1"
                    class="-mt-1 text-xs text-muted-foreground"
                >
                    {{ documentos.total }} pendientes en total; aquí recorres
                    los de la página {{ documentos.current_page }}.
                </p>

                <template v-if="puedeDecidir">
                    <div
                        v-if="decisionVisor"
                        ref="cajaMotivoVisor"
                        class="grid gap-2"
                    >
                        <Label for="motivo-visor">{{
                            decisionVisor === 'rechazar'
                                ? 'Motivo del rechazo'
                                : 'Qué debe corregir el colaborador'
                        }}</Label>
                        <Textarea
                            id="motivo-visor"
                            v-model="formVisor.motivo"
                            rows="2"
                            placeholder="Explica por qué, para que el colaborador sepa qué corregir…"
                            @keydown.ctrl.enter="confirmarDecisionVisor"
                            @keydown.meta.enter="confirmarDecisionVisor"
                        />
                        <InputError :message="formVisor.errors.motivo" />
                        <div class="flex flex-wrap justify-end gap-2">
                            <Button
                                variant="outline"
                                :disabled="formVisor.processing"
                                @click="cancelarDecision"
                                >Cancelar</Button
                            >
                            <Button
                                :variant="
                                    decisionVisor === 'rechazar'
                                        ? 'destructive'
                                        : 'default'
                                "
                                :disabled="formVisor.processing"
                                @click="confirmarDecisionVisor"
                            >
                                <Spinner v-if="formVisor.processing" />
                                {{
                                    decisionVisor === 'rechazar'
                                        ? 'Confirmar rechazo'
                                        : 'Pedir nueva versión'
                                }}
                            </Button>
                        </div>
                    </div>

                    <div
                        v-else
                        class="grid grid-cols-2 gap-2 sm:flex sm:justify-end"
                    >
                        <Button
                            v-if="puedeSolicitarCorreccion"
                            size="lg"
                            variant="outline"
                            class="col-span-1"
                            :disabled="formVisor.processing"
                            @click="pedirMotivo('correccion')"
                        >
                            <RotateCcw class="size-4" />
                            Pedir nueva versión
                        </Button>
                        <Button
                            v-if="puedeRechazar"
                            size="lg"
                            variant="outline-destructive"
                            class="col-span-1"
                            :disabled="formVisor.processing"
                            @click="pedirMotivo('rechazar')"
                        >
                            <X class="size-4" />
                            Rechazar
                        </Button>
                        <Button
                            v-if="puedeAprobar"
                            size="lg"
                            variant="success"
                            class="col-span-2 sm:min-w-44"
                            :disabled="formVisor.processing"
                            @click="aprobarEnVisor"
                        >
                            <Spinner v-if="formVisor.processing" />
                            <Check v-else class="size-4" />
                            Aprobar
                        </Button>
                    </div>
                </template>
                <p v-else class="text-xs text-muted-foreground">
                    Puedes revisar el documento, pero aprobarlo, rechazarlo o
                    pedir una nueva versión requiere permisos que no tienes.
                </p>
            </section>
        </template>
    </DocumentPreviewDialog>
</template>
