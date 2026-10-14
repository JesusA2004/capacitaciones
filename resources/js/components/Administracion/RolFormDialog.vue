<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import RolController from '@/actions/App/Http/Controllers/Administracion/RolController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { etiquetaModulo, etiquetaPermiso } from '@/lib/permisos';

export type PermisoItem = {
    id: number;
    name: string;
};

export type RolItem = {
    id: number;
    nombre: string;
    permisos: string[];
    usuarios_count: number;
    es_protegido: boolean;
};

/**
 * Módulo con acceso + alcance + acciones (config/permisos_modulos.php).
 * El formulario sigue enviando la lista plana de permisos: el modelo por
 * módulo es solo una forma clara de elegirlos.
 */
export type ModuloPermisos = {
    etiqueta: string;
    grupo: string;
    acceso: string;
    alcances: Partial<Record<NivelAlcance, string>>;
    acciones: Record<string, string>;
};

type NivelAlcance = 'todos' | 'region' | 'sucursal' | 'equipo';

const NIVELES: { valor: NivelAlcance | 'propios'; etiqueta: string }[] = [
    { valor: 'propios', etiqueta: 'Propios' },
    { valor: 'equipo', etiqueta: 'Equipo' },
    { valor: 'sucursal', etiqueta: 'Sucursal' },
    { valor: 'region', etiqueta: 'Región' },
    { valor: 'todos', etiqueta: 'Todos' },
];

const ORDEN: NivelAlcance[] = ['todos', 'region', 'sucursal', 'equipo'];

const props = defineProps<{
    open: boolean;
    rol?: RolItem | null;
    permisosAgrupados: Record<string, PermisoItem[]>;
    modulos?: Record<string, ModuloPermisos>;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    nombre: props.rol?.nombre ?? '',
    permisos: [...(props.rol?.permisos ?? [])] as string[],
});

const mostrarOtros = ref(false);

const listaModulos = computed(() =>
    Object.entries(props.modulos ?? {}).map(([clave, modulo]) => ({
        clave,
        ...modulo,
    })),
);

const gruposModulos = computed(() => {
    const grupos = new Map<string, typeof listaModulos.value>();

    for (const modulo of listaModulos.value) {
        grupos.set(modulo.grupo, [...(grupos.get(modulo.grupo) ?? []), modulo]);
    }

    return [...grupos.entries()];
});

/** Permisos que ya se eligen por módulo (no se repiten en "Otros"). */
const permisosDeModulos = computed(() => {
    const set = new Set<string>();

    for (const modulo of listaModulos.value) {
        set.add(modulo.acceso);
        Object.values(modulo.alcances).forEach((p) => p && set.add(p));
        Object.keys(modulo.acciones).forEach((p) => set.add(p));
    }

    return set;
});

const otrosAgrupados = computed(() => {
    const resultado: Record<string, PermisoItem[]> = {};

    for (const [modulo, permisos] of Object.entries(props.permisosAgrupados)) {
        const restantes = permisos.filter(
            (p) => !permisosDeModulos.value.has(p.name),
        );

        if (restantes.length > 0) {
            resultado[modulo] = restantes;
        }
    }

    return resultado;
});

function tiene(nombre: string): boolean {
    return form.permisos.includes(nombre);
}

function alternarPermiso(nombre: string, marcado: boolean) {
    if (marcado) {
        if (!form.permisos.includes(nombre)) {
            form.permisos.push(nombre);
        }
    } else {
        form.permisos = form.permisos.filter((permiso) => permiso !== nombre);
    }
}

function alternarModulo(permisosModulo: PermisoItem[], marcado: boolean) {
    permisosModulo.forEach((permiso) => alternarPermiso(permiso.name, marcado));
}

function moduloCompleto(permisosModulo: PermisoItem[]): boolean {
    return permisosModulo.every((permiso) =>
        form.permisos.includes(permiso.name),
    );
}

function nivelActual(modulo: ModuloPermisos): NivelAlcance | 'propios' {
    for (const nivel of ORDEN) {
        const permiso = modulo.alcances[nivel];

        if (permiso && tiene(permiso)) {
            return nivel;
        }
    }

    return 'propios';
}

function cambiarNivel(modulo: ModuloPermisos, valor: unknown) {
    Object.values(modulo.alcances).forEach(
        (permiso) => permiso && alternarPermiso(permiso, false),
    );

    const permiso = modulo.alcances[valor as NivelAlcance];

    if (permiso) {
        alternarPermiso(permiso, true);
    }
}

function alternarAcceso(modulo: ModuloPermisos, marcado: boolean) {
    alternarPermiso(modulo.acceso, marcado);

    // Sin acceso no hay alcance ni acciones que conservar.
    if (!marcado) {
        cambiarNivel(modulo, 'propios');
        Object.keys(modulo.acciones).forEach((p) => alternarPermiso(p, false));
    }
}

function nivelesDisponibles(modulo: ModuloPermisos) {
    return NIVELES.filter(
        (n) => n.valor === 'propios' || modulo.alcances[n.valor] !== undefined,
    );
}

function enviar() {
    if (props.rol) {
        form.put(RolController.update.url(String(props.rol.id)), {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
    } else {
        form.post(RolController.store.url(), {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>{{
                    rol ? 'Editar rol' : 'Nuevo rol'
                }}</DialogTitle>
                <DialogDescription>
                    Para cada módulo elige si el rol entra, qué registros ve
                    (alcance) y qué puede hacer. Ver todo no permite aprobar:
                    las acciones se dan aparte.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-6" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="nombre">Nombre del rol</Label>
                    <Input
                        id="nombre"
                        v-model="form.nombre"
                        placeholder="por ejemplo: coordinador_capacitacion"
                        :disabled="rol?.es_protegido"
                    />
                    <InputError :message="form.errors.nombre" />
                </div>

                <div
                    v-for="[grupo, modulosGrupo] in gruposModulos"
                    :key="grupo"
                    class="space-y-2"
                >
                    <p
                        class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ grupo }}
                    </p>

                    <div
                        v-for="modulo in modulosGrupo"
                        :key="modulo.clave"
                        class="rounded-md border p-3"
                        :data-modulo="modulo.clave"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <label
                                class="flex items-center gap-2 text-sm font-medium"
                            >
                                <Checkbox
                                    :model-value="tiene(modulo.acceso)"
                                    @update:model-value="
                                        (valor) =>
                                            alternarAcceso(modulo, !!valor)
                                    "
                                />
                                {{ modulo.etiqueta }}
                                <span
                                    class="text-xs font-normal text-muted-foreground"
                                    >Acceso</span
                                >
                            </label>

                            <div
                                v-if="Object.keys(modulo.alcances).length > 0"
                                class="flex items-center gap-2 text-sm"
                            >
                                <span class="text-muted-foreground"
                                    >Alcance</span
                                >
                                <NativeSelect
                                    :model-value="nivelActual(modulo)"
                                    :disabled="!tiene(modulo.acceso)"
                                    :aria-label="`Alcance de ${modulo.etiqueta}`"
                                    @update:model-value="
                                        (valor) => cambiarNivel(modulo, valor)
                                    "
                                >
                                    <NativeSelectOption
                                        v-for="nivel in nivelesDisponibles(
                                            modulo,
                                        )"
                                        :key="nivel.valor"
                                        :value="nivel.valor"
                                    >
                                        {{ nivel.etiqueta }}
                                    </NativeSelectOption>
                                </NativeSelect>
                            </div>
                        </div>

                        <div
                            v-if="Object.keys(modulo.acciones).length > 0"
                            class="mt-3 flex flex-wrap gap-x-4 gap-y-2"
                        >
                            <label
                                v-for="(etiqueta, permiso) in modulo.acciones"
                                :key="permiso"
                                class="flex items-center gap-2 text-sm"
                                :class="{
                                    'opacity-50': !tiene(modulo.acceso),
                                }"
                                :title="permiso"
                            >
                                <Checkbox
                                    :model-value="tiene(permiso)"
                                    :disabled="!tiene(modulo.acceso)"
                                    @update:model-value="
                                        (valor) =>
                                            alternarPermiso(permiso, !!valor)
                                    "
                                />
                                {{ etiqueta }}
                            </label>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <Button
                        type="button"
                        variant="ghost"
                        class="gap-2 px-0"
                        @click="mostrarOtros = !mostrarOtros"
                    >
                        <ChevronDown
                            class="size-4 transition-transform"
                            :class="{ 'rotate-180': mostrarOtros }"
                        />
                        Otros permisos (catálogos, configuración, app móvil…)
                    </Button>

                    <template v-if="mostrarOtros || listaModulos.length === 0">
                        <div
                            v-for="(permisosModulo, modulo) in otrosAgrupados"
                            :key="modulo"
                            class="rounded-md border p-3"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-sm font-medium">{{
                                    etiquetaModulo(modulo)
                                }}</span>
                                <label
                                    class="flex items-center gap-2 text-xs text-muted-foreground"
                                    title="Marca o quita todos los permisos de este módulo a la vez"
                                >
                                    <Checkbox
                                        :model-value="
                                            moduloCompleto(permisosModulo)
                                        "
                                        @update:model-value="
                                            (valor) =>
                                                alternarModulo(
                                                    permisosModulo,
                                                    !!valor,
                                                )
                                        "
                                    />
                                    Seleccionar todo
                                </label>
                            </div>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <label
                                    v-for="permiso in permisosModulo"
                                    :key="permiso.id"
                                    class="flex items-center gap-2 text-sm"
                                    :title="permiso.name"
                                >
                                    <Checkbox
                                        :model-value="tiene(permiso.name)"
                                        @update:model-value="
                                            (valor) =>
                                                alternarPermiso(
                                                    permiso.name,
                                                    !!valor,
                                                )
                                        "
                                    />
                                    {{ etiquetaPermiso(permiso.name) }}
                                </label>
                            </div>
                        </div>
                    </template>
                    <InputError :message="form.errors.permisos" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
