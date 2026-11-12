<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ImagePlus, Images, Power, RefreshCw, Save, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import SeccionesDocumentosMaestros from '@/components/documentos/maestros/SeccionesDocumentosMaestros.vue';
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { useAccionEnCurso } from '@/composables/useAccionEnCurso';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { index as indexMaestros } from '@/routes/rh/documentos-maestros';
import {
    destroy,
    index,
    reemplazar,
    store,
    update,
} from '@/routes/rh/documentos-maestros/fondos';
import type { FondoDocumento, PresetLayout } from '@/types';

const accion = useAccionEnCurso();

/**
 * Biblioteca de fondos de página de los documentos maestros. Un fondo en
 * uso nunca se sobrescribe: «Reemplazar» crea una versión nueva (los
 * documentos ya generados siguen ligados a la anterior, por hash).
 */
defineProps<{
    fondos: FondoDocumento[];
    presets: PresetLayout[];
    puedeEditar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Documentos maestros', href: indexMaestros() },
            { title: 'Fondos', href: index() },
        ],
    },
});

const { confirmarAccion, confirmarEliminacion } = useAlertas();

const opcionesAjuste = [
    { value: 'stretch', label: 'Llenar página (estirar)' },
    { value: 'contain', label: 'Ajustar' },
    { value: 'cover', label: 'Cubrir' },
];

const opcionesTipo = [
    { value: 'background', label: 'Fondo de página' },
    { value: 'logo', label: 'Logo' },
    { value: 'stamp', label: 'Sello' },
    { value: 'watermark', label: 'Marca de agua' },
    { value: 'image', label: 'Imagen' },
];

// --- Alta --------------------------------------------------------------------
const dialogoNuevo = ref(false);
const formNuevo = useForm({
    nombre: '',
    tipo: 'background',
    archivo: null as File | null,
    fit_mode: 'stretch',
    default_opacity: 100,
    safe_area_top_mm: '' as string | number,
    safe_area_right_mm: '' as string | number,
    safe_area_bottom_mm: '' as string | number,
    safe_area_left_mm: '' as string | number,
});

/** La zona de arrastre trabaja con una lista; el formulario envía un solo archivo. */
const archivoNuevo = computed<File[]>({
    get: () => (formNuevo.archivo ? [formNuevo.archivo] : []),
    set: (archivos) => {
        formNuevo.archivo = archivos[0] ?? null;
    },
});

function subir() {
    formNuevo.post(store.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            dialogoNuevo.value = false;
            formNuevo.reset();
        },
    });
}

// --- Edición -----------------------------------------------------------------
const editando = ref<FondoDocumento | null>(null);
const formEditar = useForm({
    nombre: '',
    fit_mode: 'stretch',
    default_opacity: 100,
    activo: true,
    safe_area_top_mm: '' as string | number,
    safe_area_right_mm: '' as string | number,
    safe_area_bottom_mm: '' as string | number,
    safe_area_left_mm: '' as string | number,
});

function abrirEdicion(f: FondoDocumento) {
    editando.value = f;
    formEditar.nombre = f.nombre;
    formEditar.fit_mode = f.fit_mode;
    formEditar.default_opacity = f.default_opacity;
    formEditar.activo = f.activo;
    formEditar.safe_area_top_mm = f.safe_area?.top ?? '';
    formEditar.safe_area_right_mm = f.safe_area?.right ?? '';
    formEditar.safe_area_bottom_mm = f.safe_area?.bottom ?? '';
    formEditar.safe_area_left_mm = f.safe_area?.left ?? '';
}

function guardarEdicion() {
    if (!editando.value) {
        return;
    }

    formEditar.put(update.url(editando.value.id), {
        preserveScroll: true,
        onSuccess: () => (editando.value = null),
    });
}

async function alternarActivo(f: FondoDocumento) {
    if (
        f.activo &&
        !(await confirmarAccion(
            'Desactivar fondo',
            'Deja de ofrecerse para diseños nuevos. Los diseños y documentos que ya lo usan no cambian.',
            'Desactivar',
        ))
    ) {
        return;
    }

    accion.ejecutar('alternarActivo', (estado) => {
        router.put(
            update.url(f.id),
            { activo: !f.activo },
            { ...estado, preserveScroll: true },
        );
    });
}

// --- Reemplazo (versión nueva) -----------------------------------------------
const inputReemplazo = ref<HTMLInputElement | null>(null);
const reemplazando = ref<FondoDocumento | null>(null);

function elegirReemplazo(f: FondoDocumento) {
    reemplazando.value = f;
    inputReemplazo.value?.click();
}

async function alElegirArchivo(evento: Event) {
    const archivo = (evento.target as HTMLInputElement).files?.[0];
    const f = reemplazando.value;
    (evento.target as HTMLInputElement).value = '';

    if (!archivo || !f) {
        return;
    }

    if (
        !(await confirmarAccion(
            'Reemplazar fondo',
            `Se crea la versión ${f.version + 1} de «${f.nombre}» y los diseños que usan esta versión pasan a la nueva. Los documentos ya generados conservan el fondo anterior.`,
            'Reemplazar',
        ))
    ) {
        return;
    }

    accion.ejecutar('alElegirArchivo', (estado) => {
        router.post(
            reemplazar.url(f.id),
            { archivo },
            { forceFormData: true, ...estado, preserveScroll: true },
        );
    });
}

async function eliminar(f: FondoDocumento) {
    if (!(await confirmarEliminacion(`el fondo «${f.nombre}»`))) {
        return;
    }

    accion.ejecutar('eliminar', (estado) => {
        router.delete(destroy.url(f.id), { ...estado, preserveScroll: true });
    });
}
</script>

<template>
    <Head title="Fondos de documentos" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Fondos y recursos"
            descripcion="Fondos de página, logos, sellos y marcas de agua para los documentos maestros y administrativos. Reemplazar un recurso crea otra versión: los documentos ya generados conservan el anterior."
            :icono="Images"
        >
            <Button v-if="puedeEditar" @click="dialogoNuevo = true">
                <ImagePlus class="size-4" />
                Añadir recurso
            </Button>
        </CrudPageHeader>

        <SeccionesDocumentosMaestros actual="recursos" />

        <EmptyState
            v-if="fondos.length === 0"
            :icono="Images"
            titulo="Sin fondos"
            descripcion="Sube un PNG, JPG o WEBP del tamaño de la hoja."
            class="rounded-2xl border border-dashed border-border/60 bg-card"
        />

        <div
            v-else
            class="grid entrada-escalonada gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
        >
            <article
                v-for="f in fondos"
                :key="f.id"
                class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 transition-shadow hover:shadow-md"
                :class="{ 'opacity-60': !f.activo }"
            >
                <div
                    class="relative mx-auto aspect-[8.5/11] w-full max-w-[12rem] overflow-hidden rounded border border-border bg-white"
                >
                    <img
                        :src="f.url_imagen"
                        :alt="f.nombre"
                        class="h-full w-full object-fill"
                    />
                    <div
                        v-if="f.safe_area"
                        class="absolute border border-dashed border-warning/50"
                        :style="{
                            top: `${((f.safe_area.top ?? 0) / 279.4) * 100}%`,
                            bottom: `${((f.safe_area.bottom ?? 0) / 279.4) * 100}%`,
                            left: `${((f.safe_area.left ?? 0) / 215.9) * 100}%`,
                            right: `${((f.safe_area.right ?? 0) / 215.9) * 100}%`,
                        }"
                    />
                </div>
                <div>
                    <p class="font-semibold">{{ f.nombre }}</p>
                    <p class="text-xs text-muted-foreground">
                        v{{ f.version }} · {{ f.width }}×{{ f.height }} px ·
                        {{ f.activo ? 'Activo' : 'Inactivo' }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        En uso: {{ f.en_uso.presets }} preset(s),
                        {{ f.en_uso.familias }} familia(s),
                        {{ f.en_uso.documentos }} documento(s) generados
                    </p>
                </div>
                <div v-if="puedeEditar" class="mt-auto flex flex-wrap gap-1">
                    <Button
                        size="sm"
                        variant="outline"
                        @click="abrirEdicion(f)"
                    >
                        <Save class="size-4" />
                        Editar
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="elegirReemplazo(f)"
                    >
                        <RefreshCw class="size-4" />
                        Reemplazar
                    </Button>
                    <Button
                        :disabled="accion.ocupado.value"
                        size="sm"
                        variant="ghost"
                        @click="alternarActivo(f)"
                    >
                        <Power class="size-4" />
                        {{ f.activo ? 'Desactivar' : 'Activar' }}
                    </Button>
                    <Button
                        :disabled="accion.ocupado.value"
                        v-if="f.puede_eliminar"
                        size="sm"
                        variant="ghost"
                        class="text-destructive hover:text-destructive"
                        :aria-label="`Eliminar ${f.nombre}`"
                        @click="eliminar(f)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </article>
        </div>

        <section
            v-if="presets.length"
            class="rounded-2xl border border-border/60 bg-card p-5"
        >
            <h2 class="mb-3 text-base font-semibold">Presets de diseño</h2>
            <ul class="flex flex-col gap-2 text-sm">
                <li v-for="p in presets" :key="p.id">
                    <span class="font-medium">{{ p.nombre }}</span>
                    <span class="text-muted-foreground">
                        — {{ p.familias.length }} familia(s):
                        {{ p.familias.join(', ') || 'ninguna' }}</span
                    >
                </li>
            </ul>
        </section>
    </div>

    <input
        ref="inputReemplazo"
        type="file"
        accept="image/png,image/jpeg,image/webp"
        class="hidden"
        tabindex="-1"
        aria-hidden="true"
        @change="alElegirArchivo"
    />

    <!-- Nuevo fondo -->
    <Dialog v-model:open="dialogoNuevo">
        <DialogContent class="max-h-[92dvh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Añadir fondo</DialogTitle>
                <DialogDescription>
                    PNG, JPG o WEBP del tamaño de la hoja (carta vertical:
                    proporción 8.5 × 11).
                </DialogDescription>
            </DialogHeader>
            <div class="flex flex-col gap-3">
                <div class="grid gap-1.5">
                    <Label for="fondo-nombre">Nombre</Label>
                    <Input
                        id="fondo-nombre"
                        v-model="formNuevo.nombre"
                        placeholder="MR LANA — Responsivas"
                    />
                    <InputError :message="formNuevo.errors.nombre" />
                </div>
                <div class="grid gap-1.5">
                    <Label>Tipo de recurso</Label>
                    <SelectSimple
                        v-model="formNuevo.tipo"
                        :opciones="opcionesTipo"
                    />
                    <InputError :message="formNuevo.errors.tipo" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="fondo-archivo">Archivo</Label>
                    <PeopleFileDropzone
                        id="fondo-archivo"
                        v-model="archivoNuevo"
                        accept=".png,.jpg,.jpeg,.webp"
                        :max-size-mb="20"
                        label="Arrastra la imagen aquí"
                        vista-previa
                        :loading="formNuevo.processing"
                        :progreso="formNuevo.progress?.percentage ?? null"
                    />
                    <InputError :message="formNuevo.errors.archivo" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label>Ajuste</Label>
                        <SelectSimple
                            v-model="formNuevo.fit_mode"
                            :opciones="opcionesAjuste"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Opacidad (%)</Label>
                        <Input
                            v-model="formNuevo.default_opacity"
                            type="number"
                            min="0"
                            max="100"
                        />
                    </div>
                </div>
                <p
                    class="text-xs font-semibold text-muted-foreground uppercase"
                >
                    Área segura (mm desde cada orilla)
                </p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <Input
                        v-model="formNuevo.safe_area_top_mm"
                        type="number"
                        min="0"
                        placeholder="Arriba"
                        aria-label="Área segura: arriba (mm)"
                    />
                    <Input
                        v-model="formNuevo.safe_area_right_mm"
                        type="number"
                        min="0"
                        placeholder="Derecha"
                        aria-label="Área segura: derecha (mm)"
                    />
                    <Input
                        v-model="formNuevo.safe_area_bottom_mm"
                        type="number"
                        min="0"
                        placeholder="Abajo"
                        aria-label="Área segura: abajo (mm)"
                    />
                    <Input
                        v-model="formNuevo.safe_area_left_mm"
                        type="number"
                        min="0"
                        placeholder="Izquierda"
                        aria-label="Área segura: izquierda (mm)"
                    />
                </div>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="dialogoNuevo = false"
                    >Cancelar</Button
                >
                <Button
                    :disabled="formNuevo.processing || !formNuevo.archivo"
                    @click="subir"
                >
                    <Spinner v-if="formNuevo.processing" />
                    Subir
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Editar fondo -->
    <Dialog
        :open="editando !== null"
        @update:open="(v: boolean) => !v && (editando = null)"
    >
        <DialogContent class="max-h-[92dvh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Editar «{{ editando?.nombre }}»</DialogTitle>
                <DialogDescription>
                    Cambia nombre, ajuste, opacidad o área segura. Para cambiar
                    la imagen usa «Reemplazar» (crea una versión nueva).
                </DialogDescription>
            </DialogHeader>
            <div class="flex flex-col gap-3">
                <div class="grid gap-1.5">
                    <Label for="fondo-editar-nombre">Nombre</Label>
                    <Input
                        id="fondo-editar-nombre"
                        v-model="formEditar.nombre"
                    />
                    <InputError :message="formEditar.errors.nombre" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label>Ajuste</Label>
                        <SelectSimple
                            v-model="formEditar.fit_mode"
                            :opciones="opcionesAjuste"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Opacidad (%)</Label>
                        <Input
                            v-model="formEditar.default_opacity"
                            type="number"
                            min="0"
                            max="100"
                        />
                    </div>
                </div>
                <p
                    class="text-xs font-semibold text-muted-foreground uppercase"
                >
                    Área segura (mm desde cada orilla)
                </p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <Input
                        v-model="formEditar.safe_area_top_mm"
                        type="number"
                        min="0"
                        placeholder="Arriba"
                        aria-label="Área segura: arriba (mm)"
                    />
                    <Input
                        v-model="formEditar.safe_area_right_mm"
                        type="number"
                        min="0"
                        placeholder="Derecha"
                        aria-label="Área segura: derecha (mm)"
                    />
                    <Input
                        v-model="formEditar.safe_area_bottom_mm"
                        type="number"
                        min="0"
                        placeholder="Abajo"
                        aria-label="Área segura: abajo (mm)"
                    />
                    <Input
                        v-model="formEditar.safe_area_left_mm"
                        type="number"
                        min="0"
                        placeholder="Izquierda"
                        aria-label="Área segura: izquierda (mm)"
                    />
                </div>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="editando = null"
                    >Cancelar</Button
                >
                <Button
                    :disabled="formEditar.processing"
                    @click="guardarEdicion"
                    >Guardar</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
