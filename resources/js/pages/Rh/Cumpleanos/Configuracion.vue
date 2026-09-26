<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2, Upload } from '@lucide/vue';
import { ref } from 'vue';
import CelebracionConfiguracionLayout from '@/components/Celebraciones/CelebracionConfiguracionLayout.vue';
import EmojiPicker from '@/components/Common/EmojiPicker.vue';
import InputError from '@/components/InputError.vue';
import PeopleConfirmDialog from '@/components/people/PeopleConfirmDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/cumpleanos';
import { vistaPrevia } from '@/routes/rh/cumpleanos/configuracion';
import { actualizar, eliminar as eliminarFondo } from '@/routes/rh/cumpleanos/configuracion/fondo';
import { destroy as destroyFrase, store as storeFrase, update as updateFrase } from '@/routes/rh/cumpleanos/frases';

/**
 * Configuración de la tarjeta de cumpleaños: fondo propio y frases que
 * rotan en las tarjetas, con la vista previa REAL a un lado (mismo diseño
 * que la configuración de Aniversarios).
 */
type Frase = { id: number; texto: string; categoria: string | null; activo: boolean; usado_count: number };

defineProps<{
    tieneFondo: boolean;
    fondoUrl: string | null;
    frases: Frase[];
    puedeGestionarFrases: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cumpleaños', href: index.url() },
            { title: 'Configuración', href: '' },
        ],
    },
});

const version = ref(Date.now());
const refrescar = () => (version.value = Date.now());

// --- Fondo ---
const formFondo = useForm({ fondo: null as File | null });
const eliminandoFondo = ref(false);
const confirmarQuitarFondo = ref(false);

function elegirFondo(evento: Event) {
    formFondo.fondo = (evento.target as HTMLInputElement).files?.[0] ?? null;

    if (formFondo.fondo) {
        formFondo.post(actualizar.url(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                formFondo.reset();
                refrescar();
            },
        });
    }
}

function quitarFondo() {
    eliminandoFondo.value = true;
    router.delete(eliminarFondo.url(), {
        preserveScroll: true,
        onSuccess: refrescar,
        onFinish: () => {
            eliminandoFondo.value = false;
            confirmarQuitarFondo.value = false;
        },
    });
}

// --- Frases ---
const nuevaFrase = useForm({ texto: '', categoria: '' });
const fraseAEliminar = ref<Frase | null>(null);
const eliminandoFrase = ref(false);

function agregarFrase() {
    if (!nuevaFrase.texto.trim()) {
        return;
    }

    nuevaFrase.post(storeFrase.url(), { preserveScroll: true, onSuccess: () => nuevaFrase.reset() });
}

function alternarFrase(frase: Frase) {
    router.put(updateFrase.url(frase.id), { activo: !frase.activo }, { preserveScroll: true, preserveState: true });
}

function eliminarFrase() {
    if (!fraseAEliminar.value) {
        return;
    }

    eliminandoFrase.value = true;
    router.delete(destroyFrase.url(fraseAEliminar.value.id), {
        preserveScroll: true,
        onFinish: () => {
            eliminandoFrase.value = false;
            fraseAEliminar.value = null;
        },
    });
}
</script>

<template>
    <Head title="Configuración de cumpleaños" />

    <CelebracionConfiguracionLayout titulo="Tarjeta de cumpleaños" :volver-url="index.url()" :vista-previa-url="vistaPrevia.url()" :version="version">
        <section class="flex flex-col gap-3" aria-labelledby="config-fondo">
            <div>
                <h2 id="config-fondo" class="text-sm font-semibold">Fondo</h2>
                <p class="text-sm text-muted-foreground">
                    {{ tieneFondo ? 'Se usa un fondo propio en todas las tarjetas nuevas o regeneradas.' : 'Se usa el diseño con globos por defecto.' }}
                    Recomendado: vertical 1080×1350, PNG/JPG/WebP, máximo 8 MB.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <img v-if="fondoUrl" :src="fondoUrl" alt="Fondo actual" class="h-20 w-16 rounded-md border object-cover" />
                <Button as-child variant="outline" size="sm" :disabled="formFondo.processing">
                    <label class="cursor-pointer">
                        <Spinner v-if="formFondo.processing" />
                        <Upload v-else class="size-4" />
                        {{ tieneFondo ? 'Reemplazar fondo' : 'Subir fondo' }}
                        <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="elegirFondo" />
                    </label>
                </Button>
                <Button v-if="tieneFondo" variant="ghost" size="sm" class="text-destructive" :disabled="eliminandoFondo" @click="confirmarQuitarFondo = true">
                    <Trash2 class="size-4" />
                    Quitar fondo
                </Button>
            </div>
            <InputError :message="formFondo.errors.fondo" />
        </section>

        <section v-if="puedeGestionarFrases" class="flex flex-col gap-3 border-t pt-6" aria-labelledby="config-frases">
            <div>
                <h2 id="config-frases" class="text-sm font-semibold">Frases de felicitación</h2>
                <p class="text-sm text-muted-foreground">Las frases activas rotan en las tarjetas para no repetir siempre la misma.</p>
            </div>

            <form class="flex flex-col gap-2 sm:flex-row" @submit.prevent="agregarFrase">
                <Input v-model="nuevaFrase.texto" placeholder="Escribe una nueva frase…" class="flex-1" aria-label="Nueva frase" />
                <div class="flex gap-2">
                    <EmojiPicker @select="(emoji) => (nuevaFrase.texto += emoji)" />
                    <Button type="submit" class="flex-1 sm:flex-none" :disabled="nuevaFrase.processing || !nuevaFrase.texto.trim()">
                        <Spinner v-if="nuevaFrase.processing" />
                        <Plus v-else class="size-4" />
                        Agregar
                    </Button>
                </div>
            </form>
            <InputError :message="nuevaFrase.errors.texto" />

            <p v-if="frases.length === 0" class="py-4 text-sm text-muted-foreground">Todavía no hay frases. Agrega la primera arriba.</p>
            <ul v-else class="divide-y rounded-lg border">
                <li v-for="frase in frases" :key="frase.id" class="flex flex-col gap-2 p-3 sm:flex-row sm:items-start" :class="!frase.activo && 'bg-muted/30'">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm break-words" :class="!frase.activo && 'text-muted-foreground'">{{ frase.texto }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Usada {{ frase.usado_count }} {{ frase.usado_count === 1 ? 'vez' : 'veces' }}
                            <Badge v-if="!frase.activo" variant="outline" class="ml-1">Inactiva</Badge>
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <Button size="sm" variant="ghost" @click="alternarFrase(frase)">{{ frase.activo ? 'Desactivar' : 'Activar' }}</Button>
                        <Button size="icon-sm" variant="ghost" class="text-destructive" :aria-label="`Eliminar frase ${frase.texto.slice(0, 30)}`" @click="fraseAEliminar = frase">
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </li>
            </ul>
        </section>
    </CelebracionConfiguracionLayout>

    <PeopleConfirmDialog
        :open="confirmarQuitarFondo"
        titulo="Quitar fondo"
        descripcion="Las tarjetas nuevas volverán al diseño con globos por defecto."
        destructivo
        texto-confirmar="Quitar"
        :cargando="eliminandoFondo"
        @update:open="(v) => (confirmarQuitarFondo = v)"
        @confirm="quitarFondo"
    />
    <PeopleConfirmDialog
        :open="fraseAEliminar !== null"
        titulo="Eliminar frase"
        :descripcion="fraseAEliminar ? `¿Eliminar «${fraseAEliminar.texto.slice(0, 60)}${fraseAEliminar.texto.length > 60 ? '…' : ''}»? Esta acción no se puede deshacer.` : undefined"
        destructivo
        texto-confirmar="Eliminar"
        :cargando="eliminandoFrase"
        @update:open="(v) => !v && (fraseAEliminar = null)"
        @confirm="eliminarFrase"
    />
</template>
