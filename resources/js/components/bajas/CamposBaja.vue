<script setup lang="ts">
import { AlertTriangle, Info } from '@lucide/vue';
import { computed } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { claseForma, diasEntre, sumarDiasHabiles } from '@/lib/bajas';
import { cn } from '@/lib/utils';
import type {
    CamposBaja,
    FormaSalidaClave,
    FormaSalidaOpcion,
    LimitesBaja,
} from '@/types';

/**
 * Bloques 2 y 3 de la solicitud de baja (especificación de RH): «¿Cómo se
 * va?» con tres tarjetas grandes y «¿Por qué se va?» con la causal de esa
 * forma, su ayuda debajo (RN-02, RN-03), comentario (obligatorio en RV-05 y
 * rescisión, RN-04), último día laborado, ¿recontratable? y, en rescisión,
 * la fecha en que se conoció la falta y la entrega del aviso (RN-07/RN-08).
 * Lo usan la pantalla del gerente y el atajo «Iniciar baja» del ciclo
 * laboral; las reglas reales las valida el backend.
 */
const props = withDefaults(
    defineProps<{
        formas: FormaSalidaOpcion[];
        recontratable: { value: string; label: string }[];
        entregas: { value: string; label: string }[];
        limites: LimitesBaja;
        errores?: Partial<Record<string, string>>;
        fechaIngreso?: string | null;
        /** RN-09: en rescisión solo RH cambia «¿Recontratable?». */
        esRh?: boolean;
        disabled?: boolean;
        /** El atajo de RH no exige recontratable/rescisión al aplicar. */
        etiquetaFecha?: string;
        /** Numera los bloques (2 y 3) como en la solicitud del gerente. */
        numerar?: boolean;
    }>(),
    {
        errores: () => ({}),
        fechaIngreso: null,
        esRh: false,
        disabled: false,
        etiquetaFecha: 'Último día laborado',
        numerar: false,
    },
);

const modelo = defineModel<CamposBaja>({ required: true });

const forma = computed(
    () =>
        props.formas.find((f) => f.value === modelo.value.forma_salida) ?? null,
);
const causal = computed(
    () =>
        forma.value?.causales.find(
            (c) => c.id === modelo.value.causal_rotacion_id,
        ) ?? null,
);
const esRescision = computed(() => modelo.value.forma_salida === 'RE');
const comentarioObligatorio = computed(
    () => esRescision.value || Boolean(causal.value?.comentario_obligatorio),
);
const opcionesCausal = computed(() =>
    (forma.value?.causales ?? []).map((c) => ({
        value: c.id,
        label: c.clave ? `${c.clave} · ${c.nombre}` : c.nombre,
    })),
);
const largoComentario = computed(() => modelo.value.comentarios.trim().length);

// RN-02: al cambiar la forma se limpia la causal. RN-09: rescisión → «No».
function elegirForma(valor: FormaSalidaClave) {
    if (props.disabled || modelo.value.forma_salida === valor) {
        return;
    }

    modelo.value.forma_salida = valor;
    modelo.value.causal_rotacion_id = null;

    if (valor === 'RE') {
        modelo.value.recontratable = 'no';
    } else {
        modelo.value.fecha_conocimiento_falta = '';
        modelo.value.entrega_aviso_rescision = '';
        modelo.value.fecha_entrega_aviso = '';
    }
}

const recontratableBloqueado = computed(() => esRescision.value && !props.esRh);

// Avisos en pantalla (el backend vuelve a calcularlos).
const avisoPrescripcion = computed(
    () =>
        esRescision.value &&
        Boolean(modelo.value.fecha_conocimiento_falta) &&
        Boolean(modelo.value.fecha_baja) &&
        diasEntre(
            modelo.value.fecha_conocimiento_falta,
            modelo.value.fecha_baja,
        ) > props.limites.dias_prescripcion,
);
const avisoTribunal = computed(
    () =>
        esRescision.value &&
        modelo.value.entrega_aviso_rescision === 'ante_tribunal' &&
        Boolean(modelo.value.fecha_baja) &&
        Boolean(modelo.value.fecha_entrega_aviso) &&
        modelo.value.fecha_entrega_aviso >
            sumarDiasHabiles(
                modelo.value.fecha_baja,
                props.limites.dias_habiles_tribunal,
            ),
);
const avisoTemprana = computed(
    () =>
        Boolean(props.fechaIngreso) &&
        Boolean(modelo.value.fecha_baja) &&
        diasEntre(props.fechaIngreso as string, modelo.value.fecha_baja) < 90,
);

const claseRadio = (activo: boolean) =>
    cn(
        'inline-flex h-10 min-w-0 items-center justify-center rounded-xl border px-2 text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50 disabled:opacity-60',
        activo
            ? 'border-primary bg-primary text-primary-foreground'
            : 'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] hover:bg-muted',
    );
</script>

<template>
    <section class="grid gap-3">
        <h3 class="text-base font-semibold">
            {{ numerar ? '2 · ' : '' }}¿Cómo se va?
        </h3>
        <div
            class="grid gap-3 md:grid-cols-3"
            role="radiogroup"
            aria-label="Forma de salida"
        >
            <button
                v-for="f in formas"
                :key="f.value"
                type="button"
                role="radio"
                :aria-checked="modelo.forma_salida === f.value"
                :disabled="disabled"
                :class="
                    cn(
                        'flex tarjeta-interactiva flex-col gap-1 rounded-2xl border-2 bg-[var(--mrl-superficie)] p-5 text-left',
                        modelo.forma_salida === f.value
                            ? cn(
                                  claseForma(f.token).borde,
                                  'bg-crema/40 shadow-sm',
                              )
                            : 'border-[var(--mrl-borde)]',
                    )
                "
                @click="elegirForma(f.value)"
            >
                <span class="flex items-center gap-2">
                    <span
                        :class="
                            cn(
                                'size-3 shrink-0 rounded-full',
                                claseForma(f.token).fondo,
                            )
                        "
                        aria-hidden="true"
                    />
                    <span class="text-lg font-semibold">{{ f.etiqueta }}</span>
                </span>
                <span class="text-sm text-[var(--mrl-texto-suave)]"
                    >«{{ f.resumen }}»</span
                >
            </button>
        </div>
        <InputError :message="errores.forma_salida" />
        <InputError :message="errores.tipo_baja" />
    </section>

    <section class="grid gap-4">
        <h3 class="text-base font-semibold">
            {{ numerar ? '3 · ' : '' }}¿Por qué se va?
        </h3>

        <div class="grid gap-1.5">
            <Label for="baja-causal">Causal</Label>
            <SelectSimple
                id="baja-causal"
                v-model="modelo.causal_rotacion_id"
                :opciones="opcionesCausal"
                numerico
                :disabled="disabled || !forma"
                :placeholder="
                    forma ? 'Elige la causal…' : 'Primero elige cómo se va'
                "
                aria-label="Causal de la baja"
            />
            <p
                v-if="causal?.ayuda"
                class="flex items-start gap-1.5 rounded-xl bg-[var(--mrl-menta)] px-3 py-2 text-sm"
            >
                <Info class="mt-0.5 size-4 shrink-0 text-primary" />
                <span>{{ causal.ayuda }}</span>
            </p>
            <p
                v-if="causal?.alerta"
                class="flex items-start gap-1.5 rounded-xl bg-warning-soft px-3 py-2 text-sm text-warning"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <span>{{ causal.alerta }}</span>
            </p>
            <p
                v-if="causal?.requiere_juridico"
                class="text-xs text-[var(--mrl-texto-suave)]"
            >
                Esta causal pasa por revisión de Jurídico antes del cierre.
            </p>
            <InputError :message="errores.causal_rotacion_id" />
        </div>

        <div class="grid gap-1.5">
            <Label for="baja-comentarios"
                >Comentarios
                {{
                    comentarioObligatorio
                        ? `(obligatorio, mínimo ${limites.comentario_minimo} caracteres)`
                        : '(opcional)'
                }}</Label
            >
            <Textarea
                id="baja-comentarios"
                v-model="modelo.comentarios"
                rows="3"
                :maxlength="limites.comentario_maximo"
                :disabled="disabled"
                placeholder="Lo que pasó, acuerdos, contexto…"
            />
            <p
                v-if="comentarioObligatorio"
                :class="
                    cn(
                        'text-xs',
                        largoComentario < limites.comentario_minimo
                            ? 'text-warning'
                            : 'text-[var(--mrl-texto-suave)]',
                    )
                "
            >
                {{ largoComentario }} / mínimo {{ limites.comentario_minimo }}
            </p>
            <InputError :message="errores.comentarios ?? errores.motivo" />
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="grid gap-1.5">
                <Label for="baja-fecha">{{ etiquetaFecha }}</Label>
                <DatePicker
                    id="baja-fecha"
                    v-model="modelo.fecha_baja"
                    :disabled="disabled"
                    :min-value="fechaIngreso ?? undefined"
                />
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    No antes del ingreso ni más de
                    {{ limites.dias_futuro_maximo }} días en el futuro.
                </p>
                <InputError
                    :message="errores.fecha_baja ?? errores.fecha_efectiva"
                />
            </div>
            <div class="grid gap-1.5">
                <Label>¿Recontratable?</Label>
                <div
                    class="grid grid-cols-3 gap-2"
                    role="radiogroup"
                    aria-label="¿Recontratable?"
                >
                    <button
                        v-for="r in recontratable"
                        :key="r.value"
                        type="button"
                        role="radio"
                        :aria-checked="modelo.recontratable === r.value"
                        :disabled="disabled || recontratableBloqueado"
                        :class="claseRadio(modelo.recontratable === r.value)"
                        @click="modelo.recontratable = r.value"
                    >
                        {{ r.label }}
                    </button>
                </div>
                <p
                    v-if="recontratableBloqueado"
                    class="text-xs text-[var(--mrl-texto-suave)]"
                >
                    En rescisión es «No»; solo RH puede cambiarlo.
                </p>
                <InputError :message="errores.recontratable" />
            </div>
        </div>

        <p
            v-if="avisoTemprana"
            class="flex items-start gap-1.5 rounded-xl bg-info-soft px-3 py-2 text-sm"
        >
            <Info class="mt-0.5 size-4 shrink-0 text-primary" />
            Rotación temprana: la persona tiene menos de 90 días de antigüedad.
        </p>

        <div
            v-if="esRescision"
            class="grid gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-crema/40 p-4"
        >
            <p class="text-sm font-medium">Datos de la rescisión</p>
            <div class="grid gap-4 md:grid-cols-3">
                <div class="grid gap-1.5">
                    <Label for="baja-conocimiento"
                        >Fecha en que se conoció la falta</Label
                    >
                    <DatePicker
                        id="baja-conocimiento"
                        v-model="modelo.fecha_conocimiento_falta"
                        :disabled="disabled"
                    />
                    <InputError :message="errores.fecha_conocimiento_falta" />
                </div>
                <div class="grid gap-1.5">
                    <Label>Entrega del aviso de rescisión</Label>
                    <div class="flex flex-wrap gap-2" role="radiogroup">
                        <button
                            v-for="e in entregas"
                            :key="e.value"
                            type="button"
                            role="radio"
                            :aria-checked="
                                modelo.entrega_aviso_rescision === e.value
                            "
                            :disabled="disabled"
                            :class="
                                cn(
                                    claseRadio(
                                        modelo.entrega_aviso_rescision ===
                                            e.value,
                                    ),
                                    'min-w-24 px-4',
                                )
                            "
                            @click="modelo.entrega_aviso_rescision = e.value"
                        >
                            {{ e.label }}
                        </button>
                    </div>
                    <InputError :message="errores.entrega_aviso_rescision" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="baja-entrega">Fecha de entrega del aviso</Label>
                    <DatePicker
                        id="baja-entrega"
                        v-model="modelo.fecha_entrega_aviso"
                        :disabled="disabled"
                    />
                    <InputError :message="errores.fecha_entrega_aviso" />
                </div>
            </div>
            <p class="text-xs text-[var(--mrl-texto-suave)]">
                El aviso de rescisión firmado se adjunta en «Documentos».
            </p>
            <p
                v-if="avisoPrescripcion"
                class="flex items-start gap-1.5 rounded-xl bg-danger-soft px-3 py-2 text-sm text-destructive"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                Puede haber prescrito el derecho a rescindir (art. 517 LFT).
                Consulte a Jurídico.
            </p>
            <p
                v-if="avisoTribunal"
                class="flex items-start gap-1.5 rounded-xl bg-warning-soft px-3 py-2 text-sm text-warning"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                El aviso ante el Tribunal se entrega después de
                {{ limites.dias_habiles_tribunal }} días hábiles de la baja: se
                avisará a Jurídico.
            </p>
        </div>
    </section>
</template>
