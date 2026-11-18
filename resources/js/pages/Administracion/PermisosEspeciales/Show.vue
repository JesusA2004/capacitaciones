<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarClock,
    ChevronDown,
    History,
    Info,
    MinusCircle,
    PlusCircle,
    RotateCcw,
    Save,
    Search,
    Sparkles,
    UserCog,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ModuloPermisosCard from '@/components/Administracion/Roles/ModuloPermisosCard.vue';
import PermisoSwitch from '@/components/Administracion/Roles/PermisoSwitch.vue';
import ResumenRol from '@/components/Administracion/Roles/ResumenRol.vue';
import ResumenVisualRol from '@/components/Administracion/Roles/ResumenVisualRol.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import {
    etiquetaModulo,
    etiquetaPermiso,
    registrarEtiquetas,
} from '@/lib/permisos';
import {
    accionesVisibles,
    coincide,
    moduloVisible,
    normalizar,
    permisosDelModulo,
} from '@/lib/rolesPermisos';
import { dashboard } from '@/routes';
import { index as indexEspeciales } from '@/routes/administracion/permisos-especiales';
import { index as indexUsuarios } from '@/routes/administracion/usuarios';
import { destroy, update } from '@/routes/administracion/usuarios/permisos';
import type {
    AjustePermisoItem,
    BitacoraPermisoItem,
    CatalogoPermisos,
    ModuloCatalogo,
    NivelAlcance,
    TipoAjustePermiso,
    UsuarioPermisosEspeciales,
} from '@/types';

/**
 * «Permisos especiales» de UNA persona: parte de lo que le da su rol y
 * permite conceder (+) o quitar (−) permisos y alcance por módulo sin tocar
 * el rol. Lo especial se resalta contra «Su rol». La autorización real y las
 * reglas (no a sí mismo, solo conceder lo que uno tiene…) están en el backend.
 */
const props = defineProps<{
    usuario: UsuarioPermisosEspeciales;
    /** Lo que tiene SIN ajustes: directos + roles activos. */
    base: string[];
    efectivos: string[];
    ajustes: AjustePermisoItem[];
    bitacora: BitacoraPermisoItem[];
    /** null = puede conceder cualquiera (super_admin). */
    concedibles: string[] | null;
    motivoNoGestionable: string | null;
    catalogo: CatalogoPermisos;
}>();

// Nombres en español de los permisos sin módulo (nunca el código).
registrarEtiquetas(props.catalogo.etiquetas);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Usuarios', href: '/administracion/usuarios' },
            { title: 'Permisos especiales', href: '#' },
        ],
    },
});

const { confirmarAccion, mostrarError } = useAlertas();

const soloLectura = computed(() => props.motivoNoGestionable !== null);
const base = computed(() => new Set(props.base));
const concedibles = computed(() =>
    props.concedibles === null ? null : new Set(props.concedibles),
);

// --------------------------------------------------------------- borrador
function ajustesIniciales(): Map<string, TipoAjustePermiso> {
    return new Map(
        props.ajustes.filter((a) => a.vigente).map((a) => [a.permiso, a.tipo]),
    );
}

const borrador = ref(ajustesIniciales());
const motivo = ref('');
const hasta = ref('');

const efectivos = computed(() => {
    const set = new Set(props.base);

    borrador.value.forEach((tipo, permiso) => {
        if (tipo === 'conceder') {
            set.add(permiso);
        } else {
            set.delete(permiso);
        }
    });

    return set;
});

const iniciales = computed(() => ajustesIniciales());
const cambios = computed(() => {
    const todos = new Set([
        ...iniciales.value.keys(),
        ...borrador.value.keys(),
    ]);

    return [...todos].filter(
        (p) => iniciales.value.get(p) !== borrador.value.get(p),
    ).length;
});

const concedidos = computed(
    () => [...borrador.value.values()].filter((t) => t === 'conceder').length,
);
const quitados = computed(
    () => [...borrador.value.values()].filter((t) => t === 'revocar').length,
);
const vencidos = computed(() => props.ajustes.filter((a) => !a.vigente));
const proximoVencimiento = computed(() => {
    const fechas = props.ajustes
        .filter((a) => a.vigente && a.vigente_hasta)
        .map((a) => a.vigente_hasta as string)
        .sort();

    return fechas[0] ?? null;
});

function poner(permiso: string, valor: boolean) {
    if (soloLectura.value) {
        return;
    }

    const copia = new Map(borrador.value);

    if (valor === base.value.has(permiso)) {
        copia.delete(permiso);
    } else if (
        valor &&
        concedibles.value !== null &&
        !concedibles.value.has(permiso)
    ) {
        mostrarError(
            `No puedes conceder «${etiquetaPermiso(permiso)}»: tú no lo tienes.`,
        );

        return;
    } else {
        copia.set(permiso, valor ? 'conceder' : 'revocar');
    }

    borrador.value = copia;
}

function cambiarNivel(modulo: ModuloCatalogo, valor: NivelAlcance | 'propios') {
    const destino = valor === 'propios' ? undefined : modulo.alcances[valor];

    Object.values(modulo.alcances).forEach((p) => p && poner(p, p === destino));
}

function cambiarAcceso(modulo: ModuloCatalogo, valor: boolean) {
    if (!modulo.acceso) {
        return;
    }

    poner(modulo.acceso.permiso, valor);

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
    borrador.value = ajustesIniciales();
    motivo.value = '';
    hasta.value = '';
}

// ---------------------------------------------------------------- guardar
const guardando = ref(false);
const errores = ref<Record<string, string>>({});

async function guardar() {
    if (cambios.value === 0) {
        return;
    }

    const confirmado = await confirmarAccion(
        `¿Guardar los permisos especiales de ${props.usuario.nombre}?`,
        `Queda con ${concedidos.value} permiso(s) concedido(s) y ${quitados.value} quitado(s) por encima de su rol${hasta.value ? `, vigentes hasta el ${new Date(`${hasta.value}T23:59`).toLocaleDateString('es-MX', { dateStyle: 'long' })}` : ''}. Su rol no cambia y se le avisa.`,
        'Guardar',
    );

    if (!confirmado) {
        return;
    }

    guardando.value = true;
    router.put(
        update.url(props.usuario.id),
        {
            ajustes: [...borrador.value.entries()].map(([permiso, tipo]) => ({
                permiso,
                tipo,
            })),
            motivo: motivo.value.trim() || null,
            hasta: hasta.value || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                errores.value = {};
                motivo.value = '';
                hasta.value = '';
                borrador.value = ajustesIniciales();
            },
            onError: (e) => {
                errores.value = e;
                mostrarError(
                    Object.values(e)[0] ??
                        'No fue posible guardar los permisos especiales.',
                );
            },
            onFinish: () => (guardando.value = false),
        },
    );
}

async function quitarTodos() {
    const confirmado = await confirmarAccion(
        '¿Quitar todos los permisos especiales?',
        `${props.usuario.nombre} queda EXACTAMENTE con lo que le dan sus roles. Queda en la bitácora y se le avisa.`,
        'Quitar todos',
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(props.usuario.id), {
        preserveScroll: true,
        onSuccess: () => descartar(),
        onError: (e) =>
            mostrarError(
                Object.values(e)[0] ?? 'No fue posible quitar los ajustes.',
            ),
    });
}

// --------------------------------------------------------------- filtros
const vista = ref<'matriz' | 'resumen' | 'bitacora'>('matriz');
const busqueda = ref('');
const filtro = computed(() => normalizar(busqueda.value));
const soloEspeciales = ref(false);
const plegadas = ref(new Set<string>());

function esEspecial(permiso: string): boolean {
    return borrador.value.has(permiso);
}

const etapasVisibles = computed(() =>
    props.catalogo.etapas
        .map((etapa) => ({
            ...etapa,
            modulos: etapa.modulos.filter(
                (m) =>
                    moduloVisible(m, filtro.value) &&
                    (!soloEspeciales.value ||
                        permisosDelModulo(m).some(esEspecial)),
            ),
        }))
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
                    (!soloEspeciales.value || esEspecial(p)),
            ),
        }))
        .filter((g) => g.permisos.length > 0),
);

/** Lista de lo especial, para el panel lateral. */
const listaEspeciales = computed(() =>
    [...borrador.value.entries()]
        .map(([permiso, tipo]) => ({
            permiso,
            tipo,
            ajuste: props.ajustes.find((a) => a.permiso === permiso) ?? null,
        }))
        .sort((a, b) => a.permiso.localeCompare(b.permiso)),
);

function alternarEtapa(clave: string) {
    const copia = new Set(plegadas.value);

    if (copia.has(clave)) {
        copia.delete(clave);
    } else {
        copia.add(clave);
    }

    plegadas.value = copia;
}

function fecha(valor: string | null): string {
    return valor
        ? new Date(valor).toLocaleString('es-MX', {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '';
}

const hoy = new Date().toISOString().slice(0, 10);
</script>

<template>
    <Head :title="`Permisos especiales · ${usuario.nombre}`" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            :titulo="`Permisos especiales · ${usuario.nombre}`"
            descripcion="Concede o quita permisos a esta persona sin tocar su rol. Quitar todos los ajustes la deja exactamente con lo de su rol."
            :icono="UserCog"
        >
            <Button variant="outline" as-child>
                <Link :href="indexEspeciales.url()">
                    <Sparkles class="size-4" />
                    Quién tiene permisos especiales
                </Link>
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="indexUsuarios.url()">
                    <ArrowLeft class="size-4" />
                    Usuarios
                </Link>
            </Button>
        </CrudPageHeader>

        <!-- Ficha -->
        <section
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm md:flex-row md:items-center md:justify-between"
        >
            <div class="flex min-w-0 flex-col gap-1">
                <p class="font-semibold">
                    {{ usuario.nombre }}
                    <span class="font-normal text-muted-foreground"
                        >· {{ usuario.username }}</span
                    >
                </p>
                <p
                    v-if="usuario.puesto || usuario.sucursal"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        [usuario.puesto, usuario.sucursal]
                            .filter(Boolean)
                            .join(' · ')
                    }}
                </p>
                <div class="flex flex-wrap items-center gap-1.5 text-sm">
                    <span class="text-muted-foreground">Su rol:</span>
                    <Badge
                        v-for="r in usuario.roles"
                        :key="r.nombre"
                        :variant="r.activo ? 'outline' : 'secondary'"
                        class="capitalize"
                        >{{ r.nombre.replace(/_/g, ' ')
                        }}<template v-if="!r.activo">
                            (desactivado)</template
                        ></Badge
                    >
                    <span
                        v-if="usuario.roles.length === 0"
                        class="text-muted-foreground"
                        >Sin roles</span
                    >
                </div>
            </div>
            <Button
                v-if="!soloLectura && ajustes.length > 0"
                variant="outline-destructive"
                @click="quitarTodos"
            >
                <RotateCcw class="size-4" />
                Quitar todos los ajustes especiales
            </Button>
        </section>

        <div
            v-if="motivoNoGestionable"
            class="flex gap-2 rounded-xl bg-info-soft p-3 text-sm"
        >
            <Info class="mt-0.5 size-4 shrink-0 text-info" />
            <span>{{ motivoNoGestionable }} Solo consulta.</span>
        </div>

        <CrudStats
            :estadisticas="[
                {
                    etiqueta: 'Concedidos (+)',
                    valor: concedidos,
                    icono: PlusCircle,
                    tono: 'success',
                },
                {
                    etiqueta: 'Quitados (−)',
                    valor: quitados,
                    icono: MinusCircle,
                    tono: 'danger',
                },
                {
                    etiqueta: 'Permisos efectivos',
                    valor: efectivos.size,
                    icono: Sparkles,
                    tono: 'info',
                },
                {
                    etiqueta: proximoVencimiento
                        ? `Vence ${fecha(proximoVencimiento)}`
                        : 'Sin vencimiento',
                    valor: ajustes.filter((a) => a.vigente_hasta && a.vigente)
                        .length,
                    icono: CalendarClock,
                },
            ]"
        />

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="flex min-w-0 flex-col gap-4">
                <!-- Barra -->
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
                                { valor: 'bitacora', etiqueta: 'Bitácora' },
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
                                placeholder="Buscar permiso: «contrato», «directorio», «aprobar»…"
                                aria-label="Buscar permiso"
                            />
                        </div>
                        <label class="flex shrink-0 items-center gap-2 text-sm">
                            <PermisoSwitch
                                v-model="soloEspeciales"
                                tamano="sm"
                                etiqueta="Solo lo especial"
                            />
                            Solo lo especial
                        </label>
                    </template>
                </div>

                <p
                    v-if="vista === 'matriz'"
                    class="flex items-start gap-2 text-xs text-muted-foreground"
                >
                    <Info class="mt-0.5 size-3.5 shrink-0" />
                    Lo resaltado en amarillo es distinto de lo que le da su rol
                    («Su rol: sí/no»). Ver no permite aprobar: el alcance y las
                    acciones van aparte.
                </p>

                <!-- Bitácora -->
                <div
                    v-if="vista === 'bitacora'"
                    class="rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                >
                    <EmptyState
                        v-if="bitacora.length === 0"
                        :icono="History"
                        titulo="Sin movimientos"
                        descripcion="Aquí queda quién concedió o quitó qué, cuándo y por qué."
                    />
                    <ol v-else class="flex flex-col divide-y divide-border/60">
                        <li
                            v-for="b in bitacora"
                            :key="b.id"
                            class="flex flex-col gap-0.5 py-2.5 text-sm"
                        >
                            <span class="flex flex-wrap items-center gap-2">
                                <Badge
                                    :variant="
                                        b.accion === 'concedido'
                                            ? 'success'
                                            : b.accion === 'quitado'
                                              ? 'destructive'
                                              : 'secondary'
                                    "
                                    >{{ b.etiqueta }}</Badge
                                >
                                <span v-if="b.permiso" class="font-medium">{{
                                    etiquetaPermiso(b.permiso)
                                }}</span>
                                <span
                                    v-if="b.permiso"
                                    class="font-mono text-[10px] text-muted-foreground"
                                    >{{ b.permiso }}</span
                                >
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ fecha(b.fecha) }}
                                <template v-if="b.actor">
                                    · {{ b.actor }}</template
                                >
                                <template v-else> · Sistema</template>
                                <template v-if="b.vigente_hasta">
                                    · hasta
                                    {{ fecha(b.vigente_hasta) }}</template
                                >
                            </span>
                            <span v-if="b.motivo" class="text-xs"
                                >«{{ b.motivo }}»</span
                            >
                        </li>
                    </ol>
                </div>

                <!-- Qué puede hacer (efectivo) -->
                <div
                    v-else-if="vista === 'resumen'"
                    class="rounded-2xl border border-border/60 bg-card p-5 shadow-sm"
                >
                    <ResumenVisualRol
                        class="mb-5"
                        :catalogo="catalogo"
                        :permisos="efectivos"
                    />
                    <ResumenRol
                        :catalogo="catalogo"
                        :permisos="efectivos"
                        :nombre-rol="usuario.nombre"
                    />
                </div>

                <!-- Matriz -->
                <template v-else>
                    <EmptyState
                        v-if="
                            etapasVisibles.length === 0 &&
                            otrosVisibles.length === 0
                        "
                        :icono="Search"
                        :titulo="
                            soloEspeciales
                                ? 'Sin permisos especiales'
                                : 'Ningún permiso coincide'
                        "
                        :descripcion="
                            soloEspeciales
                                ? 'Esta persona tiene exactamente lo de su rol.'
                                : 'Prueba con otra palabra.'
                        "
                    />

                    <section
                        v-for="etapa in etapasVisibles"
                        :key="etapa.clave"
                        class="flex flex-col gap-3"
                    >
                        <button
                            type="button"
                            class="flex presionable items-center gap-3 rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-expanded="!plegadas.has(etapa.clave)"
                            @click="alternarEtapa(etapa.clave)"
                        >
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-crema text-sm font-semibold"
                                >{{ etapa.numero ?? '·' }}</span
                            >
                            <span class="flex-1 font-semibold">{{
                                etapa.etiqueta
                            }}</span>
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
                            class="grid gap-3 2xl:grid-cols-2"
                        >
                            <ModuloPermisosCard
                                v-for="modulo in etapa.modulos"
                                :key="modulo.clave"
                                :modulo="{
                                    ...modulo,
                                    acciones: soloEspeciales
                                        ? accionesVisibles(
                                              modulo,
                                              filtro,
                                          ).filter((a) => esEspecial(a.permiso))
                                        : modulo.acciones,
                                }"
                                :permisos="efectivos"
                                :comparacion="base"
                                nombre-comparacion="Su rol"
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
                        <h3 class="font-semibold">Otros permisos</h3>
                        <div class="grid gap-3 2xl:grid-cols-2">
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
                                            esEspecial(permiso)
                                                ? 'border-warning/50 bg-warning-soft/60'
                                                : 'border-transparent bg-muted/40'
                                        "
                                    >
                                        <PermisoSwitch
                                            tamano="sm"
                                            :model-value="
                                                efectivos.has(permiso)
                                            "
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
                                                v-if="esEspecial(permiso)"
                                                class="block text-[11px] text-warning"
                                                >Su rol:
                                                {{
                                                    base.has(permiso)
                                                        ? 'sí'
                                                        : 'no'
                                                }}</span
                                            ></span
                                        >
                                    </li>
                                </ul>
                            </article>
                        </div>
                    </section>
                </template>
            </section>

            <!-- Panel lateral: lo especial -->
            <aside
                class="flex flex-col gap-3 xl:sticky xl:top-4 xl:max-h-[calc(100vh-2rem)] xl:self-start xl:overflow-y-auto"
            >
                <div
                    class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                >
                    <h3 class="flex items-center gap-2 font-semibold">
                        <Sparkles class="size-4 text-primary" />
                        Lo especial de esta persona
                    </h3>
                    <p
                        v-if="listaEspeciales.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Nada: tiene exactamente lo de su rol. Activa o apaga
                        cualquier permiso de la matriz para concederlo (+) o
                        quitarlo (−) solo a ella.
                    </p>
                    <ul v-else class="flex flex-col gap-1.5">
                        <li
                            v-for="e in listaEspeciales"
                            :key="e.permiso"
                            class="flex items-start gap-2 rounded-xl px-2.5 py-1.5 text-sm"
                            :class="
                                e.tipo === 'conceder'
                                    ? 'bg-success-soft'
                                    : 'bg-danger-soft'
                            "
                        >
                            <PlusCircle
                                v-if="e.tipo === 'conceder'"
                                class="mt-0.5 size-4 shrink-0 text-success"
                            />
                            <MinusCircle
                                v-else
                                class="mt-0.5 size-4 shrink-0 text-destructive"
                            />
                            <span class="min-w-0 flex-1 leading-tight">
                                {{ etiquetaPermiso(e.permiso) }}
                                <span
                                    v-if="e.ajuste?.vigente_hasta"
                                    class="block text-[11px] text-muted-foreground"
                                    >Hasta
                                    {{ fecha(e.ajuste.vigente_hasta) }}</span
                                >
                                <span
                                    v-else-if="!e.ajuste"
                                    class="block text-[11px] text-muted-foreground"
                                    >Sin guardar</span
                                >
                            </span>
                            <button
                                v-if="!soloLectura"
                                type="button"
                                class="presionable rounded-full p-0.5 text-muted-foreground hover:text-foreground"
                                :aria-label="`Volver a lo de su rol: ${etiquetaPermiso(e.permiso)}`"
                                @click="poner(e.permiso, base.has(e.permiso))"
                            >
                                <RotateCcw class="size-3.5" />
                            </button>
                        </li>
                    </ul>
                    <p
                        v-if="vencidos.length > 0"
                        class="text-xs text-muted-foreground"
                    >
                        {{ vencidos.length }} ajuste(s) ya vencido(s): no
                        cuentan y se retiran solos.
                    </p>
                </div>
            </aside>
        </div>

        <!-- Barra de cambios -->
        <div
            v-if="cambios > 0 && !soloLectura"
            class="sticky bottom-20 z-20 flex flex-col gap-3 rounded-2xl border border-warning/40 bg-warning-soft p-3 shadow-lg md:bottom-4"
            role="status"
        >
            <span class="text-sm">
                <strong class="font-semibold"
                    >{{ cambios }} cambio(s) sin guardar</strong
                >
                para {{ usuario.nombre }}: su rol no cambia.
            </span>
            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_auto]">
                <div class="grid gap-1">
                    <Label for="motivo-especial">Motivo (obligatorio)</Label>
                    <Textarea
                        id="motivo-especial"
                        v-model="motivo"
                        rows="1"
                        class="min-h-9 bg-background"
                        placeholder="Ej. Cubre la gerencia de Cuautla del 1 al 15 de diciembre"
                    />
                    <p v-if="errores.motivo" class="text-xs text-destructive">
                        {{ errores.motivo }}
                    </p>
                </div>
                <div class="grid gap-1">
                    <Label for="hasta-especial">Vigente hasta (opcional)</Label>
                    <Input
                        id="hasta-especial"
                        v-model="hasta"
                        type="date"
                        :min="hoy"
                        class="bg-background"
                    />
                    <p class="text-[11px] text-muted-foreground">
                        Ideal para coberturas: vence sola.
                    </p>
                </div>
                <div class="flex items-end gap-2">
                    <Button variant="outline" size="sm" @click="descartar">
                        <RotateCcw class="size-4" />
                        Descartar
                    </Button>
                    <Button size="sm" :disabled="guardando" @click="guardar">
                        <Save class="size-4" />
                        Guardar
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
