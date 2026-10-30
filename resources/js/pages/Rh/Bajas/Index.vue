<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    ClipboardCheck,
    FileWarning,
    Hourglass,
    Lock,
    Undo2,
    UserMinus,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import type { Component } from 'vue';
import GeneradorDocumentoSalida from '@/components/bajas/GeneradorDocumentoSalida.vue';
import PestanasBajasNav from '@/components/bajas/PestanasBajas.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { parametrosPorPaginaActuales } from '@/composables/usePaginacion';
import { claseForma, estatusBaja } from '@/lib/bajas';
import { formatearFecha } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import rutasBajas from '@/routes/rh/bajas';
import type {
    FilaBaja,
    PanelDocumentoSalida,
    PestanasBajas,
    ResumenBajas,
} from '@/types';

/**
 * «Bajas»: solo gerentes (su personal), RH y super admin; Jurídico y
 * Contraloría de lectura. La baja es directa: se registra y queda aplicada;
 * aquí se sigue su trámite (documentos, finiquito, cierre) sin autorización de
 * Jurídico. Al elegir un renglón se abre a la derecha «Generar documento de
 * salida». El alcance lo aplica el backend (BajaConsultaService).
 */
type Paginador = {
    data: FilaBaja[];
    current_page: number;
    last_page: number;
    per_page: number;
    from?: number | null;
    to?: number | null;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

const props = defineProps<{
    bajas: Paginador;
    filtros: {
        estatus: string;
        sucursal_id: number | null;
        forma: string | null;
        busqueda: string;
        cubo: string | null;
    };
    pestanas: PestanasBajas;
    seleccion: PanelDocumentoSalida | null;
    estatus: { value: string; label: string }[];
    formas: { value: string; label: string; token: string }[];
    sucursales: { value: number; label: string }[];
    resumen: ResumenBajas;
    puede: {
        solicitar: boolean;
        gestionar: boolean;
        tablero: boolean;
        recopilacion: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Bajas', href: '' },
        ],
    },
});

const termino = ref(props.filtros.busqueda);
const estatusElegido = ref(props.filtros.estatus);
const sucursal = ref<number | null>(props.filtros.sucursal_id);
const forma = ref(props.filtros.forma ?? '');
const cubo = ref(props.filtros.cubo ?? '');

function filtrar() {
    router.get(
        rutasBajas.index.url(),
        {
            ...parametrosPorPaginaActuales(),
            busqueda: termino.value || undefined,
            estatus: estatusElegido.value || undefined,
            sucursal_id: sucursal.value ?? undefined,
            forma: forma.value || undefined,
            cubo: cubo.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(termino, useDebounceFn(filtrar, 300));
watch([estatusElegido, sucursal, forma, cubo], filtrar);

function seleccionar(b: FilaBaja) {
    router.get(
        rutasBajas.index.url(),
        {
            ...parametrosPorPaginaActuales(),
            busqueda: termino.value || undefined,
            estatus: estatusElegido.value || undefined,
            sucursal_id: sucursal.value ?? undefined,
            forma: forma.value || undefined,
            cubo: cubo.value || undefined,
            baja: b.id,
        },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['seleccion', 'errors'],
        },
    );
}

// Flujo sin Jurídico (PDF Etapa 1): «Faltan documentos» y «Listas para cerrar»
// son agrupadores derivados de los documentos, no estatus persistentes.
const metricas: {
    etiqueta: string;
    detalle: string;
    campo: keyof ResumenBajas;
    icono: Component;
    tono: 'info' | 'alerta' | 'exito' | 'neutro' | 'primario';
    cubo?: string;
    estatus?: string;
}[] = [
    {
        etiqueta: 'En trámite',
        detalle: 'Integrando documentos',
        campo: 'en_tramite',
        icono: Hourglass,
        tono: 'info',
        cubo: 'en_tramite',
    },
    {
        etiqueta: 'Faltan documentos',
        detalle: 'Último día ya pasó',
        campo: 'faltan_documentos',
        icono: FileWarning,
        tono: 'alerta',
        cubo: 'faltan_documentos',
    },
    {
        etiqueta: 'Listas para cerrar',
        detalle: 'Documentos completos',
        campo: 'lista_para_cerrar',
        icono: ClipboardCheck,
        tono: 'primario',
        cubo: 'lista_para_cerrar',
    },
    {
        etiqueta: 'Cerradas',
        detalle: 'En el periodo',
        campo: 'cerradas',
        icono: Lock,
        tono: 'exito',
        estatus: 'cerrada',
    },
    {
        etiqueta: 'No procede',
        detalle: 'Canceladas',
        campo: 'no_procede',
        icono: Undo2,
        tono: 'neutro',
        estatus: 'cancelada',
    },
];

function metricaActiva(m: (typeof metricas)[number]): boolean {
    return m.cubo ? cubo.value === m.cubo : estatusElegido.value === m.estatus;
}

function elegirMetrica(m: (typeof metricas)[number]) {
    if (m.cubo) {
        cubo.value = cubo.value === m.cubo ? '' : m.cubo;
        estatusElegido.value = 'abiertas';

        return;
    }

    cubo.value = '';
    estatusElegido.value =
        estatusElegido.value === m.estatus ? 'abiertas' : (m.estatus as string);
}

const flujo = [
    { texto: '1 · En trámite', clase: 'bg-info-soft text-info' },
    { texto: '2 · Faltan documentos', clase: 'bg-warning-soft text-warning' },
    { texto: '3 · Lista para cerrar', clase: 'bg-menta text-foreground' },
    { texto: '4 · Cerrada', clase: 'bg-success-soft text-success' },
];

function etiquetaCubo(b: FilaBaja): string {
    return (
        (
            {
                en_tramite: 'En trámite',
                faltan_documentos: 'Faltan documentos',
                lista_para_cerrar: 'Lista para cerrar',
            } as Record<string, string>
        )[b.cubo] ?? b.estatus_etiqueta
    );
}

function tonoCubo(b: FilaBaja) {
    return (
        (
            {
                en_tramite: 'info',
                faltan_documentos: 'alerta',
                lista_para_cerrar: 'primario',
            } as Record<string, 'info' | 'alerta' | 'primario'>
        )[b.cubo] ?? estatusBaja(b.estatus).tono
    );
}

function avance(b: FilaBaja): string {
    if (b.documentos_total === 0) {
        return 'No exige';
    }

    return `${Math.max(0, b.documentos_total - b.documentos_faltantes)}/${b.documentos_total}`;
}
</script>

<template>
    <Head title="Bajas" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Bajas"
            descripcion="Se registran y quedan aplicadas al momento. Aquí sigues sus documentos y el cierre."
            :icono="UserMinus"
        >
            <Button v-if="puede.solicitar" as-child>
                <Link :href="rutasBajas.create()"
                    ><UserMinus class="size-4" />Registrar baja</Link
                >
            </Button>
        </CrudPageHeader>

        <PestanasBajasNav :pestanas="pestanas" actual="bajas" />

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <TarjetaMetrica
                v-for="m in metricas"
                :key="m.campo"
                :etiqueta="m.etiqueta"
                :valor="resumen[m.campo]"
                :detalle="m.detalle"
                :icono="m.icono"
                :tono="m.tono"
                clicable
                :activa="metricaActiva(m)"
                @click="elegirMetrica(m)"
            />
        </div>

        <div
            class="flex flex-wrap items-center gap-2 rounded-2xl border border-border/60 bg-card px-4 py-2 text-xs shadow-sm"
            aria-label="Flujo de la baja"
        >
            <span class="font-semibold">Flujo de la baja:</span>
            <template v-for="(p, i) in flujo" :key="p.texto">
                <span v-if="i > 0" aria-hidden="true">→</span>
                <span
                    :class="cn('rounded-full px-2 py-0.5 font-medium', p.clase)"
                    >{{ p.texto }}</span
                >
            </template>
            <span class="ml-1 rounded-full bg-muted px-2 py-0.5 font-medium"
                >No procede</span
            >
            <span class="ml-auto text-muted-foreground"
                >Sin autorización de Jurídico</span
            >
        </div>

        <div
            :class="
                cn(
                    'grid items-start gap-4',
                    seleccion && 'xl:grid-cols-[3fr_2fr]',
                )
            "
        >
            <div class="flex min-w-0 flex-col gap-3">
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <Label class="mb-1.5 text-xs text-muted-foreground"
                            >Buscar</Label
                        >
                        <Input
                            v-model="termino"
                            placeholder="Nombre, número, folio, puesto o sucursal"
                        />
                    </div>
                    <div>
                        <Label class="mb-1.5 text-xs text-muted-foreground"
                            >Estatus</Label
                        >
                        <SelectSimple
                            v-model="estatusElegido"
                            :opciones="props.estatus"
                        />
                    </div>
                    <div>
                        <Label class="mb-1.5 text-xs text-muted-foreground"
                            >Sucursal</Label
                        >
                        <SelectSimple
                            v-model="sucursal"
                            :opciones="sucursales"
                            numerico
                            opcion-vacia="Todas"
                            placeholder="Todas"
                        />
                    </div>
                    <div>
                        <Label class="mb-1.5 text-xs text-muted-foreground"
                            >Forma de salida</Label
                        >
                        <SelectSimple
                            v-model="forma"
                            :opciones="formas"
                            opcion-vacia="Todas"
                            placeholder="Todas"
                        />
                    </div>
                </div>

                <p
                    v-if="!props.bajas.data.length"
                    class="rounded-2xl border border-border/60 bg-card px-4 py-10 text-center text-sm text-muted-foreground"
                >
                    No hay bajas con estos filtros.
                </p>

                <template v-else>
                    <div
                        class="tabla-scroll rounded-2xl border border-border/60 bg-card shadow-sm"
                    >
                        <table class="w-full text-left text-sm">
                            <thead
                                class="bg-crema/40 text-xs text-muted-foreground uppercase"
                            >
                                <tr>
                                    <th class="min-w-56 px-3 py-2 font-medium">
                                        Colaborador
                                    </th>
                                    <th class="min-w-48 px-3 py-2 font-medium">
                                        Forma · causal
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        Estatus
                                    </th>
                                    <th class="px-3 py-2 font-medium">Docs.</th>
                                    <th class="px-3 py-2 font-medium">
                                        Finiquito
                                    </th>
                                    <th class="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="b in props.bajas.data"
                                    :key="b.id"
                                    :class="
                                        cn(
                                            'border-b border-border/60 last:border-0',
                                            seleccion?.baja_id === b.id &&
                                                'bg-crema/50',
                                        )
                                    "
                                >
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <ColaboradorAvatar
                                                :nombre="b.colaborador"
                                                :foto-url="b.foto_url"
                                            />
                                            <div class="min-w-0">
                                                <p class="font-medium">
                                                    {{ b.colaborador }}
                                                </p>
                                                <p
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {{ b.sucursal ?? '—' }} ·
                                                    {{ b.puesto ?? '—' }}
                                                </p>
                                                <p
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    <span class="font-mono">{{
                                                        b.folio
                                                    }}</span>
                                                    · Último día
                                                    {{
                                                        formatearFecha(
                                                            b.fecha_baja,
                                                        )
                                                    }}
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-xs">
                                        <p
                                            class="flex items-center gap-1.5 font-medium"
                                        >
                                            <span
                                                :class="
                                                    cn(
                                                        'size-2.5 shrink-0 rounded-full',
                                                        claseForma(
                                                            b.forma_token,
                                                        ).fondo,
                                                    )
                                                "
                                            />
                                            {{
                                                b.forma_etiqueta ?? 'Sin forma'
                                            }}
                                        </p>
                                        <p class="text-muted-foreground">
                                            {{ b.causal ?? 'Causal pendiente' }}
                                        </p>
                                        <ChipEstado
                                            v-if="b.integrar_expediente"
                                            class="mt-1"
                                            etiqueta="Integrar expediente probatorio"
                                            tono="oro"
                                        />
                                        <ChipEstado
                                            v-if="b.causal_migrada"
                                            class="mt-1"
                                            etiqueta="Causal migrada: revisar"
                                            tono="alerta"
                                        />
                                    </td>
                                    <td class="px-3 py-2">
                                        <ChipEstado
                                            :etiqueta="etiquetaCubo(b)"
                                            :tono="tonoCubo(b)"
                                        />
                                    </td>
                                    <td class="px-3 py-2 text-xs">
                                        <span
                                            class="inline-flex items-center gap-1 font-medium"
                                            :class="
                                                b.documentos_completos
                                                    ? 'text-success'
                                                    : 'text-warning'
                                            "
                                            ><CheckCircle2
                                                v-if="b.documentos_completos"
                                                class="size-4"
                                            /><AlertTriangle
                                                v-else
                                                class="size-4"
                                            />{{ avance(b) }}</span
                                        >
                                    </td>
                                    <td class="px-3 py-2 text-xs">
                                        <ChipEstado
                                            v-if="b.finiquito"
                                            :etiqueta="b.finiquito.etiqueta"
                                            :tono="
                                                b.finiquito.estado === 'pagado'
                                                    ? 'exito'
                                                    : 'alerta'
                                            "
                                        />
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >—</span
                                        >
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <div class="flex justify-end gap-1">
                                            <Button
                                                v-if="puede.gestionar"
                                                size="sm"
                                                variant="ghost"
                                                @click="seleccionar(b)"
                                                >Documento</Button
                                            >
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                as-child
                                            >
                                                <Link
                                                    :href="
                                                        rutasBajas.show.url(
                                                            b.id,
                                                        )
                                                    "
                                                    >Abrir<ArrowRight
                                                        class="size-3.5"
                                                /></Link>
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Al elegir «Documento» se abre a la derecha el generador
                        del documento de salida de esa baja.
                    </p>

                    <PaginacionTabla :datos="props.bajas" />
                </template>
            </div>

            <GeneradorDocumentoSalida v-if="seleccion" :panel="seleccion" />
        </div>
    </div>
</template>
