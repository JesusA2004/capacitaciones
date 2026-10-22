<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Eye,
    FileStack,
    FileText,
    ListChecks,
    ScanText,
    Split,
} from '@lucide/vue';
import { computed, reactive } from 'vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import { useAlertas } from '@/composables/useAlertas';
import { opcionesDocumentoChecklist } from '@/lib/opcionesDocumentoChecklist';
import { dashboard } from '@/routes';
import { index as indexExpedientes } from '@/routes/rh/expedientes';
import { aplicar } from '@/routes/rh/expedientes/division-historico/datos';
import { asignar } from '@/routes/rh/expedientes/division-historico/partes';
import { dividir } from '@/routes/rh/expedientes/historico';
import type {
    ConfianzaDivision,
    DatoDetectadoDivision,
    DetalleDivisionHistorico,
    DivisionHistorico,
    HistoricoDivision,
    ParteDivision,
} from '@/types';

/**
 * RH → Expediente → «Dividir PDF histórico» (docs/DIVISION_EXPEDIENTE_HISTORICO.md).
 * El PDF único se divide en COPIAS por documento del checklist (el original
 * nunca se toca); lo clasificado entra al checklist EN REVISIÓN y los datos
 * detectados solo se aplican cuando RH los confirma uno por uno.
 */
const props = defineProps<{
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        sucursal: string | null;
        expediente_url: string;
    };
    detalle: DetalleDivisionHistorico;
    tiposChecklist: {
        id: number;
        nombre: string;
        clave?: string;
        requerido?: boolean;
    }[];
    permisos: { dividir: boolean; asignar: boolean; aplicar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Expedientes', href: indexExpedientes() },
            { title: 'Dividir PDF histórico' },
        ],
    },
});

const { confirmarAccion } = useAlertas();

// Requeridos / Opcionales con buscador; lo que ya está en el expediente se
// ve deshabilitado (asignarlo crearía otra versión del mismo documento).
const opcionesTipo = computed(() =>
    opcionesDocumentoChecklist(props.tiposChecklist, {
        ids: props.detalle.tipos_cubiertos ?? [],
        nota: 'Ya en el expediente',
    }),
);

/** Tipo elegido por parte para asignarla a mano. */
const tipoParte = reactive<Record<number, number | null>>({});
const enviando = reactive<Record<string, boolean>>({});

const CONFIANZA: Record<
    ConfianzaDivision,
    { etiqueta: string; variante: 'success' | 'info' | 'warning' }
> = {
    alta: { etiqueta: 'Alta', variante: 'success' },
    media: { etiqueta: 'Media', variante: 'info' },
    baja: { etiqueta: 'Baja', variante: 'warning' },
    manual: { etiqueta: 'Asignada por RH', variante: 'success' },
};

function varianteParte(
    parte: ParteDivision,
): 'success' | 'warning' | 'secondary' {
    if (parte.asignada) {
        return 'success';
    }

    return parte.estado === 'sin_clasificar' ? 'warning' : 'secondary';
}

function varianteDato(
    dato: DatoDetectadoDivision,
): 'success' | 'warning' | 'destructive' | 'info' {
    switch (dato.estado) {
        case 'coincide':
        case 'aplicado':
            return 'success';
        case 'faltante':
            return 'info';
        case 'discrepancia':
            return 'warning';
        default:
            return 'destructive';
    }
}

function datoAplicable(dato: DatoDetectadoDivision): boolean {
    return (
        props.permisos.aplicar &&
        ['faltante', 'discrepancia', 'conflicto_documentos'].includes(
            dato.estado,
        )
    );
}

function relevantes(division: DivisionHistorico): DatoDetectadoDivision[] {
    return division.datos.filter((d) => d.estado !== 'coincide');
}

async function dividirHistorico(h: HistoricoDivision): Promise<void> {
    const repetir = h.division !== null;

    if (
        repetir &&
        !(await confirmarAccion(
            'Volver a dividir',
            'Se genera una división nueva con las reglas actuales. Lo que ya se cargó al checklist no se borra ni se reemplaza, y las hojas que RH corrigió a mano se respetan.',
            'Sí, volver a dividir',
        ))
    ) {
        return;
    }

    enviando[`h${h.id}`] = true;
    router.post(
        dividir(h.id).url,
        { forzar: repetir },
        {
            preserveScroll: true,
            onFinish: () => (enviando[`h${h.id}`] = false),
        },
    );
}

function asignarParte(parte: ParteDivision): void {
    const tipo = tipoParte[parte.id];

    if (!tipo) {
        return;
    }

    enviando[`p${parte.id}`] = true;
    router.post(
        asignar(parte.id).url,
        { document_type_id: tipo },
        {
            preserveScroll: true,
            onFinish: () => (enviando[`p${parte.id}`] = false),
        },
    );
}

async function aplicarDato(
    division: DivisionHistorico,
    dato: DatoDetectadoDivision,
    valor: string,
): Promise<void> {
    const texto =
        dato.actual !== null && dato.actual !== ''
            ? `El colaborador tiene «${dato.actual}». ¿Reemplazarlo por «${valor}», como dice el PDF histórico?`
            : `¿Registrar «${valor}» como ${dato.etiqueta} del colaborador?`;

    if (
        !(await confirmarAccion(
            `Aplicar ${dato.etiqueta}`,
            texto,
            'Sí, aplicar',
        ))
    ) {
        return;
    }

    enviando[`d${division.id}${dato.campo}`] = true;
    router.post(
        aplicar(division.id).url,
        { campo: dato.campo, valor },
        {
            preserveScroll: true,
            onFinish: () => (enviando[`d${division.id}${dato.campo}`] = false),
        },
    );
}
</script>

<template>
    <Head title="Dividir PDF histórico" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Dividir PDF histórico"
            :descripcion="`${colaborador.nombre}${colaborador.numero_empleado ? ` · ${colaborador.numero_empleado}` : ''}${colaborador.sucursal ? ` · ${colaborador.sucursal}` : ''}. El PDF único se separa en copias por documento del checklist; el original nunca se modifica.`"
            :icono="Split"
        >
            <template #default>
                <Button variant="outline" as-child>
                    <Link :href="colaborador.expediente_url">
                        <ArrowLeft class="size-4" /> Volver al expediente
                    </Link>
                </Button>
            </template>
        </CrudPageHeader>

        <!-- Disponibilidad de OCR -->
        <div
            class="flex items-start gap-3 rounded-2xl border p-4 text-sm"
            :class="
                detalle.ocr.disponible
                    ? 'border-success/30 bg-success-soft text-success'
                    : 'border-warning/30 bg-warning-soft text-warning'
            "
        >
            <ScanText class="mt-0.5 size-5 shrink-0" />
            <div>
                <p class="font-semibold">
                    {{
                        detalle.ocr.disponible
                            ? `OCR local disponible: ${detalle.ocr.motor}`
                            : 'OCR no disponible en este servidor'
                    }}
                </p>
                <p v-if="!detalle.ocr.disponible" class="text-foreground/80">
                    {{ detalle.ocr.motivo }} Las páginas escaneadas se reportan
                    como «sin OCR disponible» y RH las asigna a mano.
                </p>
            </div>
        </div>

        <!-- Checklist: ¿expediente completo? -->
        <Card class="rounded-2xl border-border/60">
            <CardContent class="flex flex-col gap-4 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold">
                            Checklist del expediente
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Solo cuenta como completo lo que RH aprobó. Lo que
                            sale del PDF histórico entra en revisión.
                        </p>
                    </div>
                    <Badge
                        :variant="
                            detalle.checklist.completo
                                ? 'success'
                                : detalle.checklist.cubierto
                                  ? 'info'
                                  : 'warning'
                        "
                        class="text-sm"
                    >
                        <CheckCircle2
                            v-if="detalle.checklist.completo"
                            class="size-4"
                        />
                        {{
                            detalle.checklist.completo
                                ? 'Expediente completo'
                                : detalle.checklist.cubierto
                                  ? 'Completo en cuanto RH apruebe lo que está en revisión'
                                  : `Incompleto: faltan ${detalle.checklist.faltantes.length}`
                        }}
                    </Badge>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <MetricCard
                        etiqueta="Obligatorios"
                        :valor="detalle.checklist.requeridos"
                    />
                    <MetricCard
                        etiqueta="Con archivo"
                        :valor="detalle.checklist.entregados"
                    />
                    <MetricCard
                        etiqueta="En revisión"
                        :valor="detalle.checklist.en_revision"
                    />
                    <MetricCard
                        etiqueta="Aprobados"
                        :valor="`${detalle.checklist.aprobados} (${detalle.checklist.porcentaje}%)`"
                    />
                </div>

                <ul class="grid gap-2 md:grid-cols-2">
                    <li
                        v-for="d in detalle.checklist.documentos"
                        :key="d.document_type_id"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border/60 px-3 py-2 text-sm"
                    >
                        <span class="min-w-0">
                            <span class="font-medium">{{ d.nombre }}</span>
                            <span
                                v-if="d.desde_historico"
                                class="block text-xs text-muted-foreground"
                                >Del PDF histórico (p.
                                {{ d.desde_historico }})</span
                            >
                        </span>
                        <Badge
                            :variant="
                                d.estado === 'aprobado'
                                    ? 'success'
                                    : d.documento_id === null
                                      ? 'destructive'
                                      : 'warning'
                            "
                        >
                            {{
                                d.documento_id === null
                                    ? 'Falta'
                                    : d.estado_etiqueta
                            }}
                        </Badge>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <p
            v-if="!detalle.historicos.length"
            class="rounded-2xl border border-border/60 bg-muted/30 p-6 text-center text-sm text-muted-foreground"
        >
            Este colaborador no tiene PDF histórico de la migración inicial.
        </p>

        <!-- Cada PDF histórico -->
        <Card
            v-for="h in detalle.historicos"
            :key="h.id"
            class="rounded-2xl border-border/60"
        >
            <CardContent class="flex flex-col gap-5 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <FileStack class="size-6 shrink-0 text-primary" />
                        <div class="min-w-0">
                            <p class="truncate font-semibold">
                                {{ h.nombre }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Original: {{ h.original
                                }}{{
                                    h.size
                                        ? ` · ${(h.size / 1048576).toFixed(1)} MB`
                                        : ''
                                }}
                                <template v-if="h.division">
                                    · {{ h.division.estado_etiqueta }}
                                    {{
                                        h.division.creada_en
                                            ? new Date(
                                                  h.division.creada_en,
                                              ).toLocaleString('es-MX')
                                            : ''
                                    }}
                                    {{
                                        h.division.ejecutado_por
                                            ? `por ${h.division.ejecutado_por}`
                                            : ''
                                    }}
                                </template>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" as-child>
                            <a :href="h.ver_url" target="_blank" rel="noopener"
                                ><Eye class="size-4" /> Ver original</a
                            >
                        </Button>
                        <Button
                            v-if="permisos.dividir && h.elegible"
                            size="sm"
                            :disabled="enviando[`h${h.id}`]"
                            @click="dividirHistorico(h)"
                        >
                            <Split class="size-4" />
                            {{
                                h.division
                                    ? 'Volver a dividir'
                                    : 'Dividir por checklist'
                            }}
                        </Button>
                    </div>
                </div>

                <p
                    v-if="!h.division"
                    class="rounded-xl bg-muted/30 p-4 text-sm text-muted-foreground"
                >
                    Aún no se divide. Al dividirlo se lee cada página, se
                    reconoce a qué documento del checklist pertenece y se
                    generan copias; el PDF original se conserva íntegro.
                </p>

                <template v-else>
                    <div
                        v-if="h.division.estado === 'fallida'"
                        class="rounded-xl border border-destructive/30 bg-danger-soft p-4 text-sm text-destructive"
                    >
                        No se pudo dividir: {{ h.division.error }}
                    </div>
                    <div
                        v-else-if="h.division.estado === 'procesando'"
                        class="rounded-xl bg-info-soft p-4 text-sm text-info"
                    >
                        Dividiendo… actualiza la página en unos minutos.
                    </div>

                    <template v-if="h.division.estado === 'completada'">
                        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <MetricCard
                                etiqueta="Páginas"
                                :valor="h.division.total_paginas"
                                :icono="FileText"
                            />
                            <MetricCard
                                etiqueta="Clasificadas"
                                :valor="h.division.paginas_clasificadas"
                            />
                            <MetricCard
                                etiqueta="Sin clasificar"
                                :valor="h.division.paginas_sin_clasificar"
                            />
                            <MetricCard
                                etiqueta="Escaneadas sin OCR"
                                :valor="h.division.paginas_sin_texto"
                            />
                        </div>

                        <ul
                            v-if="h.division.advertencias.length"
                            class="flex flex-col gap-1 rounded-xl border border-warning/30 bg-warning-soft p-4 text-sm"
                        >
                            <li
                                v-for="(a, i) in h.division.advertencias"
                                :key="i"
                                class="flex items-start gap-2"
                            >
                                <AlertTriangle
                                    class="mt-0.5 size-4 shrink-0 text-warning"
                                />
                                <span>{{ a }}</span>
                            </li>
                        </ul>

                        <!-- Editor de hojas -->
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/20 bg-salvia/50 p-4"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-semibold">
                                    ¿El OCR juntó o dejó fuera hojas?
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    Corrige hoja por hoja: asigna cada hoja a su
                                    documento, une hojas al documento anterior o
                                    parte un bloque.
                                    <template v-if="h.division.revision">
                                        Última corrección: revisión
                                        {{ h.division.revision.version }}
                                        {{
                                            h.division.revision.por
                                                ? `por ${h.division.revision.por}`
                                                : ''
                                        }}
                                        ({{ h.division.revision.cambios }}
                                        hoja(s)).
                                    </template>
                                    <template
                                        v-else-if="h.division.paginas_manuales"
                                    >
                                        {{ h.division.paginas_manuales }}
                                        hoja(s) corregidas por RH.
                                    </template>
                                </p>
                            </div>
                            <Button as-child>
                                <Link :href="h.division.editor_url">
                                    <ListChecks class="size-4" /> Corregir hojas
                                </Link>
                            </Button>
                        </div>

                        <!-- Partes -->
                        <section class="flex flex-col gap-2">
                            <h4 class="text-sm font-semibold">
                                Documentos encontrados
                            </h4>
                            <div class="overflow-x-auto rounded-xl border">
                                <table class="w-full text-sm">
                                    <thead class="bg-muted/40 text-left">
                                        <tr>
                                            <th class="p-2">Páginas</th>
                                            <th class="p-2">Documento</th>
                                            <th class="p-2">Confianza</th>
                                            <th class="p-2">Resultado</th>
                                            <th class="p-2 text-right">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="p in h.division.partes"
                                            :key="p.id"
                                            class="border-t align-top"
                                        >
                                            <td class="p-2 whitespace-nowrap">
                                                {{ p.paginas }}
                                            </td>
                                            <td class="p-2">
                                                {{ p.tipo ?? 'Sin clasificar' }}
                                            </td>
                                            <td class="p-2">
                                                <Badge
                                                    v-if="p.confianza"
                                                    :variant="
                                                        CONFIANZA[p.confianza]
                                                            .variante
                                                    "
                                                    >{{
                                                        CONFIANZA[p.confianza]
                                                            .etiqueta
                                                    }}</Badge
                                                >
                                                <span
                                                    v-else
                                                    class="text-muted-foreground"
                                                    >—</span
                                                >
                                            </td>
                                            <td class="p-2">
                                                <Badge
                                                    :variant="varianteParte(p)"
                                                    >{{
                                                        p.estado_etiqueta
                                                    }}</Badge
                                                >
                                                <span
                                                    v-if="p.documento_estado"
                                                    class="block text-xs text-muted-foreground"
                                                    >Documento:
                                                    {{
                                                        p.documento_estado
                                                    }}</span
                                                >
                                            </td>
                                            <td class="p-2">
                                                <div
                                                    class="flex flex-wrap items-center justify-end gap-2"
                                                >
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        as-child
                                                    >
                                                        <a
                                                            :href="p.ver_url"
                                                            target="_blank"
                                                            rel="noopener"
                                                            ><Eye
                                                                class="size-4"
                                                            />
                                                            Ver</a
                                                        >
                                                    </Button>
                                                    <template
                                                        v-if="
                                                            permisos.asignar &&
                                                            !p.asignada
                                                        "
                                                    >
                                                        <ComboboxBuscable
                                                            v-model="
                                                                tipoParte[p.id]
                                                            "
                                                            :opciones="
                                                                opcionesTipo
                                                            "
                                                            placeholder="Asignar a…"
                                                            placeholder-busqueda="Busca: INE, CURP, licencia…"
                                                            size="sm"
                                                            numerico
                                                            class="w-56"
                                                            aria-label="Documento del checklist"
                                                        />
                                                        <Button
                                                            size="sm"
                                                            :disabled="
                                                                !tipoParte[
                                                                    p.id
                                                                ] ||
                                                                enviando[
                                                                    `p${p.id}`
                                                                ]
                                                            "
                                                            @click="
                                                                asignarParte(p)
                                                            "
                                                            >Cargar</Button
                                                        >
                                                    </template>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <!-- Datos faltantes / discrepancias -->
                        <section class="flex flex-col gap-2">
                            <h4 class="text-sm font-semibold">
                                Datos encontrados en el PDF
                            </h4>
                            <p class="text-xs text-muted-foreground">
                                Nada se aplica solo: confirma cada dato. El
                                nombre nunca se toma del PDF, solo se verifica.
                            </p>
                            <p
                                v-if="!relevantes(h.division).length"
                                class="rounded-xl bg-muted/30 p-4 text-sm text-muted-foreground"
                            >
                                {{
                                    h.division.datos.length
                                        ? 'Todo lo que se encontró ya coincide con el expediente.'
                                        : 'No se encontraron datos personales legibles en el PDF.'
                                }}
                            </p>
                            <div
                                v-else
                                class="overflow-x-auto rounded-xl border"
                            >
                                <table class="w-full text-sm">
                                    <thead class="bg-muted/40 text-left">
                                        <tr>
                                            <th class="p-2">Dato</th>
                                            <th class="p-2">En el sistema</th>
                                            <th class="p-2">En el PDF</th>
                                            <th class="p-2">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="d in relevantes(h.division)"
                                            :key="d.campo"
                                            class="border-t align-top"
                                        >
                                            <td class="p-2 font-medium">
                                                {{ d.etiqueta }}
                                            </td>
                                            <td class="p-2">
                                                {{ d.actual ?? '—' }}
                                            </td>
                                            <td class="p-2">
                                                <div
                                                    v-for="v in d.valores"
                                                    :key="v.valor"
                                                    class="flex flex-wrap items-center gap-2 py-0.5"
                                                >
                                                    <span class="font-mono">{{
                                                        v.valor
                                                    }}</span>
                                                    <span
                                                        class="text-xs text-muted-foreground"
                                                        >{{
                                                            v.fuentes.join(
                                                                ' · ',
                                                            )
                                                        }}</span
                                                    >
                                                    <Button
                                                        v-if="datoAplicable(d)"
                                                        size="sm"
                                                        variant="outline"
                                                        :disabled="
                                                            enviando[
                                                                `d${h.division.id}${d.campo}`
                                                            ]
                                                        "
                                                        @click="
                                                            aplicarDato(
                                                                h.division,
                                                                d,
                                                                v.valor,
                                                            )
                                                        "
                                                        >Aplicar</Button
                                                    >
                                                </div>
                                            </td>
                                            <td class="p-2">
                                                <Badge
                                                    :variant="varianteDato(d)"
                                                    >{{
                                                        d.estado_etiqueta
                                                    }}</Badge
                                                >
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <!-- Auditoría página por página -->
                        <details class="rounded-xl border p-3 text-sm">
                            <summary class="cursor-pointer font-medium">
                                Detalle por página ({{
                                    h.division.paginas.length
                                }})
                            </summary>
                            <ul class="mt-3 flex flex-col gap-1">
                                <li
                                    v-for="pg in h.division.paginas"
                                    :key="pg.pagina"
                                    class="flex flex-wrap gap-x-3 border-t py-1"
                                >
                                    <span class="w-16 font-medium"
                                        >Pág. {{ pg.pagina }}</span
                                    >
                                    <span class="w-48">{{
                                        pg.tipo_nombre ?? 'Sin clasificar'
                                    }}</span>
                                    <span class="text-muted-foreground">{{
                                        pg.metodo_etiqueta
                                    }}</span>
                                    <span
                                        v-if="pg.coincidencias.length"
                                        class="text-muted-foreground"
                                        >·
                                        {{ pg.coincidencias.join(', ') }}</span
                                    >
                                    <span
                                        v-if="pg.motivo"
                                        class="text-muted-foreground"
                                        >· {{ pg.motivo }}</span
                                    >
                                </li>
                            </ul>
                        </details>
                    </template>
                </template>
            </CardContent>
        </Card>
    </div>
</template>
