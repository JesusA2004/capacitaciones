<script setup lang="ts">
import {
    Briefcase,
    Clock,
    Eye,
    Mail,
    MapPin,
    MessageSquareText,
    Phone,
    Radio,
    Sparkles,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import cv from '@/routes/rh/candidatos/cv';
import type { CandidatoItem, OpcionEnum } from '@/types';

const props = defineProps<{
    candidato: CandidatoItem;
    fuentes?: OpcionEnum[];
}>();

const nombreCompleto = computed(() =>
    `${props.candidato.nombre} ${props.candidato.apellidos ?? ''}`.trim(),
);

const iniciales = computed(() => {
    const partes = nombreCompleto.value.split(/\s+/).filter(Boolean);

    return (
        (partes[0]?.[0] ?? '') + (partes[1]?.[0] ?? partes[0]?.[1] ?? '')
    ).toUpperCase();
});

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
    const desde =
        props.candidato.ultimo_cambio_estado?.fecha ??
        props.candidato.created_at;
    const dias = Math.floor(
        (Date.now() - new Date(desde).getTime()) / (1000 * 60 * 60 * 24),
    );

    return Math.max(dias, 0);
});

const etiquetaUltimoMovimiento = computed(() => {
    const dias = diasEnFase.value;

    if (dias === 0) {
        return 'Hoy';
    }

    return dias === 1 ? 'Hace 1 día' : `Hace ${dias} días`;
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

/**
 * Marca explícita (CLAUDE.md §3): un candidato espontáneo no tiene vacante
 * todavía y no puede avanzar a contratación hasta vincularse a una real.
 */
const esEspontaneo = computed(() => props.candidato.espontaneo);

function detenerArrastre(evento: Event) {
    // El botón de CV vive dentro de una tarjeta arrastrable (drag and drop
    // del tablero): sin esto, el navegador intenta arrastrar el elemento en
    // vez de disparar la acción.
    evento.stopPropagation();
}

const previewAbierto = ref(false);

function abrirPreview(evento: Event) {
    detenerArrastre(evento);
    previewAbierto.value = true;
}
</script>

<template>
    <div class="flex flex-col gap-2.5">
        <div class="flex items-start gap-2.5">
            <Avatar class="size-9 shrink-0">
                <AvatarFallback
                    class="bg-[var(--brand-primary)]/10 text-xs font-semibold text-[var(--brand-primary)]"
                    >{{ iniciales }}</AvatarFallback
                >
            </Avatar>

            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="truncate text-sm leading-tight font-semibold">{{
                    nombreCompleto
                }}</span>
                <span
                    v-if="esEspontaneo"
                    class="flex items-center gap-1 text-[10px] font-medium text-amber-600 dark:text-amber-400"
                >
                    <Sparkles class="size-3 shrink-0" />
                    Candidato espontáneo · sin vacante
                </span>
            </div>

            <Badge
                v-if="urgente"
                variant="warning"
                class="shrink-0 text-[10px]"
                >{{ etiquetaUltimoMovimiento }}</Badge
            >
        </div>

        <div
            class="flex items-center gap-1.5 text-xs font-medium text-foreground"
        >
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
            v-if="candidato.telefono"
            class="flex items-center gap-1.5 text-xs text-muted-foreground"
        >
            <Phone class="size-3.5 shrink-0" />
            <span class="truncate">{{ candidato.telefono }}</span>
        </div>

        <div
            v-if="candidato.correo"
            class="flex items-center gap-1.5 text-xs text-muted-foreground"
        >
            <Mail class="size-3.5 shrink-0" />
            <span class="truncate">{{ candidato.correo }}</span>
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
            <span class="flex items-center gap-1" :title="etiquetaUltimoMovimiento">
                <Clock class="size-3.5" />
                {{ fechaIngreso }}
            </span>

            <button
                v-if="candidato.tiene_cv"
                type="button"
                class="flex items-center gap-1 font-medium text-[var(--brand-primary)] hover:underline"
                :title="candidato.cv_original_name ?? 'Ver CV'"
                draggable="false"
                @click="abrirPreview"
                @dragstart="detenerArrastre"
            >
                <Eye class="size-3.5" />
                Ver CV
            </button>
            <span v-else class="italic">Sin CV</span>
        </div>
    </div>

    <DocumentPreviewDialog
        v-if="candidato.tiene_cv"
        v-model:open="previewAbierto"
        :preview-url="cv.previsualizar.url(candidato.id)"
        :download-url="cv.descargar.url(candidato.id)"
        :nombre="candidato.cv_original_name ?? 'CV del candidato'"
    />
</template>
