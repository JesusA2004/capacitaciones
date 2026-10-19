<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, ClipboardList, Download, Eye, RotateCcw, X } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import InputError from '@/components/InputError.vue';
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
import type { RespuestaPaginada } from '@/types';

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
}>();

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
                        <TableHead class="text-center">Versión</TableHead>
                        <TableHead>Cargado</TableHead>
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
                        <TableCell>{{ fila.tipo }}</TableCell>
                        <TableCell class="text-center tabular-nums"
                            >v{{ fila.version }}</TableCell
                        >
                        <TableCell>
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
                                    as-child
                                    title="Ver en el expediente"
                                >
                                    <Link
                                        v-if="fila.expediente_url"
                                        :href="fila.expediente_url"
                                    >
                                        <Eye class="size-4" />
                                    </Link>
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
                                <template v-if="puedeAprobar">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="text-success"
                                        title="Aprobar"
                                        @click="aprobar(fila)"
                                    >
                                        <Check class="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="text-warning"
                                        title="Solicitar nueva versión"
                                        @click="abrir('correccion', fila)"
                                    >
                                        <RotateCcw class="size-4" />
                                    </Button>
                                    <Button
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
</template>
