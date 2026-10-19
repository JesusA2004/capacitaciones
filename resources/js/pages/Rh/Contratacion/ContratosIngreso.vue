<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowUpRight, FileSignature } from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFiltros } from '@/composables/useFiltros';
import type { RespuestaPaginada } from '@/types';

type DocumentoContrato = {
    id: number;
    titulo: string;
    clave: string | null;
    estado: string | null;
    estado_etiqueta: string;
};

type ContratoFila = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    puesto: string | null;
    sucursal: string | null;
    tipo_contrato: string | null;
    estado_contrato: string;
    paquete_generado: boolean;
    paquete_firmado: boolean;
    documentos: DocumentoContrato[];
    gestion_url: string;
};

const props = defineProps<{
    contratos: RespuestaPaginada<ContratoFila>;
    filtros: { busqueda?: string; sucursal_id?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: '/dashboard' },
            { title: 'Contratos de ingreso', href: '/rh/contratos-ingreso' },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(
    '/rh/contratos-ingreso',
    {
        busqueda: props.filtros.busqueda ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
    },
);

function variantePaquete(
    fila: ContratoFila,
): 'success' | 'warning' | 'secondary' {
    if (fila.paquete_firmado) {
        return 'success';
    }

    return fila.paquete_generado ? 'warning' : 'secondary';
}

// El estado del documento firmado físicamente es terminal (el original se
// resguarda en papel): se muestra en verde; lo demás está en curso.
function varianteDocumento(estado: string | null): 'success' | 'info' {
    return estado === 'firmado_fisicamente' ? 'success' : 'info';
}
</script>

<template>
    <Head title="Contratos de ingreso" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Contratos de ingreso"
            descripcion="Contrato vigente y documentos laborales iniciales (contrato del puesto, confidencialidad, no competencia…) de cada persona en contratación. La generación y la firma física se gestionan por persona."
            :icono="FileSignature"
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
            v-if="!contratos.data.length"
            :icono="FileSignature"
            titulo="Sin contratos de ingreso"
            descripcion="No hay personas en contratación dentro de tu alcance."
        />

        <div v-else class="tabla-scroll rounded-2xl border border-border/60">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Colaborador</TableHead>
                        <TableHead>Puesto / Sucursal</TableHead>
                        <TableHead>Tipo de contrato</TableHead>
                        <TableHead>Documentos del paquete</TableHead>
                        <TableHead>Paquete</TableHead>
                        <TableHead class="text-right">Gestión</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="fila in contratos.data" :key="fila.id">
                        <TableCell>
                            <p class="font-medium">{{ fila.nombre }}</p>
                            <p
                                v-if="fila.numero_empleado"
                                class="text-xs text-muted-foreground"
                            >
                                #{{ fila.numero_empleado }}
                            </p>
                        </TableCell>
                        <TableCell class="text-xs">
                            {{ fila.puesto ?? '—' }}<br />
                            <span class="text-muted-foreground">{{
                                fila.sucursal ?? '—'
                            }}</span>
                        </TableCell>
                        <TableCell class="text-xs">
                            {{ fila.tipo_contrato ?? '—' }}<br />
                            <span class="text-muted-foreground">{{
                                fila.estado_contrato
                            }}</span>
                        </TableCell>
                        <TableCell>
                            <div
                                v-if="fila.documentos.length"
                                class="flex flex-col gap-1"
                            >
                                <div
                                    v-for="doc in fila.documentos"
                                    :key="doc.id"
                                    class="flex items-center gap-2"
                                >
                                    <Badge
                                        :variant="varianteDocumento(doc.estado)"
                                        >{{ doc.estado_etiqueta }}</Badge
                                    >
                                    <span class="text-xs">{{
                                        doc.titulo
                                    }}</span>
                                </div>
                            </div>
                            <span v-else class="text-xs text-muted-foreground"
                                >Sin documentos generados</span
                            >
                        </TableCell>
                        <TableCell>
                            <Badge :variant="variantePaquete(fila)">
                                {{
                                    fila.paquete_firmado
                                        ? 'Firmado'
                                        : fila.paquete_generado
                                          ? 'Generado'
                                          : 'Por generar'
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button variant="ghost" size="sm" as-child>
                                <Link :href="fila.gestion_url">
                                    Gestionar
                                    <ArrowUpRight class="size-4" />
                                </Link>
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <PaginacionTabla :datos="contratos" />
    </div>
</template>
