<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    CheckCircle2,
    Circle,
    CircleDot,
    Clock,
    Download,
    Eye,
    FileDown,
    FilePlus2,
    FileText,
    FileWarning,
    Fingerprint,
    History,
    Lock,
    MoreHorizontal,
    PenLine,
    Printer,
    RefreshCcw,
    Send,
    ShieldAlert,
    Truck,
    Users,
} from '@lucide/vue';
import { ref } from 'vue';
import type { Component } from 'vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
import BotonVistaPrevia from '@/components/people/BotonVistaPrevia.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { formatearFecha } from '@/lib/fechas';
import { TONO_SUAVE } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';
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
 *
 * `previaCargando` dice qué vista previa se está generando en ESTA sección
 * ('paquete' o la clave del documento) para que solo ese botón muestre el
 * spinner; `previaOcupada` deshabilita las demás mientras tanto.
 */
withDefaults(
    defineProps<{
        seccion: SeccionDocumentosProceso;
        ocupado: string | null;
        previaCargando?: string | null;
        previaOcupada?: boolean;
    }>(),
    { previaCargando: null, previaOcupada: false },
);

const emit = defineEmits<{
    accion: [item: ItemDocumentoProceso, accion: AccionDocumento];
    accionSeccion: [accion: AccionDocumento];
}>();

const historialAbierto = ref<string | null>(null);

type InfoEstado = { tono: Tono; icono: Component };

function estado(valor: string): InfoEstado {
    if (
        [
            'archivado',
            'escaneado',
            'firmado_fisicamente',
            'recibido_corporativo',
            'pagado',
            'firmado',
        ].includes(valor)
    ) {
        return { tono: 'exito', icono: CheckCircle2 };
    }

    if (valor === 'sin_formato' || valor === 'formato_sin_validar') {
        return { tono: 'peligro', icono: FileWarning };
    }

    if (['bloqueado', 'pendiente_rh'].includes(valor)) {
        return { tono: 'neutro', icono: Lock };
    }

    if (valor === 'por_generar') {
        return { tono: 'info', icono: FilePlus2 };
    }

    return { tono: 'alerta', icono: Clock };
}

const iconoAccion: Record<string, Component> = {
    vista_previa: Eye,
    vista_previa_paquete: Eye,
    generar: FilePlus2,
    generar_paquete: FilePlus2,
    regenerar: RefreshCcw,
    nueva_revision: ShieldAlert,
    descargar: Download,
    descargar_word: FileDown,
    marcar_impreso: Printer,
    registrar_firma: PenLine,
    registrar_envio: Truck,
    registrar_recepcion: Send,
    archivar: Archive,
};

function principal(item: ItemDocumentoProceso): AccionDocumento | null {
    return item.acciones.find((a) => a.tipo === 'primaria') ?? null;
}

/**
 * «Vista previa» junto a «Generar» va como botón visible ANTES de generar
 * (no en el menú); ya generado (antes de regenerar) sí va en el menú.
 */
function vistaPrevia(item: ItemDocumentoProceso): AccionDocumento | null {
    return item.documento
        ? null
        : (item.acciones.find((a) => a.clave === 'vista_previa') ?? null);
}

function secundarias(item: ItemDocumentoProceso): AccionDocumento[] {
    const p = principal(item);
    const previa = vistaPrevia(item);

    return item.acciones.filter((a) => a !== p && a !== previa);
}

function clave(item: ItemDocumentoProceso, accion: AccionDocumento): string {
    return `${item.clave}:${accion.clave}`;
}

function requisitos(item: ItemDocumentoProceso): string[] {
    return [
        item.requiere.huella ? 'Huella' : null,
        item.requiere.testigos
            ? `${item.requiere.cantidad_testigos || ''} testigos`.trim()
            : null,
    ].filter((v): v is string => v !== null);
}

function varianteSeccion(a: AccionDocumento) {
    return a.tipo === 'primaria'
        ? 'default'
        : a.tipo === 'peligro'
          ? 'destructive'
          : 'outline';
}
</script>

<template>
    <section
        class="@container flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 shadow-sm sm:p-5"
        :aria-label="seccion.titulo"
    >
        <header class="flex flex-col gap-3 @3xl:flex-row @3xl:items-start">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-salvia text-primary"
                    aria-hidden="true"
                >
                    <FileText class="size-5" />
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <h2 class="text-lg leading-tight font-semibold">
                            {{ seccion.titulo }}
                        </h2>
                        <ChipEstado
                            v-if="seccion.expediente_completo"
                            etiqueta="Expediente completo"
                            tono="exito"
                            :icono="CheckCircle2"
                        />
                    </div>
                    <p class="mt-0.5 text-sm text-muted-foreground">
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
            </div>
            <div
                v-if="seccion.acciones.length"
                class="grid grid-cols-1 gap-2 @md:grid-cols-2 @3xl:flex @3xl:max-w-[50%] @3xl:shrink-0 @3xl:flex-wrap @3xl:justify-end"
            >
                <template v-for="a in seccion.acciones" :key="a.clave">
                    <BotonVistaPrevia
                        v-if="a.clave === 'vista_previa_paquete'"
                        class="h-10"
                        :texto="a.etiqueta"
                        :cargando="previaCargando === 'paquete'"
                        :deshabilitado="ocupado !== null || previaOcupada"
                        @click="emit('accionSeccion', a)"
                    />
                    <Button
                        v-else
                        class="h-10"
                        :variant="varianteSeccion(a)"
                        :disabled="ocupado !== null"
                        @click="emit('accionSeccion', a)"
                    >
                        <Spinner v-if="ocupado === `seccion:${a.clave}`" />
                        <component
                            :is="iconoAccion[a.clave] ?? FileText"
                            v-else
                            class="size-4"
                        />
                        {{ a.etiqueta }}
                    </Button>
                </template>
            </div>
        </header>

        <p
            v-if="seccion.bloqueo"
            class="flex items-start gap-2 rounded-xl bg-warning-soft/70 p-3 text-sm"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />
            {{ seccion.bloqueo }}
        </p>
        <p
            v-if="seccion.documentos.length === 0"
            class="rounded-xl border border-dashed border-[var(--mrl-borde)] p-4 text-sm text-muted-foreground"
        >
            Este proceso no requiere documentos oficiales.
        </p>

        <ul class="grid grid-cols-1 gap-3 @5xl:grid-cols-2">
            <li
                v-for="item in seccion.documentos"
                :key="item.clave"
                class="flex min-w-0 flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)]/60 p-3 sm:p-4"
            >
                <!-- Qué es y cómo va -->
                <div class="flex items-start gap-3">
                    <span
                        :class="[
                            'flex size-10 shrink-0 items-center justify-center rounded-xl',
                            TONO_SUAVE[estado(item.estado).tono],
                        ]"
                        aria-hidden="true"
                    >
                        <FileText class="size-5" />
                    </span>
                    <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                        <p class="flex items-start gap-1 leading-snug">
                            <span class="font-semibold">{{ item.nombre }}</span>
                            <AyudaBoton
                                v-if="item.motivo"
                                :titulo="item.nombre"
                                :texto="item.motivo"
                            />
                        </p>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <ChipEstado
                                :etiqueta="item.estado_etiqueta"
                                :tono="estado(item.estado).tono"
                                :icono="estado(item.estado).icono"
                            />
                            <span
                                v-if="item.master"
                                class="inline-flex items-center gap-1 rounded-full bg-card px-2 py-0.5 text-xs text-muted-foreground"
                                :title="
                                    item.master.heredada
                                        ? 'Plantilla anterior'
                                        : undefined
                                "
                            >
                                Formato
                                <strong class="font-medium text-foreground">{{
                                    item.master.etiqueta ??
                                    `v${item.master.version}`
                                }}</strong>
                                <template v-if="item.master.heredada"
                                    >· anterior</template
                                >
                            </span>
                            <span
                                v-for="r in requisitos(item)"
                                :key="r"
                                class="inline-flex items-center gap-1 rounded-full bg-card px-2 py-0.5 text-xs text-muted-foreground"
                            >
                                <Fingerprint
                                    v-if="r === 'Huella'"
                                    class="size-3"
                                />
                                <Users v-else class="size-3" />
                                {{ r }}
                            </span>
                        </div>
                        <p
                            v-if="item.documento?.generado_en"
                            class="text-xs text-muted-foreground"
                        >
                            Generado
                            {{ formatearFecha(item.documento.generado_en) }}
                            <template v-if="item.documento.generado_por"
                                >· {{ item.documento.generado_por }}</template
                            >
                        </p>
                        <p
                            v-if="item.documento?.revision_de_id"
                            class="text-xs text-warning"
                        >
                            Revisión del firmado #{{
                                item.documento.revision_de_id
                            }}: {{ item.documento.motivo_revision }}
                        </p>
                    </div>
                </div>

                <!-- Línea de tiempo -->
                <ol
                    v-if="item.documento && item.linea_tiempo?.length"
                    class="flex flex-wrap items-center gap-x-1 gap-y-2 rounded-xl bg-card px-3 py-2 text-xs"
                    aria-label="Avance del documento"
                >
                    <li
                        v-for="(paso, i) in item.linea_tiempo"
                        :key="paso.clave"
                        class="flex items-center gap-1"
                    >
                        <CheckCircle2
                            v-if="paso.estado === 'hecho'"
                            class="size-4 text-success"
                        />
                        <CircleDot
                            v-else-if="paso.estado === 'actual'"
                            class="size-4 text-primary"
                        />
                        <Circle
                            v-else
                            class="size-4 text-muted-foreground/50"
                        />
                        <span
                            :class="
                                paso.estado === 'actual'
                                    ? 'font-semibold text-primary'
                                    : paso.estado === 'hecho'
                                      ? ''
                                      : 'text-muted-foreground'
                            "
                        >
                            {{ paso.etiqueta }}
                        </span>
                        <span
                            v-if="i < item.linea_tiempo.length - 1"
                            class="mx-1 h-px w-3 bg-[var(--mrl-borde)] sm:w-5"
                            aria-hidden="true"
                        />
                    </li>
                </ol>

                <!-- Avisos -->
                <p
                    v-if="
                        item.documento &&
                        item.documento.archivo_disponible === false
                    "
                    class="flex items-start gap-2 rounded-xl bg-warning-soft/70 p-3 text-sm"
                >
                    <FileWarning class="mt-0.5 size-4 shrink-0 text-warning" />
                    <span>
                        El archivo ya no está en el almacenamiento.
                        {{
                            item.acciones.some((a) => a.clave === 'regenerar')
                                ? 'Usa «Regenerar» en el menú.'
                                : 'Avisa a Sistemas.'
                        }}
                    </span>
                </p>
                <div
                    v-if="item.formato_faltante"
                    class="flex flex-col gap-2 rounded-xl bg-danger-soft/70 p-3 text-sm sm:flex-row sm:items-center"
                >
                    <p class="flex flex-1 items-start gap-2 text-destructive">
                        <FileWarning class="mt-0.5 size-4 shrink-0" />
                        <span class="font-medium">{{
                            item.formato_faltante.mensaje
                        }}</span>
                        <AyudaBoton
                            texto="RH/Jurídico debe cargar el formato oficial antes de generarlo."
                        />
                    </p>
                    <Button
                        v-if="item.formato_faltante.cobertura_url"
                        size="sm"
                        variant="outline"
                        as-child
                    >
                        <Link :href="item.formato_faltante.cobertura_url"
                            >Ver cobertura</Link
                        >
                    </Button>
                </div>
                <p
                    v-else-if="item.bloqueo"
                    class="flex items-start gap-2 text-xs text-muted-foreground"
                >
                    <AlertTriangle
                        class="mt-0.5 size-3.5 shrink-0 text-warning"
                    />
                    {{ item.bloqueo }}
                </p>

                <!-- Acciones -->
                <div
                    v-if="item.acciones.length || item.historial.length"
                    class="mt-auto flex flex-wrap items-center gap-2"
                >
                    <BotonVistaPrevia
                        v-if="vistaPrevia(item)"
                        class="h-10 flex-1 sm:flex-none"
                        :texto="vistaPrevia(item)!.etiqueta"
                        :cargando="previaCargando === item.clave"
                        :deshabilitado="ocupado !== null || previaOcupada"
                        @click="emit('accion', item, vistaPrevia(item)!)"
                    />
                    <Button
                        v-if="principal(item)"
                        class="h-10 flex-1 sm:flex-none"
                        :disabled="ocupado !== null"
                        @click="emit('accion', item, principal(item)!)"
                    >
                        <Spinner
                            v-if="ocupado === clave(item, principal(item)!)"
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
                                size="icon-lg"
                                variant="outline"
                                :disabled="ocupado !== null"
                                aria-label="Más acciones"
                            >
                                <MoreHorizontal class="size-5" />
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
                </div>

                <ul
                    v-if="historialAbierto === item.clave"
                    class="flex flex-col gap-1 border-t border-[var(--mrl-borde)] pt-2 text-xs text-muted-foreground"
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
            class="flex flex-col gap-1 rounded-xl bg-warning-soft/70 p-3 text-sm"
        >
            <p class="flex items-center gap-2 font-semibold text-warning">
                <AlertTriangle class="size-4" />
                Negativa de firma ·
                {{ formatearFecha(seccion.negativa.registrada_en) }}
            </p>
            <p class="text-xs">
                Testigos:
                {{
                    seccion.negativa.testigos.length
                        ? seccion.negativa.testigos
                              .map((t) => `${t.nombre} (${t.cargo})`)
                              .join(', ')
                        : 'pendientes de capturar'
                }}
            </p>
            <p v-if="seccion.negativa.finiquito_a_disposicion" class="text-xs">
                El finiquito quedó a disposición del colaborador.
            </p>
        </div>

        <div v-if="seccion.checklist">
            <h3
                class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                Checklist final de la baja
            </h3>
            <ul class="grid gap-1.5 text-sm sm:grid-cols-2">
                <li
                    v-for="p in seccion.checklist.filter((c) => c.aplica)"
                    :key="p.clave"
                    class="flex items-center gap-2 rounded-lg px-2 py-1"
                    :class="p.cumplido ? 'bg-success-soft/60' : 'bg-card'"
                >
                    <CheckCircle2
                        v-if="p.cumplido"
                        class="size-4 shrink-0 text-success"
                    />
                    <Circle
                        v-else
                        class="size-4 shrink-0 text-muted-foreground/50"
                    />
                    {{ p.etiqueta }}
                </li>
            </ul>
        </div>
    </section>
</template>
