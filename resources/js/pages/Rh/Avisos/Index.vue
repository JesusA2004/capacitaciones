<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    BellRing,
    FileText,
    Loader2,
    Paperclip,
    Send,
    Users2,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import AdjuntoAviso from '@/components/avisos/AdjuntoAviso.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import {
    archivo as archivoUrl,
    colaboradores,
    index,
    store,
} from '@/routes/rh/avisos';
import type {
    AvisoItem,
    ColaboradorBusqueda,
    RespuestaPaginada,
} from '@/types';

/**
 * Avisos de RH: escribir y enviar (una sola acción primaria) y, al lado,
 * lo ya enviado. Adjunto opcional: imagen (se ve) o PDF (tarjeta). El
 * push nunca lleva el archivo: abre el aviso.
 */
const props = defineProps<{
    avisos: RespuestaPaginada<AvisoItem>;
    /** Sin avisos.enviar solo se consulta el historial (avisos.ver). */
    puedeEnviar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Enviar notificación', href: index.url() },
        ],
    },
});

const TIPOS = 'image/jpeg,image/png,image/webp,application/pdf';
const MAX_MB = 10;

const colaboradorElegido = ref<ColaboradorBusqueda | null>(null);
const previsualizacion = ref<string | null>(null);
const errorLocal = ref<string | null>(null);

const form = useForm<{
    titulo: string;
    mensaje: string;
    alcance: 'todos' | 'colaborador';
    colaborador_objetivo_id: number | null;
    archivo: File | null;
}>({
    titulo: '',
    mensaje: '',
    alcance: 'todos',
    colaborador_objetivo_id: null,
    archivo: null,
});

const faltaColaborador = computed(
    () => form.alcance === 'colaborador' && colaboradorElegido.value === null,
);
const esPdf = computed(() => form.archivo?.type === 'application/pdf');
const tamanoElegido = computed(() =>
    form.archivo ? `${(form.archivo.size / 1024 / 1024).toFixed(1)} MB` : '',
);

function liberarPrevisualizacion() {
    if (previsualizacion.value) {
        URL.revokeObjectURL(previsualizacion.value);
    }

    previsualizacion.value = null;
}

function elegirArchivo(evento: Event) {
    const entrada = evento.target as HTMLInputElement;
    const elegido = entrada.files?.[0] ?? null;
    entrada.value = '';
    errorLocal.value = null;

    if (!elegido) {
        return;
    }

    if (!TIPOS.split(',').includes(elegido.type)) {
        errorLocal.value = 'Elige una imagen (JPG, PNG, WEBP) o un PDF.';

        return;
    }

    if (elegido.size > MAX_MB * 1024 * 1024) {
        errorLocal.value = `El archivo pesa más de ${MAX_MB} MB.`;

        return;
    }

    liberarPrevisualizacion();
    form.archivo = elegido;
    previsualizacion.value =
        elegido.type === 'application/pdf'
            ? null
            : URL.createObjectURL(elegido);
}

function quitarArchivo() {
    form.archivo = null;
    liberarPrevisualizacion();
}

function enviar() {
    form.colaborador_objetivo_id = colaboradorElegido.value?.id ?? null;
    form.transform((datos) => ({
        ...datos,
        colaborador_objetivo_id:
            form.alcance === 'colaborador'
                ? datos.colaborador_objetivo_id
                : null,
    })).post(store.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            colaboradorElegido.value = null;
            liberarPrevisualizacion();
        },
    });
}

onBeforeUnmount(liberarPrevisualizacion);
</script>

<template>
    <Head title="Enviar notificación" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Nueva notificación"
            descripcion="Un mensaje para toda la empresa o para una persona."
            :icono="BellRing"
        />

        <div class="grid gap-6 lg:grid-cols-5">
            <!-- Escribir -->
            <Card
                v-if="puedeEnviar"
                class="h-fit lg:sticky lg:top-4 lg:col-span-2"
            >
                <CardContent class="flex flex-col gap-4 pt-6">
                    <div class="flex flex-col gap-1.5">
                        <Label for="aviso-alcance">Para</Label>
                        <Select v-model="form.alcance">
                            <SelectTrigger id="aviso-alcance" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="todos"
                                    >Toda la empresa</SelectItem
                                >
                                <SelectItem value="colaborador"
                                    >Una persona</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>

                    <div
                        v-if="form.alcance === 'colaborador'"
                        class="flex flex-col gap-1.5"
                    >
                        <Label>Colaborador</Label>
                        <BuscadorColaborador
                            v-model="colaboradorElegido"
                            :buscar-url="
                                (texto) =>
                                    colaboradores.url({ query: { q: texto } })
                            "
                        />
                        <p
                            v-if="form.errors.colaborador_objetivo_id"
                            class="text-xs text-destructive"
                        >
                            {{ form.errors.colaborador_objetivo_id }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="aviso-titulo">Título</Label>
                        <Input
                            id="aviso-titulo"
                            v-model="form.titulo"
                            maxlength="150"
                            placeholder="Ej. Cambio de horario de caja"
                        />
                        <p
                            v-if="form.errors.titulo"
                            class="text-xs text-destructive"
                        >
                            {{ form.errors.titulo }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="aviso-mensaje">Mensaje</Label>
                        <Textarea
                            id="aviso-mensaje"
                            v-model="form.mensaje"
                            rows="5"
                            maxlength="2000"
                            placeholder="Escribe el aviso tal como quieres que lo lean."
                        />
                        <p
                            v-if="form.errors.mensaje"
                            class="text-xs text-destructive"
                        >
                            {{ form.errors.mensaje }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label>Archivo (opcional)</Label>
                        <Transition
                            mode="out-in"
                            enter-active-class="transition duration-150 ease-out motion-reduce:transition-none"
                            enter-from-class="opacity-0 translate-y-1"
                            leave-active-class="transition duration-100 ease-in motion-reduce:transition-none"
                            leave-to-class="opacity-0"
                        >
                            <div
                                v-if="form.archivo"
                                key="elegido"
                                class="relative overflow-hidden rounded-xl border"
                            >
                                <img
                                    v-if="previsualizacion"
                                    :src="previsualizacion"
                                    alt="Vista previa de la imagen"
                                    class="h-40 w-full object-cover"
                                />
                                <div
                                    v-else-if="esPdf"
                                    class="flex items-center gap-3 bg-crema/60 p-3 pr-12"
                                >
                                    <span
                                        class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-danger-soft text-danger"
                                    >
                                        <FileText class="size-5" />
                                    </span>
                                    <span class="min-w-0">
                                        <span
                                            class="block truncate text-sm font-medium"
                                            >{{ form.archivo.name }}</span
                                        >
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >PDF · {{ tamanoElegido }}</span
                                        >
                                    </span>
                                </div>
                                <Button
                                    size="icon"
                                    variant="secondary"
                                    class="absolute top-2 right-2 size-7"
                                    type="button"
                                    aria-label="Quitar archivo"
                                    @click="quitarArchivo"
                                >
                                    <X class="size-4" />
                                </Button>
                            </div>
                            <label
                                v-else
                                key="vacio"
                                class="flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed p-5 text-center text-sm text-muted-foreground transition-colors duration-150 focus-within:ring-2 focus-within:ring-ring hover:border-primary hover:bg-muted/40"
                            >
                                <Paperclip class="size-5" />
                                <span>Adjuntar imagen o PDF</span>
                                <span class="text-xs"
                                    >JPG, PNG, WEBP o PDF · máx.
                                    {{ MAX_MB }} MB</span
                                >
                                <input
                                    type="file"
                                    :accept="TIPOS"
                                    class="sr-only"
                                    @change="elegirArchivo"
                                />
                            </label>
                        </Transition>
                        <p
                            v-if="errorLocal || form.errors.archivo"
                            class="text-xs text-destructive"
                            role="alert"
                        >
                            {{ errorLocal ?? form.errors.archivo }}
                        </p>
                    </div>

                    <Button
                        class="mt-1 w-full"
                        :disabled="
                            form.processing ||
                            !form.titulo ||
                            !form.mensaje ||
                            faltaColaborador
                        "
                        @click="enviar"
                    >
                        <Loader2
                            v-if="form.processing"
                            class="size-4 animate-spin"
                        />
                        <Send v-else class="size-4" />
                        {{
                            form.processing
                                ? 'Enviando…'
                                : 'Enviar notificación'
                        }}
                    </Button>
                </CardContent>
            </Card>

            <!-- Enviadas -->
            <section
                class="flex flex-col gap-3 lg:col-span-3"
                aria-label="Notificaciones enviadas"
            >
                <h2 class="text-sm font-semibold text-muted-foreground">
                    Enviadas
                </h2>

                <EmptyState
                    v-if="!props.avisos.data.length"
                    :icono="BellRing"
                    titulo="Aún no has enviado notificaciones"
                    descripcion="Lo que envíes aparecerá aquí, con cuántas personas lo vieron."
                />

                <TransitionGroup
                    tag="div"
                    class="flex flex-col gap-3"
                    enter-active-class="transition duration-200 ease-out motion-reduce:transition-none"
                    enter-from-class="opacity-0 translate-y-1.5"
                >
                    <Card v-for="aviso in props.avisos.data" :key="aviso.id">
                        <CardContent
                            class="flex flex-col gap-3 pt-5 sm:flex-row sm:gap-4"
                        >
                            <AdjuntoAviso
                                v-if="aviso.archivo?.tipo === 'imagen'"
                                :archivo="aviso.archivo"
                                :url="archivoUrl.url(aviso.id)"
                                variante="miniatura"
                            />
                            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <p class="font-semibold">
                                        {{ aviso.titulo }}
                                    </p>
                                    <Badge variant="outline" class="gap-1.5">
                                        <Users2 class="size-3" />
                                        {{
                                            aviso.alcance === 'todos'
                                                ? 'Toda la empresa'
                                                : aviso.colaborador_objetivo
                                                  ? `${aviso.colaborador_objetivo.name} ${aviso.colaborador_objetivo.apellidos ?? ''}`
                                                  : 'Una persona'
                                        }}
                                    </Badge>
                                </div>
                                <p
                                    class="line-clamp-2 text-sm text-muted-foreground"
                                >
                                    {{ aviso.mensaje }}
                                </p>
                                <AdjuntoAviso
                                    v-if="aviso.archivo?.tipo === 'pdf'"
                                    :archivo="aviso.archivo"
                                    :url="archivoUrl.url(aviso.id)"
                                />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        aviso.enviado_en
                                            ? formatearFecha(aviso.enviado_en)
                                            : '—'
                                    }}
                                    · {{ aviso.creado_por?.name ?? 'RH' }} ·
                                    {{ aviso.lecturas_count ?? 0 }} vista(s)
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </TransitionGroup>

                <PaginacionTabla :datos="props.avisos" />
            </section>
        </div>
    </div>
</template>
