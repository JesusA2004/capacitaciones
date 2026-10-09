<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BellRing,
    CheckCircle2,
    CircleAlert,
    PencilLine,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { avisarDatosFaltantes } from '@/routes/rh/expedientes';
import { index as indexSolicitudes } from '@/routes/solicitudes';

/**
 * Completitud del expediente POR SECCIÓN (Identidad ✓ · Fiscal ⚠ ·
 * Domicilio ✗ · Documentos ✓), no solo un porcentaje, y el aviso de
 * datos contractuales faltantes. RH: «Avisar al colaborador»
 * (notificación + push, máximo uno cada 24 h). El propio colaborador:
 * «Completar mis datos» abre la solicitud de actualización (nunca edita
 * directo).
 */
export type SeccionCompletitud = {
    clave: string;
    etiqueta: string;
    estado: 'completo' | 'incompleto' | 'faltante';
    faltantes: string[];
};

export type DatosFaltantesResumen = {
    completo: boolean;
    personales: { campo: string; etiqueta: string }[];
    laborales: { campo: string; etiqueta: string }[];
    secciones?: SeccionCompletitud[];
    solicitud_en_revision: boolean;
    ultimo_aviso: { fecha: string | null; enviado_por: string | null } | null;
    puede_avisar_de_nuevo: boolean;
};

const props = defineProps<{
    datos: DatosFaltantesResumen;
    colaboradorId: number;
    esPropio: boolean;
    puedeAvisar: boolean;
    /** Documentos obligatorios aprobados / total (resumen del expediente). */
    documentos?: { aprobados: number; total: number } | null;
}>();

const enviando = ref(false);
const personales = computed(() => props.datos.personales.map((d) => d.etiqueta));
const laborales = computed(() => props.datos.laborales.map((d) => d.etiqueta));
const totalFaltantes = computed(
    () => personales.value.length + (props.esPropio ? 0 : laborales.value.length),
);

const secciones = computed<SeccionCompletitud[]>(() => {
    const lista = [...(props.datos.secciones ?? [])];

    if (props.documentos && props.documentos.total > 0) {
        const { aprobados, total } = props.documentos;
        lista.push({
            clave: 'documentos',
            etiqueta: 'Documentos',
            estado: aprobados >= total ? 'completo' : aprobados === 0 ? 'faltante' : 'incompleto',
            faltantes: aprobados >= total ? [] : [`${total - aprobados} de ${total} por aprobar`],
        });
    }

    return lista;
});

const ESTILO = {
    completo: { icono: CheckCircle2, clase: 'border-success/25 bg-success-soft/60 text-success' },
    incompleto: { icono: CircleAlert, clase: 'border-oro/40 bg-warning-soft/70 text-warning' },
    faltante: { icono: XCircle, clase: 'border-destructive/25 bg-danger-soft/60 text-destructive' },
} as const;

function avisar() {
    enviando.value = true;
    router.post(avisarDatosFaltantes.url(props.colaboradorId), {}, {
        preserveScroll: true,
        onFinish: () => (enviando.value = false),
    });
}
</script>

<template>
    <section class="flex flex-col gap-3">
        <ul
            v-if="secciones.length"
            class="flex flex-wrap gap-2"
            aria-label="Completitud del expediente por sección"
        >
            <li
                v-for="s in secciones"
                :key="s.clave"
                class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold"
                :class="ESTILO[s.estado].clase"
                :title="s.faltantes.length ? `Falta: ${s.faltantes.join(', ')}` : 'Completo'"
            >
                <component :is="ESTILO[s.estado].icono" class="size-3.5" />
                {{ s.etiqueta }}
            </li>
        </ul>

        <div
            v-if="!datos.completo && (esPropio ? personales.length > 0 : true)"
            class="flex flex-col gap-3 rounded-2xl border border-oro/40 bg-warning-soft/40 p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-start gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-oro/20 text-warning">
                    <AlertTriangle class="size-4" />
                </span>
                <div class="grid gap-1 text-sm">
                    <p class="font-semibold">
                        {{ esPropio ? 'Completa tu información' : `Faltan ${totalFaltantes} dato${totalFaltantes === 1 ? '' : 's'} que impiden generar contrato.` }}
                    </p>
                    <p v-if="personales.length" class="text-muted-foreground">
                        <span class="font-medium text-foreground">{{ esPropio ? 'Te falta' : 'Del colaborador' }}:</span>
                        {{ personales.join(', ') }}
                    </p>
                    <p v-if="!esPropio && laborales.length" class="text-muted-foreground">
                        <span class="font-medium text-foreground">Los captura RH:</span> {{ laborales.join(', ') }}
                    </p>
                    <p v-if="datos.solicitud_en_revision" class="text-xs text-warning">
                        Hay una solicitud de actualización de datos en revisión.
                    </p>
                    <p v-if="!esPropio && datos.ultimo_aviso" class="text-xs text-muted-foreground">
                        Último aviso: {{ datos.ultimo_aviso.fecha ? new Date(datos.ultimo_aviso.fecha).toLocaleString('es-MX') : '' }}
                        <template v-if="datos.ultimo_aviso.enviado_por"> por {{ datos.ultimo_aviso.enviado_por }}</template>
                    </p>
                </div>
            </div>

            <Button
                v-if="!esPropio && puedeAvisar && personales.length"
                variant="outline"
                class="min-h-11 shrink-0 sm:min-h-9"
                :disabled="enviando || !datos.puede_avisar_de_nuevo"
                :title="datos.puede_avisar_de_nuevo ? '' : 'Ya se avisó en las últimas 24 horas'"
                @click="avisar"
            >
                <BellRing class="size-4" />
                Avisar al colaborador
            </Button>
            <Button v-else-if="esPropio && !datos.solicitud_en_revision" as-child class="min-h-11 shrink-0 sm:min-h-9">
                <Link :href="indexSolicitudes({ query: { nueva: 'actualizacion_datos' } })">
                    <PencilLine class="size-4" />
                    Completar mis datos
                </Link>
            </Button>
        </div>
    </section>
</template>
