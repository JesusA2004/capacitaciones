<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    ClipboardList,
    FileCheck2,
    FileClock,
    FileSignature,
    FileX2,
    FolderOpen,
    Hourglass,
    MapPin,
    ShieldCheck,
    UserPlus,
} from '@lucide/vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import FiltroColaboradorActivo from '@/components/flujos/FiltroColaboradorActivo.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
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
import { vTablaTarjetas } from '@/lib/tablaTarjetas';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/contratacion';
import { index as contratosIngresoIndex } from '@/routes/rh/contratos-ingreso';
import { index as validacionIndex } from '@/routes/rh/validacion-documentos';
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
    foto_url: string | null;
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
    filtros: { busqueda?: string; sucursal_id?: string; colaborador?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard.url() },
            { title: 'Personas en contratación', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(index.url(), {
    busqueda: props.filtros.busqueda ?? '',
    sucursal_id: props.filtros.sucursal_id ?? '',
    colaborador: props.filtros.colaborador ?? '',
});

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
        <FiltroColaboradorActivo
            :activo="!!filtros.colaborador"
            @quitar="
                filtros.colaborador = '';
                aplicarConDebounce(0);
            "
        />
        <CrudPageHeader
            titulo="Personas en contratación"
            descripcion="Colaboradores en la Etapa 2 (contratación y expediente): integra tanto a quienes ya se dieron de alta como las altas digitales que aún no se aprueban."
            :icono="UserPlus"
        />

        <!-- Indicadores + accesos a los dos pasos que siguen (validar
             documentos y contratos de ingreso). -->
        <div
            data-tour="encabezado"
            class="grid entrada-escalonada grid-cols-2 gap-3 xl:grid-cols-4"
        >
            <TarjetaMetrica
                etiqueta="En contratación"
                :valor="personas.total"
                :icono="UserPlus"
                tono="primario"
                detalle="Etapa 2: contrato y expediente"
            />
            <TarjetaMetrica
                etiqueta="Altas digitales por aprobar"
                :valor="altasDigitales.length"
                :icono="Hourglass"
                :tono="altasDigitales.length ? 'alerta' : 'exito'"
                detalle="Esperan la revisión de RH"
            />
            <Link
                :href="validacionIndex.url()"
                class="group col-span-2 flex tarjeta-interactiva items-center gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm md:col-span-1"
            >
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-info-soft text-info"
                    aria-hidden="true"
                >
                    <ClipboardList class="size-5" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold"
                        >Validar documentos</span
                    >
                    <span class="block text-xs text-muted-foreground"
                        >Revisa lo que subió cada persona</span
                    >
                </span>
                <ArrowRight class="size-4 icono-avanza text-muted-foreground" />
            </Link>
            <Link
                :href="contratosIngresoIndex.url()"
                class="group col-span-2 flex tarjeta-interactiva items-center gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm md:col-span-1"
            >
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-crema text-bronce"
                    aria-hidden="true"
                >
                    <FileSignature class="size-5" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold"
                        >Contratos de ingreso</span
                    >
                    <span class="block text-xs text-muted-foreground"
                        >Genera y da seguimiento al paquete</span
                    >
                </span>
                <ArrowRight class="size-4 icono-avanza text-muted-foreground" />
            </Link>
        </div>

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
            <div
                class="grid entrada-escalonada grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <Link
                    v-for="alta in altasDigitales"
                    :key="alta.id"
                    :href="alta.ver_url"
                    class="group flex tarjeta-interactiva flex-col gap-1 rounded-xl border border-border/60 bg-background p-3"
                >
                    <p class="flex items-center gap-2 text-sm font-semibold">
                        <ColaboradorAvatar
                            :nombre="alta.nombre"
                            :foto-url="alta.foto_url"
                            tamano="xs"
                        />
                        <span class="truncate">{{ alta.nombre }}</span>
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
            class="grid entrada-escalonada grid-cols-1 gap-3 md:grid-cols-2 xl:hidden"
        >
            <li
                v-for="persona in personas.data"
                :key="persona.id"
                class="flex min-w-0 flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transicion-ui hover:border-oro/40 hover:shadow-md"
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
                            class="enlace-animado font-medium text-primary"
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
                            class="h-full crece-barra rounded-full bg-gradient-to-r from-primary to-esmeralda"
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
            class="hidden tabla-scroll rounded-2xl border border-border/60 bg-card shadow-sm xl:block"
        >
            <Table v-tabla-tarjetas>
                <TableHeader>
                    <TableRow>
                        <TableHead>Colaborador</TableHead>
                        <TableHead>Avance</TableHead>
                        <TableHead>Documentos</TableHead>
                        <TableHead>Contrato</TableHead>
                        <TableHead>IMSS</TableHead>
                        <TableHead class="text-right"
                            >Siguiente acción</TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody class="entrada-escalonada">
                    <TableRow
                        v-for="persona in personas.data"
                        :key="persona.id"
                        class="transicion-ui hover:bg-menta/40"
                    >
                        <TableCell>
                            <div class="flex min-w-0 items-center gap-3">
                                <ColaboradorAvatar
                                    :nombre="persona.nombre"
                                    :foto-url="persona.foto_url"
                                />
                                <div class="min-w-0">
                                    <Link
                                        :href="persona.ciclo_url"
                                        class="enlace-animado font-medium text-primary"
                                    >
                                        {{ persona.nombre }}
                                    </Link>
                                    <p
                                        class="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground"
                                    >
                                        <template
                                            v-if="persona.numero_empleado"
                                        >
                                            <span class="tabular-nums"
                                                >#{{
                                                    persona.numero_empleado
                                                }}</span
                                            >
                                            <span aria-hidden="true">·</span>
                                        </template>
                                        <span>{{
                                            persona.puesto ?? 'Sin puesto'
                                        }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span
                                            class="inline-flex items-center gap-0.5"
                                        >
                                            <MapPin
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                            {{
                                                persona.sucursal ??
                                                'Sin sucursal'
                                            }}
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </TableCell>
                        <TableCell>
                            <div class="flex min-w-40 flex-col gap-1.5">
                                <div
                                    class="flex items-baseline justify-between gap-2 text-xs"
                                >
                                    <span class="font-medium">{{
                                        persona.etapa
                                    }}</span>
                                    <span
                                        class="text-muted-foreground tabular-nums"
                                        >{{ persona.progreso }}%</span
                                    >
                                </div>
                                <div
                                    class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full crece-barra rounded-full bg-gradient-to-r from-primary to-esmeralda"
                                        :style="{
                                            width: persona.progreso + '%',
                                        }"
                                    />
                                </div>
                            </div>
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-wrap gap-1.5">
                                <ChipEstado
                                    :icono="
                                        persona.documentos_pendientes
                                            ? FileClock
                                            : FileCheck2
                                    "
                                    :tono="
                                        persona.documentos_pendientes
                                            ? 'alerta'
                                            : 'exito'
                                    "
                                    :etiqueta="
                                        persona.documentos_pendientes
                                            ? `${persona.documentos_pendientes} por recibir`
                                            : 'Completos'
                                    "
                                />
                                <ChipEstado
                                    v-if="persona.documentos_rechazados"
                                    :icono="FileX2"
                                    tono="peligro"
                                    :etiqueta="`${persona.documentos_rechazados} rechazado${persona.documentos_rechazados === 1 ? '' : 's'}`"
                                />
                            </div>
                        </TableCell>
                        <TableCell
                            class="max-w-64 min-w-40 text-xs whitespace-normal text-muted-foreground"
                        >
                            {{ persona.estado_contrato }}
                        </TableCell>
                        <TableCell>
                            <ChipEstado
                                :icono="ShieldCheck"
                                :tono="
                                    persona.estado_imss
                                        .toLowerCase()
                                        .includes('pendiente')
                                        ? 'neutro'
                                        : 'exito'
                                "
                                :etiqueta="persona.estado_imss"
                            />
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                variant="outline"
                                size="sm"
                                class="h-auto min-h-8 max-w-60 border-primary/25 bg-salvia/40 text-left whitespace-normal text-primary hover:bg-salvia"
                                as-child
                            >
                                <Link :href="persona.ciclo_url">
                                    {{
                                        persona.siguiente_accion ?? 'Ver ciclo'
                                    }}
                                    <ArrowRight
                                        class="size-3.5 shrink-0 icono-avanza"
                                    />
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
