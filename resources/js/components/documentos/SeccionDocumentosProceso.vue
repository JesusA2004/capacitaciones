<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    CheckCircle2,
    Circle,
    CircleDot,
    Download,
    FileDown,
    FilePlus2,
    FileText,
    FileUp,
    History,
    Loader2,
    MoreHorizontal,
    PenLine,
    Printer,
    RefreshCcw,
    Send,
    ShieldAlert,
    Truck,
} from '@lucide/vue';
import { ref } from 'vue';
import type { Component } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatearFecha } from '@/lib/fechas';
import type {
    AccionDocumento,
    ItemDocumentoProceso,
    SeccionDocumentosProceso,
} from '@/types';

/**
 * Tarjeta "Documentos de …" de UN proceso (contratación, baja, permiso,
 * préstamo…). Solo pinta lo que manda el backend: qué documentos tocan, su
 * formato oficial ("Gestor v2"), la línea de tiempo, la SIGUIENTE acción
 * (un solo botón principal) y las demás en el menú "…".
 */
defineProps<{
    seccion: SeccionDocumentosProceso;
    ocupado: string | null;
}>();

const emit = defineEmits<{
    accion: [item: ItemDocumentoProceso, accion: AccionDocumento];
    accionSeccion: [accion: AccionDocumento];
}>();

const historialAbierto = ref<string | null>(null);

type Variante = NonNullable<BadgeVariants['variant']>;

function variante(estado: string): Variante {
    if (
        [
            'archivado',
            'escaneado',
            'firmado_fisicamente',
            'recibido_corporativo',
            'pagado',
            'firmado',
        ].includes(estado)
    ) {
        return 'success';
    }

    if (estado === 'sin_formato' || estado === 'formato_sin_validar') {
        return 'destructive';
    }

    if (['bloqueado', 'pendiente_rh'].includes(estado)) {
        return 'outline';
    }

    if (estado === 'por_generar') {
        return 'info';
    }

    return 'warning';
}

const iconoAccion: Record<string, Component> = {
    generar: FilePlus2,
    regenerar: RefreshCcw,
    nueva_revision: ShieldAlert,
    descargar: Download,
    descargar_word: FileDown,
    marcar_impreso: Printer,
    registrar_firma: PenLine,
    registrar_envio: Truck,
    registrar_recepcion: Send,
    subir_escaneo: FileUp,
    archivar: Archive,
};

function principal(item: ItemDocumentoProceso): AccionDocumento | null {
    return item.acciones.find((a) => a.tipo === 'primaria') ?? null;
}

function secundarias(item: ItemDocumentoProceso): AccionDocumento[] {
    const p = principal(item);

    return item.acciones.filter((a) => a !== p);
}

function clave(item: ItemDocumentoProceso, accion: AccionDocumento): string {
    return `${item.clave}:${accion.clave}`;
}
</script>

<template>
    <section
        class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        :aria-label="seccion.titulo"
    >
        <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-base font-semibold">{{ seccion.titulo }}</h2>
                <p class="text-sm text-[var(--mrl-texto-suave)]">
                    {{ seccion.descripcion }}
                </p>
                <Link
                    v-if="
                        seccion.cobertura_url &&
                        !seccion.colaborador.grupo_documental
                    "
                    :href="seccion.cobertura_url"
                    class="text-xs font-medium text-primary underline"
                >
                    Ver cobertura documental
                </Link>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="a in seccion.acciones"
                    :key="a.clave"
                    size="sm"
                    :variant="
                        a.tipo === 'primaria'
                            ? 'default'
                            : a.tipo === 'peligro'
                              ? 'destructive'
                              : 'outline'
                    "
                    :disabled="ocupado !== null"
                    @click="emit('accionSeccion', a)"
                >
                    <Loader2
                        v-if="ocupado === `seccion:${a.clave}`"
                        class="size-4 animate-spin"
                    />
                    {{ a.etiqueta }}
                </Button>
            </div>
        </header>

        <p
            v-if="seccion.expediente_completo"
            class="mb-3 flex items-center gap-2 text-sm text-[var(--mrl-verde)]"
        >
            <CheckCircle2 class="size-4" /> Expediente completo
        </p>
        <p
            v-if="seccion.bloqueo"
            class="mb-3 flex items-start gap-2 rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />
            {{ seccion.bloqueo }}
        </p>
        <p
            v-if="seccion.documentos.length === 0"
            class="rounded-xl border border-dashed border-[var(--mrl-borde)] p-4 text-sm text-[var(--mrl-texto-suave)]"
        >
            Este proceso no requiere documentos oficiales.
        </p>

        <ul class="flex flex-col gap-3">
            <li
                v-for="item in seccion.documentos"
                :key="item.clave"
                class="rounded-xl border border-[var(--mrl-borde)] p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                        >
                            <FileText class="size-4" />
                        </span>
                        <div class="min-w-0">
                            <p class="font-medium">{{ item.nombre }}</p>
                            <p class="text-xs text-[var(--mrl-texto-suave)]">
                                {{ item.motivo }}
                            </p>
                            <p
                                v-if="item.master"
                                class="mt-0.5 text-xs text-[var(--mrl-texto-suave)]"
                            >
                                Formato:
                                <span
                                    class="font-medium text-[var(--mrl-texto)]"
                                    >{{
                                        item.master.etiqueta ??
                                        `v${item.master.version}`
                                    }}</span
                                >
                                <template v-if="item.master.heredada">
                                    (plantilla anterior)</template
                                >
                                <template v-if="item.documento?.generado_en">
                                    · generado
                                    {{
                                        formatearFecha(
                                            item.documento.generado_en,
                                        )
                                    }}
                                    <template v-if="item.documento.generado_por"
                                        >por
                                        {{
                                            item.documento.generado_por
                                        }}</template
                                    >
                                </template>
                            </p>
                            <p
                                v-if="item.documento?.revision_de_id"
                                class="mt-0.5 text-xs text-warning"
                            >
                                Revisión de un documento firmado (#{{
                                    item.documento.revision_de_id
                                }}): {{ item.documento.motivo_revision }}
                            </p>
                        </div>
                    </div>
                    <Badge :variant="variante(item.estado)">{{
                        item.estado_etiqueta
                    }}</Badge>
                </div>

                <!-- Línea de tiempo -->
                <ol
                    v-if="item.documento && item.linea_tiempo?.length"
                    class="mt-3 flex flex-wrap items-center gap-x-1 gap-y-2 text-xs"
                    aria-label="Avance del documento"
                >
                    <li
                        v-for="(paso, i) in item.linea_tiempo"
                        :key="paso.clave"
                        class="flex items-center gap-1"
                    >
                        <CheckCircle2
                            v-if="paso.estado === 'hecho'"
                            class="size-4 text-[var(--mrl-verde)]"
                        />
                        <CircleDot
                            v-else-if="paso.estado === 'actual'"
                            class="size-4 text-primary"
                        />
                        <Circle
                            v-else
                            class="size-4 text-[var(--mrl-gris-verdoso)]"
                        />
                        <span
                            :class="
                                paso.estado === 'actual'
                                    ? 'font-semibold text-primary'
                                    : paso.estado === 'hecho'
                                      ? ''
                                      : 'text-[var(--mrl-texto-suave)]'
                            "
                        >
                            {{ paso.etiqueta }}
                        </span>
                        <span
                            v-if="i < item.linea_tiempo.length - 1"
                            class="mx-1 h-px w-4 bg-[var(--mrl-borde)]"
                            aria-hidden="true"
                        />
                    </li>
                </ol>

                <p
                    v-if="
                        item.documento &&
                        item.documento.archivo_disponible === false
                    "
                    class="mt-3 rounded-xl border border-warning/30 bg-warning-soft/50 p-3 text-sm text-warning"
                >
                    El archivo de este documento ya no está en el
                    almacenamiento.
                    {{
                        item.acciones.some((a) => a.clave === 'regenerar')
                            ? 'Usa «Regenerar» en el menú para volver a emitirlo.'
                            : 'Avisa a Sistemas para restaurarlo.'
                    }}
                </p>
                <div
                    v-if="item.formato_faltante"
                    class="mt-3 rounded-xl border border-destructive/30 bg-danger-soft/50 p-3 text-sm text-destructive"
                >
                    <p class="font-medium">
                        {{ item.formato_faltante.mensaje }}
                    </p>
                    <p class="text-xs">
                        RH/Jurídico debe cargar el formato oficial antes de
                        generarlo.
                    </p>
                    <Link
                        v-if="item.formato_faltante.cobertura_url"
                        :href="item.formato_faltante.cobertura_url"
                        class="mt-1 inline-block text-xs font-medium underline"
                    >
                        Ver cobertura documental
                    </Link>
                </div>
                <p
                    v-else-if="item.bloqueo"
                    class="mt-3 flex items-start gap-2 text-xs text-[var(--mrl-texto-suave)]"
                >
                    <AlertTriangle
                        class="mt-0.5 size-3.5 shrink-0 text-warning"
                    />
                    {{ item.bloqueo }}
                </p>

                <div
                    v-if="item.acciones.length || item.historial.length"
                    class="mt-3 flex flex-wrap items-center gap-2"
                >
                    <Button
                        v-if="principal(item)"
                        size="sm"
                        :disabled="ocupado !== null"
                        @click="emit('accion', item, principal(item)!)"
                    >
                        <Loader2
                            v-if="ocupado === clave(item, principal(item)!)"
                            class="size-4 animate-spin"
                        />
                        <component
                            :is="
                                iconoAccion[principal(item)!.clave] ?? FileText
                            "
                            v-else
                            class="size-4"
                        />
                        {{ principal(item)!.etiqueta }}
                    </Button>
                    <DropdownMenu
                        v-if="secundarias(item).length || item.historial.length"
                    >
                        <DropdownMenuTrigger as-child>
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="ocupado !== null"
                                aria-label="Más acciones"
                            >
                                <MoreHorizontal class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="min-w-56">
                            <DropdownMenuItem
                                v-for="a in secundarias(item)"
                                :key="a.clave"
                                :class="
                                    a.tipo === 'peligro'
                                        ? 'text-destructive focus:text-destructive'
                                        : ''
                                "
                                @select="emit('accion', item, a)"
                            >
                                <component
                                    :is="iconoAccion[a.clave] ?? FileText"
                                    class="size-4"
                                />
                                {{ a.etiqueta }}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator
                                v-if="
                                    secundarias(item).length &&
                                    item.historial.length
                                "
                            />
                            <DropdownMenuItem
                                v-if="item.historial.length"
                                @select="
                                    historialAbierto =
                                        historialAbierto === item.clave
                                            ? null
                                            : item.clave
                                "
                            >
                                <History class="size-4" /> Historial ({{
                                    item.historial.length
                                }})
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <span
                        v-if="item.requiere.huella || item.requiere.testigos"
                        class="text-xs text-[var(--mrl-texto-suave)]"
                    >
                        {{
                            [
                                item.requiere.huella ? 'requiere huella' : null,
                                item.requiere.testigos
                                    ? `${item.requiere.cantidad_testigos || ''} testigos`
                                    : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </span>
                </div>

                <ul
                    v-if="historialAbierto === item.clave"
                    class="mt-3 flex flex-col gap-1 border-t border-[var(--mrl-borde)] pt-2 text-xs text-[var(--mrl-texto-suave)]"
                >
                    <li v-for="h in item.historial" :key="h.id">
                        #{{ h.id }} · v{{ h.version_plantilla ?? '—' }} ·
                        {{ h.estado_etiqueta }} ·
                        {{ h.generado_en ? formatearFecha(h.generado_en) : '' }}
                        <template v-if="h.motivo_cancelacion">
                            · {{ h.motivo_cancelacion }}</template
                        >
                    </li>
                </ul>
            </li>
        </ul>

        <div
            v-if="seccion.negativa"
            class="mt-4 rounded-xl border border-warning/30 bg-warning-soft/50 p-3 text-xs text-warning"
        >
            <p class="font-semibold">
                Negativa de firma registrada el
                {{ formatearFecha(seccion.negativa.registrada_en) }}
            </p>
            <p>
                Testigos:
                {{
                    seccion.negativa.testigos.length
                        ? seccion.negativa.testigos
                              .map((t) => `${t.nombre} (${t.cargo})`)
                              .join(', ')
                        : 'pendientes de capturar'
                }}
            </p>
            <p v-if="seccion.negativa.finiquito_a_disposicion">
                El finiquito quedó a disposición del colaborador.
            </p>
        </div>

        <div v-if="seccion.checklist" class="mt-4">
            <h3
                class="mb-2 text-xs font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
            >
                Checklist final del procedimiento de baja
            </h3>
            <ul class="grid gap-1 text-sm sm:grid-cols-2">
                <li
                    v-for="p in seccion.checklist.filter((c) => c.aplica)"
                    :key="p.clave"
                    class="flex items-center gap-2"
                >
                    <CheckCircle2
                        v-if="p.cumplido"
                        class="size-4 text-[var(--mrl-verde)]"
                    />
                    <Circle
                        v-else
                        class="size-4 text-[var(--mrl-gris-verdoso)]"
                    />
                    {{ p.etiqueta }}
                </li>
            </ul>
        </div>
    </section>
</template>
