<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Image as ImageIcon, Library, Upload, X } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { store as subirRecurso } from '@/routes/rh/documentos-maestros/fondos';
import type { RecursoDocumento } from '@/types';

/**
 * Picker visual (biblioteca con miniaturas + subir nuevo) para fondo/logo
 * de un documento administrativo — reemplaza el <select> de texto plano.
 * Reutiliza App\Models\DocumentAsset y su endpoint de subida real
 * (rh.documentos-maestros.fondos.store); nunca inventa otro almacenamiento.
 */
const props = defineProps<{
    recursos: RecursoDocumento[];
    etiqueta: string;
    descripcion?: string;
    tipoSubida?: string;
}>();

const modelo = defineModel<number | null>({ default: null });

const bibliotecaAbierta = ref(false);
const subiendoFormAbierto = ref(false);
const nombreNuevo = ref('');
const archivoNuevo = ref<File | null>(null);
const subiendo = ref(false);

const actual = computed(
    () => props.recursos.find((r) => r.id === modelo.value) ?? null,
);

function elegir(id: number) {
    modelo.value = id;
    bibliotecaAbierta.value = false;
}

function quitar() {
    modelo.value = null;
}

const idNombre = `recurso-nombre-${useId()}`;

/** La zona de arrastre trabaja con una lista; aquí se sube un solo archivo. */
const archivosElegidos = computed<File[]>({
    get: () => (archivoNuevo.value ? [archivoNuevo.value] : []),
    set: (archivos) => {
        archivoNuevo.value = archivos[0] ?? null;

        if (archivoNuevo.value && !nombreNuevo.value) {
            nombreNuevo.value = archivoNuevo.value.name.replace(/\.[^.]+$/, '');
        }
    },
});

function subir() {
    if (!archivoNuevo.value || !nombreNuevo.value.trim()) {
        return;
    }

    subiendo.value = true;
    router.post(
        subirRecurso.url(),
        {
            nombre: nombreNuevo.value.trim(),
            tipo: props.tipoSubida ?? '',
            archivo: archivoNuevo.value,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                subiendo.value = false;
                subiendoFormAbierto.value = false;
                nombreNuevo.value = '';
                archivoNuevo.value = null;
            },
        },
    );
}
</script>

<template>
    <div class="grid gap-2">
        <Label>{{ etiqueta }}</Label>
        <p v-if="descripcion" class="text-xs text-muted-foreground">
            {{ descripcion }}
        </p>

        <div
            v-if="actual"
            class="flex aparece-zoom items-center gap-3 rounded-xl border bg-muted/30 p-2"
        >
            <img
                :src="actual.url"
                :alt="actual.nombre"
                class="size-14 shrink-0 rounded-lg border bg-white object-contain"
            />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">{{ actual.nombre }}</p>
            </div>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                :aria-label="`Quitar ${actual.nombre}`"
                @click="quitar"
            >
                <X class="size-4" />
            </Button>
        </div>

        <div v-else class="flex flex-wrap gap-2">
            <Popover v-model:open="bibliotecaAbierta">
                <PopoverTrigger as-child>
                    <Button type="button" variant="outline" size="sm">
                        <Library class="size-4" />
                        Elegir de la biblioteca
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    class="w-80 max-w-[calc(100vw-2rem)] p-3"
                    align="start"
                >
                    <div
                        v-if="recursos.length === 0"
                        class="flex flex-col items-center gap-1.5 px-2 py-5 text-center"
                    >
                        <span
                            class="flex size-10 items-center justify-center rounded-2xl bg-crema text-bronce"
                            aria-hidden="true"
                        >
                            <ImageIcon class="size-5" />
                        </span>
                        <p class="text-sm font-medium">Biblioteca vacía</p>
                        <p class="text-xs text-muted-foreground">
                            Todavía no hay recursos de este tipo. Usa «Subir
                            nuevo» para agregar el primero.
                        </p>
                    </div>
                    <div
                        v-else
                        class="grid max-h-80 grid-cols-3 gap-2 overflow-y-auto"
                    >
                        <button
                            v-for="recurso in recursos"
                            :key="recurso.id"
                            type="button"
                            class="flex presionable flex-col items-center gap-1 rounded-lg border p-1.5 text-center transition-colors hover:border-primary/50 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @click="elegir(recurso.id)"
                        >
                            <img
                                :src="recurso.url"
                                :alt="recurso.nombre"
                                class="aspect-square w-full rounded-md border bg-white object-contain"
                            />
                            <span
                                class="line-clamp-2 text-[11px] leading-tight"
                                >{{ recurso.nombre }}</span
                            >
                        </button>
                    </div>
                </PopoverContent>
            </Popover>

            <Popover v-model:open="subiendoFormAbierto">
                <PopoverTrigger as-child>
                    <Button type="button" variant="outline" size="sm">
                        <Upload class="size-4" />
                        Subir nuevo
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    class="w-80 max-w-[calc(100vw-2rem)] p-3"
                    align="start"
                >
                    <div class="grid gap-3">
                        <div class="grid gap-1.5">
                            <Label :for="idNombre">Nombre</Label>
                            <Input
                                :id="idNombre"
                                v-model="nombreNuevo"
                                maxlength="150"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Archivo</Label>
                            <PeopleFileDropzone
                                v-model="archivosElegidos"
                                accept=".png,.jpg,.jpeg,.webp"
                                :max-size-mb="20"
                                label="Arrastra la imagen aquí"
                                :loading="subiendo"
                            />
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            :disabled="
                                !archivoNuevo || !nombreNuevo.trim() || subiendo
                            "
                            @click="subir"
                        >
                            <Spinner v-if="subiendo" />
                            <ImageIcon v-else class="size-4" />
                            {{
                                subiendo
                                    ? 'Subiendo…'
                                    : 'Agregar a la biblioteca'
                            }}
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
        </div>
    </div>
</template>
