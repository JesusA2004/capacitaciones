<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronsDownUp, ChevronsUpDown, ShieldQuestion } from '@lucide/vue';
import { computed, inject, ref, watch } from 'vue';
import DialogoClasificacionRegion from '@/components/Organigrama/DialogoClasificacionRegion.vue';
import DialogoCoberturaOrganigrama from '@/components/Organigrama/DialogoCoberturaOrganigrama.vue';
import DialogoMovimientoOrganigrama from '@/components/Organigrama/DialogoMovimientoOrganigrama.vue';
import FilaListaOrganigrama from '@/components/Organigrama/FilaListaOrganigrama.vue';
import LienzoOrganigrama from '@/components/Organigrama/LienzoOrganigrama.vue';
import PanelNodoOrganigrama from '@/components/Organigrama/PanelNodoOrganigrama.vue';
import { CLAVE_BUSQUEDA_ORGANIGRAMA, coincidePersona } from '@/lib/organigrama';
import { contarRama, hijosPorPadre } from '@/lib/organigramaEditable';
import { finalizar as finalizarCobertura } from '@/routes/administracion/jerarquia-puestos/coberturas';
import { clasificar } from '@/routes/administracion/jerarquia-puestos/personas';
import type {
    AccionMovimientoOrganigrama,
    NodoOrganigramaPersona,
    RegionOrganigrama,
} from '@/types';

/**
 * Organigrama GENERAL editable (una tarjeta por persona / plaza):
 * - Q1 / Q3: cada Gerente de Sucursal cuelga de UN lado; los que RH no ha
 *   clasificado se ven «Por clasificar» (aviso arriba + chip en la tarjeta).
 * - Plaza vacante → «Cubre temporalmente este puesto» (sin fechas).
 * - Subir / Bajar / Mover bajo… con confirmación y movimiento laboral.
 * Escritorio: lienzo con zoom/arrastre. Celular: lista jerárquica.
 * Solo RH / super admin ve las acciones; el backend valida con Policies.
 */
const props = defineProps<{
    nodos: NodoOrganigramaPersona[];
    regiones: RegionOrganigrama[];
    puedeEditar: boolean;
    candidatos: { value: string; label: string }[];
}>();

const busqueda = inject(CLAVE_BUSQUEDA_ORGANIGRAMA, ref(''));

const porClave = computed(() => new Map(props.nodos.map((n) => [n.clave, n])));
const hijos = computed(() => hijosPorPadre(props.nodos));
const raices = computed(() => hijos.value.get('') ?? []);

/** Por defecto se ven hasta los gerentes: las ramas de sucursal, cerradas. */
function colapsadosIniciales(): Set<string> {
    return new Set(
        props.nodos
            .filter(
                (n) =>
                    n.de_sucursal &&
                    (hijos.value.get(n.clave) ?? []).length > 0,
            )
            .map((n) => n.clave),
    );
}

const colapsados = ref<Set<string>>(colapsadosIniciales());

function alternar(clave: string): void {
    const nuevo = new Set(colapsados.value);

    if (nuevo.has(clave)) {
        nuevo.delete(clave);
    } else {
        nuevo.add(clave);
    }

    colapsados.value = nuevo;
}

function expandirTodo(): void {
    colapsados.value = new Set();
}

function contraerSucursales(): void {
    colapsados.value = colapsadosIniciales();
}

// --- Búsqueda: resalta coincidencias y abre sus ramas ---
const coincidencias = computed<Set<string> | null>(() => {
    const texto = busqueda.value.trim();

    if (!texto) {
        return null;
    }

    return new Set(
        props.nodos
            .filter((n) => coincidePersona(n, texto))
            .map((n) => n.clave),
    );
});

const abiertosPorBusqueda = computed(() => {
    const abiertos = new Set<string>();

    for (const clave of coincidencias.value ?? []) {
        let actual = porClave.value.get(clave);

        while (actual?.padre && !abiertos.has(actual.padre)) {
            abiertos.add(actual.padre);
            actual = porClave.value.get(actual.padre);
        }
    }

    return abiertos;
});

const colapsadosEfectivos = computed(() => {
    const efectivos = new Set(colapsados.value);

    for (const clave of abiertosPorBusqueda.value) {
        efectivos.delete(clave);
    }

    return efectivos;
});

/** Nodos visibles: se omiten los que cuelgan de una rama cerrada. */
const visibles = computed(() => {
    const lista: NodoOrganigramaPersona[] = [];
    const pila = [...raices.value].reverse();
    const vistos = new Set<string>();

    while (pila.length) {
        const nodo = pila.pop();

        if (!nodo || vistos.has(nodo.clave)) {
            continue;
        }

        vistos.add(nodo.clave);
        lista.push(nodo);

        if (!colapsadosEfectivos.value.has(nodo.clave)) {
            pila.push(...[...(hijos.value.get(nodo.clave) ?? [])].reverse());
        }
    }

    return lista;
});

const aCargo = computed(() => {
    const mapa = new Map<string, number>();

    for (const nodo of props.nodos) {
        if ((hijos.value.get(nodo.clave) ?? []).length > 0) {
            mapa.set(nodo.clave, contarRama(nodo.clave, hijos.value));
        }
    }

    return mapa;
});

// Lista móvil: abiertos = lo contrario de colapsados (mismo estado).
const abiertosLista = computed(() => {
    const abiertos = new Set<string>();

    for (const nodo of props.nodos) {
        if (!colapsadosEfectivos.value.has(nodo.clave)) {
            abiertos.add(nodo.clave);
        }
    }

    return abiertos;
});

// --- Q1 / Q3 por clasificar ---
const porClasificar = computed(() =>
    props.nodos.filter(
        (n) =>
            n.tipo === 'persona' &&
            n.clasificacion?.estado === 'por_clasificar',
    ),
);
const dialogoClasificacion = ref(false);

// --- Panel y acciones ---
const seleccion = ref<string | null>(null);
const panelAbierto = ref(false);
const ocupado = ref(false);
const lienzo = ref<InstanceType<typeof LienzoOrganigrama> | null>(null);

const nodoSeleccionado = computed(() =>
    seleccion.value ? (porClave.value.get(seleccion.value) ?? null) : null,
);

function seleccionar(clave: string): void {
    seleccion.value = clave;
    panelAbierto.value = true;
    lienzo.value?.enfocar(clave);
}

watch(panelAbierto, (abierto) => {
    if (!abierto) {
        seleccion.value = null;
    }
});

// Tras recargar (clasificar, cubrir, mover) el nodo puede cambiar de clave:
// una cobertura quitada vuelve a ser la plaza vacante del mismo puesto.
let reemplazo: ((n: NodoOrganigramaPersona) => boolean) | null = null;

watch(
    () => props.nodos,
    () => {
        if (seleccion.value && !porClave.value.has(seleccion.value)) {
            const nuevo = reemplazo ? props.nodos.find(reemplazo) : undefined;
            seleccion.value = nuevo?.clave ?? null;
            panelAbierto.value = nuevo !== undefined;
        }

        reemplazo = null;
    },
);

function mismaPlaza(nodo: NodoOrganigramaPersona) {
    return (otro: NodoOrganigramaPersona): boolean =>
        otro.puesto.id === nodo.puesto.id &&
        (otro.sucursal?.id ?? null) === (nodo.sucursal?.id ?? null) &&
        (otro.region?.id ?? null) === (nodo.region?.id ?? null) &&
        otro.tipo !== 'persona';
}

const dialogoCobertura = ref(false);
const nodoACubrir = ref<NodoOrganigramaPersona | null>(null);

function cubrir(nodo: NodoOrganigramaPersona): void {
    nodoACubrir.value = nodo;
    reemplazo = mismaPlaza(nodo);
    dialogoCobertura.value = true;
}

function quitarCobertura(nodo: NodoOrganigramaPersona): void {
    if (!nodo.cobertura) {
        return;
    }

    reemplazo = mismaPlaza(nodo);
    ocupado.value = true;
    router.post(
        finalizarCobertura.url(nodo.cobertura.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                ocupado.value = false;
            },
        },
    );
}

function clasificarNodo(
    nodo: NodoOrganigramaPersona,
    regionId: number | null,
): void {
    if (!nodo.persona) {
        return;
    }

    ocupado.value = true;
    router.put(
        clasificar.url(nodo.persona.id),
        { region_id: regionId },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                ocupado.value = false;
            },
        },
    );
}

const dialogoMovimiento = ref(false);
const nodoAMover = ref<NodoOrganigramaPersona | null>(null);
const accionMovimiento = ref<AccionMovimientoOrganigrama>('subir');

function moverNodo(
    nodo: NodoOrganigramaPersona,
    accion: AccionMovimientoOrganigrama,
): void {
    nodoAMover.value = nodo;
    accionMovimiento.value = accion;
    dialogoMovimiento.value = true;
}
</script>

<template>
    <div class="flex flex-col gap-3" data-tour="organigrama-arbol">
        <!-- Q1 / Q3 pendientes: nunca se ocultan -->
        <div
            v-if="porClasificar.length"
            class="flex flex-col gap-3 rounded-2xl border border-dashed border-warning/50 bg-warning-soft/70 p-4 sm:flex-row sm:items-center"
        >
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-card text-warning"
            >
                <ShieldQuestion class="size-5" />
            </span>
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-semibold text-foreground">
                    {{ porClasificar.length }}
                    {{
                        porClasificar.length === 1
                            ? 'gerente de sucursal está'
                            : 'gerentes de sucursal están'
                    }}
                    «Por clasificar» en Q1 / Q3
                </p>
                <p class="text-xs text-foreground/70">
                    Cada gerente pertenece a un solo lado. Mientras no se
                    clasifique, su jefe sale provisionalmente de la región de su
                    sucursal en la matriz comercial.
                </p>
            </div>
            <button
                v-if="puedeEditar"
                type="button"
                class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl bg-primary px-4 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90"
                @click="dialogoClasificacion = true"
            >
                Clasificar Q1 / Q3
            </button>
        </div>
        <div v-else-if="puedeEditar" class="flex justify-end">
            <button
                type="button"
                class="text-xs font-medium text-primary hover:underline"
                @click="dialogoClasificacion = true"
            >
                Revisar clasificación Q1 / Q3
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-border bg-card px-3 text-sm hover:bg-muted"
                @click="expandirTodo"
            >
                <ChevronsUpDown class="size-4" /> Ver todas las sucursales
            </button>
            <button
                type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-border bg-card px-3 text-sm hover:bg-muted"
                @click="contraerSucursales"
            >
                <ChevronsDownUp class="size-4" /> Solo hasta gerentes
            </button>
            <p class="ml-auto hidden text-xs text-muted-foreground md:block">
                Toca una tarjeta para ver su detalle<template
                    v-if="puedeEditar"
                >
                    y sus acciones</template
                >.
            </p>
        </div>

        <LienzoOrganigrama
            ref="lienzo"
            class="hidden md:block"
            :nodos="visibles"
            :regiones="regiones"
            :seleccion="seleccion"
            :a-cargo="aCargo"
            :colapsados="colapsadosEfectivos"
            :coincidencias="coincidencias"
            @seleccionar="seleccionar"
            @alternar="alternar"
        />

        <ul class="flex flex-col gap-1.5 md:hidden">
            <FilaListaOrganigrama
                v-for="raiz in raices"
                :key="raiz.clave"
                :nodo="raiz"
                :hijos="hijos"
                :regiones="regiones"
                :nivel="0"
                :seleccion="seleccion"
                :abiertos="abiertosLista"
                :coincidencias="coincidencias"
                @seleccionar="seleccionar"
                @alternar="alternar"
            />
        </ul>
    </div>

    <PanelNodoOrganigrama
        v-model:open="panelAbierto"
        :nodo="nodoSeleccionado"
        :por-clave="porClave"
        :hijos="hijos"
        :regiones="regiones"
        :puede-editar="puedeEditar"
        :ocupado="ocupado"
        @seleccionar="seleccionar"
        @cubrir="cubrir"
        @quitar-cobertura="quitarCobertura"
        @clasificar="clasificarNodo"
        @mover="moverNodo"
    />

    <DialogoCoberturaOrganigrama
        v-model:open="dialogoCobertura"
        :nodo="nodoACubrir"
        :candidatos="candidatos"
    />

    <DialogoMovimientoOrganigrama
        v-model:open="dialogoMovimiento"
        :nodo="nodoAMover"
        :accion-inicial="accionMovimiento"
    />

    <DialogoClasificacionRegion
        v-model:open="dialogoClasificacion"
        :nodos="nodos"
        :regiones="regiones"
    />
</template>
