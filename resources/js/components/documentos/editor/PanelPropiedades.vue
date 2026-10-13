<script setup lang="ts">
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Bold,
    Check,
    Copy,
    Lock,
    LockOpen,
    Sparkles,
    Trash2,
    Wand2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConstructorExpresion from '@/components/documentos/editor/ConstructorExpresion.vue';
import PanelVariables from '@/components/documentos/editor/PanelVariables.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { FONDO_TAPAR } from '@/lib/editorDocumentos';
import { cn } from '@/lib/utils';
import type {
    CampoEditor,
    GrupoVariablesEditor,
    TransformacionCampo,
    VariableEditor,
    ZonaCampo,
} from '@/types';

/**
 * Propiedades del espacio seleccionado. Todo con controles (nunca JSON):
 * qué es el espacio, qué dato lleva (con las sugerencias del detector),
 * cómo se escribe y —en PDF— dónde y con qué tamaño.
 */
const props = defineProps<{
    campo: CampoEditor;
    numero: number;
    motor: 'docx' | 'pdf_overlay';
    grupos: GrupoVariablesEditor[];
    editable: boolean;
}>();

const emit = defineEmits<{
    cambiar: [cambios: Partial<CampoEditor>];
    confirmar: [];
    duplicar: [];
    eliminar: [];
}>();

const selectorAbierto = ref(false);
const esPdf = computed(() => props.motor === 'pdf_overlay');

const etiquetaDe = computed(() => {
    const mapa = new Map<string, string>();

    for (const g of props.grupos) {
        for (const v of g.variables) {
            mapa.set(v.clave, v.etiqueta);
        }
    }

    return mapa;
});

const zonas: { valor: ZonaCampo; etiqueta: string }[] = [
    { valor: 'dato', etiqueta: 'Dato' },
    { valor: 'firma', etiqueta: 'Firma física' },
    { valor: 'huella', etiqueta: 'Huella' },
    { valor: 'testigo', etiqueta: 'Testigo' },
    { valor: 'ignorar', etiqueta: 'Sin dato' },
];

const transformaciones: { valor: TransformacionCampo | null; etiqueta: string }[] = [
    { valor: null, etiqueta: 'Normal' },
    { valor: 'mayusculas', etiqueta: 'MAYÚSCULAS' },
    { valor: 'minusculas', etiqueta: 'minúsculas' },
    { valor: 'titulo', etiqueta: 'Título' },
];

const confirmable = computed(
    () =>
        props.campo.estado !== 'confirmado' &&
        (props.campo.zona !== 'dato' ||
            props.campo.source_key !== null ||
            props.campo.expression !== null),
);

function elegir(variable: VariableEditor) {
    selectorAbierto.value = false;
    emit('cambiar', {
        source_key: variable.clave,
        expression: null,
        label: variable.etiqueta,
    });
}

function aceptarSugerencia(clave: string, etiqueta: string) {
    emit('cambiar', { source_key: clave, expression: null, label: etiqueta, estado: 'confirmado' });
}

function aNumero(valor: string | number): number | null {
    const n = typeof valor === 'number' ? valor : Number.parseFloat(valor);

    return Number.isFinite(n) ? Math.round(n * 10) / 10 : null;
}

function modoCompuesto(activo: boolean) {
    emit('cambiar', {
        expression: activo
            ? {
                  partes: props.campo.source_key
                      ? [{ tipo: 'variable', clave: props.campo.source_key }]
                      : [],
              }
            : null,
        source_key: activo ? null : props.campo.source_key,
    });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <header class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground">Espacio {{ numero }}</p>
                <p class="truncate font-semibold">{{ campo.label ?? 'Sin dato asignado' }}</p>
                <p v-if="campo.contexto" class="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                    Cerca de: «{{ campo.contexto }}»
                </p>
            </div>
            <Badge
                :class="
                    cn(
                        'shrink-0',
                        campo.zona !== 'dato' && 'bg-muted text-muted-foreground',
                        campo.zona === 'dato' && campo.estado === 'confirmado' && 'bg-success-soft text-success',
                        campo.zona === 'dato' && campo.estado === 'sugerido' && 'bg-warning-soft text-warning',
                        campo.zona === 'dato' && campo.estado === 'sin_asignar' && 'bg-danger-soft text-danger',
                    )
                "
                variant="secondary"
                >{{
                    campo.zona !== 'dato'
                        ? 'Sin dato'
                        : campo.estado === 'confirmado'
                          ? 'Confirmado'
                          : campo.estado === 'sugerido'
                            ? 'Por confirmar'
                            : 'Sin asignar'
                }}</Badge
            >
        </header>

        <p v-if="campo.motivo" class="rounded-lg bg-warning-soft px-3 py-2 text-xs text-warning">
            {{ campo.motivo }}
        </p>

        <!-- Qué es este espacio -->
        <div class="flex flex-col gap-1.5">
            <Label class="text-xs">Este espacio es</Label>
            <div class="flex flex-wrap gap-1">
                <button
                    v-for="z in zonas"
                    :key="z.valor"
                    type="button"
                    :disabled="!editable"
                    :class="
                        cn(
                            'rounded-full border px-2.5 py-1 text-xs',
                            campo.zona === z.valor ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
                        )
                    "
                    @click="emit('cambiar', { zona: z.valor, label: z.valor === 'dato' ? campo.label : z.etiqueta })"
                >
                    {{ z.etiqueta }}
                </button>
            </div>
        </div>

        <template v-if="campo.zona === 'dato'">
            <!-- Sugerencias del detector -->
            <div v-if="campo.sugerencias.length && campo.estado !== 'confirmado'" class="flex flex-col gap-1.5">
                <Label class="flex items-center gap-1 text-xs"><Sparkles class="size-3.5" /> Sugerencias</Label>
                <div class="flex flex-col gap-1">
                    <button
                        v-for="s in campo.sugerencias"
                        :key="s.clave"
                        type="button"
                        :disabled="!editable"
                        class="flex items-center justify-between rounded-lg border px-2.5 py-1.5 text-left text-sm hover:border-primary hover:bg-crema/60"
                        @click="aceptarSugerencia(s.clave, s.etiqueta)"
                    >
                        <span class="truncate">{{ s.etiqueta }}</span>
                        <span class="ml-2 shrink-0 text-xs text-muted-foreground">{{ Math.round(s.confianza * 100) }} %</span>
                    </button>
                </div>
            </div>

            <!-- Dato asignado -->
            <div class="flex flex-col gap-1.5">
                <Label class="text-xs">Dato</Label>
                <template v-if="!campo.expression">
                    <Popover v-model:open="selectorAbierto">
                        <PopoverTrigger as-child>
                            <Button variant="outline" class="justify-between" :disabled="!editable">
                                <span class="truncate">{{
                                    campo.source_key ? etiquetaDe.get(campo.source_key) ?? campo.label : 'Elegir dato…'
                                }}</span>
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent class="flex max-h-[26rem] w-80 flex-col" align="start">
                            <PanelVariables :grupos="grupos" :habilitado="editable" :seleccionado="true" @elegir="elegir" />
                        </PopoverContent>
                    </Popover>
                </template>
                <ConstructorExpresion
                    v-else
                    :expresion="campo.expression"
                    :grupos="grupos"
                    :editable="editable"
                    @cambiar="(e) => emit('cambiar', { expression: e })"
                />
                <label class="flex items-center gap-2 text-xs text-muted-foreground">
                    <Checkbox
                        :model-value="campo.expression !== null"
                        :disabled="!editable"
                        @update:model-value="(v) => modoCompuesto(v === true)"
                    />
                    <Wand2 class="size-3.5" /> Combinar varios datos
                </label>
            </div>

            <div class="flex flex-col gap-1.5">
                <Label class="text-xs">Escribir en</Label>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="t in transformaciones"
                        :key="t.etiqueta"
                        type="button"
                        :disabled="!editable"
                        :class="
                            cn(
                                'rounded-md border px-2 py-1 text-xs',
                                campo.transform === t.valor ? 'border-primary bg-primary/10 text-primary' : 'hover:bg-muted',
                            )
                        "
                        @click="emit('cambiar', { transform: t.valor })"
                    >
                        {{ t.etiqueta }}
                    </button>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <Checkbox
                    :model-value="campo.required"
                    :disabled="!editable"
                    @update:model-value="(v) => emit('cambiar', { required: v === true })"
                />
                Obligatorio (si falta, no se genera)
            </label>
        </template>

        <!-- Posición y tipografía (solo PDF: en Word el dato hereda el formato del texto) -->
        <template v-if="esPdf">
            <div class="grid grid-cols-2 gap-2">
                <div v-for="d in (['x', 'y', 'width', 'height'] as const)" :key="d" class="flex flex-col gap-1">
                    <Label class="text-xs">{{ { x: 'X (mm)', y: 'Y (mm)', width: 'Ancho', height: 'Alto' }[d] }}</Label>
                    <Input
                        type="number"
                        step="0.5"
                        class="h-8"
                        :model-value="campo[d] ?? ''"
                        :disabled="!editable || campo.bloqueado"
                        @update:model-value="(v) => emit('cambiar', { [d]: aNumero(v) })"
                    />
                </div>
            </div>
            <div v-if="campo.zona === 'dato'" class="grid grid-cols-2 gap-2">
                <div class="flex flex-col gap-1">
                    <Label class="text-xs">Tamaño (pt)</Label>
                    <Input
                        type="number"
                        step="0.5"
                        class="h-8"
                        :model-value="campo.font_size ?? 9"
                        :disabled="!editable"
                        @update:model-value="(v) => emit('cambiar', { font_size: aNumero(v) })"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <Label class="text-xs">Tamaño mínimo</Label>
                    <Input
                        type="number"
                        step="0.5"
                        class="h-8"
                        :model-value="campo.font_size_min ?? 6"
                        :disabled="!editable"
                        @update:model-value="(v) => emit('cambiar', { font_size_min: aNumero(v) })"
                    />
                </div>
            </div>
            <div v-if="campo.zona === 'dato'" class="flex flex-wrap items-center gap-1">
                <Button
                    size="icon"
                    variant="outline"
                    class="size-8"
                    :class="campo.font_weight === 'bold' && 'border-primary bg-primary/10'"
                    :disabled="!editable"
                    aria-label="Negrita"
                    @click="emit('cambiar', { font_weight: campo.font_weight === 'bold' ? null : 'bold' })"
                    ><Bold class="size-4"
                /></Button>
                <Button
                    v-for="a in ([['L', AlignLeft], ['C', AlignCenter], ['R', AlignRight]] as const)"
                    :key="a[0]"
                    size="icon"
                    variant="outline"
                    class="size-8"
                    :class="(campo.text_align ?? 'L') === a[0] && 'border-primary bg-primary/10'"
                    :disabled="!editable"
                    :aria-label="`Alinear ${a[0]}`"
                    @click="emit('cambiar', { text_align: a[0] })"
                    ><component :is="a[1]" class="size-4"
                /></Button>
                <label class="ml-2 flex items-center gap-1.5 text-xs">
                    <Checkbox
                        :model-value="campo.multiline"
                        :disabled="!editable"
                        @update:model-value="(v) => emit('cambiar', { multiline: v === true })"
                    />
                    Varias líneas
                </label>
                <label class="flex items-center gap-1.5 text-xs">
                    <Checkbox
                        :model-value="campo.type === 'casilla'"
                        :disabled="!editable"
                        @update:model-value="(v) => emit('cambiar', { type: v === true ? 'casilla' : 'texto' })"
                    />
                    Casilla ☒
                </label>
                <label class="flex items-center gap-1.5 text-xs">
                    <Checkbox
                        :model-value="campo.background_color !== null"
                        :disabled="!editable"
                        @update:model-value="(v) => emit('cambiar', { background_color: v === true ? FONDO_TAPAR : null })"
                    />
                    Tapar texto impreso
                </label>
            </div>
        </template>

        <div class="flex flex-wrap gap-2 border-t pt-3">
            <Button v-if="confirmable" size="sm" :disabled="!editable" @click="emit('confirmar')">
                <Check class="size-4" /> Confirmar
            </Button>
            <Button
                v-if="esPdf"
                size="sm"
                variant="outline"
                :disabled="!editable"
                @click="emit('cambiar', { bloqueado: !campo.bloqueado })"
            >
                <component :is="campo.bloqueado ? LockOpen : Lock" class="size-4" />
                {{ campo.bloqueado ? 'Desbloquear' : 'Bloquear' }}
            </Button>
            <Button v-if="esPdf" size="sm" variant="outline" :disabled="!editable" @click="emit('duplicar')">
                <Copy class="size-4" /> Duplicar
            </Button>
            <Button v-if="esPdf" size="sm" variant="ghost" class="text-danger" :disabled="!editable" @click="emit('eliminar')">
                <Trash2 class="size-4" /> Eliminar
            </Button>
        </div>
    </div>
</template>
