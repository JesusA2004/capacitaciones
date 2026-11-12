<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import CampoMoneda from '@/components/Common/CampoMoneda.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import MonthPicker from '@/components/Common/MonthPicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type {
    CampoListaSolicitud,
    CampoSolicitud,
    ValorSolicitud,
} from '@/types';

/**
 * Control de UN dato de la Solicitud de empleo / Estudio socioeconómico,
 * según su tipo del catálogo (PerfilIngresoService). Compartido por la
 * pantalla de RH y el formulario del colaborador: solo pinta y emite el
 * valor, no guarda nada.
 *
 * - booleano → Sí / No (dos botones, aria-pressed)
 * - select con ≤ 4 opciones → píldoras; con más → lista desplegable
 * - multiselect → chips que se encienden y apagan
 * - moneda → «$» + teclado decimal; entero → teclado numérico
 */
const props = defineProps<{
    campo: CampoSolicitud | CampoListaSolicitud;
    modelValue: ValorSolicitud;
    id: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [valor: ValorSolicitud] }>();

const PILDORAS_MAX = 4;

const comoTexto = computed(() =>
    props.modelValue === null ||
    props.modelValue === undefined ||
    Array.isArray(props.modelValue) ||
    typeof props.modelValue === 'boolean'
        ? ''
        : String(props.modelValue),
);

const seleccion = computed<string[]>(() =>
    Array.isArray(props.modelValue) ? props.modelValue : [],
);

const monto = computed<number | null>(() => {
    const n = Number.parseFloat(comoTexto.value);

    return Number.isFinite(n) ? n : null;
});

/** Teclado del celular (numérico para C.P./teléfono) en datos personales. */
const teclado = computed(() =>
    'teclado' in props.campo ? (props.campo.teclado ?? null) : null,
);

function emitir(valor: ValorSolicitud): void {
    emit('update:modelValue', valor);
}

function alTexto(valor: string | number): void {
    const texto = String(valor);
    emitir(texto === '' ? null : texto);
}

function alEntero(valor: string | number): void {
    const limpio = String(valor).replace(/\D/g, '');
    emitir(limpio === '' ? null : Number.parseInt(limpio, 10));
}

function alternar(opcion: string): void {
    emitir(
        seleccion.value.includes(opcion)
            ? seleccion.value.filter((v) => v !== opcion)
            : [...seleccion.value, opcion],
    );
}

const claseBoton =
    'inline-flex min-h-10 items-center justify-center gap-1.5 rounded-xl border px-3 py-2 text-sm transicion-ui focus-visible:outline-2 focus-visible:outline-oro';
const claseActivo =
    'border-[var(--mrl-petroleo)] bg-salvia font-medium text-[var(--mrl-petroleo)]';
const claseInactivo =
    'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] hover:border-oro/60';
</script>

<template>
    <!-- Sí / No -->
    <div
        v-if="campo.tipo === 'booleano'"
        :id="id"
        role="group"
        :aria-label="campo.etiqueta"
        class="grid grid-cols-2 gap-2 sm:inline-grid sm:w-56"
    >
        <button
            v-for="op in [
                { valor: true, etiqueta: 'Sí' },
                { valor: false, etiqueta: 'No' },
            ]"
            :key="op.etiqueta"
            type="button"
            :class="[
                claseBoton,
                modelValue === op.valor ? claseActivo : claseInactivo,
            ]"
            :aria-pressed="modelValue === op.valor"
            @click="emitir(modelValue === op.valor ? null : op.valor)"
        >
            <Check v-if="modelValue === op.valor" class="size-4" />
            {{ op.etiqueta }}
        </button>
    </div>

    <!-- Pocas opciones: píldoras -->
    <div
        v-else-if="
            campo.tipo === 'select' && campo.opciones.length <= PILDORAS_MAX
        "
        :id="id"
        role="radiogroup"
        :aria-label="campo.etiqueta"
        class="flex flex-wrap gap-2"
    >
        <button
            v-for="op in campo.opciones"
            :key="op.value"
            type="button"
            role="radio"
            :class="[
                claseBoton,
                'rounded-full',
                comoTexto === op.value ? claseActivo : claseInactivo,
            ]"
            :aria-checked="comoTexto === op.value"
            @click="emitir(op.value)"
        >
            <Check v-if="comoTexto === op.value" class="size-4" />
            {{ op.label }}
        </button>
    </div>

    <SelectSimple
        v-else-if="campo.tipo === 'select'"
        :id="id"
        :model-value="comoTexto || undefined"
        :opciones="campo.opciones"
        placeholder="Elige una opción"
        @update:model-value="(v) => emitir(v ? String(v) : null)"
    />

    <!-- Varias a la vez: chips -->
    <div
        v-else-if="campo.tipo === 'multiselect'"
        :id="id"
        role="group"
        :aria-label="campo.etiqueta"
        class="flex flex-wrap gap-2"
    >
        <button
            v-for="op in campo.opciones"
            :key="op.value"
            type="button"
            :class="[
                claseBoton,
                'rounded-full',
                seleccion.includes(op.value) ? claseActivo : claseInactivo,
            ]"
            :aria-pressed="seleccion.includes(op.value)"
            @click="alternar(op.value)"
        >
            <Check v-if="seleccion.includes(op.value)" class="size-4" />
            {{ op.label }}
        </button>
    </div>

    <CampoMoneda
        v-else-if="campo.tipo === 'moneda'"
        :id="id"
        :model-value="monto"
        @update:model-value="(v) => emitir(v)"
    />

    <Input
        v-else-if="campo.tipo === 'entero'"
        :id="id"
        :model-value="comoTexto"
        type="text"
        inputmode="numeric"
        pattern="[0-9]*"
        autocomplete="off"
        @update:model-value="alEntero"
    />

    <DatePicker
        v-else-if="campo.tipo === 'fecha'"
        :id="id"
        :model-value="comoTexto || null"
        :anio-desde="1940"
        @update:model-value="(v) => emitir(v || null)"
    />

    <MonthPicker
        v-else-if="campo.tipo === 'mes'"
        :id="id"
        :model-value="comoTexto"
        :aria-label="campo.etiqueta"
        @update:model-value="(v) => emitir(v || null)"
    />

    <Textarea
        v-else-if="campo.tipo === 'texto_largo'"
        :id="id"
        :model-value="comoTexto"
        :maxlength="campo.max ?? undefined"
        rows="2"
        @update:model-value="alTexto"
    />

    <Input
        v-else
        :id="id"
        :model-value="comoTexto"
        :type="teclado === 'email' ? 'email' : 'text'"
        :inputmode="teclado ?? undefined"
        :autocomplete="teclado ? 'off' : undefined"
        :maxlength="campo.max ?? undefined"
        @update:model-value="alTexto"
    />
</template>
