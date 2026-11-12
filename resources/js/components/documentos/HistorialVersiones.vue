<script setup lang="ts">
import { Download, History } from '@lucide/vue';
import { onMounted, ref, watch } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import { formatearFecha } from '@/lib/fechas';
import { getJson } from '@/lib/http';
import rutas from '@/routes/rh/documentos-edicion';
import { descargar } from '@/routes/rh/documentos-laborales';
import type { VersionDocumento } from '@/types';

/**
 * Cadena de versiones de un documento: v1, v2…, estado, motivo, usuario, firmados
 * y descarga de cada una para quien tenga permiso (la Policy decide en el
 * servidor). `recargar` se incrementa cuando se emite otra versión.
 */
const props = defineProps<{
    tipo: string;
    id: number;
    clave?: string | null;
    recargar?: number;
}>();

const versiones = ref<VersionDocumento[] | null>(null);
const cargando = ref(false);
const error = ref<string | null>(null);

async function cargar() {
    cargando.value = true;
    error.value = null;

    try {
        const respuesta = await getJson<{ versiones: VersionDocumento[] }>(
            rutas.versiones.url(
                { tipo: props.tipo, id: props.id },
                props.clave ? { query: { clave: props.clave } } : undefined,
            ),
        );
        versiones.value = respuesta.versiones;
    } catch {
        error.value = 'No se pudo cargar el historial de versiones.';
    } finally {
        cargando.value = false;
    }
}

onMounted(cargar);
watch(() => props.recargar, cargar);
</script>

<template>
    <div class="flex flex-col gap-2 text-xs">
        <p class="flex items-center gap-1.5 font-medium">
            <History class="size-3.5" />Versiones del documento
        </p>
        <p
            v-if="cargando && !versiones"
            class="flex items-center gap-2 text-muted-foreground"
        >
            <Spinner />Cargando…
        </p>
        <p
            v-else-if="error"
            class="rounded-xl bg-danger-soft px-3 py-2 text-destructive"
            role="alert"
        >
            {{ error }}
        </p>
        <p
            v-else-if="versiones && !versiones.length"
            class="text-muted-foreground"
        >
            Aún no hay versiones generadas.
        </p>
        <ul v-else class="flex entrada-escalonada flex-col gap-1.5">
            <li
                v-for="v in versiones"
                :key="v.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border/60 px-3 py-2"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-medium">
                        v{{ v.version }} · {{ v.estado_etiqueta ?? 'Generado' }}
                        <span
                            v-if="v.vigente"
                            class="rounded-full bg-success-soft px-2 py-0.5"
                            >Vigente</span
                        >
                        <span
                            v-if="v.tipo_emision === 'rectificacion'"
                            class="rounded-full bg-crema px-2 py-0.5"
                            >{{ v.tipo_emision_etiqueta }}</span
                        >
                    </p>
                    <p class="text-muted-foreground">
                        {{ v.usuario ?? 'Sistema' }}
                        <template v-if="v.fecha">
                            · {{ formatearFecha(v.fecha) }}</template
                        >
                    </p>
                    <p v-if="v.motivo">Motivo: {{ v.motivo }}</p>
                    <p
                        v-if="v.campos_corregidos.length"
                        class="text-muted-foreground"
                    >
                        Datos corregidos:
                        {{
                            v.campos_corregidos
                                .map((c) => c.replaceAll('_', ' '))
                                .join(', ')
                        }}
                    </p>
                    <p v-if="v.firmados.length" class="text-muted-foreground">
                        Firmado:
                        {{ v.firmados.map((f) => f.nombre).join(', ') }}
                    </p>
                </div>
                <a
                    v-if="v.puede_descargar && v.archivo_disponible"
                    :href="descargar.url(v.id, { query: { descargar: 1 } })"
                    class="inline-flex items-center gap-1 enlace-animado font-medium"
                >
                    <Download class="size-3.5" />Descargar
                </a>
                <span
                    v-else-if="!v.archivo_disponible"
                    class="text-muted-foreground"
                    >Archivo no disponible</span
                >
            </li>
        </ul>
    </div>
</template>
