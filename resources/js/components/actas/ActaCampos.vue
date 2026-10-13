<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { FormularioActa } from '@/types';

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
}>();

function error(campo: string): string | undefined {
    return props.errores[campo];
}

function agregarTestigo() {
    form.value.testigos.push({ nombre: '', puesto: '' });
}

function agregarManifestacion() {
    form.value.declaraciones.push({ persona: '', declaracion: '' });
}
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
                <Input id="acta-fecha" v-model="form.fecha" type="date" />
                <InputError :message="error('fecha')" />
            </div>
            <div class="flex flex-col gap-1.5">
                <Label for="acta-hora">Hora</Label>
                <Input id="acta-hora" v-model="form.hora" type="time" />
                <InputError :message="error('hora')" />
            </div>
            <div class="flex flex-col gap-1.5">
                <Label for="acta-lugar">Ciudad</Label>
                <Input
                    id="acta-lugar"
                    v-model="form.lugar"
                    placeholder="La de su sucursal"
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

        <fieldset class="flex flex-col gap-2">
            <legend class="mb-1 text-sm font-medium">Testigos</legend>
            <p class="text-xs text-muted-foreground">
                El formato lleva dos testigos que firman.
            </p>
            <div
                v-for="(t, i) in form.testigos"
                :key="i"
                class="grid items-start gap-2 sm:grid-cols-[1fr_1fr_auto]"
            >
                <div class="flex flex-col gap-1">
                    <Input
                        v-model="t.nombre"
                        :aria-label="`Testigo ${i + 1}: nombre`"
                        :placeholder="`Testigo ${i + 1}: nombre completo`"
                    />
                    <InputError :message="error(`testigos.${i}.nombre`)" />
                </div>
                <Input
                    v-model="t.puesto"
                    :aria-label="`Testigo ${i + 1}: cargo`"
                    placeholder="Cargo"
                />
                <Button
                    v-if="form.testigos.length > 2"
                    type="button"
                    variant="ghost"
                    size="icon"
                    :aria-label="`Quitar testigo ${i + 1}`"
                    @click="form.testigos.splice(i, 1)"
                >
                    <Trash2 class="size-4" />
                </Button>
            </div>
            <Button
                v-if="form.testigos.length < 5"
                type="button"
                variant="ghost"
                size="sm"
                class="self-start"
                @click="agregarTestigo"
            >
                <Plus class="size-4" /> Agregar testigo
            </Button>
        </fieldset>

        <fieldset class="flex flex-col gap-2">
            <legend class="mb-1 text-sm font-medium">Manifestaciones</legend>
            <p class="text-xs text-muted-foreground">
                Lo que dice cada persona (empezando por el colaborador). Si no
                quiso manifestar nada, escríbelo así.
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
                <Textarea
                    v-model="d.declaracion"
                    :aria-label="`Manifestación ${i + 1}: qué dijo`"
                    rows="3"
                    placeholder="Qué manifestó"
                />
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
