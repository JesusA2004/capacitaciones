<script setup lang="ts">
import { Presentation, RotateCcw, Video, Volume2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AsignarDialog from '@/components/onboarding/AsignarDialog.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    previsualizar,
    store,
} from '@/routes/rh/onboarding/induccion/asignaciones';
import type { ColaboradorBusqueda } from '@/types';

/**
 * «Asignar inducción»: qué asignar (presentación del PDF oficial y/o el
 * video/audio de explicación), fecha límite y mensaje opcionales, y a
 * quién (AsignarDialog). AsignacionInduccionService decide si es un
 * onboarding de ingreso (se ajusta) o una reinducción (personal activo).
 */
const props = withDefaults(
    defineProps<{
        /** Tipo del material vigente, o null si no hay. */
        material: 'audio' | 'video' | null;
        contenidoInicial?: 'ambos' | 'presentacion' | 'material';
        preseleccion?: ColaboradorBusqueda[];
    }>(),
    { contenidoInicial: 'ambos', preseleccion: () => [] },
);

const abierto = defineModel<boolean>('open', { default: false });

const presentacion = ref(true);
const conMaterial = ref(true);
const fechaLimite = ref('');
const mensaje = ref('');
const reasignar = ref(false);

watch(abierto, (v) => {
    if (v) {
        presentacion.value = props.contenidoInicial !== 'material';
        conMaterial.value =
            props.material !== null &&
            props.contenidoInicial !== 'presentacion';
        fechaLimite.value = '';
        mensaje.value = '';
        reasignar.value = false;
    }
});

const hoy = new Date().toISOString().slice(0, 10);

const extra = computed(() => ({
    incluye_presentacion: presentacion.value,
    incluye_material: conMaterial.value && props.material !== null,
    fecha_limite: fechaLimite.value || null,
    mensaje: mensaje.value.trim() || null,
    reasignar_aprobadas: reasignar.value,
}));

const bloqueado = computed(() =>
    !presentacion.value && !(conMaterial.value && props.material !== null)
        ? 'Elige qué asignar: la presentación, el video/audio o ambos.'
        : null,
);
</script>

<template>
    <AsignarDialog
        v-model:open="abierto"
        titulo="Asignar inducción"
        descripcion="La persona la verá de inmediato en «Mi inducción» (Mi espacio y la app) y le llegará un aviso. Al terminar presenta su evaluación FO-RH-EI."
        :previsualizar-url="previsualizar.url()"
        :enviar-url="store.url()"
        :extra="extra"
        :preseleccion="preseleccion"
        :bloqueado="bloqueado"
        texto-boton="Asignar inducción"
    >
        <template #opciones>
            <fieldset class="flex flex-col gap-2">
                <legend class="mb-2 text-sm font-semibold">
                    ¿Qué le asignas?
                </legend>
                <label
                    class="flex cursor-pointer items-start gap-3 rounded-2xl border border-border/60 p-3 hover:bg-muted/40"
                >
                    <Checkbox
                        :model-value="presentacion"
                        class="mt-0.5"
                        @update:model-value="(v) => (presentacion = v === true)"
                    />
                    <Presentation class="mt-0.5 size-4 shrink-0 text-primary" />
                    <span>
                        <span class="block text-sm font-medium"
                            >Presentación (PDF oficial)</span
                        >
                        <span class="block text-xs text-muted-foreground"
                            >Debe ver todas las hojas antes de su
                            evaluación.</span
                        >
                    </span>
                </label>
                <label
                    class="flex items-start gap-3 rounded-2xl border border-border/60 p-3"
                    :class="
                        props.material === null
                            ? 'opacity-70'
                            : 'cursor-pointer hover:bg-muted/40'
                    "
                    :aria-disabled="props.material === null"
                >
                    <Checkbox
                        :model-value="conMaterial && props.material !== null"
                        :disabled="props.material === null"
                        class="mt-0.5"
                        @update:model-value="(v) => (conMaterial = v === true)"
                    />
                    <Video
                        v-if="props.material !== 'audio'"
                        class="mt-0.5 size-4 shrink-0 text-bronce"
                    />
                    <Volume2
                        v-else
                        class="mt-0.5 size-4 shrink-0 text-bronce"
                    />
                    <span>
                        <span class="block text-sm font-medium">{{
                            props.material === 'audio'
                                ? 'Audio de explicación'
                                : 'Video de explicación'
                        }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            props.material === null
                                ? 'No hay video ni audio cargado. Súbelo en «Material de explicación».'
                                : props.material === 'video'
                                  ? 'Obligatorio: lo ve completo, sin adelantar, para desbloquear la evaluación.'
                                  : 'Narración que acompaña las hojas (no bloquea la evaluación).'
                        }}</span>
                    </span>
                </label>
            </fieldset>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <Label for="asignar-fecha">Fecha límite (opcional)</Label>
                    <Input
                        id="asignar-fecha"
                        v-model="fechaLimite"
                        type="date"
                        :min="hoy"
                    />
                    <p class="text-xs text-muted-foreground">
                        Se le muestra en su tarjeta «Mi inducción».
                    </p>
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="asignar-mensaje"
                        >Mensaje para la persona (opcional)</Label
                    >
                    <Textarea
                        id="asignar-mensaje"
                        v-model="mensaje"
                        rows="2"
                        maxlength="1000"
                        placeholder="Ej. Revísala antes de tu primer día en sucursal."
                    />
                </div>
            </div>

            <label
                class="flex cursor-pointer items-start gap-3 rounded-2xl bg-crema/60 p-3"
            >
                <Checkbox
                    :model-value="reasignar"
                    class="mt-0.5"
                    @update:model-value="(v) => (reasignar = v === true)"
                />
                <RotateCcw class="mt-0.5 size-4 shrink-0 text-bronce" />
                <span>
                    <span class="block text-sm font-medium">Reinducción</span>
                    <span class="block text-xs text-muted-foreground"
                        >Volver a asignarla también a quien ya la aprobó. Se
                        abre como reinducción: no cambia su contratación ni su
                        estado laboral.</span
                    >
                </span>
            </label>
        </template>
    </AsignarDialog>
</template>
