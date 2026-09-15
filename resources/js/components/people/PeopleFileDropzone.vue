<script setup lang="ts">
import { FileText, Image, Upload, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * Zona de arrastrar-y-soltar reutilizable para toda la app (sección 10 del
 * encargo "cierre definitivo"): reemplaza los `<input type="file">` sueltos
 * y feos repartidos en Solicitudes/Expediente/Formatos. No sube nada por su
 * cuenta — solo junta los archivos elegidos (drag o click) y los expone por
 * `v-model`; quien la usa decide cuándo y cómo enviarlos (auto-submit al
 * cambiar el modelo, o esperar un botón "Subir").
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
    },
);

const emit = defineEmits<{
    'update:modelValue': [files: File[]];
    error: [mensaje: string];
}>();

const arrastrando = ref(false);
const inputRef = ref<HTMLInputElement | null>(null);
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

function abrirSelector() {
    if (props.disabled || props.loading) {
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

function procesarArchivos(lista: FileList | File[]) {
    const archivos = Array.from(lista);
    const validos: File[] = [];

    for (const archivo of archivos) {
        if (!extensionValida(archivo)) {
            emit(
                'error',
                `«${archivo.name}» no tiene un formato permitido (${extensionesAceptadas.value}).`,
            );
            continue;
        }

        if (archivo.size > props.maxSizeMb * 1024 * 1024) {
            emit(
                'error',
                `«${archivo.name}» supera el tamaño máximo de ${props.maxSizeMb} MB.`,
            );
            continue;
        }

        validos.push(archivo);
    }

    if (validos.length === 0) {
        return;
    }

    emit('update:modelValue', props.multiple ? [...props.modelValue, ...validos] : [validos[0]]);
}

function alSoltar(evento: DragEvent) {
    evento.preventDefault();
    arrastrando.value = false;
    contadorDragEnter = 0;

    if (props.disabled || props.loading || !evento.dataTransfer) {
        return;
    }

    procesarArchivos(evento.dataTransfer.files);
}

function alEntrarArrastre(evento: DragEvent) {
    evento.preventDefault();
    contadorDragEnter += 1;

    if (!props.disabled && !props.loading) {
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
</script>

<template>
    <div class="flex flex-col gap-3">
        <div
            role="button"
            tabindex="0"
            :class="
                cn(
                    'flex w-full flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed p-6 text-center transition-colors',
                    arrastrando
                        ? 'border-[var(--brand-primary)] bg-[var(--brand-primary)]/5'
                        : 'border-border/70 hover:border-primary/40 hover:bg-accent/40',
                    (disabled || loading) && 'pointer-events-none opacity-60',
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
            <p class="text-xs text-muted-foreground">{{ textoAyuda }}</p>

            <input
                ref="inputRef"
                type="file"
                class="hidden"
                :accept="accept"
                :multiple="multiple"
                :disabled="disabled || loading"
                @change="alSeleccionarInput"
            />
        </div>

        <ul v-if="modelValue.length > 0" class="flex flex-col gap-2">
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
                    @click.stop="quitarArchivo(indice)"
                >
                    <X class="size-4" />
                </Button>
            </li>
        </ul>
    </div>
</template>
