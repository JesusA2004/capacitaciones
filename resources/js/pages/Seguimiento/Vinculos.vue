<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { Check, Link2, Search, Sparkles, Unlink } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import AvisoReportes from '@/components/Seguimiento/AvisoReportes.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import { Input } from '@/components/ui/input';
import SelectorVista from '@/components/ui/SelectorVista.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard } from '@/routes';
import { index as indexSeguimiento } from '@/routes/seguimiento';
import rutasVinculos from '@/routes/seguimiento/vinculos';
import { store as guardarColaborador } from '@/routes/seguimiento/vinculos/colaboradores';
import { store as guardarSucursal } from '@/routes/seguimiento/vinculos/sucursales';
import type { PantallaVinculos, RespuestaSeguimiento } from '@/types';

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

type Vista = 'sucursales' | 'gestores';
type Filtro = 'con_datos' | 'sin_vincular' | 'todos';

const vista = ref<Vista>('sucursales');
const filtro = ref<Filtro>('con_datos');
const busqueda = ref('');
const enviando = ref<string | null>(null);

const pantalla = computed(() => props.datos?.datos ?? null);

function normalizar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

function pasaFiltro(conDatos: boolean, vinculado: boolean, texto: string): boolean {
    if (filtro.value === 'con_datos' && !conDatos) {
        return false;
    }

    if (filtro.value === 'sin_vincular' && vinculado) {
        return false;
    }

    return busqueda.value.trim() === '' || normalizar(texto).includes(normalizar(busqueda.value.trim()));
}

const branches = computed(() =>
    (pantalla.value?.branches ?? []).filter((b) =>
        pasaFiltro(b.con_datos, b.vinculo !== null, `${b.nombre} ${b.codigo} ${b.vinculo?.sucursal ?? ''}`),
    ),
);

const empleados = computed(() =>
    (pantalla.value?.empleados ?? []).filter((e) =>
        pasaFiltro(e.con_datos, e.vinculo !== null, `${e.nombre} ${e.clave ?? ''} ${e.branch ?? ''} ${e.vinculo?.colaborador ?? ''}`),
    ),
);

const sugerenciasSucursal = computed(() => (pantalla.value?.branches ?? []).filter((b) => b.sugerencia).length);
const sugerenciasGestor = computed(() => (pantalla.value?.empleados ?? []).filter((e) => e.sugerencia).length);

function enviar(clave: string, accion: () => void): void {
    enviando.value = clave;
    accion();
}

const opcionesEnvio = {
    preserveScroll: true,
    preserveState: true,
    only: ['datos', 'flash'],
    onFinish: () => {
        enviando.value = null;
    },
};

function vincularSucursal(reportesId: number, sucursalId: number | string | null, sugerencia = false): void {
    if (sucursalId === null || sucursalId === '') {
        return;
    }

    enviar(`b-${reportesId}`, () =>
        router.post(guardarSucursal.url(), { reportes_id: reportesId, sucursal_id: Number(sucursalId), sugerencia }, opcionesEnvio),
    );
}

function vincularColaborador(reportesId: number, colaboradorId: number | string | null, sugerencia = false): void {
    if (colaboradorId === null || colaboradorId === '') {
        return;
    }

    enviar(`e-${reportesId}`, () =>
        router.post(guardarColaborador.url(), { reportes_id: reportesId, colaborador_id: Number(colaboradorId), sugerencia }, opcionesEnvio),
    );
}

function desvincular(vinculoId: number, clave: string): void {
    enviar(clave, () => router.delete(rutasVinculos.destroy.url(vinculoId), opcionesEnvio));
}

function confirmarSugerencias(tipo: 'sucursal' | 'colaborador'): void {
    enviar(`todas-${tipo}`, () => router.post(rutasVinculos.confirmarSugerencias.url(), { tipo }, opcionesEnvio));
}
</script>

<template>
    <Head title="Vínculos con Reportes" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Vínculos con Reportes"
            descripcion="Qué sucursal y qué colaborador de PEOPLE corresponde a cada uno de Reportes."
            :icono="Link2"
        />

        <section class="rounded-2xl border border-border bg-crema/60 p-4 text-sm text-pretty">
            <p class="font-medium">¿Por qué hay que vincular?</p>
            <p class="mt-1 text-muted-foreground">
                PEOPLE y Reportes no comparten una llave confiable: la clave de empleado de Reportes (NOI) no es única y
                sus sucursales son oficinas/rutas propias. PEOPLE <span class="font-medium">sugiere</span> cuando el
                nombre es idéntico y único; tú confirmas. Cambiar un vínculo nunca borra el anterior: queda en el
                historial con quién y cuándo.
            </p>
        </section>

        <Deferred data="datos">
            <template #fallback>
                <div class="flex flex-col gap-3" aria-busy="true">
                    <Skeleton class="h-12 w-72 rounded-xl" />
                    <Skeleton v-for="i in 6" :key="i" class="h-16 rounded-2xl" />
                </div>
            </template>

            <AvisoReportes v-if="datos?.error" :mensaje="datos.error" />

            <template v-else-if="pantalla">
                <section class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-4">
                    <div class="rounded-2xl border border-border bg-salvia p-4">
                        <p class="text-xs text-muted-foreground">Sucursales de Reportes vinculadas</p>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ pantalla.totales.branches_vinculados }} / {{ pantalla.totales.branches }}
                        </p>
                    </div>
                    <div class="rounded-2xl border border-border bg-card p-4">
                        <p class="text-xs text-muted-foreground">Colaboradores de Reportes vinculados</p>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ pantalla.totales.empleados_vinculados }} / {{ pantalla.totales.empleados }}
                        </p>
                    </div>
                    <div class="rounded-2xl border border-border bg-warning-soft p-4">
                        <p class="text-xs text-muted-foreground">Sugerencias por confirmar</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ pantalla.totales.sugerencias }}</p>
                    </div>
                    <div class="rounded-2xl border border-border bg-card p-4">
                        <p class="text-xs text-muted-foreground">«Con datos» = actividad en</p>
                        <p class="text-lg font-semibold">{{ pantalla.mes ?? '—' }}</p>
                    </div>
                </section>

                <div class="flex flex-wrap items-center gap-3">
                    <SelectorVista
                        v-model="vista"
                        etiqueta="Qué vincular"
                        :opciones="[
                            { valor: 'sucursales', etiqueta: 'Sucursales', contador: sugerenciasSucursal },
                            { valor: 'gestores', etiqueta: 'Gestores', contador: sugerenciasGestor },
                        ]"
                    />
                    <SelectorVista
                        v-model="filtro"
                        etiqueta="Mostrar"
                        :opciones="[
                            { valor: 'con_datos', etiqueta: 'Con datos' },
                            { valor: 'sin_vincular', etiqueta: 'Sin vincular' },
                            { valor: 'todos', etiqueta: 'Todos' },
                        ]"
                    />
                    <div class="relative w-full sm:w-72">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input v-model="busqueda" class="pl-9" placeholder="Buscar…" aria-label="Buscar" />
                    </div>
                    <Button
                        v-if="(vista === 'sucursales' ? sugerenciasSucursal : sugerenciasGestor) > 0"
                        class="ml-auto"
                        :disabled="enviando !== null"
                        @click="confirmarSugerencias(vista === 'sucursales' ? 'sucursal' : 'colaborador')"
                    >
                        <Sparkles class="size-4" />
                        Confirmar {{ vista === 'sucursales' ? sugerenciasSucursal : sugerenciasGestor }} sugerencias
                    </Button>
                </div>

                <CambioSuave>
                    <section v-if="vista === 'sucursales'" key="sucursales" class="flex flex-col gap-2">
                        <EmptyState
                            v-if="branches.length === 0"
                            :icono="Link2"
                            titulo="Nada que mostrar"
                            descripcion="Ninguna sucursal de Reportes coincide con el filtro."
                        />
                        <div
                            v-for="b in branches"
                            :key="b.reportes_id"
                            class="flex flex-wrap items-center gap-3 rounded-2xl border border-border bg-card p-3 transicion-ui hover:shadow-sm"
                        >
                            <div class="min-w-48 flex-1">
                                <p class="font-medium">{{ b.nombre }}</p>
                                <p class="text-[11px] text-muted-foreground">
                                    Reportes · {{ b.codigo }}<span v-if="b.sucursal_reportes"> · {{ b.sucursal_reportes }}</span>
                                    <span v-if="b.con_datos" class="ml-1 rounded-full bg-success-soft px-1.5 text-success">con datos</span>
                                </p>
                            </div>
                            <div
                                v-if="b.sugerencia && !b.vinculo"
                                class="flex items-center gap-2 rounded-xl bg-warning-soft px-3 py-1.5 text-sm"
                            >
                                <Sparkles class="size-4 text-warning" aria-hidden="true" />
                                ¿Es <span class="font-medium">{{ b.sugerencia.sucursal }}</span>?
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="enviando !== null"
                                    @click="vincularSucursal(b.reportes_id, b.sugerencia.sucursal_id, true)"
                                >
                                    <Check class="size-4" /> Confirmar
                                </Button>
                            </div>
                            <ComboboxBuscable
                                class="w-full sm:w-64"
                                :model-value="b.vinculo?.sucursal_id ?? null"
                                :opciones="pantalla.opciones_sucursales"
                                numerico
                                clase-boton="bg-card"
                                placeholder="Sucursal de PEOPLE…"
                                :aria-label="`Sucursal de PEOPLE para ${b.nombre}`"
                                :disabled="enviando !== null"
                                @update:model-value="(v) => vincularSucursal(b.reportes_id, v)"
                            />
                            <div v-if="b.vinculo" class="flex items-center gap-2 text-[11px] text-muted-foreground">
                                <span>{{ b.vinculo.metodo }}<span v-if="b.vinculo.vinculado_por"> · {{ b.vinculo.vinculado_por }}</span></span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    :aria-label="`Desvincular ${b.nombre}`"
                                    :disabled="enviando !== null"
                                    @click="desvincular(b.vinculo.id, `b-${b.reportes_id}`)"
                                >
                                    <Unlink class="size-4" />
                                </Button>
                            </div>
                        </div>
                    </section>

                    <section v-else key="gestores" class="flex flex-col gap-2">
                        <EmptyState
                            v-if="empleados.length === 0"
                            :icono="Link2"
                            titulo="Nada que mostrar"
                            descripcion="Ningún colaborador de Reportes coincide con el filtro."
                        />
                        <div
                            v-for="e in empleados"
                            :key="e.reportes_id"
                            class="flex flex-wrap items-center gap-3 rounded-2xl border border-border bg-card p-3 transicion-ui hover:shadow-sm"
                        >
                            <div class="min-w-48 flex-1">
                                <p class="font-medium">{{ e.nombre }}</p>
                                <p class="text-[11px] text-muted-foreground">
                                    Reportes · clave {{ e.clave ?? '—' }}<span v-if="e.branch"> · {{ e.branch }}</span>
                                    <span v-if="e.con_datos" class="ml-1 rounded-full bg-success-soft px-1.5 text-success">con datos</span>
                                    <span v-if="e.homonimos" class="ml-1 rounded-full bg-warning-soft px-1.5 text-warning">homónimos: elige a mano</span>
                                </p>
                            </div>
                            <div
                                v-if="e.sugerencia && !e.vinculo"
                                class="flex items-center gap-2 rounded-xl bg-warning-soft px-3 py-1.5 text-sm"
                            >
                                <Sparkles class="size-4 text-warning" aria-hidden="true" />
                                ¿Es <span class="font-medium">{{ e.sugerencia.colaborador }}</span>?
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="enviando !== null"
                                    @click="vincularColaborador(e.reportes_id, e.sugerencia.colaborador_id, true)"
                                >
                                    <Check class="size-4" /> Confirmar
                                </Button>
                            </div>
                            <ComboboxBuscable
                                class="w-full sm:w-80"
                                :model-value="e.vinculo?.colaborador_id ?? null"
                                :opciones="pantalla.opciones_colaboradores.map((o) => ({ ...o, descripcion: o.descripcion ?? undefined }))"
                                numerico
                                clase-boton="bg-card"
                                placeholder="Colaborador de PEOPLE…"
                                :aria-label="`Colaborador de PEOPLE para ${e.nombre}`"
                                :disabled="enviando !== null"
                                @update:model-value="(v) => vincularColaborador(e.reportes_id, v)"
                            />
                            <div v-if="e.vinculo" class="flex items-center gap-2 text-[11px] text-muted-foreground">
                                <span>{{ e.vinculo.metodo }}<span v-if="e.vinculo.vinculado_por"> · {{ e.vinculo.vinculado_por }}</span></span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    :aria-label="`Desvincular ${e.nombre}`"
                                    :disabled="enviando !== null"
                                    @click="desvincular(e.vinculo.id, `e-${e.reportes_id}`)"
                                >
                                    <Unlink class="size-4" />
                                </Button>
                            </div>
                        </div>
                    </section>
                </CambioSuave>
            </template>
        </Deferred>
    </div>
</template>
