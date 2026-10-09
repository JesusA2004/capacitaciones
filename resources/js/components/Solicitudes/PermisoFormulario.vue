<script setup lang="ts">
import { CalendarX2, Clock3, LogOut, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import TimePicker from '@/components/Common/TimePicker.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Captura del Formato de Permiso oficial: pregunta solo lo necesario.
 *  - Permiso para faltar → fecha + días.
 *  - Salir temprano → fecha + hora de salida.
 *  - Llegar tarde → fecha + hora de entrada.
 *  - Tipo: con goce / sin goce / especial (+ causal solo si es especial).
 * Las opciones vienen del backend (SolicitudesService::camposPermiso()).
 */
type Opcion = { value: string; label: string };

const props = defineProps<{
    opcionesTipo: Opcion[];
    opcionesGoce: Opcion[];
    opcionesCausal: Opcion[];
    errores: Record<string, string | undefined>;
}>();

const permisoTipo = defineModel<string>('permisoTipo', { required: true });
const permisoGoce = defineModel<string>('permisoGoce', { required: true });
const permisoCausal = defineModel<string>('permisoCausal', { required: true });
const fechaInicio = defineModel<string>('fechaInicio', { required: true });
const duracionDias = defineModel<string>('duracionDias', { required: true });
const horaSalida = defineModel<string>('horaSalida', { required: true });
const horaEntrada = defineModel<string>('horaEntrada', { required: true });

const ICONOS: Record<string, typeof Clock3> = {
    faltar: CalendarX2,
    salir_temprano: LogOut,
    llegar_tarde: Clock3,
};

const esEspecial = computed(() => permisoGoce.value === 'especial');

function elegirGoce(valor: string) {
    permisoGoce.value = valor;

    if (valor !== 'especial') {
        permisoCausal.value = '';
    }
}

const clasesOpcion = (activa: boolean) =>
    activa
        ? 'border-primary bg-primary/5 ring-1 ring-primary'
        : 'border-border/70 hover:border-primary/30 hover:bg-muted/50';

const tieneTipo = computed(() => props.opcionesTipo.some((o) => o.value === permisoTipo.value));
</script>

<template>
    <div class="grid gap-5">
        <section class="grid gap-2">
            <Label>Permiso solicitado</Label>
            <div class="grid gap-2 sm:grid-cols-3">
                <button
                    v-for="opcion in opcionesTipo"
                    :key="opcion.value"
                    type="button"
                    class="flex items-center gap-3 rounded-2xl border p-3 text-left transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="clasesOpcion(permisoTipo === opcion.value)"
                    :aria-pressed="permisoTipo === opcion.value"
                    @click="permisoTipo = opcion.value"
                >
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <component :is="ICONOS[opcion.value] ?? Clock3" class="size-4" />
                    </span>
                    <span class="text-sm font-semibold">{{ opcion.label }}</span>
                </button>
            </div>
            <InputError :message="errores.permiso_tipo" />
        </section>

        <section v-if="tieneTipo" class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="permiso-fecha">Fecha del permiso</Label>
                <DatePicker id="permiso-fecha" v-model="fechaInicio" />
                <InputError :message="errores.fecha_inicio" />
            </div>
            <div v-if="permisoTipo === 'faltar'" class="grid gap-2">
                <Label for="permiso-dias">Días</Label>
                <Input id="permiso-dias" v-model="duracionDias" type="number" min="1" max="30" inputmode="numeric" />
                <InputError :message="errores.duracion_dias" />
            </div>
            <div v-else-if="permisoTipo === 'salir_temprano'" class="grid gap-2">
                <Label for="permiso-hora-salida">Hora de salida</Label>
                <TimePicker id="permiso-hora-salida" v-model="horaSalida" placeholder="Hora de salida" />
                <InputError :message="errores.hora_salida" />
            </div>
            <div v-else class="grid gap-2">
                <Label for="permiso-hora-entrada">Hora de entrada</Label>
                <TimePicker id="permiso-hora-entrada" v-model="horaEntrada" placeholder="Hora de entrada" />
                <InputError :message="errores.hora_entrada" />
            </div>
        </section>

        <section class="grid gap-2">
            <Label>Tipo de permiso</Label>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="opcion in opcionesGoce"
                    :key="opcion.value"
                    type="button"
                    class="rounded-full border px-4 py-2 text-sm font-medium transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="clasesOpcion(permisoGoce === opcion.value)"
                    :aria-pressed="permisoGoce === opcion.value"
                    @click="elegirGoce(opcion.value)"
                >
                    {{ opcion.label }}
                </button>
            </div>
            <InputError :message="errores.permiso_goce" />
        </section>

        <section
            v-if="esEspecial"
            class="grid gap-2 rounded-2xl border border-oro/40 bg-warning-soft/40 p-4"
        >
            <Label class="flex items-center gap-1.5"><Sparkles class="size-3.5 text-oro" /> Causal del permiso especial</Label>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="opcion in opcionesCausal"
                    :key="opcion.value"
                    type="button"
                    class="rounded-full border px-4 py-2 text-sm font-medium transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="clasesOpcion(permisoCausal === opcion.value)"
                    :aria-pressed="permisoCausal === opcion.value"
                    @click="permisoCausal = opcion.value"
                >
                    {{ opcion.label }}
                </button>
            </div>
            <InputError :message="errores.permiso_causal" />
        </section>
    </div>
</template>
