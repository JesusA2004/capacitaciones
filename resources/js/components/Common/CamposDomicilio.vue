<script setup lang="ts">
import { computed } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ESTADOS_REPUBLICA, estadoOficial } from '@/lib/estadosRepublica';

/**
 * Domicilio estructurado (DomicilioService): calle, número exterior e
 * interior, colonia, C.P. (5 dígitos), municipio/alcaldía y estado (de la
 * lista). El texto completo `domicilio` lo compone el backend al guardar.
 * Mismo componente en el alta digital y en el expediente.
 */
const calle = defineModel<string>('calle', { required: true });
const numeroExterior = defineModel<string>('numeroExterior', {
    required: true,
});
const numeroInterior = defineModel<string>('numeroInterior', {
    required: true,
});
const colonia = defineModel<string>('colonia', { required: true });
const cp = defineModel<string>('cp', { required: true });
const municipio = defineModel<string>('municipio', { required: true });
const estado = defineModel<string>('estado', { required: true });

const props = withDefaults(
    defineProps<{
        errores?: Partial<Record<string, string>>;
        disabled?: boolean;
        /** Marca con * los obligatorios (alta digital). */
        requerido?: boolean;
        /** Texto completo ya registrado (registros previos sin campos). */
        textoAnterior?: string | null;
        /** Prefijo de los id de los inputs (dos formularios en una página). */
        prefijo?: string;
    }>(),
    { errores: () => ({}), prefijo: 'domicilio' },
);

const opcionesEstado = ESTADOS_REPUBLICA.map((e) => ({ value: e, label: e }));

/** Un estado capturado antes en mayúsculas («MORELOS») se preselecciona igual. */
const estadoSeleccionado = computed({
    get: () => estadoOficial(estado.value) || estado.value,
    set: (v: string) => {
        estado.value = v;
    },
});

const sinCampos = computed(
    () =>
        !!props.textoAnterior &&
        !calle.value &&
        !colonia.value &&
        !cp.value &&
        !municipio.value,
);

function soloDigitos(v: string | number) {
    cp.value = String(v).replace(/\D/g, '').slice(0, 5);
}

const id = (campo: string) => `${props.prefijo}_${campo}`;
const marca = (obligatorio = true) =>
    props.requerido && obligatorio ? ' *' : '';
</script>

<template>
    <div class="flex flex-col gap-3">
        <p
            v-if="sinCampos"
            class="rounded-md bg-crema px-3 py-2 text-xs text-muted-foreground"
        >
            Domicilio registrado en un solo texto: «{{ textoAnterior }}».
            Captura cada campo para que los contratos lo impriman en su casilla.
        </p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
            <div class="grid gap-2 sm:col-span-4">
                <Label :for="id('calle')">Calle{{ marca() }}</Label>
                <Input
                    :id="id('calle')"
                    v-model="calle"
                    maxlength="150"
                    autocomplete="address-line1"
                    :disabled="disabled"
                />
                <InputError :message="errores.domicilio_calle" />
            </div>
            <div class="grid gap-2 sm:col-span-1">
                <Label :for="id('numero_exterior')"
                    >Núm. ext.{{ marca() }}</Label
                >
                <Input
                    :id="id('numero_exterior')"
                    v-model="numeroExterior"
                    maxlength="20"
                    placeholder="123 o S/N"
                    :disabled="disabled"
                />
                <InputError :message="errores.domicilio_numero_exterior" />
            </div>
            <div class="grid gap-2 sm:col-span-1">
                <Label :for="id('numero_interior')">Núm. int.</Label>
                <Input
                    :id="id('numero_interior')"
                    v-model="numeroInterior"
                    maxlength="20"
                    placeholder="Opcional"
                    :disabled="disabled"
                />
                <InputError :message="errores.domicilio_numero_interior" />
            </div>
            <div class="grid gap-2 sm:col-span-4">
                <Label :for="id('colonia')">Colonia{{ marca() }}</Label>
                <Input
                    :id="id('colonia')"
                    v-model="colonia"
                    maxlength="120"
                    :disabled="disabled"
                />
                <InputError :message="errores.domicilio_colonia" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label :for="id('cp')">Código postal{{ marca() }}</Label>
                <Input
                    :id="id('cp')"
                    :model-value="cp"
                    inputmode="numeric"
                    maxlength="5"
                    autocomplete="postal-code"
                    placeholder="5 dígitos"
                    :disabled="disabled"
                    @update:model-value="soloDigitos"
                />
                <InputError :message="errores.domicilio_cp" />
            </div>
            <div class="grid gap-2 sm:col-span-3">
                <Label :for="id('municipio')"
                    >Municipio o alcaldía{{ marca() }}</Label
                >
                <Input
                    :id="id('municipio')"
                    v-model="municipio"
                    maxlength="120"
                    :disabled="disabled"
                />
                <InputError :message="errores.domicilio_municipio" />
            </div>
            <div class="grid gap-2 sm:col-span-3">
                <Label :for="id('estado')">Estado{{ marca() }}</Label>
                <SelectSimple
                    :id="id('estado')"
                    v-model="estadoSeleccionado"
                    :opciones="opcionesEstado"
                    placeholder="Elige el estado…"
                    :disabled="disabled"
                    class="w-full"
                />
                <InputError :message="errores.domicilio_estado" />
            </div>
        </div>
    </div>
</template>
