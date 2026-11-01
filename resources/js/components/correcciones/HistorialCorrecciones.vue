<script setup lang="ts">
import { ArrowRight, History, Lock, MessageSquareText } from '@lucide/vue';
import { onMounted, ref, watch } from 'vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { pedirCorreccion } from '@/lib/correcciones';
import { formatearFechaHora } from '@/lib/fechas';
import { historial as historialDeRegistro } from '@/routes/correcciones';
import { historial as historialDeColaborador } from '@/routes/correcciones/colaborador';
import type { DominioCorreccion, RegistroCorreccion } from '@/types';

/**
 * Historial inmutable de correcciones de un registro (dominio + sujetoId) o de
 * una persona (colaboradorId). El servidor oculta lo que este usuario no puede ver.
 */
const props = defineProps<{
    dominio?: DominioCorreccion;
    sujetoId?: number;
    colaboradorId?: number;
    /** Cambia para volver a cargar (p. ej. tras una corrección). */
    version?: number;
}>();

const registros = ref<RegistroCorreccion[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
    const url =
        props.colaboradorId !== undefined
            ? historialDeColaborador.url(props.colaboradorId)
            : historialDeRegistro.url({
                  dominio: props.dominio ?? '',
                  id: props.sujetoId ?? 0,
              });

    cargando.value = true;
    error.value = null;

    try {
        registros.value = await pedirCorreccion<RegistroCorreccion[]>(url);
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : 'No se pudo cargar el historial.';
    } finally {
        cargando.value = false;
    }
}

onMounted(cargar);
watch(() => props.version, cargar);
</script>

<template>
    <section aria-label="Historial de correcciones" data-historial-correcciones>
        <div
            v-if="cargando"
            class="flex items-center gap-2 py-4 text-sm text-muted-foreground"
            role="status"
        >
            <Spinner /> Cargando historial…
        </div>
        <div
            v-else-if="error"
            class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-destructive/10 px-3 py-2 text-sm text-destructive"
            role="alert"
        >
            {{ error }}
            <Button size="sm" variant="secondary" @click="cargar">
                Reintentar
            </Button>
        </div>
        <div
            v-else-if="registros.length === 0"
            class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-border/70 bg-card px-4 py-8 text-center"
        >
            <History class="size-7 text-muted-foreground/60" />
            <p class="text-sm font-medium">Sin correcciones registradas</p>
        </div>
        <ol v-else class="flex flex-col gap-3">
            <li
                v-for="registro in registros"
                :key="registro.id"
                class="rounded-2xl border border-border/60 bg-card p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <ChipEstado
                            :etiqueta="registro.dominio_etiqueta"
                            tono="primario"
                        />
                        <span class="text-sm font-semibold">
                            {{ registro.actor }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            · {{ registro.origen }} · versión
                            {{ registro.version }}
                        </span>
                    </div>
                    <time class="text-xs text-muted-foreground tabular-nums">
                        {{ formatearFechaHora(registro.fecha) }}
                    </time>
                </div>
                <p
                    class="mt-2 flex items-start gap-1.5 rounded-xl bg-crema px-3 py-2 text-xs"
                >
                    <MessageSquareText
                        class="mt-0.5 size-3.5 shrink-0 text-bronce"
                    />
                    <span>
                        <span class="font-semibold">Motivo:</span>
                        {{ registro.motivo }}
                    </span>
                </p>
                <ul class="mt-3 flex flex-col gap-2">
                    <li
                        v-for="campo in registro.campos"
                        :key="campo.campo"
                        class="text-sm"
                    >
                        <span class="text-xs text-muted-foreground">
                            {{ campo.etiqueta }}
                        </span>
                        <span
                            v-if="campo.visible"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <span class="text-muted-foreground">
                                {{ campo.anterior ?? 'Sin capturar' }}
                            </span>
                            <ArrowRight
                                class="size-3.5 shrink-0"
                                aria-label="cambió a"
                            />
                            <span class="font-medium">
                                {{ campo.nuevo ?? 'Sin capturar' }}
                            </span>
                        </span>
                        <span
                            v-else
                            class="flex items-center gap-1.5 text-muted-foreground"
                        >
                            <Lock class="size-3.5" /> Confidencial
                        </span>
                    </li>
                </ul>
                <p
                    v-if="registro.vigente_desde"
                    class="mt-2 text-xs text-muted-foreground"
                >
                    Vigente desde {{ registro.vigente_desde }}
                </p>
            </li>
        </ol>
    </section>
</template>
