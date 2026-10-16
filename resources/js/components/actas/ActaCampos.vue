<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import TimePicker from '@/components/Common/TimePicker.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { testigos as buscarTestigos } from '@/routes/rh/actas';
import type { ColaboradorBusqueda, FormularioActa } from '@/types';

/**
 * Lo que se captura al levantar un acta (PRO-ADP-08) y va al formato
 * oficial: motivo (encabezado «ACTA ADMINISTRATIVA POR …»), fecha, hora,
 * lugar, hechos, dos testigos y las manifestaciones. Nombre, puesto y
 * contrato del colaborador los pone el sistema.
 */
/** El formulario (useForm) del padre, por v-model: se editan sus campos. */
const form = defineModel<FormularioActa>({ required: true });

const props = defineProps<{
    /** Errores de validación del formulario (form.errors). */
    errores: Partial<Record<string, string>>;
    /** El motivo solo es obligatorio en el acta administrativa. */
    pideMotivo: boolean;
    /** Colaborador sujeto del acta: ningún testigo puede ser esta persona. */
    sujetoId?: number | null;
}>();

function error(campo: string): string | undefined {
    return props.errores[campo];
}

/** Snapshot guardado → forma que entiende BuscadorColaborador (solo para mostrar el chip elegido). */
function testigoModelo(i: number): ColaboradorBusqueda | null {
    const t = form.value.testigos[i];

    return t?.colaborador_id
        ? {
              id: t.colaborador_id,
              nombre: t.nombre,
              numero_empleado: null,
              puesto: t.puesto || null,
              sucursal: null,
          }
        : null;
}

function elegirTestigo(i: number, c: ColaboradorBusqueda | null) {
    form.value.testigos[i] = c
        ? { colaborador_id: c.id, nombre: c.nombre, puesto: c.puesto ?? '' }
        : { colaborador_id: null, nombre: '', puesto: '' };
}

function errorTestigo(i: number): string | null {
    const t = form.value.testigos[i];

    if (!t?.colaborador_id) {
        return null;
    }

    if (props.sujetoId && t.colaborador_id === props.sujetoId) {
        return 'No puede ser el colaborador del acta.';
    }

    const otro = form.value.testigos[i === 0 ? 1 : 0];

    if (otro?.colaborador_id === t.colaborador_id) {
        return 'Ya es el otro testigo.';
    }

    return null;
}

function agregarManifestacion() {
    form.value.declaraciones.push({ persona: '', declaracion: '' });
}

/** «No quiso manifestar nada»: redacción jurídica fija, nunca texto libre del operador. */
const FRASE_NO_MANIFESTO = 'No quiso manifestar nada.';

type ModoManifestacion = 'sin_elegir' | 'capturar' | 'no_manifesto';

function modoDeclaracion(i: number): ModoManifestacion {
    const texto = form.value.declaraciones[i]?.declaracion ?? '';

    if (texto === FRASE_NO_MANIFESTO) {
        return 'no_manifesto';
    }

    if (texto.trim() !== '') {
        return 'capturar';
    }

    return 'sin_elegir';
}

function elegirModo(i: number, modo: 'capturar' | 'no_manifesto') {
    const actual = form.value.declaraciones[i];

    if (!actual) {
        return;
    }

    if (modo === 'no_manifesto') {
        actual.declaracion = FRASE_NO_MANIFESTO;
    } else if (actual.declaracion === FRASE_NO_MANIFESTO) {
        actual.declaracion = '';
    }
}

const hoy = computed(() => new Date());
</script>

<template>
    <div class="flex flex-col gap-4">
        <div v-if="pideMotivo" class="flex flex-col gap-1.5">
            <Label for="acta-motivo">Motivo del acta</Label>
            <Input
                id="acta-motivo"
                v-model="form.motivo"
                maxlength="191"
                placeholder="Ej. faltas injustificadas los días 6 y 7 de octubre"
            />
            <p class="text-xs text-muted-foreground">
                Va en el encabezado: «ACTA ADMINISTRATIVA POR …».
            </p>
            <InputError :message="error('motivo')" />
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="flex flex-col gap-1.5">
                <Label for="acta-fecha">Fecha</Label>
                <DatePicker
                    id="acta-fecha"
                    v-model="form.fecha"
                    :max-value="hoy.toISOString().slice(0, 10)"
                />
                <InputError :message="error('fecha')" />
            </div>
            <div class="flex flex-col gap-1.5">
                <Label for="acta-hora">Hora</Label>
                <TimePicker id="acta-hora" v-model="form.hora" />
                <InputError :message="error('hora')" />
            </div>
            <div class="flex flex-col gap-1.5">
                <Label for="acta-lugar">Ciudad</Label>
                <Input
                    id="acta-lugar"
                    v-model="form.lugar"
                    placeholder="Ciudad donde se levanta el acta"
                />
                <InputError :message="error('lugar')" />
            </div>
        </div>

        <div class="flex flex-col gap-1.5">
            <Label for="acta-hechos">Hechos</Label>
            <Textarea
                id="acta-hechos"
                v-model="form.hechos"
                rows="6"
                placeholder="Qué pasó, cuándo y dónde, en orden. Si son varios, numéralos (PRIMERO, SEGUNDO…)."
            />
            <InputError :message="error('hechos')" />
        </div>

        <fieldset class="flex flex-col gap-3">
            <legend class="mb-1 text-sm font-medium">Testigos</legend>
            <p class="text-xs text-muted-foreground">
                El formato oficial lleva exactamente dos testigos que firman:
                colaboradores activos, distintos entre sí y distintos del
                colaborador del acta.
            </p>
            <div
                v-for="(t, i) in form.testigos"
                :key="i"
                class="flex flex-col gap-1.5"
            >
                <Label>Testigo {{ i + 1 }}</Label>
                <!-- Al abrir: activos de tu sucursal. Escribe nombre, número,
                     puesto o sucursal («Orizaba»); RH/corporativo buscan en
                     toda la organización (alcance del módulo de actas). -->
                <BuscadorColaborador
                    :model-value="testigoModelo(i)"
                    :buscar-url="
                        (texto: string) =>
                            buscarTestigos.url({
                                query: {
                                    q: texto,
                                    ...(sujetoId ? { excluir: sujetoId } : {}),
                                },
                            })
                    "
                    @update:model-value="(c) => elegirTestigo(i, c)"
                />
                <p
                    v-if="t.colaborador_id && !errorTestigo(i)"
                    class="text-xs text-muted-foreground"
                >
                    Cargo: {{ t.puesto || 'Sin puesto asignado' }}
                </p>
                <p v-if="errorTestigo(i)" class="text-xs text-destructive">
                    {{ errorTestigo(i) }}
                </p>
                <InputError :message="error(`testigos.${i}.colaborador_id`)" />
            </div>
        </fieldset>

        <fieldset class="flex flex-col gap-2">
            <legend class="mb-1 text-sm font-medium">Manifestaciones</legend>
            <p class="text-xs text-muted-foreground">
                Lo que dice cada persona (empezando por el colaborador).
            </p>
            <div
                v-for="(d, i) in form.declaraciones"
                :key="i"
                class="flex flex-col gap-2 rounded-xl border border-[var(--mrl-borde)] p-3"
            >
                <div class="flex items-center gap-2">
                    <Input
                        v-model="d.persona"
                        :aria-label="`Manifestación ${i + 1}: quién`"
                        placeholder="Quién manifiesta"
                    />
                    <Button
                        v-if="form.declaraciones.length > 1"
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Quitar manifestación ${i + 1}`"
                        @click="form.declaraciones.splice(i, 1)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            modoDeclaracion(i) === 'capturar'
                                ? 'default'
                                : 'outline'
                        "
                        @click="elegirModo(i, 'capturar')"
                    >
                        Capturar manifestación
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            modoDeclaracion(i) === 'no_manifesto'
                                ? 'default'
                                : 'outline'
                        "
                        @click="elegirModo(i, 'no_manifesto')"
                    >
                        No quiso manifestar nada
                    </Button>
                </div>

                <Textarea
                    v-if="modoDeclaracion(i) === 'capturar'"
                    v-model="d.declaracion"
                    :aria-label="`Manifestación ${i + 1}: qué dijo`"
                    rows="3"
                    placeholder="Qué manifestó"
                />
                <p
                    v-else-if="modoDeclaracion(i) === 'no_manifesto'"
                    class="text-xs text-muted-foreground"
                >
                    Se registrará: «{{ FRASE_NO_MANIFESTO }}»
                </p>

                <InputError
                    :message="
                        error(`declaraciones.${i}.persona`) ??
                        error(`declaraciones.${i}.declaracion`)
                    "
                />
            </div>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="self-start"
                @click="agregarManifestacion"
            >
                <Plus class="size-4" /> Agregar manifestación
            </Button>
        </fieldset>
    </div>
</template>
