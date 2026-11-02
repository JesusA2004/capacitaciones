<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Building, Loader2, Pencil, Plus, Tags } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import PeopleConfirmDialog from '@/components/people/PeopleConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SelectorVista from '@/components/ui/SelectorVista.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { numero, plural, tonoClasificacion } from '@/lib/rotacion';
import {
    store as guardarCategoriaRuta,
    update as actualizarCategoriaRuta,
} from '@/routes/reportes/rotacion/tablero/categorias';
import { mapeo as mapeoRuta } from '@/routes/reportes/rotacion/tablero/causales';
import type {
    CatalogoHomologacionRotacion,
    CategoriaHomologadaRotacion,
    ClasificacionRotacion,
    MapeoCausalCatalogoRotacion,
    OpcionesRotacion,
} from '@/types';

/**
 * Homologación de causales (bajas.gestionar): categorías para la estadística
 * y a cuál pertenece cada causal del catálogo administrativo. Todo cambio
 * pide comentario y avisa cuántas bajas se reclasifican.
 */
const props = defineProps<{
    catalogo: CatalogoHomologacionRotacion | undefined;
    opciones: OpcionesRotacion;
}>();

const abierto = defineModel<boolean>('open', { required: true });

const cargando = ref(false);

function cargar(): void {
    router.reload({
        only: ['catalogo'],
        onStart: () => {
            cargando.value = true;
        },
        onFinish: () => {
            cargando.value = false;
        },
    });
}

watch(abierto, (si) => {
    if (si && props.catalogo === undefined) {
        cargar();
    }
});

type Pestana = 'mapeo' | 'categorias';
const pestana = ref<Pestana>('mapeo');
const pestanas = computed(() => [
    {
        valor: 'mapeo' as const,
        etiqueta: 'Causales del catálogo',
        contador:
            props.catalogo?.mapeo.filter((m) => m.causal_analitica_id === null)
                .length ?? 0,
    },
    { valor: 'categorias' as const, etiqueta: 'Causales homologadas' },
]);

const opcionesCategoria = computed(() =>
    (props.catalogo?.categorias ?? [])
        .filter((c) => c.activo)
        .map((c) => ({
            value: c.id,
            label: c.nombre,
            descripcion: c.clasificacion_etiqueta,
        })),
);

function nombreCategoria(id: number | null): string {
    return (
        props.catalogo?.categorias.find((c) => c.id === id)?.nombre ??
        'Sin homologar'
    );
}

/* --- Mapeo -------------------------------------------------------------- */

const cambioMapeo = ref<{
    fila: MapeoCausalCatalogoRotacion;
    nuevo: number | null;
} | null>(null);
const comentarioMapeo = ref('');
const errorMapeo = ref('');
const guardandoMapeo = ref(false);

const dialogoMapeo = computed({
    get: () => cambioMapeo.value !== null,
    set: (v: boolean) => {
        if (!v) {
            cambioMapeo.value = null;
        }
    },
});

function pedirMapeo(
    fila: MapeoCausalCatalogoRotacion,
    nuevo: number | null,
): void {
    if (nuevo === fila.causal_analitica_id) {
        return;
    }

    cambioMapeo.value = { fila, nuevo };
    comentarioMapeo.value = '';
    errorMapeo.value = '';
}

function guardarMapeo(): void {
    const c = cambioMapeo.value;

    if (!c) {
        return;
    }

    router.put(
        mapeoRuta.url(c.fila.id),
        { causal_analitica_id: c.nuevo, comentario: comentarioMapeo.value },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                guardandoMapeo.value = true;
            },
            onFinish: () => {
                guardandoMapeo.value = false;
            },
            onError: (e) => {
                errorMapeo.value = e.comentario ?? Object.values(e)[0] ?? '';
            },
            onSuccess: () => {
                cambioMapeo.value = null;
                cargar();
            },
        },
    );
}

/* --- Categorías --------------------------------------------------------- */

const editando = ref<CategoriaHomologadaRotacion | null>(null);
const formAbierto = ref(false);
const form = useForm({
    nombre: '',
    clasificacion: 'voluntaria' as ClasificacionRotacion,
    es_estructural: false,
    activo: true,
    descripcion: '',
    comentario: '',
});

function nuevaCategoria(): void {
    editando.value = null;
    form.reset();
    form.clearErrors();
    formAbierto.value = true;
}

function editarCategoria(c: CategoriaHomologadaRotacion): void {
    editando.value = c;
    form.clearErrors();
    form.nombre = c.nombre;
    form.clasificacion = c.clasificacion;
    form.es_estructural = c.es_estructural;
    form.activo = c.activo;
    form.descripcion = c.descripcion ?? '';
    form.comentario = '';
    formAbierto.value = true;
}

function guardarCategoria(): void {
    const opciones = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            formAbierto.value = false;
            cargar();
        },
    };

    if (editando.value) {
        form.put(actualizarCategoriaRuta.url(editando.value.id), opciones);
    } else {
        form.post(guardarCategoriaRuta.url(), opciones);
    }
}

const cambiaClasificacion = computed(
    () =>
        editando.value !== null &&
        (editando.value.clasificacion !== form.clasificacion ||
            editando.value.es_estructural !== form.es_estructural),
);
</script>

<template>
    <Sheet v-model:open="abierto">
        <SheetContent class="w-full sm:max-w-3xl">
            <SheetHeader>
                <SheetTitle class="flex items-center gap-2">
                    <Tags class="size-4 text-bronce" />
                    Homologación de causales
                </SheetTitle>
                <SheetDescription>
                    La causal homologada es solo para la estadística: no cambia
                    la forma de salida ni los documentos de la baja.
                </SheetDescription>
            </SheetHeader>

            <div class="flex flex-col gap-4 px-4 pb-6">
                <p
                    v-if="catalogo === undefined || cargando"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" /> Cargando catálogo…
                </p>

                <template v-if="catalogo">
                    <SelectorVista
                        v-model="pestana"
                        :opciones="pestanas"
                        etiqueta="Partes de la homologación"
                    />

                    <!-- Mapeo -->
                    <ul
                        v-if="pestana === 'mapeo'"
                        class="flex flex-col divide-y divide-border/50 rounded-2xl border border-border/60"
                    >
                        <li
                            v-for="m in catalogo.mapeo"
                            :key="m.id"
                            class="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    {{ m.nombre }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ m.forma }} ·
                                    {{ plural(m.bajas, 'baja', 'bajas') }}
                                </p>
                            </div>
                            <SelectSimple
                                :model-value="m.causal_analitica_id"
                                :opciones="opcionesCategoria"
                                numerico
                                opcion-vacia="Sin homologar"
                                placeholder="Sin homologar"
                                class="w-full sm:w-64"
                                :aria-label="`Causal homologada de ${m.nombre}`"
                                @update:model-value="(v) => pedirMapeo(m, v)"
                            />
                        </li>
                        <li
                            v-if="!catalogo.mapeo.length"
                            class="p-4 text-center text-sm text-muted-foreground"
                        >
                            El catálogo de causales está vacío.
                        </li>
                    </ul>

                    <!-- Categorías -->
                    <template v-else>
                        <Button class="w-fit" @click="nuevaCategoria">
                            <Plus class="size-4" />Nueva causal homologada
                        </Button>
                        <ul class="grid gap-2 sm:grid-cols-2">
                            <li
                                v-for="c in catalogo.categorias"
                                :key="c.id"
                                class="flex flex-col gap-1.5 rounded-2xl border border-border/60 p-3"
                                :class="!c.activo && 'opacity-60'"
                            >
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <p class="text-sm font-medium">
                                        {{ c.nombre }}
                                    </p>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8 shrink-0"
                                        :aria-label="`Editar ${c.nombre}`"
                                        @click="editarCategoria(c)"
                                    >
                                        <Pencil class="size-3.5" />
                                    </Button>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <ChipEstado
                                        :etiqueta="c.clasificacion_etiqueta"
                                        :tono="
                                            tonoClasificacion(c.clasificacion)
                                        "
                                    />
                                    <ChipEstado
                                        v-if="c.es_estructural"
                                        etiqueta="Estructural"
                                        tono="info"
                                        :icono="Building"
                                    />
                                    <ChipEstado
                                        v-if="!c.activo"
                                        etiqueta="Inactiva"
                                        tono="neutro"
                                    />
                                </div>
                                <p
                                    v-if="c.descripcion"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ c.descripcion }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{
                                        plural(
                                            c.causales_catalogo,
                                            'causal del catálogo',
                                            'causales del catálogo',
                                        )
                                    }}
                                </p>
                            </li>
                        </ul>
                    </template>
                </template>
            </div>
        </SheetContent>
    </Sheet>

    <PeopleConfirmDialog
        v-model:open="dialogoMapeo"
        titulo="Cambiar homologación"
        :descripcion="
            cambioMapeo
                ? `«${cambioMapeo.fila.nombre}»: ${nombreCategoria(cambioMapeo.fila.causal_analitica_id)} → ${nombreCategoria(cambioMapeo.nuevo)}. Se reclasifican ${numero(cambioMapeo.fila.bajas)} ${cambioMapeo.fila.bajas === 1 ? 'baja' : 'bajas'} del sistema que no tienen corrección propia.`
                : undefined
        "
        pedir-comentario
        comentario-label="Comentario (queda en la bitácora)"
        v-model:comentario-model-value="comentarioMapeo"
        :comentario-error="errorMapeo"
        :comentario-maximo="1000"
        :cargando="guardandoMapeo"
        texto-confirmar="Guardar homologación"
        @confirm="guardarMapeo"
    />

    <Dialog v-model:open="formAbierto">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    editando
                        ? 'Editar causal homologada'
                        : 'Nueva causal homologada'
                }}</DialogTitle>
                <DialogDescription
                    >Se usa en el tablero, los reportes y las
                    exportaciones.</DialogDescription
                >
            </DialogHeader>
            <form
                class="flex flex-col gap-4"
                @submit.prevent="guardarCategoria"
            >
                <div>
                    <Label for="cat-nombre" class="mb-1.5">Nombre</Label>
                    <Input
                        id="cat-nombre"
                        v-model="form.nombre"
                        maxlength="160"
                    />
                    <InputError :message="form.errors.nombre" />
                </div>
                <div>
                    <Label class="mb-1.5">Clasificación</Label>
                    <SelectSimple
                        v-model="form.clasificacion"
                        :opciones="opciones.clasificaciones"
                        aria-label="Clasificación"
                    />
                    <InputError :message="form.errors.clasificacion" />
                </div>
                <div class="flex flex-col gap-2">
                    <label class="flex items-center gap-2 text-sm">
                        <Casilla v-model="form.es_estructural" />
                        Estructural (reestructura, cierre o fin de contrato)
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <Casilla v-model="form.activo" />
                        Activa (se puede elegir al corregir)
                    </label>
                </div>
                <div>
                    <Label for="cat-descripcion" class="mb-1.5"
                        >Descripción (opcional)</Label
                    >
                    <Textarea
                        id="cat-descripcion"
                        v-model="form.descripcion"
                        rows="2"
                        maxlength="500"
                    />
                    <InputError :message="form.errors.descripcion" />
                </div>
                <p
                    v-if="cambiaClasificacion && editando"
                    class="rounded-xl bg-warning-soft px-3 py-2 text-xs text-warning"
                >
                    Cambiar la clasificación reclasifica las bajas de
                    {{
                        plural(
                            editando.causales_catalogo,
                            'causal del catálogo',
                            'causales del catálogo',
                        )
                    }}
                    y las corregidas a esta causal.
                </p>
                <div>
                    <Label for="cat-comentario" class="mb-1.5"
                        >Comentario (queda en la bitácora)</Label
                    >
                    <Textarea
                        id="cat-comentario"
                        v-model="form.comentario"
                        rows="2"
                        maxlength="1000"
                    />
                    <InputError :message="form.errors.comentario" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="formAbierto = false"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            form.nombre.trim().length < 3 ||
                            form.comentario.trim().length < 5
                        "
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
