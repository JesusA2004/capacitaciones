<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Building2,
    CircleAlert,
    Search,
    Send,
    UserRound,
    Users,
    UsersRound,
    X,
} from '@lucide/vue';
import { onClickOutside, useDebounceFn } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, ref, useTemplateRef, watch } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';
import { enviarJson, getJson } from '@/lib/http';
import {
    colaboradores as buscarColaboradores,
    sucursales as listaSucursales,
} from '@/routes/rh/onboarding/asignar';
import type {
    ColaboradorBusqueda,
    ModoDestinatarios,
    ResumenAsignacion,
    SucursalAsignable,
} from '@/types';

/**
 * «Asignar a…» reutilizable de Onboarding (presentación de la inducción,
 * video/audio y descriptivo de puesto): todos, una persona, una lista de
 * personas (buscador: lista completa al enfocar, filtra al escribir) o por
 * sucursal. Antes de asignar muestra el resumen del servidor («Se asignará a
 * 37 personas de Sucursal Centro») y a quién se omite y por qué. El alcance y
 * la regla viven en el backend (DestinatariosOnboardingService); aquí solo se
 * arma la selección. `extra` agrega campos propios de cada asignación (qué
 * incluir, fecha límite…) — van en el slot `opciones`.
 */
const props = withDefaults(
    defineProps<{
        titulo: string;
        descripcion: string;
        previsualizarUrl: string;
        enviarUrl: string;
        /** Campos adicionales que viajan con la asignación. */
        extra?: Record<string, unknown>;
        textoBoton?: string;
        /** Personas ya elegidas al abrir (p. ej. «Reasignar»). */
        preseleccion?: ColaboradorBusqueda[];
        /** Deshabilita «Revisar» (p. ej. no se eligió qué asignar). */
        bloqueado?: string | null;
    }>(),
    {
        extra: () => ({}),
        textoBoton: 'Asignar',
        preseleccion: () => [],
        bloqueado: null,
    },
);

const abierto = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ asignado: [] }>();

const modo = ref<ModoDestinatarios>('personas');
const personas = ref<ColaboradorBusqueda[]>([]);
const sucursalIds = ref<number[]>([]);
const sucursales = ref<SucursalAsignable[]>([]);
const resumen = ref<ResumenAsignacion | null>(null);
const cargando = ref(false);
const enviando = ref(false);
const error = ref<string | null>(null);

const modos: {
    valor: ModoDestinatarios;
    texto: string;
    ayuda: string;
    icono: Component;
}[] = [
    {
        valor: 'personas',
        texto: 'Personas',
        ayuda: 'Una o varias, con el buscador',
        icono: UserRound,
    },
    {
        valor: 'sucursales',
        texto: 'Por sucursal',
        ayuda: 'Todo el personal activo de la sucursal',
        icono: Building2,
    },
    {
        valor: 'todos',
        texto: 'Todos',
        ayuda: 'Todo el personal activo de tu alcance',
        icono: UsersRound,
    },
];

watch(abierto, (v) => {
    if (v) {
        personas.value = [...props.preseleccion];
        modo.value = 'personas';
        sucursalIds.value = [];
        resumen.value = null;
        error.value = null;
    }
});

watch(
    [modo, personas, sucursalIds, () => JSON.stringify(props.extra)],
    () => {
        resumen.value = null;
        error.value = null;
    },
    { deep: true },
);

watch(modo, async (m) => {
    if (m === 'sucursales' && sucursales.value.length === 0) {
        try {
            sucursales.value = (
                await getJson<{ data: SucursalAsignable[] }>(
                    listaSucursales.url(),
                )
            ).data;
        } catch {
            error.value = 'No se pudieron cargar las sucursales.';
        }
    }
});

// --- Buscador múltiple: lista completa al enfocar, filtra al escribir ---
const raiz = useTemplateRef('raiz');
const termino = ref('');
const resultados = ref<(ColaboradorBusqueda & { etapa?: string })[]>([]);
const listaAbierta = ref(false);
const buscando = ref(false);
let solicitud = 0;

onClickOutside(raiz, () => (listaAbierta.value = false));

const buscar = useDebounceFn(async (texto: string) => {
    const actual = ++solicitud;
    buscando.value = true;

    try {
        const r = await getJson<{
            data: (ColaboradorBusqueda & { etapa?: string })[];
        }>(buscarColaboradores.url({ query: { q: texto } }));

        if (actual === solicitud) {
            resultados.value = r.data;
        }
    } catch {
        if (actual === solicitud) {
            resultados.value = [];
        }
    } finally {
        if (actual === solicitud) {
            buscando.value = false;
        }
    }
}, 200);

watch(termino, (t) => {
    listaAbierta.value = true;
    void buscar(t);
});

function enfocar() {
    listaAbierta.value = true;

    if (resultados.value.length === 0) {
        void buscar(termino.value);
    }
}

function alternarPersona(c: ColaboradorBusqueda) {
    personas.value = personas.value.some((p) => p.id === c.id)
        ? personas.value.filter((p) => p.id !== c.id)
        : [...personas.value, c];
}

function alternarSucursal(id: number) {
    sucursalIds.value = sucursalIds.value.includes(id)
        ? sucursalIds.value.filter((s) => s !== id)
        : [...sucursalIds.value, id];
}

const carga = computed(() => ({
    modo: modo.value,
    colaborador_ids:
        modo.value === 'personas' ? personas.value.map((p) => p.id) : [],
    sucursal_ids: modo.value === 'sucursales' ? sucursalIds.value : [],
    ...props.extra,
}));

const seleccionVacia = computed(
    () =>
        (modo.value === 'personas' && personas.value.length === 0) ||
        (modo.value === 'sucursales' && sucursalIds.value.length === 0),
);

async function revisar() {
    cargando.value = true;
    error.value = null;

    try {
        resumen.value = (
            await enviarJson<{ data: ResumenAsignacion }>(
                'POST',
                props.previsualizarUrl,
                carga.value,
            )
        ).data;
    } catch (e) {
        error.value =
            e instanceof Error
                ? e.message
                : 'No se pudo revisar la asignación.';
    } finally {
        cargando.value = false;
    }
}

function confirmar() {
    enviando.value = true;
    router.post(props.enviarUrl, carga.value, {
        preserveScroll: true,
        onSuccess: () => {
            abierto.value = false;
            emit('asignado');
        },
        onError: (e) =>
            (error.value = Object.values(e)[0] ?? 'No se pudo asignar.'),
        onFinish: () => (enviando.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent
            class="max-h-[100svh] content-start overflow-y-auto max-sm:h-[100svh] max-sm:max-w-none max-sm:rounded-none sm:max-h-[90vh] sm:max-w-2xl"
        >
            <DialogHeader>
                <DialogTitle>{{ titulo }}</DialogTitle>
                <DialogDescription>{{ descripcion }}</DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-5">
                <!-- Qué asignar (propio de cada asignación) -->
                <slot name="opciones" />

                <!-- A quién -->
                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-semibold">
                        ¿A quién se lo asignas?
                    </legend>
                    <div class="grid gap-2 sm:grid-cols-3" role="radiogroup">
                        <button
                            v-for="m in modos"
                            :key="m.valor"
                            type="button"
                            role="radio"
                            :aria-checked="modo === m.valor"
                            class="flex tarjeta-interactiva items-start gap-2 rounded-2xl border p-3 text-left"
                            :class="
                                modo === m.valor
                                    ? 'border-2 border-primary bg-salvia/60'
                                    : 'border-border/60 bg-card'
                            "
                            @click="modo = m.valor"
                        >
                            <component
                                :is="m.icono"
                                class="mt-0.5 size-4 shrink-0 text-primary"
                            />
                            <span>
                                <span class="block text-sm font-semibold">{{
                                    m.texto
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ m.ayuda }}</span
                                >
                            </span>
                        </button>
                    </div>
                </fieldset>

                <!-- Personas -->
                <div
                    v-if="modo === 'personas'"
                    ref="raiz"
                    class="flex flex-col gap-2"
                >
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <input
                            v-model="termino"
                            type="search"
                            role="combobox"
                            :aria-expanded="listaAbierta"
                            aria-autocomplete="list"
                            placeholder="Busca por nombre o número de empleado…"
                            class="h-10 w-full rounded-xl border border-input bg-transparent pr-9 pl-9 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            @focus="enfocar"
                        />
                        <Spinner
                            v-if="buscando"
                            class="absolute top-1/2 right-3 size-4 -translate-y-1/2"
                        />
                        <div
                            v-if="listaAbierta"
                            class="absolute z-50 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-border/60 bg-popover p-1 shadow-lg"
                            role="listbox"
                            aria-multiselectable="true"
                        >
                            <p
                                v-if="!buscando && resultados.length === 0"
                                class="px-3 py-2 text-sm text-muted-foreground"
                            >
                                Sin coincidencias dentro de tu alcance.
                            </p>
                            <button
                                v-for="c in resultados"
                                :key="c.id"
                                type="button"
                                role="option"
                                :aria-selected="
                                    personas.some((p) => p.id === c.id)
                                "
                                class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left hover:bg-muted focus:bg-muted focus:outline-none"
                                @click="alternarPersona(c)"
                            >
                                <Checkbox
                                    :model-value="
                                        personas.some((p) => p.id === c.id)
                                    "
                                    tabindex="-1"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate text-sm font-medium"
                                        >{{ c.nombre }}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                    >
                                        {{
                                            [
                                                c.numero_empleado,
                                                c.puesto,
                                                c.sucursal,
                                                c.etapa,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}
                                    </span>
                                </span>
                            </button>
                        </div>
                    </div>
                    <ul
                        v-if="personas.length"
                        class="flex flex-wrap gap-1.5"
                        aria-label="Personas elegidas"
                    >
                        <li
                            v-for="p in personas"
                            :key="p.id"
                            class="flex items-center gap-1 rounded-full border border-border/60 bg-salvia/50 py-0.5 pr-1 pl-2.5 text-xs"
                        >
                            {{ p.nombre }}
                            <button
                                type="button"
                                class="rounded-full p-0.5 hover:bg-card"
                                :aria-label="`Quitar a ${p.nombre}`"
                                @click="alternarPersona(p)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </li>
                    </ul>
                    <p v-else class="text-xs text-muted-foreground">
                        Enfoca el buscador para ver la lista completa; escribe
                        para filtrar.
                    </p>
                </div>

                <!-- Sucursales -->
                <div
                    v-else-if="modo === 'sucursales'"
                    class="flex flex-col gap-2"
                >
                    <p
                        v-if="sucursales.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Cargando sucursales de tu alcance…
                    </p>
                    <div
                        class="grid max-h-64 gap-1.5 overflow-y-auto sm:grid-cols-2"
                    >
                        <label
                            v-for="s in sucursales"
                            :key="s.id"
                            class="flex cursor-pointer items-center gap-2 rounded-xl border border-border/60 px-3 py-2 text-sm hover:bg-muted/50"
                        >
                            <Checkbox
                                :model-value="sucursalIds.includes(s.id)"
                                @update:model-value="alternarSucursal(s.id)"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                s.nombre
                            }}</span>
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >{{ s.total }}</span
                            >
                        </label>
                    </div>
                </div>

                <p
                    v-else
                    class="flex items-start gap-2 rounded-xl bg-crema/70 px-3 py-2 text-sm"
                >
                    <Users class="mt-0.5 size-4 shrink-0 text-bronce" />
                    Se asignará a todo el personal activo y en incorporación que
                    está en tu alcance. Quien ya lo tiene se omite.
                </p>

                <!-- Resumen del servidor -->
                <div
                    v-if="resumen"
                    class="flex flex-col gap-2 rounded-2xl border border-primary/30 bg-salvia/40 p-4"
                    aria-live="polite"
                >
                    <p class="text-sm font-semibold">
                        Se asignará a {{ resumen.asignar }}
                        {{ resumen.asignar === 1 ? 'persona' : 'personas' }}
                        <span class="font-normal text-muted-foreground"
                            >· {{ resumen.descripcion }}</span
                        >
                    </p>
                    <p
                        v-if="resumen.muestra.length"
                        class="text-xs text-muted-foreground"
                    >
                        {{ resumen.muestra.join(', ')
                        }}{{
                            resumen.asignar > resumen.muestra.length ? '…' : ''
                        }}
                    </p>
                    <details v-if="resumen.omitidos.length" class="text-sm">
                        <summary
                            class="cursor-pointer font-medium text-warning"
                        >
                            Se omite a {{ resumen.omitidos.length }} (ya lo
                            tienen o no aplica)
                        </summary>
                        <ul
                            class="mt-2 flex max-h-40 flex-col gap-1 overflow-y-auto text-xs"
                        >
                            <li v-for="o in resumen.omitidos" :key="o.id">
                                <strong>{{ o.nombre }}</strong> — {{ o.motivo }}
                            </li>
                        </ul>
                    </details>
                </div>

                <p
                    v-if="error || bloqueado"
                    class="flex items-start gap-2 rounded-xl bg-danger-soft px-3 py-2 text-sm text-destructive"
                    role="alert"
                >
                    <CircleAlert class="mt-0.5 size-4 shrink-0" />
                    {{ error ?? bloqueado }}
                </p>
            </div>

            <DialogFooter class="gap-2 sm:gap-2">
                <Button variant="ghost" @click="abierto = false"
                    >Cancelar</Button
                >
                <Button
                    v-if="!resumen"
                    variant="outline"
                    :disabled="cargando || seleccionVacia || !!bloqueado"
                    @click="revisar"
                >
                    <Spinner v-if="cargando" /> Revisar a quién se asigna
                </Button>
                <Button
                    v-else
                    :disabled="enviando || resumen.asignar === 0 || !!bloqueado"
                    @click="confirmar"
                >
                    <Spinner v-if="enviando" />
                    <Send v-else class="size-4" />
                    {{ textoBoton }} ({{ resumen.asignar }})
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
