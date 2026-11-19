<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    ClipboardCheck,
    Download,
    ExternalLink,
    FileText,
    Presentation,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { formatearFechaHora } from '@/lib/fechas';
import { dashboard } from '@/routes';
import {
    calificar,
    descargar,
    index,
} from '@/routes/calificaciones/actividades';
import { index as indexCapacitacion } from '@/routes/capacitacion';
import { aula } from '@/routes/cursos';
import type { EntregaDetalleCalificacion } from '@/types';

const props = defineProps<{
    entrega: EntregaDetalleCalificacion;
    curso: { id: number; titulo: string } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Calificar actividades', href: index.url() },
            { title: 'Entrega', href: '#' },
        ],
    },
});

const { mostrarExito, mostrarError } = useAlertas();

const calificacion = ref<number | undefined>(
    props.entrega.calificacion ?? undefined,
);
const retroalimentacion = ref(props.entrega.retroalimentacion ?? '');
const enviando = ref(false);

const nombre = computed(() =>
    `${props.entrega.usuario.name} ${props.entrega.usuario.apellidos ?? ''}`.trim(),
);
const minima = computed(() => props.entrega.actividad.calificacion_minima);
const sugerencia = computed(() =>
    calificacion.value === undefined
        ? null
        : calificacion.value >= minima.value
          ? 'aprobar'
          : 'rechazar',
);

const ESTADO: Record<string, { texto: string; clase: string }> = {
    entregada: { texto: 'Por calificar', clase: 'bg-warning-soft text-bronce' },
    aprobada: { texto: 'Aprobada', clase: 'bg-success-soft text-primary' },
    rechazada: { texto: 'Rechazada', clase: 'bg-danger-soft text-destructive' },
};

function enviarCalificacion(aprobada: boolean) {
    enviando.value = true;
    router.post(
        calificar.url(props.entrega.id),
        {
            aprobada,
            calificacion: calificacion.value,
            retroalimentacion: retroalimentacion.value || undefined,
        },
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Entrega calificada correctamente.'),
            onError: () => mostrarError('No fue posible calificar la entrega.'),
            onFinish: () => (enviando.value = false),
        },
    );
}
</script>

<template>
    <Head title="Calificar entrega" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="entrega.actividad.titulo"
            :descripcion="`${nombre} · Entrega ${entrega.version}${curso ? ` · ${curso.titulo}` : ''}`"
            :icono="ClipboardCheck"
            detalle
        >
            <Button v-if="curso" variant="outline" as-child>
                <Link :href="aula(curso.id)">
                    <Presentation class="size-4" /> Aula
                </Link>
            </Button>
            <Button variant="outline" as-child>
                <Link :href="index()">
                    <ArrowLeft class="size-4" /> Volver a la bandeja
                </Link>
            </Button>
        </CrudPageHeader>

        <div
            class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,26rem)]"
        >
            <section
                class="flex entrada-suave flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm"
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <h2 class="font-semibold">Lo que entregó</h2>
                    <div
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <span class="tabular-nums">{{
                            formatearFechaHora(entrega.entregado_en)
                        }}</span>
                        <Badge
                            variant="secondary"
                            :class="
                                ESTADO[entrega.estado]?.clase ??
                                'bg-muted text-muted-foreground'
                            "
                            >{{
                                ESTADO[entrega.estado]?.texto ?? entrega.estado
                            }}</Badge
                        >
                    </div>
                </header>

                <div
                    v-if="entrega.actividad.instrucciones"
                    class="rounded-xl border border-dashed border-border bg-crema/30 p-3 text-xs text-muted-foreground"
                >
                    <p class="mb-1 font-semibold text-foreground">
                        Instrucciones de la actividad
                    </p>
                    <p class="whitespace-pre-line">
                        {{ entrega.actividad.instrucciones }}
                    </p>
                </div>

                <p
                    v-if="entrega.contenido_texto"
                    class="rounded-xl bg-crema/40 p-4 text-sm whitespace-pre-line"
                >
                    {{ entrega.contenido_texto }}
                </p>

                <div class="flex flex-wrap gap-2">
                    <Button v-if="entrega.url" variant="outline" as-child>
                        <a
                            :href="entrega.url"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <ExternalLink class="size-4" />
                            Abrir enlace entregado
                        </a>
                    </Button>
                    <Button
                        v-if="entrega.recurso_multimedia_id"
                        variant="outline"
                        as-child
                    >
                        <a :href="descargar.url(entrega.id)">
                            <Download class="size-4" />
                            Descargar
                            {{
                                entrega.recurso_multimedia?.nombre_original ??
                                'archivo'
                            }}
                        </a>
                    </Button>
                </div>

                <div
                    v-if="
                        !entrega.contenido_texto &&
                        !entrega.url &&
                        !entrega.recurso_multimedia_id
                    "
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <FileText class="size-4" /> La entrega no trae contenido.
                </div>
            </section>

            <aside
                class="flex entrada-suave flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm lg:sticky lg:top-20"
            >
                <h2 class="font-semibold">Calificación</h2>
                <div class="grid gap-2">
                    <Label for="calificacion">Calificación (%)</Label>
                    <Input
                        id="calificacion"
                        v-model.number="calificacion"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <p class="text-xs text-muted-foreground">
                        Mínimo para aprobar: {{ minima }}%.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="retroalimentacion">Retroalimentación</Label>
                    <Textarea
                        id="retroalimentacion"
                        v-model="retroalimentacion"
                        rows="5"
                        placeholder="Lo que hizo bien y lo que puede mejorar. La persona lo verá en su entrega."
                    />
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <Button
                        :disabled="enviando"
                        :class="{
                            'ring-2 ring-primary/30': sugerencia === 'aprobar',
                        }"
                        @click="enviarCalificacion(true)"
                    >
                        <Check class="size-4" />
                        Aprobar
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="enviando"
                        :class="{
                            'ring-2 ring-destructive/30':
                                sugerencia === 'rechazar',
                        }"
                        @click="enviarCalificacion(false)"
                    >
                        <X class="size-4" />
                        Rechazar
                    </Button>
                </div>
                <p class="text-xs text-pretty text-muted-foreground">
                    Al aprobar, la lección queda completada para la persona; si
                    se rechaza, puede volver a entregar.
                </p>
            </aside>
        </div>
    </div>
</template>
