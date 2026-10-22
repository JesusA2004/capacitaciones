<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    BriefcaseBusiness,
    CheckCircle2,
    Eye,
    Send,
    Target,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import { acusar } from '@/routes/portal/descriptivo-puesto';
import type { DescriptivoPuestoColaborador } from '@/types';

/**
 * Lo que la persona ve de su descriptivo de puesto: las secciones del
 * descriptivo oficial y, para confirmarlo, la casilla obligatoria
 * «Enterado(a)…» + «Estoy de acuerdo con…». La MISMA vista en Mi espacio y
 * en «Vista previa como colaborador» de RH (con `vistaPrevia` no se envía
 * nada). Las validaciones viven en AcuseDescriptivoPuestoService.
 */
const props = withDefaults(
    defineProps<{
        acuse: DescriptivoPuestoColaborador;
        vistaPrevia?: boolean;
    }>(),
    { vistaPrevia: false },
);

const textoCasilla = computed(
    () =>
        props.acuse.texto_casilla_enterado ??
        'Enterado(a) de las actividades y responsabilidades de mi puesto descritas en este documento.',
);

const form = useForm<{ enterado: boolean; conformidad: string }>({
    enterado: false,
    conformidad: '',
});

function enviar() {
    if (props.vistaPrevia || props.acuse.id === null) {
        return;
    }

    form.post(acusar.url(props.acuse.id), { preserveScroll: true });
}

const d = computed(() => props.acuse.descriptivo);
const errorDescriptivo = computed(
    () =>
        (form.errors as Record<string, string | undefined>).descriptivo ?? null,
);
const acusado = computed(() => props.acuse.acusado_en !== null);
</script>

<template>
    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <article
            class="flex min-w-0 flex-col gap-5 rounded-3xl border border-border/60 bg-card p-5 shadow-sm sm:p-6"
        >
            <header class="flex flex-wrap items-start gap-3">
                <span
                    class="flex size-11 items-center justify-center rounded-2xl bg-salvia text-primary"
                >
                    <BriefcaseBusiness class="size-6" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-xl font-semibold">{{ d.nombre_puesto }}</h2>
                    <p class="text-sm text-muted-foreground">
                        Descriptivo de puesto · versión {{ d.version }}
                        <template v-if="d.area"> · {{ d.area }}</template>
                        <template v-if="d.fecha">
                            · revisado {{ d.fecha }}</template
                        >
                    </p>
                </div>
                <ChipEstado
                    :etiqueta="acuse.estado_etiqueta"
                    :tono="
                        acusado
                            ? 'exito'
                            : acuse.estado === 'leido'
                              ? 'info'
                              : 'oro'
                    "
                />
            </header>

            <div
                v-if="d.objetivo"
                class="flex items-start gap-3 rounded-2xl bg-crema/70 p-4"
            >
                <Target class="mt-0.5 size-5 shrink-0 text-bronce" />
                <div>
                    <p class="text-sm font-semibold">Objetivo de tu puesto</p>
                    <p class="text-sm text-pretty">{{ d.objetivo }}</p>
                </div>
            </div>

            <p
                v-if="d.personal_a_cargo"
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <Users class="size-4" /> Personal a cargo:
                {{ d.personal_a_cargo }}
            </p>

            <section
                v-for="s in d.secciones ?? []"
                :key="s.clave"
                class="flex flex-col gap-2"
            >
                <h3 class="text-base font-semibold">{{ s.titulo }}</h3>
                <ul class="flex flex-col gap-1.5">
                    <li
                        v-for="(e, i) in s.elementos"
                        :key="i"
                        class="flex gap-2 text-sm"
                    >
                        <span
                            class="mt-2 size-1.5 shrink-0 rounded-full bg-primary"
                            aria-hidden="true"
                        />
                        <span class="text-pretty">{{ e }}</span>
                    </li>
                </ul>
            </section>
        </article>

        <aside class="flex flex-col gap-4 xl:sticky xl:top-4 xl:self-start">
            <section
                v-if="acusado"
                class="flex flex-col gap-3 rounded-3xl border border-success/40 bg-success-soft/50 p-5"
            >
                <p class="flex items-center gap-2 font-semibold text-success">
                    <CheckCircle2 class="size-5" /> Confirmado el
                    {{ formatearFecha(acuse.acusado_en ?? '') }}
                </p>
                <p class="text-sm">
                    ☑ {{ acuse.enterado_texto ?? textoCasilla }}
                </p>
                <p v-if="acuse.conformidad ?? acuse.comentario" class="text-sm">
                    <span class="font-medium">Estoy de acuerdo con:</span>
                    «{{ acuse.conformidad ?? acuse.comentario }}»
                </p>
            </section>

            <form
                v-else
                class="flex flex-col gap-4 rounded-3xl border border-primary/30 bg-card p-5 shadow-sm"
                @submit.prevent="enviar"
            >
                <div>
                    <h3 class="font-semibold">Confirma que lo leíste</h3>
                    <p class="text-sm text-muted-foreground">
                        Recursos Humanos recibirá tu confirmación. Las dos cosas
                        son obligatorias.
                    </p>
                </div>

                <label
                    class="flex cursor-pointer items-start gap-3 rounded-2xl border border-border/60 p-3"
                >
                    <Checkbox
                        :model-value="form.enterado"
                        class="mt-0.5"
                        :disabled="vistaPrevia"
                        @update:model-value="
                            (v) => (form.enterado = v === true)
                        "
                    />
                    <span class="text-sm"
                        >{{ textoCasilla }}
                        <span class="text-destructive">*</span></span
                    >
                </label>
                <p
                    v-if="form.errors.enterado"
                    class="-mt-2 text-xs text-destructive"
                >
                    {{ form.errors.enterado }}
                </p>

                <div class="flex flex-col gap-1.5">
                    <Label for="conformidad"
                        >Estoy de acuerdo con…
                        <span class="text-destructive">*</span></Label
                    >
                    <Textarea
                        id="conformidad"
                        v-model="form.conformidad"
                        rows="3"
                        maxlength="2000"
                        :disabled="vistaPrevia"
                        :placeholder="
                            acuse.ejemplo_conformidad ??
                            'Ej. Estoy de acuerdo con las funciones, horario y objetivos descritos para mi puesto.'
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Escríbelo con tus palabras: con qué estás de acuerdo de
                        tu puesto.
                    </p>
                    <p
                        v-if="form.errors.conformidad"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.conformidad }}
                    </p>
                    <p v-if="errorDescriptivo" class="text-xs text-destructive">
                        {{ errorDescriptivo }}
                    </p>
                </div>

                <p
                    v-if="vistaPrevia"
                    class="flex items-center gap-2 rounded-xl bg-warning-soft px-3 py-2 text-xs"
                >
                    <Eye class="size-3.5 shrink-0" /> Vista previa: no se envía
                    nada.
                </p>
                <Button
                    type="submit"
                    :disabled="
                        vistaPrevia ||
                        form.processing ||
                        !form.enterado ||
                        form.conformidad.trim() === ''
                    "
                >
                    <Spinner v-if="form.processing" />
                    <Send v-else class="size-4" /> Enviar mi confirmación
                </Button>
            </form>
        </aside>
    </div>
</template>
