<script setup lang="ts">
/**
 * «Roles y permisos → Pendientes»: qué pendientes le llegan a este rol
 * (docs/PENDIENTES.md). Cada tipo de pendiente es un permiso
 * `pendientes.recibir_<tipo>` del mismo borrador que la matriz, así que se
 * guarda con el botón «Guardar» de la pantalla. Solo filtra los pendientes
 * asignados POR PERMISO; los asignados a una persona por nombre (su firma,
 * su evaluación, la entrevista que debe aprobar) siempre le llegan.
 */
import {
    BellRing,
    ClipboardCheck,
    FileText,
    GraduationCap,
    Inbox,
    Info,
    LogOut,
    RotateCcw,
    Search,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onMounted, ref } from 'vue';
import PermisoSwitch from '@/components/Administracion/Roles/PermisoSwitch.vue';
import { Input } from '@/components/ui/input';
import { catalogoRoles } from '@/routes/rh/pendientes';

type TipoPendiente = {
    tipo: string;
    permiso: string;
    etiqueta: string;
    etapa: string;
    etapa_clave: string;
    informativo: boolean;
    asignado_por: string[];
    directo: boolean;
    abiertos: number;
};

const props = defineProps<{
    nombreRol: string;
    permisos: Set<string>;
    soloLectura: boolean;
}>();

const emit = defineEmits<{ poner: [permiso: string, valor: boolean] }>();

const ICONO_ETAPA: Record<string, Component> = {
    reclutamiento: Users,
    contratacion: FileText,
    onboarding: GraduationCap,
    periodo_prueba: ClipboardCheck,
    cierre: LogOut,
    reingreso: RotateCcw,
    permanencia: Inbox,
};

const TONO_ETAPA: Record<string, string> = {
    reclutamiento: 'bg-salvia text-primary',
    contratacion: 'bg-crema text-foreground',
    onboarding: 'bg-menta text-primary',
    periodo_prueba: 'bg-warning-soft text-warning',
    cierre: 'bg-danger-soft text-destructive',
    reingreso: 'bg-olivo text-foreground',
    permanencia: 'bg-verde-suave text-primary',
};

const catalogo = ref<TipoPendiente[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const busqueda = ref('');

onMounted(async () => {
    try {
        const respuesta = await fetch(catalogoRoles.url(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const cuerpo = (await respuesta.json().catch(() => ({}))) as {
            data?: TipoPendiente[];
            message?: string;
        };

        if (!respuesta.ok || !cuerpo.data) {
            error.value =
                cuerpo.message ||
                'No se pudo cargar el catálogo de pendientes.';

            return;
        }

        catalogo.value = cuerpo.data;
    } catch {
        error.value = 'No se pudo cargar el catálogo de pendientes.';
    } finally {
        cargando.value = false;
    }
});

function normalizar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const grupos = computed(() => {
    const filtro = normalizar(busqueda.value.trim());
    const mapa = new Map<
        string,
        { clave: string; etiqueta: string; tipos: TipoPendiente[] }
    >();

    for (const tipo of catalogo.value) {
        if (
            filtro &&
            !normalizar(`${tipo.etiqueta} ${tipo.etapa}`).includes(filtro)
        ) {
            continue;
        }

        const grupo = mapa.get(tipo.etapa_clave) ?? {
            clave: tipo.etapa_clave,
            etiqueta: tipo.etapa,
            tipos: [],
        };
        grupo.tipos.push(tipo);
        mapa.set(tipo.etapa_clave, grupo);
    }

    return [...mapa.values()];
});

const encendidos = computed(
    () => catalogo.value.filter((t) => props.permisos.has(t.permiso)).length,
);

/**
 * Encendido pero sin el permiso de acción con que se asigna: no le llegará
 * (salvo lo que se le asigne por nombre).
 */
function sinPermisoDeAccion(tipo: TipoPendiente): boolean {
    return (
        props.permisos.has(tipo.permiso) &&
        tipo.asignado_por.length > 0 &&
        !tipo.directo &&
        !tipo.asignado_por.some((p) => props.permisos.has(p))
    );
}

function ponerGrupo(tipos: TipoPendiente[], valor: boolean) {
    for (const tipo of tipos) {
        if (props.permisos.has(tipo.permiso) !== valor) {
            emit('poner', tipo.permiso, valor);
        }
    }
}
</script>

<template>
    <section class="flex flex-col gap-4">
        <div
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-crema/40 p-4 shadow-sm md:flex-row md:items-center"
        >
            <span
                class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
            >
                <BellRing class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="font-semibold">
                    Pendientes que le llegan a «{{ nombreRol }}»
                </p>
                <p class="text-sm text-muted-foreground">
                    Recibe {{ encendidos }} de {{ catalogo.length }} tipos.
                    Apagar un tipo lo quita de «Mis pendientes» y de sus avisos;
                    lo asignado a una persona por nombre siempre le llega. Los
                    cambios se guardan con «Guardar».
                </p>
            </div>
            <div class="relative w-full md:w-72">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="busqueda"
                    class="bg-background pl-9"
                    placeholder="Buscar pendiente…"
                    aria-label="Buscar tipo de pendiente"
                />
            </div>
        </div>

        <p v-if="cargando" class="text-sm text-muted-foreground">
            Cargando pendientes…
        </p>
        <p
            v-else-if="error"
            class="rounded-xl bg-danger-soft px-4 py-3 text-sm text-destructive"
        >
            {{ error }}
        </p>
        <p
            v-else-if="!grupos.length"
            class="rounded-xl bg-crema/50 px-4 py-6 text-center text-sm text-muted-foreground"
        >
            Ningún pendiente coincide con «{{ busqueda }}».
        </p>

        <section
            v-for="grupo in grupos"
            :key="grupo.clave"
            class="flex flex-col gap-3"
        >
            <header class="flex flex-wrap items-center justify-between gap-2">
                <h3
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <component
                        :is="ICONO_ETAPA[grupo.clave] ?? Inbox"
                        class="size-4 text-primary"
                    />
                    {{ grupo.etiqueta }}
                </h3>
                <div v-if="!soloLectura" class="flex gap-1 text-xs">
                    <button
                        type="button"
                        class="rounded-lg px-2 py-1 font-medium text-primary hover:bg-salvia"
                        @click="ponerGrupo(grupo.tipos, true)"
                    >
                        Encender todos
                    </button>
                    <button
                        type="button"
                        class="rounded-lg px-2 py-1 font-medium text-muted-foreground hover:bg-crema"
                        @click="ponerGrupo(grupo.tipos, false)"
                    >
                        Apagar todos
                    </button>
                </div>
            </header>

            <div class="rejilla-auto gap-3 [--rejilla-min:17rem]">
                <article
                    v-for="tipo in grupo.tipos"
                    :key="tipo.tipo"
                    class="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm transition-colors"
                    :class="
                        permisos.has(tipo.permiso)
                            ? 'border-primary/30'
                            : 'border-border/60 opacity-80'
                    "
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                            :class="
                                TONO_ETAPA[tipo.etapa_clave] ??
                                'bg-crema text-foreground'
                            "
                        >
                            <component
                                :is="ICONO_ETAPA[tipo.etapa_clave] ?? Inbox"
                                class="size-5"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm leading-snug font-semibold">
                                {{ tipo.etiqueta }}
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{
                                    permisos.has(tipo.permiso)
                                        ? 'Le llega'
                                        : 'No le llega'
                                }}
                                <template v-if="tipo.abiertos">
                                    · {{ tipo.abiertos }} abierto{{
                                        tipo.abiertos === 1 ? '' : 's'
                                    }}
                                    hoy
                                </template>
                            </p>
                        </div>
                        <PermisoSwitch
                            :model-value="permisos.has(tipo.permiso)"
                            :disabled="soloLectura"
                            :etiqueta="`Recibir: ${tipo.etiqueta}`"
                            @update:model-value="
                                (valor: boolean) =>
                                    emit('poner', tipo.permiso, valor)
                            "
                        />
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-[11px]">
                        <span
                            v-if="tipo.informativo"
                            class="rounded-full bg-crema px-2 py-0.5 font-medium"
                            >Informativo</span
                        >
                        <span
                            v-if="tipo.directo"
                            class="inline-flex items-center gap-1 rounded-full bg-menta px-2 py-0.5 font-medium text-primary"
                            title="Parte de estos pendientes se asignan a una persona por nombre: esa persona siempre los recibe."
                        >
                            <Info class="size-3" /> También por nombre
                        </span>
                    </div>
                    <p
                        v-if="sinPermisoDeAccion(tipo)"
                        class="flex items-start gap-1.5 rounded-lg bg-warning-soft px-2 py-1.5 text-xs text-warning"
                    >
                        <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                        Encendido, pero el rol no tiene el permiso para
                        atenderlo, así que no le llegará.
                    </p>
                </article>
            </div>
        </section>
    </section>
</template>
