<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Image as ImageIcon, Library, Upload, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
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

const actual = computed(() => props.recursos.find((r) => r.id === modelo.value) ?? null);

function elegir(id: number) {
    modelo.value = id;
    bibliotecaAbierta.value = false;
}

function quitar() {
    modelo.value = null;
}

function onArchivo(evento: Event) {
    const input = evento.target as HTMLInputElement;
    archivoNuevo.value = input.files?.[0] ?? null;

    if (archivoNuevo.value && !nombreNuevo.value) {
        nombreNuevo.value = archivoNuevo.value.name.replace(/\.[^.]+$/, '');
    }
}

function subir() {
    if (!archivoNuevo.value || !nombreNuevo.value.trim()) {
        return;
    }

    subiendo.value = true;
    router.post(
        subirRecurso.url(),
        { nombre: nombreNuevo.value.trim(), tipo: props.tipoSubida ?? '', archivo: archivoNuevo.value },
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
            class="flex items-center gap-3 rounded-xl border bg-muted/30 p-2"
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
                aria-label="Quitar"
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
                <PopoverContent class="w-80 p-3" align="start">
                    <p
                        v-if="recursos.length === 0"
                        class="p-2 text-sm text-muted-foreground"
                    >
                        Todavía no hay recursos de este tipo en la biblioteca.
                    </p>
                    <div v-else class="grid max-h-80 grid-cols-3 gap-2 overflow-y-auto">
                        <button
                            v-for="recurso in recursos"
                            :key="recurso.id"
                            type="button"
                            class="flex flex-col items-center gap-1 rounded-lg border p-1.5 text-center transition-colors hover:border-primary/50 hover:bg-muted/50"
                            @click="elegir(recurso.id)"
                        >
                            <img
                                :src="recurso.url"
                                :alt="recurso.nombre"
                                class="aspect-square w-full rounded-md border bg-white object-contain"
                            />
                            <span class="line-clamp-2 text-[11px] leading-tight">{{
                                recurso.nombre
                            }}</span>
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
                <PopoverContent class="w-72 p-3" align="start">
                    <div class="grid gap-3">
                        <div class="grid gap-1.5">
                            <Label>Nombre</Label>
                            <Input v-model="nombreNuevo" maxlength="150" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Archivo (PNG/JPG/WEBP)</Label>
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                class="text-sm"
                                @change="onArchivo"
                            />
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            :disabled="!archivoNuevo || !nombreNuevo.trim() || subiendo"
                            @click="subir"
                        >
                            <ImageIcon class="size-4" />
                            {{ subiendo ? 'Subiendo…' : 'Agregar a la biblioteca' }}
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
        </div>
    </div>
</template>
