<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Building2, Briefcase, ClipboardCheck, GraduationCap, Package, PlayCircle, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import RadioMarca from '@/components/Common/RadioMarca.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import onboarding from '@/routes/rh/onboarding';

type Pregunta = { pregunta: string; opciones: string[]; correcta: number };
type Modulo = {
    id: number;
    titulo: string;
    descripcion: string | null;
    tipo: string;
    puesto_id: number | null;
    puesto: string | null;
    orden: number;
    contenido_url: string | null;
    contenido: string | null;
    preguntas: Pregunta[];
    calificacion_minima: number;
    obligatorio: boolean;
    activo: boolean;
};
type TipoActivo = {
    id: number;
    clave: string;
    nombre: string;
    requiere_identificador: boolean;
    puesto_ids: number[] | null;
    obligatorio: boolean;
    activo: boolean;
};

const props = defineProps<{
    modulos: Modulo[];
    tiposActivo: TipoActivo[];
    puestos: { id: number; nombre: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Onboarding', href: '' },
        ],
    },
});

const editandoModulo = ref<number | 'nuevo' | null>(null);
const modulo = useForm({
    titulo: '',
    descripcion: '',
    tipo: 'institucional',
    puesto_id: null as number | null,
    orden: 1,
    contenido_url: '',
    contenido: '',
    preguntas: [] as Pregunta[],
    calificacion_minima: 8,
    obligatorio: true,
    activo: true,
});

function editarModulo(m: Modulo | null) {
    modulo.clearErrors();

    if (m === null) {
        modulo.reset();
        modulo.preguntas = [{ pregunta: '', opciones: ['', ''], correcta: 0 }];
        editandoModulo.value = 'nuevo';

        return;
    }

    Object.assign(modulo, {
        ...m,
        descripcion: m.descripcion ?? '',
        contenido_url: m.contenido_url ?? '',
        contenido: m.contenido ?? '',
        preguntas: JSON.parse(JSON.stringify(m.preguntas)),
    });
    editandoModulo.value = m.id;
}

function guardarModulo() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => (editandoModulo.value = null),
    };

    if (editandoModulo.value === 'nuevo') {
        modulo.post(onboarding.modulos.store.url(), opciones);
    } else if (typeof editandoModulo.value === 'number') {
        modulo.put(
            onboarding.modulos.update.url(editandoModulo.value),
            opciones,
        );
    }
}

const activo = useForm({
    clave: '',
    nombre: '',
    requiere_identificador: false,
    obligatorio: true,
    activo: true,
    puesto_ids: [] as number[],
});
const editandoActivo = ref<number | 'nuevo' | null>(null);

function editarActivo(t: TipoActivo | null) {
    activo.clearErrors();
    activo.reset();

    if (t !== null) {
        Object.assign(activo, { ...t, puesto_ids: t.puesto_ids ?? [] });
    }

    editandoActivo.value = t?.id ?? 'nuevo';
}

function guardarActivo() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => (editandoActivo.value = null),
    };

    if (editandoActivo.value === 'nuevo') {
        activo.post(onboarding.tiposActivo.store.url(), opciones);
    } else if (typeof editandoActivo.value === 'number') {
        activo.put(
            onboarding.tiposActivo.update.url(editandoActivo.value),
            opciones,
        );
    }
}

void props;
</script>

<template>
    <Head title="Onboarding" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Onboarding"
            descripcion="Secciones de capacitación (institucional y por puesto, mínimo 8) y el contenido que se entrega con carta responsiva."
            :icono="GraduationCap"
        >
            <Button @click="editarModulo(null)"
                ><Plus class="size-4" /> Sección de capacitación</Button
            >
            <Button variant="secondary" @click="editarActivo(null)"
                ><Plus class="size-4" /> Tipo de contenido</Button
            >
        </CrudPageHeader>

        <form
            v-if="editandoModulo !== null"
            class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-petroleo)]/30 bg-[var(--mrl-superficie)] p-5"
            @submit.prevent="guardarModulo"
        >
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label>Título</Label
                    ><Input v-model="modulo.titulo" /><InputError
                        :message="modulo.errors.titulo"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Tipo</Label>
                    <SelectSimple
                        v-model="modulo.tipo"
                        :opciones="[
                            { value: 'institucional', label: 'Institucional' },
                            { value: 'puesto', label: 'Al puesto' },
                        ]"
                    />
                </div>
                <div v-if="modulo.tipo === 'puesto'" class="grid gap-1.5">
                    <Label>Puesto</Label>
                    <SelectSimple
                        v-model="modulo.puesto_id"
                        numerico
                        :opciones="
                            puestos.map((p) => ({
                                value: p.id,
                                label: p.nombre,
                            }))
                        "
                        placeholder="Elige el puesto"
                    />
                    <InputError :message="modulo.errors.puesto_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label>Orden</Label
                    ><Input
                        v-model.number="modulo.orden"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Calificación mínima</Label
                    ><Input
                        v-model.number="modulo.calificacion_minima"
                        type="number"
                        min="0"
                        max="10"
                        step="0.5"
                    />
                </div>
                <div class="grid gap-1.5 sm:col-span-2">
                    <Label>Video / material (URL)</Label
                    ><Input v-model="modulo.contenido_url" type="url" />
                </div>
            </div>
            <div class="grid gap-1.5">
                <Label>Contenido / instrucciones</Label
                ><Textarea v-model="modulo.contenido" rows="3" />
            </div>
            <div class="flex flex-col gap-3">
                <Label>Evaluación</Label>
                <div
                    v-for="(p, i) in modulo.preguntas"
                    :key="i"
                    class="flex flex-col gap-2 rounded-xl bg-[var(--mrl-fondo)] p-3"
                >
                    <div class="flex gap-2">
                        <Input
                            v-model="p.pregunta"
                            :placeholder="`Pregunta ${i + 1}`"
                        /><Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            @click="modulo.preguntas.splice(i, 1)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                    <label
                        v-for="(_, j) in p.opciones"
                        :key="j"
                        class="flex items-center gap-2 text-sm"
                    >
                        <RadioMarca
                            v-model="p.correcta"
                            :value="j"
                            :name="`correcta-${i}`"
                        />
                        <Input
                            v-model="p.opciones[j]"
                            :placeholder="`Opción ${j + 1}`"
                        />
                    </label>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="w-fit"
                        @click="p.opciones.push('')"
                        >+ opción</Button
                    >
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="w-fit"
                    @click="
                        modulo.preguntas.push({
                            pregunta: '',
                            opciones: ['', ''],
                            correcta: 0,
                        })
                    "
                    >+ pregunta</Button
                >
                <InputError :message="modulo.errors.preguntas" />
            </div>
            <label class="flex items-center gap-2 text-sm"
                ><Casilla v-model="modulo.activo" /> Activo</label
            >
            <div class="flex gap-2">
                <Button type="submit" :disabled="modulo.processing"
                    >Guardar</Button
                ><Button
                    type="button"
                    variant="ghost"
                    @click="editandoModulo = null"
                    >Cancelar</Button
                >
            </div>
        </form>

        <form
            v-if="editandoActivo !== null"
            class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-petroleo)]/30 bg-[var(--mrl-superficie)] p-5"
            @submit.prevent="guardarActivo"
        >
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label>Nombre</Label
                    ><Input v-model="activo.nombre" /><InputError
                        :message="activo.errors.nombre"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Clave</Label
                    ><Input
                        v-model="activo.clave"
                        placeholder="uniforme"
                    /><InputError :message="activo.errors.clave" />
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm"
                ><Casilla v-model="activo.requiere_identificador" /> Requiere
                serie / identificador</label
            >
            <label class="flex items-center gap-2 text-sm"
                ><Casilla v-model="activo.obligatorio" /> Obligatorio para
                cerrar el onboarding</label
            >
            <fieldset class="grid gap-1 text-sm">
                <legend class="mb-1 font-medium">
                    Aplica a puestos (vacío = todos)
                </legend>
                <label
                    v-for="p in puestos"
                    :key="p.id"
                    class="flex items-center gap-2"
                    ><Casilla v-model="activo.puesto_ids" :value="p.id" />
                    {{ p.nombre }}</label
                >
            </fieldset>
            <div class="flex gap-2">
                <Button type="submit" :disabled="activo.processing"
                    >Guardar</Button
                ><Button
                    type="button"
                    variant="ghost"
                    @click="editandoActivo = null"
                    >Cancelar</Button
                >
            </div>
        </form>

        <section data-tour="onboarding-modulos" class="flex flex-col gap-3">
            <div>
                <h2 class="text-base font-semibold">Secciones de capacitación</h2>
                <p class="text-sm text-muted-foreground">
                    Cada sección tiene su material y una evaluación con
                    calificación mínima.
                </p>
            </div>
            <p
                v-if="!modulos.length"
                class="rounded-2xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
            >
                Todavía no hay secciones de capacitación.
            </p>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="m in modulos"
                    :key="m.id"
                    type="button"
                    class="group flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 text-left transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                    :class="m.activo ? '' : 'opacity-60'"
                    @click="editarModulo(m)"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                        >
                            <component
                                :is="m.tipo === 'institucional' ? Building2 : Briefcase"
                                class="size-5"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold text-muted-foreground">
                                Sección {{ m.orden }} ·
                                {{
                                    m.tipo === 'institucional'
                                        ? 'Institucional'
                                        : (m.puesto ?? 'Al puesto')
                                }}
                            </p>
                            <p class="truncate font-semibold">{{ m.titulo }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span
                            class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5"
                            ><ClipboardCheck class="size-3.5" />
                            {{ m.preguntas.length }} preguntas</span
                        >
                        <span
                            class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5"
                            >Mínimo {{ m.calificacion_minima }}</span
                        >
                        <span
                            v-if="m.contenido_url"
                            class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5"
                            ><PlayCircle class="size-3.5" /> Material</span
                        >
                        <span
                            v-if="!m.activo"
                            class="rounded-full bg-destructive/10 px-2 py-0.5 text-destructive"
                            >Inactiva</span
                        >
                    </div>
                </button>
            </div>
        </section>

        <section data-tour="onboarding-activos" class="flex flex-col gap-3">
            <div>
                <h2 class="text-base font-semibold">Tipos de contenido</h2>
                <p class="text-sm text-muted-foreground">
                    Uniforme, equipo o material que se entrega al colaborador
                    con carta responsiva.
                </p>
            </div>
            <p
                v-if="!tiposActivo.length"
                class="rounded-2xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
            >
                Todavía no hay tipos de contenido.
            </p>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <button
                    v-for="t in tiposActivo"
                    :key="t.id"
                    type="button"
                    class="flex items-start gap-3 rounded-2xl border border-border bg-card p-4 text-left transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                    :class="t.activo ? '' : 'opacity-60'"
                    @click="editarActivo(t)"
                >
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-secondary text-secondary-foreground"
                    >
                        <Package class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold">{{ t.nombre }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ t.obligatorio ? 'Obligatorio' : 'Opcional'
                            }}{{ t.requiere_identificador ? ' · con serie' : ''
                            }}{{
                                t.puesto_ids?.length
                                    ? ` · ${t.puesto_ids.length} puesto(s)`
                                    : ' · todos los puestos'
                            }}
                        </p>
                    </div>
                </button>
            </div>
        </section>
    </div>
</template>
