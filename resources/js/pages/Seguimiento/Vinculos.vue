<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import {
    Building2,
    Check,
    ChevronDown,
    Link2,
    RefreshCw,
    Route,
    Search,
    Sparkles,
    TriangleAlert,
    Unlink,
    UserRound,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import AvisoReportes from '@/components/Seguimiento/AvisoReportes.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import type { OpcionBuscable } from '@/components/ui/combobox-buscable/tipos';
import { Input } from '@/components/ui/input';
import SelectorVista from '@/components/ui/SelectorVista.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard } from '@/routes';
import { index as indexSeguimiento } from '@/routes/seguimiento';
import rutasVinculos from '@/routes/seguimiento/vinculos';
import {
    buscar as buscarColaboradoresRuta,
    store as guardarColaborador,
} from '@/routes/seguimiento/vinculos/colaboradores';
import { store as guardarSucursal } from '@/routes/seguimiento/vinculos/sucursales';
import type { PantallaVinculos, RespuestaSeguimiento, VinculoPersona } from '@/types';

const props = defineProps<{
    configurado: boolean;
    datos?: RespuestaSeguimiento<PantallaVinculos>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Seguimiento', href: indexSeguimiento() },
            { title: 'Vínculos con Reportes', href: '' },
        ],
    },
});

type Vista = 'personas' | 'sucursales';
type Filtro = 'pendientes' | 'sugerencias' | 'vinculados' | 'todos';

/** Filas que se pintan a la vez (la lista completa vive en memoria). */
const POR_TANDA = 40;

const vista = ref<Vista>('personas');
const filtro = ref<Filtro>('pendientes');
const soloConDatos = ref(false);
const busqueda = ref('');
const visibles = ref(POR_TANDA);
const enviando = ref<string | null>(null);
const abiertas = ref<Set<string>>(new Set());
const verNoPersonas = ref(false);

const pantalla = computed(() => props.datos?.datos ?? null);

watch([vista, filtro, soloConDatos, busqueda], () => {
    visibles.value = POR_TANDA;
});

function normalizar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

function pasa(vinculado: boolean, sugerencia: boolean, conDatos: boolean, texto: string): boolean {
    if (filtro.value === 'pendientes' && vinculado) {
        return false;
    }

    if (filtro.value === 'sugerencias' && !sugerencia) {
        return false;
    }

    if (filtro.value === 'vinculados' && !vinculado) {
        return false;
    }

    if (soloConDatos.value && !conDatos) {
        return false;
    }

    const termino = normalizar(busqueda.value.trim());

    return termino === '' || normalizar(texto).includes(termino);
}

const personas = computed(() =>
    (pantalla.value?.personas ?? []).filter((p) =>
        pasa(
            p.vinculo !== null,
            p.sugerencia !== null,
            p.con_datos,
            [p.nombre, ...p.variantes, ...p.claves, p.sucursal ?? '', ...p.rutas, p.vinculo?.colaborador ?? ''].join(' '),
        ),
    ),
);

const sucursales = computed(() =>
    (pantalla.value?.sucursales ?? []).filter((s) =>
        pasa(
            s.vinculo !== null && s.vinculo.rutas_vinculadas === s.rutas.length,
            s.sugerencia !== null,
            s.con_datos,
            [s.nombre, s.vinculo?.sucursal ?? '', ...s.rutas.map((r) => r.nombre)].join(' '),
        ),
    ),
);

const total = computed(() => (vista.value === 'personas' ? personas.value.length : sucursales.value.length));
const personasVisibles = computed(() => personas.value.slice(0, visibles.value));
const sucursalesVisibles = computed(() => sucursales.value.slice(0, visibles.value));
const sugerencias = computed(() =>
    vista.value === 'personas'
        ? (pantalla.value?.totales.sugerencias_personas ?? 0)
        : (pantalla.value?.totales.sugerencias_sucursales ?? 0),
);

const actualizado = computed(() => {
    const fecha = pantalla.value?.actualizado_en;

    return fecha
        ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(fecha))
        : null;
});

function alternar(clave: string): void {
    const copia = new Set(abiertas.value);

    if (copia.has(clave)) {
        copia.delete(clave);
    } else {
        copia.add(clave);
    }

    abiertas.value = copia;
}

const opcionesEnvio = {
    preserveScroll: true,
    preserveState: true,
    only: ['datos', 'flash'],
    onFinish: () => {
        enviando.value = null;
    },
};

function vincularSucursal(codigo: string, sucursalId: number | string | null, sugerencia = false): void {
    if (sucursalId === null || sucursalId === '') {
        return;
    }

    enviando.value = `s-${codigo}`;
    router.post(guardarSucursal.url(), { codigo, sucursal_id: Number(sucursalId), sugerencia }, opcionesEnvio);
}

function vincularPersona(reportesId: number, colaboradorId: number | string | null, sugerencia = false): void {
    if (colaboradorId === null || colaboradorId === '') {
        return;
    }

    enviando.value = `p-${reportesId}`;
    router.post(
        guardarColaborador.url(),
        { reportes_id: reportesId, colaborador_id: Number(colaboradorId), sugerencia },
        opcionesEnvio,
    );
}

function desvincular(vinculoId: number, clave: string): void {
    enviando.value = clave;
    router.delete(rutasVinculos.destroy.url(vinculoId), opcionesEnvio);
}

function confirmarSugerencias(): void {
    enviando.value = 'todas';
    router.post(
        rutasVinculos.confirmarSugerencias.url(),
        { tipo: vista.value === 'personas' ? 'colaborador' : 'sucursal' },
        opcionesEnvio,
    );
}

function actualizar(): void {
    enviando.value = 'actualizar';
    router.post(rutasVinculos.actualizar.url(), {}, opcionesEnvio);
}

/** Colaboradores de PEOPLE bajo demanda (al abrir el selector y al escribir). */
function buscadorPara(persona: VinculoPersona): (termino: string) => Promise<OpcionBuscable[]> {
    const sucursal = (pantalla.value?.opciones_sucursales ?? []).find((o) => o.label === persona.sucursal_people);

    return async (termino: string) => {
        const respuesta = await fetch(
            buscarColaboradoresRuta.url({ query: sucursal ? { q: termino, sucursal: sucursal.value } : { q: termino } }),
            { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
        );

        if (!respuesta.ok) {
            return [];
        }

        const json = (await respuesta.json()) as { data: { value: number; label: string; descripcion: string | null }[] };

        return json.data.map((o) => ({ value: o.value, label: o.label, descripcion: o.descripcion ?? undefined }));
    };
}
</script>

<template>
    <Head title="Vínculos con Reportes" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Vínculos con Reportes"
            descripcion="Cada persona y cada sucursal de Reportes con su colaborador y su sucursal en PEOPLE. Con esto la Nine Box suma el desempeño de cada quien."
            :icono="Link2"
        />

        <section class="rounded-2xl border border-border bg-crema/60 p-4 text-sm text-pretty">
            <p class="font-medium">Se vincula por persona y por sucursal, nunca por ruta</p>
            <p class="mt-1 text-muted-foreground">
                En Reportes una misma persona aparece varias veces (una por ruta, por alta de nómina o con su nombre
                escrito distinto): aquí ya viene <span class="font-medium">junta</span>, con todas sus rutas. Las rutas
                cambian, pero siempre pertenecen a su sucursal: al vincular una sucursal se vinculan todas sus rutas.
                PEOPLE <span class="font-medium">sugiere</span> por nombre (y, con homónimos, por sucursal); tú confirmas.
                Cambiar un vínculo nunca borra el anterior.
            </p>
        </section>

        <Deferred data="datos">
            <template #fallback>
                <div class="flex flex-col gap-3" aria-busy="true">
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <Skeleton v-for="i in 4" :key="i" class="h-20 rounded-2xl" />
                    </div>
                    <Skeleton v-for="i in 6" :key="`f-${i}`" class="h-16 rounded-2xl" />
                </div>
            </template>

            <AvisoReportes v-if="datos?.error" :mensaje="datos.error" />

            <template v-else-if="pantalla">
                <section class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-4">
                    <div class="rounded-2xl border border-border bg-salvia p-4">
                        <p class="text-xs text-muted-foreground">Personas vinculadas</p>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ pantalla.totales.personas_vinculadas }} / {{ pantalla.totales.personas }}
                        </p>
                    </div>
                    <div class="rounded-2xl border border-border bg-card p-4">
                        <p class="text-xs text-muted-foreground">Sucursales vinculadas</p>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ pantalla.totales.sucursales_vinculadas }} / {{ pantalla.totales.sucursales }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">{{ pantalla.totales.rutas }} rutas en total</p>
                    </div>
                    <div class="rounded-2xl border border-border bg-warning-soft p-4">
                        <p class="text-xs text-muted-foreground">Sugerencias por confirmar</p>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ pantalla.totales.sugerencias_personas + pantalla.totales.sugerencias_sucursales }}
                        </p>
                    </div>
                    <div class="flex flex-col justify-between gap-2 rounded-2xl border border-border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Datos de Reportes<span v-if="actualizado"> · {{ actualizado }}</span>
                        </p>
                        <Button size="sm" variant="outline" :disabled="enviando !== null" @click="actualizar">
                            <RefreshCw class="size-4" :class="{ 'animate-spin': enviando === 'actualizar' }" />
                            Actualizar desde Reportes
                        </Button>
                    </div>
                </section>

                <div class="flex flex-wrap items-center gap-3">
                    <SelectorVista
                        v-model="vista"
                        etiqueta="Qué vincular"
                        :opciones="[
                            { valor: 'personas', etiqueta: 'Personas', contador: pantalla.totales.sugerencias_personas },
                            { valor: 'sucursales', etiqueta: 'Sucursales', contador: pantalla.totales.sugerencias_sucursales },
                        ]"
                    />
                    <SelectorVista
                        v-model="filtro"
                        etiqueta="Mostrar"
                        :opciones="[
                            { valor: 'pendientes', etiqueta: 'Sin vincular' },
                            { valor: 'sugerencias', etiqueta: 'Con sugerencia' },
                            { valor: 'vinculados', etiqueta: 'Vinculados' },
                            { valor: 'todos', etiqueta: 'Todos' },
                        ]"
                    />
                    <label
                        v-if="pantalla.con_datos_disponible"
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <input v-model="soloConDatos" type="checkbox" class="size-4 accent-primary" />
                        Solo con actividad en {{ pantalla.mes ?? 'el mes' }}
                    </label>
                    <div class="relative w-full sm:w-72">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="busqueda"
                            class="pl-9"
                            placeholder="Nombre, clave, sucursal o ruta…"
                            aria-label="Buscar"
                        />
                    </div>
                    <Button v-if="sugerencias > 0" class="ml-auto" :disabled="enviando !== null" @click="confirmarSugerencias">
                        <Sparkles class="size-4" />
                        Confirmar {{ sugerencias }} sugerencias
                    </Button>
                </div>

                <p class="-mt-2 text-xs text-muted-foreground tabular-nums">
                    {{ total }} {{ vista === 'personas' ? 'personas' : 'sucursales' }} con este filtro
                </p>

                <CambioSuave>
                    <section v-if="vista === 'personas'" key="personas" class="flex flex-col gap-2">
                        <EmptyState
                            v-if="personas.length === 0"
                            :icono="UserRound"
                            titulo="Nada que mostrar"
                            descripcion="Ninguna persona de Reportes coincide con el filtro."
                        />
                        <article
                            v-for="p in personasVisibles"
                            :key="p.reportes_id"
                            class="flex flex-col gap-2 rounded-2xl border border-border bg-card p-3 transicion-ui hover:shadow-sm"
                        >
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="min-w-56 flex-1">
                                    <p class="font-medium">{{ p.nombre }}</p>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-muted-foreground">
                                        <span v-if="p.sucursal" class="inline-flex items-center gap-1 rounded-full bg-salvia px-2 py-0.5 text-foreground">
                                            <Building2 class="size-3" aria-hidden="true" /> {{ p.sucursal }}
                                        </span>
                                        <span v-if="p.claves.length">clave {{ p.claves.join(', ') }}</span>
                                        <button
                                            v-if="p.rutas.length"
                                            type="button"
                                            class="inline-flex items-center gap-0.5 rounded-full px-1.5 hover:bg-muted"
                                            :aria-expanded="abiertas.has(`p-${p.reportes_id}`)"
                                            @click="alternar(`p-${p.reportes_id}`)"
                                        >
                                            <Route class="size-3" aria-hidden="true" />
                                            {{ p.rutas.length }} {{ p.rutas.length === 1 ? 'ruta' : 'rutas' }}
                                            <ChevronDown
                                                class="size-3 transition-transform"
                                                :class="{ 'rotate-180': abiertas.has(`p-${p.reportes_id}`) }"
                                                aria-hidden="true"
                                            />
                                        </button>
                                        <span v-if="p.registros > 1">· {{ p.registros }} registros en Reportes</span>
                                        <span v-if="p.con_datos" class="rounded-full bg-success-soft px-1.5 text-success">con actividad</span>
                                        <span v-if="p.homonimos && !p.vinculo" class="rounded-full bg-warning-soft px-1.5 text-warning">homónimos: elige a mano</span>
                                        <span v-if="p.conflicto" class="inline-flex items-center gap-1 rounded-full bg-warning-soft px-1.5 text-warning">
                                            <TriangleAlert class="size-3" aria-hidden="true" /> vínculos anteriores a dos colaboradores
                                        </span>
                                    </div>
                                </div>
                                <div
                                    v-if="p.sugerencia && !p.vinculo"
                                    class="flex items-center gap-2 rounded-xl bg-warning-soft px-3 py-1.5 text-sm"
                                >
                                    <Sparkles class="size-4 text-warning" aria-hidden="true" />
                                    <span>
                                        ¿Es <span class="font-medium">{{ p.sugerencia.colaborador }}</span>?
                                        <span class="text-[11px] text-muted-foreground">{{ p.sugerencia.motivo }}</span>
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        :disabled="enviando !== null"
                                        @click="vincularPersona(p.reportes_id, p.sugerencia.colaborador_id, true)"
                                    >
                                        <Check class="size-4" /> Confirmar
                                    </Button>
                                </div>
                                <ComboboxBuscable
                                    class="w-full sm:w-80"
                                    :model-value="p.vinculo?.colaborador_id ?? null"
                                    :opciones="[]"
                                    :buscar="buscadorPara(p)"
                                    :etiqueta-seleccionada="p.vinculo?.colaborador ?? null"
                                    numerico
                                    clase-boton="bg-card"
                                    placeholder="Colaborador de PEOPLE…"
                                    :aria-label="`Colaborador de PEOPLE para ${p.nombre}`"
                                    :disabled="enviando !== null"
                                    @update:model-value="(v) => vincularPersona(p.reportes_id, v)"
                                />
                                <div v-if="p.vinculo" class="flex items-center gap-2 text-[11px] text-muted-foreground">
                                    <span>{{ p.vinculo.metodo }}<span v-if="p.vinculo.vinculado_por"> · {{ p.vinculo.vinculado_por }}</span></span>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        :aria-label="`Desvincular ${p.nombre}`"
                                        :disabled="enviando !== null"
                                        @click="desvincular(p.vinculo.id, `p-${p.reportes_id}`)"
                                    >
                                        <Unlink class="size-4" />
                                    </Button>
                                </div>
                            </div>
                            <div
                                v-if="abiertas.has(`p-${p.reportes_id}`)"
                                class="flex flex-wrap gap-1.5 rounded-xl bg-crema/60 px-3 py-2 text-[11px]"
                            >
                                <span v-for="r in p.rutas" :key="r" class="rounded-full border border-border bg-card px-2 py-0.5">{{ r }}</span>
                                <span v-if="p.variantes.length" class="w-full text-muted-foreground">
                                    También aparece como: {{ p.variantes.join(' · ') }}
                                </span>
                            </div>
                        </article>
                    </section>

                    <section v-else key="sucursales" class="flex flex-col gap-2">
                        <EmptyState
                            v-if="sucursales.length === 0"
                            :icono="Building2"
                            titulo="Nada que mostrar"
                            descripcion="Ninguna sucursal de Reportes coincide con el filtro."
                        />
                        <article
                            v-for="s in sucursalesVisibles"
                            :key="s.codigo"
                            class="flex flex-col gap-2 rounded-2xl border border-border bg-card p-3 transicion-ui hover:shadow-sm"
                        >
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="min-w-56 flex-1">
                                    <p class="font-medium">{{ s.nombre }}</p>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-muted-foreground">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-0.5 rounded-full px-1.5 hover:bg-muted"
                                            :aria-expanded="abiertas.has(`s-${s.codigo}`)"
                                            @click="alternar(`s-${s.codigo}`)"
                                        >
                                            <Route class="size-3" aria-hidden="true" />
                                            {{ s.rutas.length }} {{ s.rutas.length === 1 ? 'ruta' : 'rutas' }}
                                            <ChevronDown
                                                class="size-3 transition-transform"
                                                :class="{ 'rotate-180': abiertas.has(`s-${s.codigo}`) }"
                                                aria-hidden="true"
                                            />
                                        </button>
                                        <span v-if="s.vinculo && s.vinculo.rutas_vinculadas < s.rutas.length" class="rounded-full bg-warning-soft px-1.5 text-warning">
                                            {{ s.rutas.length - s.vinculo.rutas_vinculadas }} ruta(s) nueva(s) sin vincular
                                        </span>
                                        <span v-if="s.sin_sucursal_en_reportes" class="rounded-full bg-muted px-1.5">ruta sin sucursal en Reportes</span>
                                        <span v-if="s.con_datos" class="rounded-full bg-success-soft px-1.5 text-success">con actividad</span>
                                        <span v-if="s.mixta" class="inline-flex items-center gap-1 rounded-full bg-warning-soft px-1.5 text-warning">
                                            <TriangleAlert class="size-3" aria-hidden="true" /> rutas repartidas en varias sucursales: elige una
                                        </span>
                                    </div>
                                </div>
                                <div v-if="s.sugerencia" class="flex items-center gap-2 rounded-xl bg-warning-soft px-3 py-1.5 text-sm">
                                    <Sparkles class="size-4 text-warning" aria-hidden="true" />
                                    ¿Es <span class="font-medium">{{ s.sugerencia.sucursal }}</span>?
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        :disabled="enviando !== null"
                                        @click="vincularSucursal(s.codigo, s.sugerencia.sucursal_id, true)"
                                    >
                                        <Check class="size-4" /> Confirmar
                                    </Button>
                                </div>
                                <ComboboxBuscable
                                    class="w-full sm:w-64"
                                    :model-value="s.vinculo?.sucursal_id ?? null"
                                    :opciones="pantalla.opciones_sucursales"
                                    numerico
                                    clase-boton="bg-card"
                                    placeholder="Sucursal de PEOPLE…"
                                    :aria-label="`Sucursal de PEOPLE para ${s.nombre}`"
                                    :disabled="enviando !== null"
                                    @update:model-value="(v) => vincularSucursal(s.codigo, v)"
                                />
                                <div v-if="s.vinculo" class="flex items-center gap-2 text-[11px] text-muted-foreground">
                                    <span>{{ s.vinculo.metodo }}<span v-if="s.vinculo.vinculado_por"> · {{ s.vinculo.vinculado_por }}</span></span>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        :aria-label="`Desvincular ${s.nombre}`"
                                        :disabled="enviando !== null"
                                        @click="desvincular(s.vinculo.id, `s-${s.codigo}`)"
                                    >
                                        <Unlink class="size-4" />
                                    </Button>
                                </div>
                            </div>
                            <div
                                v-if="abiertas.has(`s-${s.codigo}`)"
                                class="flex flex-wrap gap-1.5 rounded-xl bg-crema/60 px-3 py-2 text-[11px]"
                            >
                                <span
                                    v-for="r in s.rutas"
                                    :key="r.id"
                                    class="rounded-full border border-border px-2 py-0.5"
                                    :class="r.sucursal_id ? 'bg-success-soft' : 'bg-card'"
                                >
                                    {{ r.nombre }}
                                </span>
                            </div>
                        </article>
                    </section>
                </CambioSuave>

                <div v-if="visibles < total" class="flex justify-center">
                    <Button variant="outline" @click="visibles += POR_TANDA">
                        Mostrar {{ Math.min(POR_TANDA, total - visibles) }} más ({{ visibles }} de {{ total }})
                    </Button>
                </div>

                <section
                    v-if="vista === 'personas' && pantalla.no_personas.length"
                    class="rounded-2xl border border-dashed border-border bg-crema/40 p-3"
                >
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 text-left text-sm"
                        :aria-expanded="verNoPersonas"
                        @click="verNoPersonas = !verNoPersonas"
                    >
                        <ChevronDown class="size-4 transition-transform" :class="{ 'rotate-180': verNoPersonas }" aria-hidden="true" />
                        <span class="font-medium">{{ pantalla.no_personas.length }} registros de Reportes que no son personas</span>
                        <span class="text-muted-foreground">(vacantes, cartera vencida, códigos): no se vinculan</span>
                    </button>
                    <ul v-if="verNoPersonas" class="mt-2 grid gap-1 text-[12px] sm:grid-cols-2 lg:grid-cols-3">
                        <li v-for="n in pantalla.no_personas" :key="n.reportes_id" class="flex items-center gap-2 rounded-lg bg-card px-2 py-1">
                            <span class="flex-1 truncate">{{ n.nombre }}</span>
                            <span class="rounded-full bg-muted px-1.5 text-[11px] text-muted-foreground">{{ n.motivo }}</span>
                        </li>
                    </ul>
                </section>
            </template>
        </Deferred>
    </div>
</template>
