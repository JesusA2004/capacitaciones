<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    Circle,
    Download,
    FileText,
    History,
    Printer,
} from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatearFecha } from '@/lib/fechas';
import type {
    AccionDocumento,
    ItemDocumentoProceso,
    SeccionDocumentosProceso,
} from '@/types';

/**
 * Tarjeta "Documentos de …" de UN proceso (contratación, baja, permiso,
 * préstamo…). Solo pinta lo que manda el backend: qué documentos tocan, su
 * estado, versión del formato oficial y las acciones permitidas.
 */
defineProps<{
    seccion: SeccionDocumentosProceso;
    ocupado: boolean;
}>();

const emit = defineEmits<{
    accion: [item: ItemDocumentoProceso, accion: AccionDocumento];
    accionSeccion: [accion: AccionDocumento];
}>();

const historialAbierto = ref<string | null>(null);

type Variante = NonNullable<BadgeVariants['variant']>;

function variante(estado: string): Variante {
    if (['archivado', 'escaneado', 'firmado_fisicamente', 'recibido_corporativo', 'pagado', 'firmado'].includes(estado)) {
        return 'success';
    }

    if (estado === 'sin_formato') {
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

function varianteBoton(tipo: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    return tipo === 'primaria' ? 'default' : tipo === 'peligro' ? 'destructive' : 'outline';
}

const pasos: { clave: 'impreso' | 'firmado' | 'escaneado' | 'archivado'; etiqueta: string }[] = [
    { clave: 'impreso', etiqueta: 'Impreso' },
    { clave: 'firmado', etiqueta: 'Firmado' },
    { clave: 'escaneado', etiqueta: 'Escaneado' },
    { clave: 'archivado', etiqueta: 'Archivado' },
];
</script>

<template>
    <section
        class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        :aria-label="seccion.titulo"
    >
        <header class="mb-3 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold">{{ seccion.titulo }}</h2>
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    {{ seccion.descripcion }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="a in seccion.acciones"
                    :key="a.clave"
                    size="sm"
                    :variant="varianteBoton(a.tipo)"
                    :disabled="ocupado"
                    @click="emit('accionSeccion', a)"
                    >{{ a.etiqueta }}</Button
                >
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
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-600" />
            {{ seccion.bloqueo }}
        </p>

        <ul class="flex flex-col gap-3">
            <li
                v-for="item in seccion.documentos"
                :key="item.clave"
                class="rounded-xl bg-[var(--mrl-fondo)] p-3 text-sm"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="flex min-w-0 items-start gap-2">
                        <FileText
                            class="mt-0.5 size-4 shrink-0 text-[var(--mrl-petroleo)]"
                        />
                        <div class="min-w-0">
                            <p class="font-medium">{{ item.nombre }}</p>
                            <p class="text-xs text-[var(--mrl-texto-suave)]">
                                {{ item.motivo }}
                            </p>
                            <p
                                v-if="item.master"
                                class="text-xs text-[var(--mrl-texto-suave)]"
                            >
                                Formato oficial v{{ item.master.version
                                }}{{ item.master.heredada ? ' (plantilla anterior)' : '' }}
                                <template v-if="item.documento?.generado_en">
                                    · generado
                                    {{ formatearFecha(item.documento.generado_en) }}
                                    <template v-if="item.documento.generado_por"
                                        >por {{ item.documento.generado_por }}</template
                                    >
                                </template>
                                <template v-if="item.documento?.fidelidad === 'aproximada'">
                                    · conversión aproximada (revisa antes de imprimir)
                                </template>
                            </p>
                        </div>
                    </div>
                    <Badge :variant="variante(item.estado)">{{
                        item.estado_etiqueta
                    }}</Badge>
                </div>

                <p
                    v-if="item.formato_faltante"
                    class="mt-2 rounded-lg border border-red-200 bg-red-50 p-2 text-xs text-red-800"
                >
                    {{ item.formato_faltante.mensaje }} RH/Jurídico debe
                    cargarlo en Administración → Documentos maestros.
                </p>
                <p
                    v-else-if="item.bloqueo"
                    class="mt-2 text-xs text-[var(--mrl-texto-suave)]"
                >
                    {{ item.bloqueo }}
                </p>

                <ul
                    v-if="item.documento && (item.requiere.impresion || item.requiere.firma_fisica)"
                    class="mt-2 flex flex-wrap gap-3 text-xs"
                >
                    <li
                        v-for="p in pasos"
                        :key="p.clave"
                        class="flex items-center gap-1"
                    >
                        <CheckCircle2
                            v-if="item.documento[p.clave]"
                            class="size-3.5 text-[var(--mrl-verde)]"
                        />
                        <Circle
                            v-else
                            class="size-3.5 text-[var(--mrl-gris-verdoso)]"
                        />
                        {{ p.etiqueta }}
                    </li>
                    <li v-if="item.requiere.huella" class="text-[var(--mrl-texto-suave)]">
                        · requiere huella
                    </li>
                    <li v-if="item.requiere.testigos" class="text-[var(--mrl-texto-suave)]">
                        · {{ item.requiere.cantidad_testigos || '' }} testigos
                    </li>
                </ul>

                <div
                    v-if="item.acciones.length || item.historial.length"
                    class="mt-3 flex flex-wrap gap-2"
                >
                    <Button
                        v-for="a in item.acciones"
                        :key="a.clave"
                        size="sm"
                        :variant="varianteBoton(a.tipo)"
                        :disabled="ocupado"
                        @click="emit('accion', item, a)"
                    >
                        <Download v-if="a.clave === 'descargar'" class="size-3.5" />
                        <Printer v-else-if="a.clave === 'marcar_impreso'" class="size-3.5" />
                        {{ a.etiqueta }}
                    </Button>
                    <Button
                        v-if="item.historial.length"
                        size="sm"
                        variant="ghost"
                        @click="historialAbierto = historialAbierto === item.clave ? null : item.clave"
                    >
                        <History class="size-3.5" /> Historial ({{ item.historial.length }})
                    </Button>
                </div>

                <ul
                    v-if="historialAbierto === item.clave"
                    class="mt-2 flex flex-col gap-1 border-t border-[var(--mrl-borde)] pt-2 text-xs text-[var(--mrl-texto-suave)]"
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
            class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"
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
                    <Circle v-else class="size-4 text-[var(--mrl-gris-verdoso)]" />
                    {{ p.etiqueta }}
                </li>
            </ul>
        </div>
    </section>
</template>
