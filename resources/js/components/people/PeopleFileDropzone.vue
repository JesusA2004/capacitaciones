<script setup lang="ts">
import {
    AlertCircle,
    FileText,
    Image,
    RefreshCw,
    Trash2,
    Upload,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';

/**
 * Zona de arrastrar-y-soltar reutilizable para toda la app (sección 10 del
 * encargo "cierre definitivo"): reemplaza los `<input type="file">` sueltos
 * y feos repartidos en Solicitudes/Expediente/Formatos. No sube nada por su
 * cuenta — solo junta los archivos elegidos (drag o click) y los expone por
 * `v-model`; quien la usa decide cuándo y cómo enviarlos (auto-submit al
 * cambiar el modelo, o esperar un botón "Subir").
 *
 * Opcionales (no cambian a quien ya la usa):
 * - `vista-previa`: con un solo archivo, muestra la imagen o el PDF elegido
 *   dentro de una tarjeta con «Cambiar archivo» y «Quitar» antes de enviar.
 * - `progreso`: porcentaje de la subida en curso (p. ej. `form.progress`).
 * - `error`: mensaje del servidor para este campo; se pinta en rojo junto a
 *   los rechazos locales (formato o tamaño), que también se emiten.
 */
const props = withDefaults(
    defineProps<{
        modelValue?: File[];
        accept?: string;
        multiple?: boolean;
        maxSizeMb?: number;
        disabled?: boolean;
        loading?: boolean;
        label?: string;
        hint?: string;
        vistaPrevia?: boolean;
        progreso?: number | null;
        error?: string | null;
        id?: string;
    }>(),
    {
        modelValue: () => [],
        accept: '.pdf,.jpg,.jpeg,.png,.webp',
        multiple: false,
        maxSizeMb: 20,
        disabled: false,
        loading: false,
        label: 'Arrastra tus archivos aquí',
        hint: undefined,
        vistaPrevia: false,
        progreso: null,
        error: null,
        id: undefined,
    },
);

const emit = defineEmits<{
    'update:modelValue': [files: File[]];
    error: [mensaje: string];
}>();

const idBase = props.id ?? `dropzone-${useId()}`;
const idAyuda = `${idBase}-ayuda`;
const arrastrando = ref(false);
const inputRef = ref<HTMLInputElement | null>(null);
const errorLocal = ref<string | null>(null);
let contadorDragEnter = 0;

const extensionesAceptadas = computed(() =>
    props.accept
        .split(',')
        .map((ext) => ext.trim().replace('.', '').toUpperCase())
        .filter(Boolean)
        .join(', '),
);

const textoAyuda = computed(
    () =>
        props.hint ??
        `${extensionesAceptadas.value} · Máximo ${props.maxSizeMb} MB`,
);

const mensajeError = computed(() => errorLocal.value ?? props.error ?? null);
const bloqueado = computed(() => props.disabled || props.loading);
const mostrarProgreso = computed(
    () => props.loading && props.progreso !== null,
);

/** Tarjeta con la vista previa en lugar de la zona (un solo archivo). */
const archivoUnico = computed<File | null>(() =>
    props.vistaPrevia && !props.multiple ? (props.modelValue[0] ?? null) : null,
);

const urlPrevia = ref<string | null>(null);

function liberarPrevia() {
    if (urlPrevia.value) {
        URL.revokeObjectURL(urlPrevia.value);
    }

    urlPrevia.value = null;
}

watch(
    archivoUnico,
    (archivo) => {
        liberarPrevia();

        if (archivo && (esImagen(archivo) || esPdf(archivo))) {
            urlPrevia.value = URL.createObjectURL(archivo);
        }
    },
    { immediate: true },
);

onBeforeUnmount(liberarPrevia);

function abrirSelector() {
    if (bloqueado.value) {
        return;
    }

    inputRef.value?.click();
}

function extensionValida(archivo: File): boolean {
    const extensionesPermitidas = props.accept
        .split(',')
        .map((ext) => ext.trim().toLowerCase())
        .filter(Boolean);

    if (extensionesPermitidas.length === 0) {
        return true;
    }

    const nombre = archivo.name.toLowerCase();

    return extensionesPermitidas.some((ext) =>
        ext.startsWith('.') ? nombre.endsWith(ext) : nombre.endsWith(`.${ext}`),
    );
}

function rechazar(mensaje: string) {
    errorLocal.value = mensaje;
    emit('error', mensaje);
}

function procesarArchivos(lista: FileList | File[]) {
    const archivos = Array.from(lista);
    const validos: File[] = [];
    errorLocal.value = null;

    for (const archivo of archivos) {
        if (!extensionValida(archivo)) {
            rechazar(
                `«${archivo.name}» no tiene un formato permitido (${extensionesAceptadas.value}).`,
            );
            continue;
        }

        if (archivo.size === 0) {
            rechazar(`«${archivo.name}» está vacío.`);
            continue;
        }

        if (archivo.size > props.maxSizeMb * 1024 * 1024) {
            rechazar(
                `«${archivo.name}» supera el tamaño máximo de ${props.maxSizeMb} MB.`,
            );
            continue;
        }

        validos.push(archivo);
    }

    if (validos.length === 0) {
        return;
    }

    emit(
        'update:modelValue',
        props.multiple ? [...props.modelValue, ...validos] : [validos[0]],
    );
}

function alSoltar(evento: DragEvent) {
    evento.preventDefault();
    arrastrando.value = false;
    contadorDragEnter = 0;

    if (bloqueado.value || !evento.dataTransfer) {
        return;
    }

    procesarArchivos(evento.dataTransfer.files);
}

function alEntrarArrastre(evento: DragEvent) {
    evento.preventDefault();
    contadorDragEnter += 1;

    if (!bloqueado.value) {
        arrastrando.value = true;
    }
}

function alSalirArrastre(evento: DragEvent) {
    evento.preventDefault();
    contadorDragEnter = Math.max(0, contadorDragEnter - 1);

    if (contadorDragEnter === 0) {
        arrastrando.value = false;
    }
}

function alSeleccionarInput(evento: Event) {
    const input = evento.target as HTMLInputElement;

    if (input.files && input.files.length > 0) {
        procesarArchivos(input.files);
    }

    input.value = '';
}

function quitarArchivo(indice: number) {
    const restantes = [...props.modelValue];
    restantes.splice(indice, 1);
    errorLocal.value = null;
    emit('update:modelValue', restantes);
}

function formatearTamano(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function esImagen(archivo: File): boolean {
    return archivo.type.startsWith('image/');
}

function esPdf(archivo: File): boolean {
    return (
        archivo.type === 'application/pdf' ||
        archivo.name.toLowerCase().endsWith('.pdf')
    );
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <input
            :id="idBase"
            ref="inputRef"
            type="file"
            class="hidden"
            :accept="accept"
            :multiple="multiple"
            :disabled="bloqueado"
            @change="alSeleccionarInput"
        />

        <!-- Un solo archivo con vista previa: tarjeta en lugar de la zona. -->
        <div
            v-if="archivoUnico"
            :class="
                cn(
                    'flex flex-col gap-3 rounded-2xl border p-3 transition-colors',
                    arrastrando
                        ? 'border-primary bg-primary/5'
                        : mensajeError
                          ? 'border-destructive/60 bg-card'
                          : 'border-border/70 bg-card',
                )
            "
            @dragenter="alEntrarArrastre"
            @dragover.prevent
            @dragleave="alSalirArrastre"
            @drop="alSoltar"
        >
            <div
                class="flex max-h-80 min-h-32 items-center justify-center overflow-hidden rounded-xl border border-border/60 bg-crema/40"
            >
                <img
                    v-if="urlPrevia && esImagen(archivoUnico)"
                    :src="urlPrevia"
                    :alt="`Vista previa de ${archivoUnico.name}`"
                    class="max-h-80 w-full object-contain"
                />
                <iframe
                    v-else-if="urlPrevia && esPdf(archivoUnico)"
                    :src="urlPrevia"
                    :title="`Vista previa de ${archivoUnico.name}`"
                    class="h-80 w-full"
                />
                <div
                    v-else
                    class="flex flex-col items-center gap-1.5 p-6 text-muted-foreground"
                >
                    <FileText class="size-8" />
                    <span class="text-xs">Sin vista previa disponible</span>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                >
                    <Image v-if="esImagen(archivoUnico)" class="size-4" />
                    <FileText v-else class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">
                        {{ archivoUnico.name }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ formatearTamano(archivoUnico.size) }} ·
                        {{ loading ? 'subiendo…' : 'listo para enviar' }}
                    </p>
                </div>
                <div class="flex gap-1.5">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="bloqueado"
                        @click="abrirSelector"
                    >
                        <RefreshCw class="size-3.5" />
                        Cambiar archivo
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-destructive hover:text-destructive"
                        :disabled="bloqueado"
                        @click="quitarArchivo(0)"
                    >
                        <Trash2 class="size-3.5" />
                        Quitar
                    </Button>
                </div>
            </div>
        </div>

        <div
            v-else
            role="button"
            tabindex="0"
            :aria-disabled="bloqueado"
            :aria-describedby="idAyuda"
            :class="
                cn(
                    'flex w-full flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed p-6 text-center transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    arrastrando
                        ? 'border-[var(--brand-primary)] bg-[var(--brand-primary)]/5'
                        : mensajeError
                          ? 'border-destructive/60 hover:bg-accent/40'
                          : 'border-border/70 hover:border-primary/40 hover:bg-accent/40',
                    bloqueado && 'pointer-events-none opacity-60',
                )
            "
            @click="abrirSelector"
            @keydown.enter="abrirSelector"
            @keydown.space.prevent="abrirSelector"
            @dragenter="alEntrarArrastre"
            @dragover.prevent
            @dragleave="alSalirArrastre"
            @drop="alSoltar"
        >
            <Upload class="size-6 text-muted-foreground" />
            <p class="text-sm font-medium">
                {{ label }}
                <span class="font-normal text-muted-foreground">
                    o haz clic para seleccionar</span
                >
            </p>
            <p :id="idAyuda" class="text-xs text-muted-foreground">
                {{ textoAyuda }}
            </p>
        </div>

        <ul
            v-if="!archivoUnico && modelValue.length > 0"
            class="flex flex-col gap-2"
        >
            <li
                v-for="(archivo, indice) in modelValue"
                :key="`${archivo.name}-${archivo.lastModified}-${indice}`"
                class="flex items-center gap-2.5 rounded-xl border border-border/60 bg-card p-2.5 text-sm"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                >
                    <Image v-if="esImagen(archivo)" class="size-4" />
                    <FileText v-else class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ archivo.name }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ formatearTamano(archivo.size) }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-7 shrink-0"
                    :disabled="loading"
                    :aria-label="`Quitar ${archivo.name}`"
                    @click.stop="quitarArchivo(indice)"
                >
                    <X class="size-4" />
                </Button>
            </li>
        </ul>

        <div v-if="mostrarProgreso" class="flex flex-col gap-1.5">
            <p class="text-xs font-medium text-primary">
                Subiendo… {{ Math.round(progreso ?? 0) }}%
            </p>
            <Progress :model-value="progreso ?? 0" class="h-1.5" />
        </div>

        <p
            v-if="mensajeError"
            class="flex items-start gap-1.5 text-sm text-destructive"
            role="alert"
            data-input-error
        >
            <AlertCircle class="mt-0.5 size-4 shrink-0" />
            <span>{{ mensajeError }}</span>
        </p>
    </div>
</template>
