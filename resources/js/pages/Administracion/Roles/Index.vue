<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ChevronDown,
    Copy,
    GitCompareArrows,
    Info,
    Layers,
    Lock,
    Plus,
    RotateCcw,
    Save,
    Search,
    ShieldCheck,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ClonarRolDialog from '@/components/Administracion/ClonarRolDialog.vue';
import ModuloPermisosCard from '@/components/Administracion/Roles/ModuloPermisosCard.vue';
import NuevoRolDialog from '@/components/Administracion/Roles/NuevoRolDialog.vue';
import PermisoSwitch from '@/components/Administracion/Roles/PermisoSwitch.vue';
import ResumenRol from '@/components/Administracion/Roles/ResumenRol.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { useAlertas } from '@/composables/useAlertas';
import { etiquetaModulo, etiquetaPermiso } from '@/lib/permisos';
import {
    accionesVisibles,
    coincide,
    moduloActivo,
    moduloVisible,
    normalizar,
    permisosDelModulo,
} from '@/lib/rolesPermisos';
import { dashboard } from '@/routes';
import { destroy, update } from '@/routes/administracion/roles';
import type {
    CatalogoPermisos,
    ModuloCatalogo,
    NivelAlcance,
    RolItem,
} from '@/types/roles';

/**
 * «Roles y permisos»: matriz súper específica por rol. Etapas del sidebar
 * → módulos → Acceso, Alcance de datos y CADA acción. Todo lo arma el
 * catálogo del backend (config/permisos_modulos.php), así que un permiso
 * nuevo aparece solo; lo que no tiene módulo se lista en «Otros permisos».
 * La autorización real sigue en el backend (Policies + Spatie).
 */
const props = defineProps<{
    roles: RolItem[];
    catalogo: CatalogoPermisos;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Roles y permisos', href: '/administracion/roles' },
        ],
    },
});

const { confirmarEliminacion, confirmarAccion, mostrarError } = useAlertas();

// ---------------------------------------------------------------- roles
const busquedaRol = ref('');
const rolesFiltrados = computed(() => {
    const filtro = normalizar(busquedaRol.value);

    return props.roles.filter((rol) => coincide(filtro, rol.nombre));
});

const rolId = ref<number | null>(null);
const rol = computed(
    () => props.roles.find((r) => r.id === rolId.value) ?? null,
);
const soloLectura = computed(() => rol.value?.es_protegido ?? true);

const borrador = ref(new Set<string>());
const nombre = ref('');

function cargarBorrador() {
    borrador.value = new Set(rol.value?.permisos ?? []);
    nombre.value = rol.value?.nombre ?? '';
}

const original = computed(() => new Set(rol.value?.permisos ?? []));
const agregados = computed(() =>
    [...borrador.value].filter((p) => !original.value.has(p)),
);
const quitados = computed(() =>
    [...original.value].filter((p) => !borrador.value.has(p)),
);
const cambioNombre = computed(
    () => rol.value !== null && nombre.value.trim() !== rol.value.nombre,
);
const totalCambios = computed(
    () =>
        agregados.value.length +
        quitados.value.length +
        (cambioNombre.value ? 1 : 0),
);

function urlConRol(id: number | null): string {
    const url = new URL(window.location.href);

    if (id === null) {
        url.searchParams.delete('rol');
    } else {
        url.searchParams.set('rol', String(id));
    }

    return url.toString();
}

async function seleccionar(id: number) {
    if (id === rolId.value) {
        return;
    }

    if (
        totalCambios.value > 0 &&
        !(await confirmarAccion(
            'Tienes cambios sin guardar',
            `Si cambias de rol se descartan ${totalCambios.value} cambio(s) en «${rol.value?.nombre}».`,
            'Descartar y cambiar',
        ))
    ) {
        return;
    }

    rolId.value = id;
    compararId.value = null;
    cargarBorrador();
    window.history.replaceState(window.history.state, '', urlConRol(id));
}

function seleccionarPorNombre(nombreRol: string) {
    const encontrado = props.roles.find((r) => r.nombre === nombreRol);

    if (encontrado) {
        rolId.value = encontrado.id;
        cargarBorrador();
        window.history.replaceState(
            window.history.state,
            '',
            urlConRol(encontrado.id),
        );
    }
}

onMounted(() => {
    const desdeUrl = Number(
        new URLSearchParams(window.location.search).get('rol'),
    );
    const inicial =
        props.roles.find((r) => r.id === desdeUrl) ??
        props.roles.find((r) => !r.es_protegido) ??
        props.roles[0];

    rolId.value = inicial?.id ?? null;
    cargarBorrador();
    window.addEventListener('beforeunload', avisarSalida);
});

onBeforeUnmount(() => window.removeEventListener('beforeunload', avisarSalida));

function avisarSalida(evento: BeforeUnloadEvent) {
    if (totalCambios.value > 0) {
        evento.preventDefault();
    }
}

// Tras guardar (o si otro administrador cambió el rol), la página recibe
// los roles frescos: sin cambios pendientes se recarga el borrador.
watch(
    () => props.roles,
    () => {
        if (rol.value === null) {
            rolId.value = props.roles[0]?.id ?? null;
        }

        if (totalCambios.value === 0 || rol.value === null) {
            cargarBorrador();
        }
    },
);

// ----------------------------------------------------------- comparación
const compararId = ref<number | null>(null);
const rolComparado = computed(
    () => props.roles.find((r) => r.id === compararId.value) ?? null,
);
const comparacion = computed(() =>
    rolComparado.value ? new Set(rolComparado.value.permisos) : null,
);
const copiarDesdeId = ref('');

async function copiarDesde() {
    const origen = props.roles.find(
        (r) => String(r.id) === copiarDesdeId.value,
    );

    if (!origen || !rol.value) {
        return;
    }

    const confirmado = await confirmarAccion(
        `¿Copiar los permisos de «${origen.nombre}»?`,
        `«${rol.value.nombre}» quedará con exactamente los ${origen.permisos.length} permisos de «${origen.nombre}». No se guarda hasta que pulses «Guardar cambios».`,
        'Copiar permisos',
    );

    if (confirmado) {
        borrador.value = new Set(origen.permisos);
        copiarDesdeId.value = '';
    }
}

// ------------------------------------------------------------- edición
function poner(permiso: string, valor: boolean) {
    if (soloLectura.value) {
        return;
    }

    if (valor) {
        borrador.value.add(permiso);
    } else {
        borrador.value.delete(permiso);
    }
}

function cambiarNivel(modulo: ModuloCatalogo, valor: NivelAlcance | 'propios') {
    Object.values(modulo.alcances).forEach((p) => p && poner(p, false));

    const permiso = valor === 'propios' ? undefined : modulo.alcances[valor];

    if (permiso) {
        poner(permiso, true);
    }
}

function cambiarAcceso(modulo: ModuloCatalogo, valor: boolean) {
    if (!modulo.acceso) {
        return;
    }

    poner(modulo.acceso.permiso, valor);

    // Sin acceso no hay alcance ni acciones que conservar.
    if (!valor) {
        cambiarNivel(modulo, 'propios');
        modulo.acciones.forEach((a) => poner(a.permiso, false));
    }
}

function cambiarAccion(
    modulo: ModuloCatalogo,
    permiso: string,
    valor: boolean,
) {
    poner(permiso, valor);

    // Una acción sin poder entrar al módulo no sirve: se enciende el acceso.
    if (valor && modulo.acceso) {
        poner(modulo.acceso.permiso, true);
    }
}

function todoModulo(modulo: ModuloCatalogo, valor: boolean) {
    if (valor) {
        if (modulo.acceso) {
            poner(modulo.acceso.permiso, true);
        }

        modulo.acciones.forEach((a) => poner(a.permiso, true));
    } else {
        permisosDelModulo(modulo).forEach((p) => poner(p, false));
    }
}

function descartar() {
    cargarBorrador();
}

const guardando = ref(false);

async function guardar() {
    if (!rol.value || totalCambios.value === 0) {
        return;
    }

    const partes = [
        agregados.value.length > 0
            ? `se agregan ${agregados.value.length} permiso(s)`
            : null,
        quitados.value.length > 0
            ? `se quitan ${quitados.value.length} permiso(s)`
            : null,
        cambioNombre.value ? `se renombra a «${nombre.value.trim()}»` : null,
    ].filter(Boolean);

    const confirmado = await confirmarAccion(
        `¿Guardar los cambios de «${rol.value.nombre}»?`,
        `${partes.join(', ').replace(/^./, (l) => l.toUpperCase())}. Aplica de inmediato a ${rol.value.usuarios_count} usuario(s) con este rol.`,
        'Guardar cambios',
    );

    if (!confirmado) {
        return;
    }

    guardando.value = true;
    router.put(
        update.url(rol.value.id),
        { nombre: nombre.value.trim(), permisos: [...borrador.value] },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => cargarBorrador(),
            onError: (errores) =>
                mostrarError(
                    Object.values(errores)[0] ??
                        'No fue posible guardar el rol.',
                ),
            onFinish: () => (guardando.value = false),
        },
    );
}

async function eliminar(objetivo: RolItem) {
    if (!(await confirmarEliminacion(`el rol «${objetivo.nombre}»`))) {
        return;
    }

    router.delete(destroy.url(objetivo.id), {
        preserveScroll: true,
        onSuccess: () => {
            if (rolId.value === objetivo.id) {
                rolId.value =
                    props.roles.find((r) => r.id !== objetivo.id)?.id ?? null;
                cargarBorrador();
            }
        },
        onError: () => mostrarError('No fue posible eliminar el rol.'),
    });
}

// --------------------------------------------------------------- filtros
const vista = ref<'matriz' | 'resumen'>('matriz');
const busqueda = ref('');
const filtro = computed(() => normalizar(busqueda.value));
const soloConAcceso = ref(false);
const soloDiferencias = ref(false);
const plegadas = ref(new Set<string>());

function difiereModulo(modulo: ModuloCatalogo): boolean {
    const otro = comparacion.value;

    return (
        otro !== null &&
        permisosDelModulo(modulo).some(
            (p) => otro.has(p) !== borrador.value.has(p),
        )
    );
}

const etapasVisibles = computed(() =>
    props.catalogo.etapas
        .map((etapa) => {
            const modulos = etapa.modulos.filter(
                (m) =>
                    moduloVisible(m, filtro.value) &&
                    (!soloConAcceso.value || moduloActivo(m, borrador.value)) &&
                    (!soloDiferencias.value || difiereModulo(m)),
            );

            return {
                ...etapa,
                modulos,
                activos: etapa.modulos.filter((m) =>
                    moduloActivo(m, borrador.value),
                ).length,
            };
        })
        .filter((etapa) => etapa.modulos.length > 0),
);

const otrosVisibles = computed(() =>
    Object.entries(props.catalogo.otros)
        .map(([grupo, permisos]) => ({
            grupo,
            permisos: permisos.filter(
                (p) =>
                    coincide(
                        filtro.value,
                        p,
                        etiquetaPermiso(p),
                        etiquetaModulo(grupo),
                    ) &&
                    (!soloConAcceso.value || borrador.value.has(p)) &&
                    (!soloDiferencias.value ||
                        (comparacion.value !== null &&
                            comparacion.value.has(p) !==
                                borrador.value.has(p))),
            ),
        }))
        .filter((g) => g.permisos.length > 0),
);

const sinResultados = computed(
    () => etapasVisibles.value.length === 0 && otrosVisibles.value.length === 0,
);

function alternarEtapa(clave: string) {
    if (plegadas.value.has(clave)) {
        plegadas.value.delete(clave);
    } else {
        plegadas.value.add(clave);
    }
}

// ----------------------------------------------------------- estadísticas
const totalModulos = computed(() =>
    props.catalogo.etapas.reduce((t, e) => t + e.modulos.length, 0),
);
const totalPermisosCatalogo = computed(
    () =>
        props.catalogo.etapas
            .flatMap((e) => e.modulos)
            .reduce((t, m) => t + permisosDelModulo(m).length, 0) +
        Object.values(props.catalogo.otros).flat().length,
);
const totalUsuarios = computed(() =>
    props.roles.reduce((t, r) => t + r.usuarios_count, 0),
);
const modulosActivos = computed(
    () =>
        props.catalogo.etapas
            .flatMap((e) => e.modulos)
            .filter((m) => moduloActivo(m, borrador.value)).length,
);

// ---------------------------------------------------------------- diálogos
const dialogNuevo = ref(false);
const dialogClonar = ref(false);
</script>

<template>
    <Head title="Roles y permisos" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Roles y permisos"
            descripcion="Qué puede hacer cada rol, acción por acción, en cada módulo."
            :icono="ShieldCheck"
        >
            <Button @click="dialogNuevo = true">
                <Plus class="size-4" />
                Nuevo rol
            </Button>
        </CrudPageHeader>

        <CrudStats
            :estadisticas="[
                { etiqueta: 'Roles', valor: roles.length, icono: ShieldCheck },
                {
                    etiqueta: 'Usuarios con rol',
                    valor: totalUsuarios,
                    icono: Users,
                    tono: 'info',
                },
                { etiqueta: 'Módulos', valor: totalModulos, icono: Layers },
                {
                    etiqueta: 'Permisos en el catálogo',
                    valor: totalPermisosCatalogo,
                    icono: Lock,
                },
            ]"
        />

        <div class="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <!-- Lista de roles -->
            <aside
                class="flex flex-col gap-3 lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)] lg:self-start"
            >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="busquedaRol"
                        class="pl-9"
                        placeholder="Buscar rol..."
                        aria-label="Buscar rol"
                    />
                </div>

                <NativeSelect
                    class="w-full lg:hidden"
                    :model-value="rolId === null ? '' : String(rolId)"
                    aria-label="Rol a editar"
                    @update:model-value="seleccionar(Number($event))"
                >
                    <NativeSelectOption
                        v-for="r in rolesFiltrados"
                        :key="r.id"
                        :value="String(r.id)"
                        >{{ r.nombre }} ·
                        {{ r.usuarios_count }} usuario(s)</NativeSelectOption
                    >
                </NativeSelect>

                <ul
                    class="hidden flex-col gap-1 overflow-y-auto rounded-2xl border border-border/60 bg-card p-2 shadow-sm lg:flex"
                    data-tour="tabla"
                >
                    <li v-for="r in rolesFiltrados" :key="r.id">
                        <button
                            type="button"
                            class="flex w-full flex-col gap-0.5 rounded-xl px-3 py-2 text-left transition-colors"
                            :class="
                                r.id === rolId
                                    ? 'bg-salvia text-foreground'
                                    : 'hover:bg-muted/60'
                            "
                            @click="seleccionar(r.id)"
                        >
                            <span
                                class="flex items-center gap-2 text-sm font-medium"
                            >
                                <span class="truncate">{{ r.nombre }}</span>
                                <Lock
                                    v-if="r.es_protegido"
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                />
                            </span>
                            <span class="text-xs text-muted-foreground"
                                >{{ r.permisos.length }} permisos ·
                                {{ r.usuarios_count }} usuario(s)</span
                            >
                        </button>
                    </li>
                    <li
                        v-if="rolesFiltrados.length === 0"
                        class="px-3 py-4 text-center text-sm text-muted-foreground"
                    >
                        Ningún rol coincide.
                    </li>
                </ul>
            </aside>

            <!-- Editor del rol -->
            <section v-if="rol" class="flex min-w-0 flex-col gap-4">
                <div
                    class="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="flex min-w-0 flex-col gap-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <Input
                                    v-if="
                                        !rol.es_del_sistema && !rol.es_protegido
                                    "
                                    v-model="nombre"
                                    class="h-9 w-full font-medium sm:w-64"
                                    aria-label="Nombre del rol"
                                />
                                <h2 v-else class="text-lg font-semibold">
                                    {{ rol.nombre }}
                                </h2>
                                <Badge
                                    v-if="rol.es_protegido"
                                    variant="secondary"
                                    ><Lock /> Acceso total</Badge
                                >
                                <Badge
                                    v-else-if="rol.es_del_sistema"
                                    variant="info"
                                    >Rol del sistema</Badge
                                >
                                <Badge v-else variant="outline"
                                    >Personalizado</Badge
                                >
                            </div>
                            <p class="text-sm text-muted-foreground">
                                {{ rol.usuarios_count }} usuario(s) ·
                                {{ borrador.size }} permisos ·
                                {{ modulosActivos }} de {{ totalModulos }}
                                módulos con acceso
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="dialogClonar = true"
                            >
                                <Copy class="size-4" />
                                Clonar
                            </Button>
                            <Button
                                v-if="!rol.es_del_sistema && !rol.es_protegido"
                                variant="outline-destructive"
                                size="sm"
                                @click="eliminar(rol)"
                            >
                                <Trash2 class="size-4" />
                                Eliminar
                            </Button>
                        </div>
                    </div>

                    <div
                        v-if="rol.es_protegido"
                        class="flex gap-2 rounded-xl bg-info-soft p-3 text-sm"
                    >
                        <Info class="mt-0.5 size-4 shrink-0 text-info" />
                        <span
                            >«super_admin» tiene siempre todo el catálogo
                            operativo y no se edita. Para un rol parecido con
                            menos permisos, clónalo y ajusta la copia.</span
                        >
                    </div>
                    <p
                        v-else-if="rol.es_del_sistema"
                        class="text-xs text-muted-foreground"
                    >
                        Los roles del sistema no se renombran ni se eliminan;
                        sus permisos sí se ajustan. Un deploy nunca vuelve a dar
                        un permiso que aquí se quitó.
                    </p>

                    <!-- Comparar / copiar -->
                    <div
                        class="grid gap-3 border-t border-border/60 pt-3 md:grid-cols-2"
                    >
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span
                                class="inline-flex items-center gap-1.5 font-medium"
                                ><GitCompareArrows class="size-4" /> Comparar
                                con</span
                            >
                            <NativeSelect
                                class="w-full"
                                :model-value="
                                    compararId === null
                                        ? ''
                                        : String(compararId)
                                "
                                @update:model-value="
                                    compararId = $event ? Number($event) : null;
                                    soloDiferencias = false;
                                "
                            >
                                <NativeSelectOption value=""
                                    >Sin comparar</NativeSelectOption
                                >
                                <NativeSelectOption
                                    v-for="r in roles.filter(
                                        (x) => x.id !== rol?.id,
                                    )"
                                    :key="r.id"
                                    :value="String(r.id)"
                                    >{{ r.nombre }}</NativeSelectOption
                                >
                            </NativeSelect>
                        </label>
                        <div
                            v-if="!soloLectura"
                            class="flex flex-col gap-1.5 text-sm"
                        >
                            <span
                                class="inline-flex items-center gap-1.5 font-medium"
                                ><Copy class="size-4" /> Copiar permisos
                                de</span
                            >
                            <div class="flex gap-2">
                                <NativeSelect
                                    v-model="copiarDesdeId"
                                    class="min-w-0 flex-1"
                                    aria-label="Rol del que se copian los permisos"
                                >
                                    <NativeSelectOption value=""
                                        >Elige un rol…</NativeSelectOption
                                    >
                                    <NativeSelectOption
                                        v-for="r in roles.filter(
                                            (x) => x.id !== rol?.id,
                                        )"
                                        :key="r.id"
                                        :value="String(r.id)"
                                        >{{ r.nombre }}</NativeSelectOption
                                    >
                                </NativeSelect>
                                <Button
                                    variant="outline"
                                    :disabled="copiarDesdeId === ''"
                                    @click="copiarDesde"
                                    >Copiar</Button
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Barra: vista, búsqueda y filtros -->
                <div
                    class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-3 shadow-sm md:flex-row md:items-center"
                >
                    <div
                        class="inline-flex shrink-0 rounded-xl bg-muted p-1"
                        role="tablist"
                    >
                        <button
                            v-for="opcion in [
                                { valor: 'matriz', etiqueta: 'Permisos' },
                                {
                                    valor: 'resumen',
                                    etiqueta: 'Qué puede hacer',
                                },
                            ] as const"
                            :key="opcion.valor"
                            type="button"
                            role="tab"
                            :aria-selected="vista === opcion.valor"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                            :class="
                                vista === opcion.valor
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="vista = opcion.valor"
                        >
                            {{ opcion.etiqueta }}
                        </button>
                    </div>

                    <template v-if="vista === 'matriz'">
                        <div class="relative min-w-0 flex-1">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                v-model="busqueda"
                                class="pl-9"
                                placeholder="Buscar permiso: «contrato», «aprobar», «descargar»…"
                                aria-label="Buscar permiso"
                            />
                        </div>
                        <label class="flex shrink-0 items-center gap-2 text-sm">
                            <PermisoSwitch
                                v-model="soloConAcceso"
                                tamano="sm"
                                etiqueta="Solo lo que tiene"
                            />
                            Solo lo que tiene
                        </label>
                        <label
                            v-if="comparacion"
                            class="flex shrink-0 items-center gap-2 text-sm"
                        >
                            <PermisoSwitch
                                v-model="soloDiferencias"
                                tamano="sm"
                                etiqueta="Solo diferencias"
                            />
                            Solo diferencias
                        </label>
                    </template>
                </div>

                <!-- Resumen en español llano -->
                <div
                    v-if="vista === 'resumen'"
                    class="rounded-2xl border border-border/60 bg-card p-5 shadow-sm"
                >
                    <ResumenRol
                        :catalogo="catalogo"
                        :permisos="borrador"
                        :nombre-rol="rol.nombre"
                    />
                </div>

                <!-- Matriz -->
                <template v-else>
                    <p
                        v-if="sinResultados"
                        class="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
                    >
                        Ningún permiso coincide con la búsqueda o los filtros.
                    </p>

                    <section
                        v-for="etapa in etapasVisibles"
                        :key="etapa.clave"
                        class="flex flex-col gap-3"
                    >
                        <button
                            type="button"
                            class="flex items-center gap-3 text-left"
                            :aria-expanded="!plegadas.has(etapa.clave)"
                            @click="alternarEtapa(etapa.clave)"
                        >
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-crema text-sm font-semibold"
                                >{{ etapa.numero ?? '·' }}</span
                            >
                            <span class="flex-1">
                                <span class="block font-semibold">{{
                                    etapa.etiqueta
                                }}</span>
                                <span class="text-xs text-muted-foreground"
                                    >{{ etapa.activos }} de
                                    {{
                                        catalogo.etapas.find(
                                            (e) => e.clave === etapa.clave,
                                        )?.modulos.length
                                    }}
                                    módulos con acceso</span
                                >
                            </span>
                            <ChevronDown
                                class="size-4 text-muted-foreground transition-transform"
                                :class="
                                    plegadas.has(etapa.clave)
                                        ? '-rotate-90'
                                        : ''
                                "
                            />
                        </button>

                        <div
                            v-if="!plegadas.has(etapa.clave)"
                            class="grid gap-3 xl:grid-cols-2"
                        >
                            <ModuloPermisosCard
                                v-for="modulo in etapa.modulos"
                                :key="modulo.clave"
                                :modulo="{
                                    ...modulo,
                                    acciones:
                                        soloConAcceso || soloDiferencias
                                            ? accionesVisibles(
                                                  modulo,
                                                  filtro,
                                              ).filter(
                                                  (a) =>
                                                      (!soloConAcceso ||
                                                          borrador.has(
                                                              a.permiso,
                                                          )) &&
                                                      (!soloDiferencias ||
                                                          comparacion?.has(
                                                              a.permiso,
                                                          ) !==
                                                              borrador.has(
                                                                  a.permiso,
                                                              )),
                                              )
                                            : modulo.acciones,
                                }"
                                :permisos="borrador"
                                :comparacion="comparacion"
                                :nombre-comparacion="
                                    rolComparado?.nombre ?? null
                                "
                                :solo-lectura="soloLectura"
                                :filtro="filtro"
                                @acceso="cambiarAcceso(modulo, $event)"
                                @nivel="cambiarNivel(modulo, $event)"
                                @accion="
                                    (permiso, valor) =>
                                        cambiarAccion(modulo, permiso, valor)
                                "
                                @todo="todoModulo(modulo, $event)"
                            />
                        </div>
                    </section>

                    <section
                        v-if="otrosVisibles.length > 0"
                        class="flex flex-col gap-3"
                    >
                        <div>
                            <h3 class="font-semibold">Otros permisos</h3>
                            <p class="text-xs text-muted-foreground">
                                Permisos que todavía no pertenecen a ningún
                                módulo (app móvil, capacitación, legado…). Se
                                listan para que nada quede oculto.
                            </p>
                        </div>
                        <div class="grid gap-3 xl:grid-cols-2">
                            <article
                                v-for="grupo in otrosVisibles"
                                :key="grupo.grupo"
                                class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                            >
                                <h4 class="font-medium">
                                    {{ etiquetaModulo(grupo.grupo) }}
                                </h4>
                                <ul class="grid gap-1.5 sm:grid-cols-2">
                                    <li
                                        v-for="permiso in grupo.permisos"
                                        :key="permiso"
                                        class="flex items-center gap-2.5 rounded-xl border px-3 py-2"
                                        :class="
                                            comparacion &&
                                            comparacion.has(permiso) !==
                                                borrador.has(permiso)
                                                ? 'border-warning/50 bg-warning-soft/60'
                                                : 'border-transparent bg-muted/40'
                                        "
                                    >
                                        <PermisoSwitch
                                            tamano="sm"
                                            :model-value="borrador.has(permiso)"
                                            :disabled="soloLectura"
                                            :etiqueta="etiquetaPermiso(permiso)"
                                            @update:model-value="
                                                poner(permiso, $event)
                                            "
                                        />
                                        <span
                                            class="min-w-0 flex-1 text-sm leading-tight"
                                            >{{ etiquetaPermiso(permiso) }}
                                            <span
                                                class="block truncate font-mono text-[10px] text-muted-foreground"
                                                >{{ permiso }}</span
                                            ></span
                                        >
                                    </li>
                                </ul>
                            </article>
                        </div>
                    </section>
                </template>
            </section>
        </div>

        <!-- Barra de cambios sin guardar -->
        <div
            v-if="totalCambios > 0 && rol && !soloLectura"
            class="sticky bottom-20 z-20 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-warning/40 bg-warning-soft p-3 shadow-lg md:bottom-4"
            role="status"
        >
            <span class="text-sm">
                <strong class="font-semibold"
                    >{{ totalCambios }} cambio(s) sin guardar</strong
                >
                en «{{ rol.nombre }}»:
                <span class="text-success">+{{ agregados.length }}</span>
                ·
                <span class="text-destructive">−{{ quitados.length }}</span>
                permisos
            </span>
            <div class="flex gap-2">
                <Button variant="outline" size="sm" @click="descartar">
                    <RotateCcw class="size-4" />
                    Descartar
                </Button>
                <Button size="sm" :disabled="guardando" @click="guardar">
                    <Save class="size-4" />
                    Guardar cambios
                </Button>
            </div>
        </div>
    </div>

    <NuevoRolDialog
        v-if="dialogNuevo"
        v-model:open="dialogNuevo"
        :roles="roles"
        @creado="seleccionarPorNombre"
    />

    <ClonarRolDialog
        v-if="dialogClonar"
        v-model:open="dialogClonar"
        :rol="rol"
        @clonado="seleccionarPorNombre"
    />
</template>
