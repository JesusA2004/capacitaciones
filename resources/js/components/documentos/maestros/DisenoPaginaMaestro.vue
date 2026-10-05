<script setup lang="ts">
import { Eye, EyeOff, ImageOff, Layers, RotateCcw, Save } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Casilla from '@/components/Common/Casilla.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useDisenoMaestros } from '@/composables/useDisenoMaestros';
import type { DisenoFamilia, LayoutDocumento } from '@/types';

/**
 * «Diseño de página» de una familia de documentos maestros: preset base,
 * fondo de página (biblioteca de fondos), sangría y márgenes. La vista
 * previa es inmediata (hoja completa con el fondo y las guías del área
 * segura, que solo existen aquí, nunca en el PDF); «Probar con
 * colaborador» genera el documento real con Word.
 */
const props = defineProps<{ familia: string; puedeEditar?: boolean }>();

const api = useDisenoMaestros();
const diseno = ref<DisenoFamilia | null>(null);
const cargando = ref(true);
const guardando = ref(false);
const mostrarFondo = ref(true);
const mostrarGuias = ref(true);

// Valores editables (overrides de la familia sobre el preset).
const presetId = ref<number | null>(null);
const fondoId = ref<number | null>(null);
const aplicarA = ref<'all_pages' | 'first_page'>('all_pages');
const ajuste = ref<'stretch' | 'contain' | 'cover'>('stretch');
const opacidad = ref(100);
const logo = ref(false);
const sangria = ref<number | string>(0.64);

function cargarValores(d: DisenoFamilia) {
    const e = d.efectivo;
    presetId.value = d.preset?.id ?? null;
    fondoId.value = e?.background?.asset_id ?? null;
    aplicarA.value =
        e?.background?.apply_to === 'first_page' ? 'first_page' : 'all_pages';
    ajuste.value = e?.background?.fit ?? 'stretch';
    opacidad.value = e?.background?.opacity ?? 100;
    logo.value = e?.header_logo_enabled ?? true;
    sangria.value = e?.paragraph?.left_indent_cm ?? 0;
}

async function cargar() {
    cargando.value = true;

    try {
        diseno.value = await api.disenoFamilia(props.familia);
        cargarValores(diseno.value);
    } catch (e) {
        toast.error((e as Error).message);
    } finally {
        cargando.value = false;
    }
}

onMounted(cargar);
watch(() => props.familia, cargar);

const fondo = computed(
    () => diseno.value?.fondos.find((f) => f.id === fondoId.value) ?? null,
);

// El fondo propio trae identidad MR. LANA: por defecto sin logo extra.
watch(fondoId, (nuevo, anterior) => {
    if (anterior !== undefined && nuevo !== null) {
        logo.value = false;
    }
});

const geometria = computed(() => diseno.value?.pagina ?? null);
const margenes = computed(() => {
    const g = geometria.value;
    const m = diseno.value?.efectivo?.page?.margins_cm;

    return {
        top: m?.top !== undefined ? m.top * 10 : (g?.top_mm ?? 25),
        right: m?.right !== undefined ? m.right * 10 : (g?.right_mm ?? 30),
        bottom: m?.bottom !== undefined ? m.bottom * 10 : (g?.bottom_mm ?? 25),
        left: m?.left !== undefined ? m.left * 10 : (g?.left_mm ?? 30),
    };
});

const pct = (mm: number, total: number) => `${(mm / total) * 100}%`;
const anchoMm = computed(() => geometria.value?.page_w_mm ?? 215.9);
const altoMm = computed(() => geometria.value?.page_h_mm ?? 279.4);

const estiloFondo = computed(() => ({
    opacity: String(opacidad.value / 100),
    objectFit: (ajuste.value === 'stretch' ? 'fill' : ajuste.value) as
        'fill' | 'contain' | 'cover',
}));

/** Advertencias del área segura recalculadas al instante. */
const advertencias = computed(() => {
    const lista: string[] = [];
    const s = fondo.value?.safe_area;

    if (!s || !mostrarFondo.value) {
        return lista;
    }

    const m = margenes.value;
    const izq = m.left + (Number(sangria.value) || 0) * 10;

    if (s.top !== null && m.top < s.top) {
        lista.push(
            `Margen superior ${m.top.toFixed(1)} mm < área segura ${s.top} mm: el contenido invade el área gráfica del fondo.`,
        );
    }

    if (s.bottom !== null && m.bottom < s.bottom) {
        lista.push(
            `Margen inferior ${m.bottom.toFixed(1)} mm < área segura ${s.bottom} mm: el contenido invade el área gráfica del fondo.`,
        );
    }

    if (s.left !== null && Math.min(m.left, izq) < s.left) {
        lista.push(
            'El contenido invade el área gráfica del fondo (izquierda).',
        );
    }

    if (s.right !== null && m.right < s.right) {
        lista.push('El contenido invade el área gráfica del fondo (derecha).');
    }

    return lista;
});

async function guardar() {
    if (!diseno.value) {
        return;
    }

    guardando.value = true;
    const overrides: Partial<LayoutDocumento> = {
        background:
            fondoId.value === null
                ? null
                : {
                      asset_id: fondoId.value,
                      apply_to: aplicarA.value,
                      fit: ajuste.value,
                      opacity: Number(opacidad.value),
                  },
        header_logo_enabled: logo.value,
        paragraph: { left_indent_cm: Number(sangria.value) || 0 },
    };

    try {
        diseno.value = await api.guardarDiseno(props.familia, {
            preset_id: presetId.value,
            overrides,
        });
        cargarValores(diseno.value);
        toast.success(
            'Diseño guardado. Los documentos nuevos lo usan; los ya generados conservan el suyo.',
        );
    } catch (e) {
        toast.error((e as Error).message);
    } finally {
        guardando.value = false;
    }
}

async function quitarPersonalizacion() {
    guardando.value = true;

    try {
        diseno.value = await api.guardarDiseno(props.familia, {
            preset_id: presetId.value,
            overrides: null,
        });
        cargarValores(diseno.value);
        toast.success('Esta familia vuelve a usar el preset tal cual.');
    } catch (e) {
        toast.error((e as Error).message);
    } finally {
        guardando.value = false;
    }
}
</script>

<template>
    <div v-if="cargando" class="flex flex-col gap-3">
        <Skeleton class="h-6 w-1/3" />
        <Skeleton class="h-96 w-full" />
    </div>

    <div
        v-else-if="diseno"
        class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]"
    >
        <!-- Hoja completa -->
        <div class="flex flex-col gap-2">
            <div class="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    @click="mostrarFondo = !mostrarFondo"
                >
                    <component
                        :is="mostrarFondo ? EyeOff : Eye"
                        class="size-4"
                    />
                    {{ mostrarFondo ? 'Ocultar fondo' : 'Mostrar fondo' }}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    @click="mostrarGuias = !mostrarGuias"
                >
                    <Layers class="size-4" />
                    {{ mostrarGuias ? 'Ocultar guías' : 'Mostrar guías' }}
                </Button>
            </div>
            <div
                class="relative mx-auto w-full max-w-md overflow-hidden rounded-md border border-border bg-white shadow-sm"
                :style="{ aspectRatio: `${anchoMm} / ${altoMm}` }"
            >
                <!-- 1. Fondo (capa inferior) -->
                <img
                    v-if="fondo && mostrarFondo"
                    :src="fondo.url_imagen"
                    alt=""
                    class="pointer-events-none absolute inset-0 h-full w-full"
                    :style="estiloFondo"
                />
                <!-- Área segura del fondo (solo editor) -->
                <div
                    v-if="fondo?.safe_area && mostrarGuias && mostrarFondo"
                    class="pointer-events-none absolute border border-dashed border-amber-500/80"
                    :style="{
                        top: pct(fondo.safe_area.top ?? 0, altoMm),
                        bottom: pct(fondo.safe_area.bottom ?? 0, altoMm),
                        left: pct(fondo.safe_area.left ?? 0, anchoMm),
                        right: pct(fondo.safe_area.right ?? 0, anchoMm),
                    }"
                />
                <!-- 4. Contenido (márgenes de página + sangría de párrafo) -->
                <div
                    class="absolute flex flex-col gap-[3%]"
                    :class="{
                        'outline outline-1 outline-sky-500/60': mostrarGuias,
                    }"
                    :style="{
                        top: pct(margenes.top, altoMm),
                        bottom: pct(margenes.bottom, altoMm),
                        left: pct(margenes.left, anchoMm),
                        right: pct(margenes.right, anchoMm),
                    }"
                >
                    <div
                        class="mx-auto h-[1.4%] w-3/4 rounded bg-neutral-800"
                    />
                    <div
                        v-for="n in 7"
                        :key="n"
                        class="flex flex-col gap-[0.35rem]"
                        :style="{
                            paddingLeft: pct(
                                (Number(sangria) || 0) * 10,
                                anchoMm - margenes.left - margenes.right,
                            ),
                        }"
                    >
                        <div
                            v-for="l in 4"
                            :key="l"
                            class="h-[0.3rem] rounded bg-neutral-400"
                            :class="{ 'w-2/3': l === 4 }"
                        />
                    </div>
                </div>
            </div>
            <p class="text-center text-xs text-muted-foreground">
                Vista previa del diseño. Las guías (área segura en ámbar, área
                de texto en azul) no salen en el PDF.
            </p>
        </div>

        <!-- Controles -->
        <div class="flex flex-col gap-4 text-sm">
            <div class="grid gap-1.5">
                <Label>Preset base</Label>
                <SelectSimple
                    v-model="presetId"
                    opcion-vacia="Sin preset (diseño del original)"
                    :opciones="
                        diseno.presets.map((p) => ({
                            value: p.id,
                            label: p.nombre,
                        }))
                    "
                    :disabled="!puedeEditar"
                />
            </div>

            <div
                class="flex flex-col gap-2 rounded-xl border border-border/60 p-3"
            >
                <p
                    class="text-xs font-semibold text-muted-foreground uppercase"
                >
                    Fondo de página
                </p>
                <div class="flex items-center gap-3">
                    <div
                        class="flex aspect-[8.5/11] w-14 shrink-0 items-center justify-center overflow-hidden rounded border border-border bg-white"
                    >
                        <img
                            v-if="fondo"
                            :src="fondo.url_imagen"
                            alt=""
                            class="h-full w-full object-fill"
                        />
                        <ImageOff v-else class="size-5 text-muted-foreground" />
                    </div>
                    <SelectSimple
                        v-model="fondoId"
                        opcion-vacia="Sin fondo"
                        :opciones="
                            diseno.fondos
                                .filter((f) => f.activo || f.id === fondoId)
                                .map((f) => ({
                                    value: f.id,
                                    label: `${f.nombre} (v${f.version})`,
                                }))
                        "
                        :disabled="!puedeEditar"
                        class="min-w-0 flex-1"
                    />
                </div>
                <template v-if="fondoId !== null">
                    <div class="grid gap-1.5">
                        <Label>Aplicar a</Label>
                        <SelectSimple
                            v-model="aplicarA"
                            :opciones="[
                                {
                                    value: 'all_pages',
                                    label: 'Todas las páginas',
                                },
                                {
                                    value: 'first_page',
                                    label: 'Primera página',
                                },
                            ]"
                            :disabled="!puedeEditar"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Ajuste</Label>
                        <SelectSimple
                            v-model="ajuste"
                            :opciones="[
                                {
                                    value: 'stretch',
                                    label: 'Llenar página (estirar)',
                                },
                                { value: 'contain', label: 'Ajustar' },
                                { value: 'cover', label: 'Cubrir' },
                            ]"
                            :disabled="!puedeEditar"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Opacidad: {{ opacidad }}%</Label>
                        <input
                            v-model.number="opacidad"
                            type="range"
                            min="0"
                            max="100"
                            step="5"
                            :disabled="!puedeEditar"
                            class="accent-primary"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Área segura:
                        {{
                            fondo?.safe_area ? 'configurada' : 'sin configurar'
                        }}
                    </p>
                </template>
                <label class="flex items-center gap-2">
                    <Casilla v-model="logo" :disabled="!puedeEditar" />
                    Conservar logo/fondo del encabezado original
                </label>
            </div>

            <div class="grid gap-1.5">
                <Label>Sangría de párrafo (cm)</Label>
                <Input
                    v-model="sangria"
                    type="number"
                    min="0"
                    max="5"
                    step="0.01"
                    :disabled="!puedeEditar"
                />
                <p class="text-xs text-muted-foreground">
                    Solo párrafos normales; títulos centrados, listas, tablas y
                    firmas conservan su formato.
                </p>
            </div>

            <ul
                v-if="advertencias.length || diseno.advertencias.length"
                class="flex flex-col gap-1 rounded-xl bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-200"
            >
                <li
                    v-for="(a, i) in [
                        ...new Set([...advertencias, ...diseno.advertencias]),
                    ]"
                    :key="i"
                >
                    {{ a }}
                </li>
            </ul>

            <div v-if="puedeEditar" class="flex flex-col gap-2">
                <Button :disabled="guardando" @click="guardar">
                    <Save class="size-4" />
                    Guardar diseño
                </Button>
                <Button
                    v-if="diseno.tiene_personalizacion"
                    variant="ghost"
                    :disabled="guardando"
                    @click="quitarPersonalizacion"
                >
                    <RotateCcw class="size-4" />
                    Usar el preset sin cambios
                </Button>
            </div>
        </div>
    </div>
</template>
