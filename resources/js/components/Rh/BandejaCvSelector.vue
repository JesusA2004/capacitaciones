<script setup lang="ts">
import { Eye, Inbox, RefreshCw } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import {
    index as listarBandeja,
    ver as verBandeja,
} from '@/routes/rh/candidatos/bandeja-cv';

/**
 * Bandeja de CVs: archivos que se descargaron de WhatsApp Web y ya están en
 * el NAS (carpeta sincronizada). Se eligen por nombre: nadie tiene que usar
 * la ventana «Seleccionar archivo» (ver App\Services\Reclutamiento\BandejaCvService).
 * v-model = nombre del archivo elegido ('' = ninguno).
 */
const modelo = defineModel<string>({ default: '' });

type ArchivoBandeja = {
    archivo: string;
    tamano: number;
    modificado_en: string;
};

const archivos = ref<ArchivoBandeja[]>([]);
const cargando = ref(false);
const error = ref('');

function tamano(bytes: number): string {
    return bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function hace(iso: string): string {
    const minutos = Math.round((Date.now() - new Date(iso).getTime()) / 60000);

    if (minutos < 1) {
        return 'hace un momento';
    }

    if (minutos < 60) {
        return `hace ${minutos} min`;
    }

    return new Date(iso).toLocaleString('es-MX', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const opciones = computed(() =>
    archivos.value.map((a) => ({
        value: a.archivo,
        label: `${a.archivo} · ${hace(a.modificado_en)} · ${tamano(a.tamano)}`,
    })),
);

async function cargar() {
    cargando.value = true;
    error.value = '';

    try {
        const respuesta = await fetch(listarBandeja.url(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!respuesta.ok) {
            throw new Error(String(respuesta.status));
        }

        archivos.value = (
            (await respuesta.json()) as { data: ArchivoBandeja[] }
        ).data;

        if (
            modelo.value &&
            !archivos.value.some((a) => a.archivo === modelo.value)
        ) {
            modelo.value = '';
        }
    } catch {
        error.value = 'No se pudo leer la bandeja de CVs.';
    } finally {
        cargando.value = false;
    }
}

onMounted(cargar);
</script>

<template>
    <div class="grid min-w-0 gap-1.5" aria-label="Bandeja de CVs">
        <div class="flex min-w-0 items-center gap-2">
            <!-- Nombres de archivo largos: se recortan, nunca ensanchan el diálogo. -->
            <div class="min-w-0 flex-1 [&_[data-slot=select-value]]:truncate">
                <SelectSimple
                    v-model="modelo"
                    :opciones="opciones"
                    opcion-vacia="Sin CV de la bandeja"
                    :placeholder="
                        cargando
                            ? 'Cargando bandeja…'
                            : archivos.length
                              ? 'Elige el CV descargado'
                              : 'La bandeja está vacía'
                    "
                    aria-label="CV de la bandeja"
                    class="min-w-0 overflow-hidden"
                />
            </div>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                :disabled="cargando"
                aria-label="Actualizar bandeja"
                title="Actualizar bandeja"
                @click="cargar"
            >
                <RefreshCw
                    class="size-4"
                    :class="cargando ? 'animate-spin' : ''"
                />
            </Button>
            <Button
                v-if="modelo"
                type="button"
                variant="ghost"
                size="icon"
                as-child
                aria-label="Ver el archivo"
                title="Ver el archivo"
            >
                <a
                    :href="verBandeja.url({ query: { archivo: modelo } })"
                    target="_blank"
                    rel="noopener"
                >
                    <Eye class="size-4" />
                </a>
            </Button>
        </div>
        <p class="flex items-start gap-1 text-xs text-muted-foreground">
            <Inbox class="size-3.5" />
            <template v-if="error">{{ error }}</template>
            <template v-else
                >CVs descargados de WhatsApp (los más recientes primero). Al
                guardarlo sale de la bandeja.</template
            >
        </p>
    </div>
</template>
