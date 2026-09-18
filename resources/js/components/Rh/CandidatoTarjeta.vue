<script setup lang="ts">
import { Briefcase, Clock, Download, MapPin, MessageSquareText, Radio, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { descargar as descargarCv } from '@/routes/rh/candidatos/cv';
import type { CandidatoItem, OpcionEnum } from '@/types';

const props = defineProps<{
    candidato: CandidatoItem;
    fuentes?: OpcionEnum[];
}>();

const nombreCompleto = computed(() =>
    `${props.candidato.nombre} ${props.candidato.apellidos ?? ''}`.trim(),
);

const fechaIngreso = computed(() =>
    new Date(props.candidato.created_at).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }),
);

/**
 * "Tiempo en la fase actual": desde el último cambio de estado registrado,
 * o desde que se registró el candidato si todavía no tiene ninguno (ver
 * App\Http\Controllers\Rh\CandidatoController::index() / Candidato::ultimoCambioEstado()).
 */
const diasEnFase = computed(() => {
    const desde = props.candidato.ultimo_cambio_estado?.fecha ?? props.candidato.created_at;
    const dias = Math.floor(
        (Date.now() - new Date(desde).getTime()) / (1000 * 60 * 60 * 24),
    );

    return Math.max(dias, 0);
});

const etiquetaDiasEnFase = computed(() => {
    const dias = diasEnFase.value;

    if (dias === 0) {
        return 'Hoy en esta fase';
    }

    return dias === 1 ? '1 día en esta fase' : `${dias} días en esta fase`;
});

const urgente = computed(() => diasEnFase.value >= 7);

const etiquetaFuente = computed(() => {
    if (!props.candidato.fuente) {
        return null;
    }

    return (
        props.fuentes?.find((f) => f.value === props.candidato.fuente)
            ?.etiqueta ?? props.candidato.fuente
    );
});

function detenerArrastre(evento: Event) {
    // El botón de descarga vive dentro de una tarjeta arrastrable (drag and
    // drop del tablero): sin esto, el navegador intenta arrastrar el <a>
    // en vez de disparar la descarga.
    evento.stopPropagation();
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex items-start justify-between gap-2">
            <span class="text-sm font-semibold leading-tight">{{
                nombreCompleto
            }}</span>
            <Badge
                v-if="urgente"
                variant="warning"
                class="shrink-0 text-[10px]"
                >{{ etiquetaDiasEnFase }}</Badge
            >
        </div>

        <div class="flex items-center gap-1.5 text-xs font-medium text-foreground">
            <Briefcase class="size-3.5 shrink-0 text-[var(--brand-primary)]" />
            <span class="truncate">{{
                candidato.puesto_objetivo?.nombre ?? 'Sin puesto objetivo'
            }}</span>
        </div>

        <div
            v-if="candidato.sucursal"
            class="flex items-center gap-1.5 text-xs text-muted-foreground"
        >
            <MapPin class="size-3.5 shrink-0" />
            <span class="truncate">{{ candidato.sucursal.nombre }}</span>
        </div>

        <div
            v-if="candidato.responsable_rh"
            class="flex items-center gap-1.5 text-xs text-muted-foreground"
        >
            <UserRound class="size-3.5 shrink-0" />
            <span class="truncate"
                >{{ candidato.responsable_rh.name }}
                {{ candidato.responsable_rh.apellidos }}</span
            >
        </div>

        <div
            v-if="etiquetaFuente"
            class="flex items-center gap-1.5 text-xs text-muted-foreground"
        >
            <Radio class="size-3.5 shrink-0" />
            <span class="truncate">{{ etiquetaFuente }}</span>
        </div>

        <p
            v-if="candidato.ultimo_seguimiento?.nota"
            class="flex items-start gap-1.5 rounded-md bg-muted/50 px-2 py-1 text-xs text-muted-foreground"
        >
            <MessageSquareText class="mt-0.5 size-3.5 shrink-0" />
            <span class="line-clamp-2">{{
                candidato.ultimo_seguimiento.nota
            }}</span>
        </p>

        <div
            class="flex items-center justify-between gap-2 border-t border-border/60 pt-2 text-[11px] text-muted-foreground"
        >
            <span class="flex items-center gap-1" :title="etiquetaDiasEnFase">
                <Clock class="size-3.5" />
                {{ fechaIngreso }}
            </span>

            <a
                v-if="candidato.tiene_cv"
                :href="descargarCv.url(candidato.id)"
                class="flex items-center gap-1 font-medium text-[var(--brand-primary)] hover:underline"
                :title="candidato.cv_original_name ?? 'Descargar CV'"
                draggable="false"
                @click="detenerArrastre"
                @dragstart="detenerArrastre"
                >
                <Download class="size-3.5" />
                CV
            </a>
            <span v-else class="italic">Sin CV</span>
        </div>
    </div>
</template>
