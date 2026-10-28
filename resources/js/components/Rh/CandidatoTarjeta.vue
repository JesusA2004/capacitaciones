<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Brain,
    Briefcase,
    CircleDashed,
    Clock,
    Eye,
    FolderOpen,
    Home,
    Mail,
    MapPin,
    MessageSquareText,
    Phone,
    Radio,
    ShieldCheck,
    Sparkles,
    UserRound,
    Video,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    archivoCvCandidato,
    archivoEvidenciaCandidato,
    claveEvidencia,
} from '@/lib/archivosCandidato';
import type {
    ArchivoPrevisualizable,
    CandidatoItem,
    EvidenciaCandidato,
    OpcionEnum,
} from '@/types';

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

/**
 * Lo que la tarjeta puede mostrar sin salir del tablero (CV + accesos
 * rápidos), como una galería del visor único: se abre en lo que se tocó y
 * se navega al resto con las flechas.
 */
const archivos = computed<ArchivoPrevisualizable[]>(() => {
    const c = props.candidato;
    const lista: ArchivoPrevisualizable[] = [];

    if (c.tiene_cv) {
        lista.push(archivoCvCandidato(c.id, { nombre: c.cv_original_name }));
    }

    const vistos = new Set<number>();

    for (const ev of [
        c.accesos_rapidos?.psicometrico,
        c.accesos_rapidos?.socioeconomico,
        c.accesos_rapidos?.video,
    ]) {
        if (ev && !vistos.has(ev.id)) {
            vistos.add(ev.id);
            lista.push(archivoEvidenciaCandidato(c.id, ev));
        }
    }

    return lista;
});

const visorAbierto = ref(false);
const indiceVisor = ref(0);

function abrirEnVisor(evento: Event, clave: string) {
    detenerArrastre(evento);
    indiceVisor.value = Math.max(
        archivos.value.findIndex((a) => a.clave === clave),
        0,
    );
    visorAbierto.value = true;
}

function abrirEvidencia(evento: Event, evidencia: EvidenciaCandidato) {
    abrirEnVisor(evento, claveEvidencia(evidencia));
}

function abrirPreview(evento: Event) {
    abrirEnVisor(evento, 'cv');
}

const psicometrico = computed(
    () => props.candidato.accesos_rapidos?.psicometrico ?? null,
);
const socioeconomico = computed(
    () =>
        props.candidato.accesos_rapidos?.socioeconomico ??
        props.candidato.accesos_rapidos?.video ??
        null,
);
</script>

<template>
    <div class="flex flex-col gap-2.5">
        <div class="flex items-start gap-2.5">
            <!-- Foto oficial del expediente cuando ya hay colaborador
                 vinculado; si no hay o falla al cargar, iniciales. -->
            <Avatar class="size-11 shrink-0 shadow-sm ring-2 ring-background">
                <AvatarImage
                    v-if="candidato.foto_url"
                    :src="candidato.foto_url"
                    :alt="`Fotografía de ${nombreCompleto}`"
                    class="object-cover"
                    draggable="false"
                />
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
                    class="flex items-center gap-1 text-[10px] font-medium text-warning"
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
            v-if="candidato.gerente_involucrado"
            class="flex items-center gap-1.5 text-xs text-muted-foreground"
        >
            <UserRound class="size-3.5 shrink-0" />
            <span class="truncate"
                >Gerente: {{ candidato.gerente_involucrado.name }}
                {{ candidato.gerente_involucrado.apellidos }}</span
            >
        </div>

        <div
            v-if="etiquetaFuente"
            class="flex items-center gap-1.5 text-xs text-muted-foreground"
        >
            <Radio class="size-3.5 shrink-0" />
            <span class="truncate">{{ etiquetaFuente }}</span>
        </div>

        <!-- Tras la selección el candidato NO desaparece: estado real de su
             contratación con acceso directo al proceso y al expediente
             (CadenaContratacionService). -->
        <div
            v-if="candidato.contratacion"
            class="flex flex-col gap-1.5 rounded-xl border border-border/60 bg-crema/60 p-2"
            data-cadena-contratacion
        >
            <div class="flex flex-wrap items-center gap-1.5">
                <ChipEstado
                    :etiqueta="candidato.contratacion.etiqueta"
                    :tono="candidato.contratacion.tono"
                />
                <span
                    v-if="candidato.contratacion.detalle"
                    class="text-[11px] text-muted-foreground"
                    >{{ candidato.contratacion.detalle }}</span
                >
            </div>
            <div class="flex flex-wrap gap-x-3 gap-y-1 text-[11px] font-medium">
                <Link
                    v-if="candidato.contratacion.expediente_url"
                    :href="candidato.contratacion.expediente_url"
                    class="flex items-center gap-1 text-primary hover:underline"
                    draggable="false"
                    data-accion="ver-expediente"
                    @click.stop
                    @dragstart="detenerArrastre"
                >
                    <FolderOpen class="size-3.5" />
                    {{ candidato.contratacion.expediente_etiqueta }}
                </Link>
                <Link
                    v-if="candidato.contratacion.proceso_url"
                    :href="candidato.contratacion.proceso_url"
                    class="flex items-center gap-1 text-[var(--brand-primary)] hover:underline"
                    draggable="false"
                    @click.stop
                    @dragstart="detenerArrastre"
                >
                    {{ candidato.contratacion.proceso_etiqueta }}
                    <ArrowRight class="size-3" />
                </Link>
            </div>
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

        <!-- Estado del expediente: con archivo, el chip abre la vista previa. -->
        <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
            <button
                v-if="candidato.tiene_cv"
                type="button"
                class="flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 font-medium text-success transition-colors hover:bg-success/20"
                title="Ver CV"
                draggable="false"
                @click="abrirPreview"
                @dragstart="detenerArrastre"
            >
                <ShieldCheck class="size-3.5" /> CV
                <Eye class="size-3" />
            </button>
            <span
                v-else
                class="flex items-center gap-1 px-1 text-muted-foreground"
            >
                <CircleDashed class="size-3.5" /> CV
            </span>

            <button
                v-if="psicometrico"
                type="button"
                class="flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 font-medium text-success transition-colors hover:bg-success/20"
                title="Ver psicométrico"
                draggable="false"
                @click="abrirEvidencia($event, psicometrico)"
                @dragstart="detenerArrastre"
            >
                <ShieldCheck class="size-3.5" /> Psicométricos
                <Eye class="size-3" />
            </button>
            <span
                v-else
                class="flex items-center gap-1 px-1"
                :class="
                    candidato.psicometricas_count > 0
                        ? 'text-success'
                        : 'text-muted-foreground'
                "
            >
                <component
                    :is="
                        candidato.psicometricas_count > 0
                            ? ShieldCheck
                            : CircleDashed
                    "
                    class="size-3.5"
                />
                Psicométricos
            </span>

            <button
                v-if="socioeconomico"
                type="button"
                class="flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 font-medium text-success transition-colors hover:bg-success/20"
                title="Ver socioeconómico"
                draggable="false"
                @click="abrirEvidencia($event, socioeconomico)"
                @dragstart="detenerArrastre"
            >
                <ShieldCheck class="size-3.5" /> Socioeco
                <Eye class="size-3" />
            </button>
            <span
                v-else
                class="flex items-center gap-1 px-1"
                :class="
                    candidato.socioeconomicos_count > 0
                        ? 'text-success'
                        : 'text-muted-foreground'
                "
            >
                <component
                    :is="
                        candidato.socioeconomicos_count > 0
                            ? ShieldCheck
                            : CircleDashed
                    "
                    class="size-3.5"
                />
                Socioeco
            </span>
        </div>

        <div
            v-if="
                candidato.accesos_rapidos?.psicometrico ||
                candidato.accesos_rapidos?.socioeconomico ||
                candidato.accesos_rapidos?.video
            "
            class="flex flex-wrap gap-x-3 gap-y-1 text-[11px] font-medium text-[var(--brand-primary)]"
        >
            <button
                v-if="candidato.accesos_rapidos?.psicometrico"
                type="button"
                class="flex items-center gap-1 hover:underline"
                draggable="false"
                @click="
                    abrirEvidencia(
                        $event,
                        candidato.accesos_rapidos.psicometrico,
                    )
                "
                @dragstart="detenerArrastre"
            >
                <Brain class="size-3.5" /> Ver psicométrico
            </button>
            <button
                v-if="candidato.accesos_rapidos?.socioeconomico"
                type="button"
                class="flex items-center gap-1 hover:underline"
                draggable="false"
                @click="
                    abrirEvidencia(
                        $event,
                        candidato.accesos_rapidos.socioeconomico,
                    )
                "
                @dragstart="detenerArrastre"
            >
                <Home class="size-3.5" /> Ver socioeconómico
            </button>
            <button
                v-if="candidato.accesos_rapidos?.video"
                type="button"
                class="flex items-center gap-1 hover:underline"
                draggable="false"
                @click="abrirEvidencia($event, candidato.accesos_rapidos.video)"
                @dragstart="detenerArrastre"
            >
                <Video class="size-3.5" /> Ver video
            </button>
        </div>

        <div
            class="flex items-center justify-between gap-2 border-t border-border/60 pt-2 text-[11px] text-muted-foreground"
        >
            <span
                class="flex items-center gap-1"
                :title="etiquetaUltimoMovimiento"
            >
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
        v-if="archivos.length"
        v-model:open="visorAbierto"
        :archivos="archivos"
        :indice="indiceVisor"
    />
</template>
