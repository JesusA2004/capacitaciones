<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ClipboardList,
    FileSignature,
    FolderOpen,
    UserPlus,
} from '@lucide/vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
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

type Persona = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    puesto: string | null;
    sucursal: string | null;
    etapa: string;
    estado_clave: string;
    progreso: number;
    documentos_pendientes: number;
    documentos_rechazados: number;
    estado_contrato: string;
    estado_imss: string;
    siguiente_accion: string | null;
    ciclo_url: string;
    /** Foto oficial del expediente (URL protegida); null → iniciales. */
    foto_url?: string | null;
};

type AltaDigital = {
    id: number;
    nombre: string;
    puesto: string | null;
    sucursal: string | null;
    estado: string;
    estado_etiqueta: string;
    creado_en: string | null;
    ver_url: string;
};

const props = defineProps<{
    personas: RespuestaPaginada<Persona>;
    altasDigitales: AltaDigital[];
    filtros: { busqueda?: string; sucursal_id?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: '/dashboard' },
            { title: 'Personas en contratación', href: '/rh/contratacion' },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(
    '/rh/contratacion',
    {
        busqueda: props.filtros.busqueda ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
    },
);

function fechaCorta(valor: string | null): string {
    return valor
        ? new Date(valor).toLocaleDateString('es-MX', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';
}
</script>

<template>
    <Head title="Personas en contratación" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Personas en contratación"
            descripcion="Colaboradores en la Etapa 2 (contratación y expediente): integra tanto a quienes ya se dieron de alta como las altas digitales que aún no se aprueban."
            :icono="UserPlus"
        >
            <Button variant="outline" as-child>
                <Link href="/rh/validacion-documentos">
                    <ClipboardList class="size-4" />
                    Validación de documentos
                </Link>
            </Button>
            <Button variant="outline" as-child>
                <Link href="/rh/contratos-ingreso">
                    <FileSignature class="size-4" />
                    Contratos de ingreso
                </Link>
            </Button>
        </CrudPageHeader>

        <!-- Altas digitales abiertas: el paso previo del mismo embudo (antes
             vivía en una entrada de menú aparte). -->
        <section
            v-if="altasDigitales.length"
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4"
        >
            <div class="flex items-center gap-2">
                <FolderOpen class="size-4 text-primary" />
                <h2 class="text-sm font-semibold">
                    Altas digitales por aprobar
                </h2>
                <Badge variant="info">{{ altasDigitales.length }}</Badge>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="alta in altasDigitales"
                    :key="alta.id"
                    :href="alta.ver_url"
                    class="flex tarjeta-interactiva flex-col gap-1 rounded-xl border border-border/60 bg-background p-3"
                >
                    <p class="truncate text-sm font-semibold">
                        {{ alta.nombre }}
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ alta.puesto ?? 'Sin puesto' }} ·
                        {{ alta.sucursal ?? 'Sin sucursal' }}
                    </p>
                    <div class="mt-1 flex items-center justify-between gap-2">
                        <Badge variant="warning">{{
                            alta.estado_etiqueta
                        }}</Badge>
                        <span class="text-[11px] text-muted-foreground">{{
                            fechaCorta(alta.creado_en)
                        }}</span>
                    </div>
                </Link>
            </div>
        </section>

        <CrudToolbar
            :model-value="filtros.busqueda"
            placeholder="Buscar por nombre, número, puesto o sucursal..."
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
            v-if="!personas.data.length"
            :icono="UserPlus"
            titulo="Nadie en contratación"
            descripcion="No hay colaboradores en la etapa de contratación dentro de tu alcance."
        />

        <!-- Hasta escritorio ancho: una tarjeta por persona (9 columnas no
             caben sin esconder «Siguiente acción», que es lo importante). -->
        <ul
            v-if="personas.data.length"
            class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:hidden"
        >
            <li
                v-for="persona in personas.data"
                :key="persona.id"
                class="flex min-w-0 flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <ColaboradorAvatar
                        :nombre="persona.nombre"
                        :foto-url="persona.foto_url"
                        tamano="lg"
                    />
                    <div class="min-w-0">
                        <Link
                            :href="persona.ciclo_url"
                            class="font-medium text-primary hover:underline"
                        >
                            {{ persona.nombre }}
                        </Link>
                        <p class="truncate text-xs text-muted-foreground">
                            <template v-if="persona.numero_empleado"
                                >#{{ persona.numero_empleado }} · </template
                            >{{ persona.puesto ?? 'Sin puesto' }} ·
                            {{ persona.sucursal ?? 'Sin sucursal' }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="text-xs font-medium">{{ persona.etapa }}</span>
                    <div
                        class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            class="h-full rounded-full bg-primary"
                            :style="{ width: persona.progreso + '%' }"
                        />
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <Badge
                        :variant="
                            persona.documentos_pendientes
                                ? 'warning'
                                : 'success'
                        "
                        >{{ persona.documentos_pendientes }} pendientes</Badge
                    >
                    <Badge
                        :variant="
                            persona.documentos_rechazados
                                ? 'destructive'
                                : 'secondary'
                        "
                        >{{ persona.documentos_rechazados }} rechazados</Badge
                    >
                </div>
                <dl
                    class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-xs"
                >
                    <dt class="text-muted-foreground">Contrato</dt>
                    <dd>{{ persona.estado_contrato }}</dd>
                    <dt class="text-muted-foreground">IMSS</dt>
                    <dd>{{ persona.estado_imss }}</dd>
                </dl>
                <Button
                    variant="outline"
                    size="sm"
                    class="mt-auto h-auto min-h-8 w-full whitespace-normal"
                    as-child
                >
                    <Link :href="persona.ciclo_url">
                        {{ persona.siguiente_accion ?? 'Ver ciclo' }}
                    </Link>
                </Button>
            </li>
        </ul>

        <div
            v-if="personas.data.length"
            class="hidden tabla-scroll rounded-2xl border border-border/60 xl:block"
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Colaborador</TableHead>
                        <TableHead>Puesto</TableHead>
                        <TableHead>Sucursal</TableHead>
                        <TableHead>Avance</TableHead>
                        <TableHead class="text-center"
                            >Docs. pendientes</TableHead
                        >
                        <TableHead class="text-center"
                            >Docs. rechazados</TableHead
                        >
                        <TableHead>Contrato</TableHead>
                        <TableHead>IMSS</TableHead>
                        <TableHead>Siguiente acción</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="persona in personas.data"
                        :key="persona.id"
                    >
                        <TableCell>
                            <div class="flex items-center gap-3">
                                <ColaboradorAvatar
                                    :nombre="persona.nombre"
                                    :foto-url="persona.foto_url"
                                />
                                <div class="min-w-0">
                                    <Link
                                        :href="persona.ciclo_url"
                                        class="font-medium text-primary hover:underline"
                                    >
                                        {{ persona.nombre }}
                                    </Link>
                                    <p
                                        v-if="persona.numero_empleado"
                                        class="text-xs text-muted-foreground"
                                    >
                                        #{{ persona.numero_empleado }}
                                    </p>
                                </div>
                            </div>
                        </TableCell>
                        <TableCell>{{ persona.puesto ?? '—' }}</TableCell>
                        <TableCell>{{ persona.sucursal ?? '—' }}</TableCell>
                        <TableCell>
                            <div class="flex min-w-32 flex-col gap-1">
                                <span class="text-xs font-medium">{{
                                    persona.etapa
                                }}</span>
                                <div
                                    class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full rounded-full bg-primary"
                                        :style="{
                                            width: persona.progreso + '%',
                                        }"
                                    />
                                </div>
                            </div>
                        </TableCell>
                        <TableCell class="text-center">
                            <Badge
                                :variant="
                                    persona.documentos_pendientes
                                        ? 'warning'
                                        : 'success'
                                "
                                >{{ persona.documentos_pendientes }}</Badge
                            >
                        </TableCell>
                        <TableCell class="text-center">
                            <Badge
                                :variant="
                                    persona.documentos_rechazados
                                        ? 'destructive'
                                        : 'secondary'
                                "
                                >{{ persona.documentos_rechazados }}</Badge
                            >
                        </TableCell>
                        <TableCell
                            class="max-w-64 min-w-40 text-xs whitespace-normal"
                            >{{ persona.estado_contrato }}</TableCell
                        >
                        <TableCell class="text-xs">{{
                            persona.estado_imss
                        }}</TableCell>
                        <TableCell>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-auto min-h-8 max-w-56 whitespace-normal"
                                as-child
                            >
                                <Link :href="persona.ciclo_url">
                                    {{
                                        persona.siguiente_accion ?? 'Ver ciclo'
                                    }}
                                </Link>
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <PaginacionTabla :datos="personas" />
    </div>
</template>
