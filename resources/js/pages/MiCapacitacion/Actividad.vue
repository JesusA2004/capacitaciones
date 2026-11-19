<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    ExternalLink,
    Hourglass,
    MessageSquareText,
    Send,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { formatearFechaHora } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index, show as showCurso } from '@/routes/mi-capacitacion';
import { store } from '@/routes/mi-capacitacion/lecciones/actividad';
import type { ActividadItem, EntregaActividadItem, LeccionItem } from '@/types';

const props = defineProps<{
    leccion: LeccionItem;
    actividad: ActividadItem;
    entregas: EntregaActividadItem[];
    puedeEntregar: boolean;
    curso?: { id: number; titulo: string } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi capacitación', href: index.url() },
            { title: 'Actividad', href: '#' },
        ],
    },
});

const { mostrarExito, mostrarError } = useAlertas();

const form = useForm({
    contenido_texto: '',
    url: '',
    archivo: null as File | null,
});

const archivos = ref<File[]>([]);

const ESTADO: Record<
    string,
    { texto: string; clase: string; icono: typeof CheckCircle2 }
> = {
    entregada: {
        texto: 'En revisión',
        clase: 'bg-warning-soft text-bronce',
        icono: Hourglass,
    },
    aprobada: {
        texto: 'Aprobada',
        clase: 'bg-success-soft text-primary',
        icono: CheckCircle2,
    },
    rechazada: {
        texto: 'Rechazada',
        clase: 'bg-danger-soft text-destructive',
        icono: XCircle,
    },
};

const ultima = computed(() => props.entregas[0] ?? null);

function enviar() {
    form.archivo = archivos.value[0] ?? null;
    form.post(store.url(props.leccion.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            archivos.value = [];
            mostrarExito('Entrega registrada correctamente.');
        },
        onError: () => mostrarError('No fue posible registrar la entrega.'),
    });
}
</script>

<template>
    <Head :title="actividad.titulo" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="actividad.titulo"
            :descripcion="
                curso ? `${curso.titulo} · ${leccion.titulo}` : leccion.titulo
            "
            :icono="ClipboardCheck"
            detalle
        >
            <Button variant="outline" as-child>
                <Link :href="curso ? showCurso(curso.id) : index()">
                    <ArrowLeft class="size-4" />
                    {{ curso ? 'Volver al curso' : 'Mi capacitación' }}
                </Link>
            </Button>
        </CrudPageHeader>

        <div
            class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,28rem)]"
        >
            <div class="flex flex-col gap-5">
                <section
                    class="flex entrada-suave flex-col gap-3 rounded-2xl border border-border/60 bg-card p-5 shadow-sm"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2 class="font-semibold">Instrucciones</h2>
                        <span
                            v-if="actividad.fecha_limite"
                            class="inline-flex items-center gap-1.5 rounded-full bg-crema px-3 py-1 text-xs text-bronce"
                        >
                            <CalendarClock class="size-3.5" />
                            Entrega antes del
                            {{ formatearFechaHora(actividad.fecha_limite) }}
                        </span>
                    </div>
                    <p
                        class="max-w-prose text-sm text-pretty whitespace-pre-line"
                        :class="{
                            'text-muted-foreground': !actividad.instrucciones,
                        }"
                    >
                        {{
                            actividad.instrucciones ||
                            'Esta actividad no trae instrucciones adicionales.'
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Para aprobar se necesita al menos
                        {{ actividad.calificacion_minima }}%.
                    </p>
                </section>

                <section class="flex flex-col gap-3">
                    <h2 class="font-semibold">Mis entregas</h2>
                    <EmptyState
                        v-if="entregas.length === 0"
                        :icono="Send"
                        titulo="Todavía no has entregado"
                        descripcion="Cuando envíes tu entrega aparecerá aquí con su estado y la retroalimentación."
                        class="rounded-2xl border border-dashed border-border bg-card"
                    />
                    <ListaAnimada
                        v-else
                        tag="ol"
                        :cantidad="entregas.length"
                        class="flex flex-col gap-3"
                    >
                        <li
                            v-for="entrega in entregas"
                            :key="entrega.id"
                            class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span class="text-sm font-medium"
                                    >Entrega {{ entrega.version }}
                                    <span
                                        class="font-normal text-muted-foreground"
                                        >·
                                        {{
                                            formatearFechaHora(
                                                entrega.entregado_en,
                                            )
                                        }}</span
                                    ></span
                                >
                                <Badge
                                    variant="secondary"
                                    :class="
                                        ESTADO[entrega.estado]?.clase ??
                                        'bg-muted text-muted-foreground'
                                    "
                                >
                                    <component
                                        :is="ESTADO[entrega.estado]?.icono"
                                        v-if="ESTADO[entrega.estado]"
                                        class="size-3"
                                    />
                                    {{
                                        ESTADO[entrega.estado]?.texto ??
                                        entrega.estado
                                    }}
                                    <template
                                        v-if="entrega.calificacion !== null"
                                    >
                                        · {{ entrega.calificacion }}%
                                    </template>
                                </Badge>
                            </div>
                            <p
                                v-if="entrega.contenido_texto"
                                class="line-clamp-4 text-sm whitespace-pre-line text-muted-foreground"
                            >
                                {{ entrega.contenido_texto }}
                            </p>
                            <a
                                v-if="entrega.url"
                                :href="entrega.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex w-fit items-center gap-1.5 text-sm text-primary hover:underline"
                            >
                                <ExternalLink class="size-4" />
                                Abrir enlace entregado
                            </a>
                            <div
                                v-if="entrega.retroalimentacion"
                                class="flex items-start gap-2 rounded-xl bg-crema/50 p-3 text-sm"
                            >
                                <MessageSquareText
                                    class="mt-0.5 size-4 shrink-0 text-bronce"
                                />
                                <p class="whitespace-pre-line">
                                    {{ entrega.retroalimentacion }}
                                </p>
                            </div>
                        </li>
                    </ListaAnimada>
                </section>
            </div>

            <form
                v-if="puedeEntregar"
                class="flex entrada-suave flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm lg:sticky lg:top-20"
                @submit.prevent="enviar"
            >
                <div>
                    <h2 class="font-semibold">
                        {{
                            ultima?.estado === 'rechazada'
                                ? 'Vuelve a entregar'
                                : 'Nueva entrega'
                        }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Quien imparte el curso la revisará y la calificará.
                    </p>
                </div>

                <div
                    v-if="actividad.tipo_entrega === 'texto'"
                    class="grid gap-2"
                >
                    <Label for="contenido_texto">Tu respuesta</Label>
                    <Textarea
                        id="contenido_texto"
                        v-model="form.contenido_texto"
                        rows="8"
                    />
                    <InputError :message="form.errors.contenido_texto" />
                </div>

                <div
                    v-else-if="actividad.tipo_entrega === 'enlace'"
                    class="grid gap-2"
                >
                    <Label for="url">Enlace</Label>
                    <Input id="url" v-model="form.url" placeholder="https://" />
                    <InputError :message="form.errors.url" />
                </div>

                <PeopleFileDropzone
                    v-else
                    id="archivo"
                    v-model="archivos"
                    label="Archivo"
                    hint="Arrastra tu archivo o haz clic para elegirlo."
                    vista-previa
                    :progreso="form.progress?.percentage ?? null"
                    :error="form.errors.archivo"
                />

                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    <Send v-else class="size-4" />
                    Enviar entrega
                </Button>
            </form>

            <aside
                v-else
                class="flex entrada-suave flex-col items-center gap-2 rounded-2xl border border-border/60 bg-card p-6 text-center shadow-sm"
            >
                <span
                    class="flex size-14 aparece-zoom items-center justify-center rounded-2xl"
                    :class="
                        ultima?.estado === 'aprobada'
                            ? 'bg-success-soft text-primary'
                            : 'bg-warning-soft text-bronce'
                    "
                >
                    <CheckCircle2
                        v-if="ultima?.estado === 'aprobada'"
                        class="size-7"
                    />
                    <Hourglass v-else class="size-7" />
                </span>
                <p class="font-semibold">
                    {{
                        ultima?.estado === 'aprobada'
                            ? '¡Actividad aprobada!'
                            : 'Tu entrega está en revisión'
                    }}
                </p>
                <p class="text-sm text-pretty text-muted-foreground">
                    {{
                        ultima?.estado === 'aprobada'
                            ? 'Esta lección ya cuenta como completada.'
                            : 'Aquí verás el resultado cuando la califiquen. Si la rechazan podrás volver a entregar.'
                    }}
                </p>
            </aside>
        </div>
    </div>
</template>
