<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ChevronRight, FilePen, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import ActaCircunstanciadaCampos from '@/components/actas/ActaCircunstanciadaCampos.vue';
import ActaPasos from '@/components/actas/ActaPasos.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { parametrosPorPaginaActuales } from '@/composables/usePaginacion';
import { cargaActa, formularioVacio } from '@/lib/actas';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { colaboradores, index, show, store } from '@/routes/rh/actas';
import type {
    ActaResumen,
    CatalogosActa,
    ColaboradorBusqueda,
    EstadoActa,
    FormularioCircunstanciada,
} from '@/types';

type Opcion = { value: string; label: string };

const props = defineProps<{
    actas: {
        data: ActaResumen[];
        current_page: number;
        last_page: number;
        per_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    filtros: { tipo?: string; estado?: string };
    tipos: Opcion[];
    estados: Opcion[];
    puedeCrear: boolean;
    catalogos: CatalogosActa;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Actas administrativas', href: '' },
        ],
    },
});

const tipoFiltro = ref<string | undefined>(props.filtros.tipo);
const estadoFiltro = ref<string | undefined>(props.filtros.estado);

function filtrar() {
    router.get(
        index.url(),
        {
            ...parametrosPorPaginaActuales(),
            tipo: tipoFiltro.value || undefined,
            estado: estadoFiltro.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const COLOR_ESTADO: Record<EstadoActa, string> = {
    borrador: 'bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)]',
    generada: 'bg-warning/10 text-warning',
    firmada: 'bg-success/10 text-success',
    cerrada: 'bg-success/10 text-success',
    cancelada: 'bg-destructive/10 text-destructive',
};

// ───── Levantar acta (pasos 1 y 2; el borrador sigue en su detalle) ─────
const abierto = ref(false);
const paso = ref<1 | 2>(1);
const colaborador = ref<ColaboradorBusqueda | null>(null);
const form = useForm<FormularioCircunstanciada>(formularioVacio());

function abrir() {
    form.defaults(formularioVacio());
    form.reset();
    form.clearErrors();
    colaborador.value = null;
    paso.value = 1;
    abierto.value = true;
}

// El lugar se precarga con la ciudad de su sucursal, editable.
function elegirColaborador(c: ColaboradorBusqueda | null) {
    colaborador.value = c;

    if (c?.ciudad && form.lugar.trim() === '') {
        form.lugar = c.ciudad;
    }
}

const incidencia = computed(() => form.circunstanciada.incidencia);
const paso1Listo = computed(
    () =>
        !!colaborador.value &&
        !!incidencia.value.tipo &&
        (incidencia.value.tipo !== 'otra' ||
            incidencia.value.otro.trim() !== '') &&
        !!incidencia.value.fecha_desde &&
        !!form.fecha,
);

// Un error del paso 1 lleva de regreso al paso 1.
const CAMPOS_PASO_1 = ['fecha', 'hora', 'lugar', 'circunstanciada.incidencia'];

function levantar() {
    if (!colaborador.value || !paso1Listo.value || !form.hechos.trim()) {
        return;
    }

    form.transform((datos) => ({
        tipo: 'administrativa',
        ...cargaActa(datos),
    })).post(store.url(colaborador.value.id), {
        onSuccess: () => {
            abierto.value = false;
        },
        onError: (errores) => {
            if (
                Object.keys(errores).some((k) =>
                    CAMPOS_PASO_1.some((p) => k.startsWith(p)),
                )
            ) {
                paso.value = 1;
            }
        },
    });
}
</script>

<template>
    <Head title="Actas administrativas" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Actas administrativas"
            descripcion="Incidencias laborales: se levanta el acta, se genera el formato oficial, se firma (o se asienta la negativa) y queda en el expediente."
            :icono="FilePen"
        >
            <Button v-if="puedeCrear" data-tour="actas-levantar" @click="abrir">
                <Plus class="size-4" /> Levantar acta
            </Button>
        </CrudPageHeader>

        <div class="flex flex-wrap items-end gap-3" data-tour="actas-filtros">
            <div class="flex w-full flex-col gap-1.5 sm:w-56">
                <Label for="filtro-tipo">Tipo</Label>
                <SelectSimple
                    id="filtro-tipo"
                    v-model="tipoFiltro"
                    :opciones="tipos"
                    opcion-vacia="Todos"
                    @update:model-value="filtrar"
                />
            </div>
            <div class="flex w-full flex-col gap-1.5 sm:w-56">
                <Label for="filtro-estado">Estado</Label>
                <SelectSimple
                    id="filtro-estado"
                    v-model="estadoFiltro"
                    :opciones="estados"
                    opcion-vacia="Todos"
                    @update:model-value="filtrar"
                />
            </div>
        </div>

        <section
            data-tour="actas-lista"
            class="overflow-hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
        >
            <ul
                v-if="actas.data.length"
                class="divide-y divide-[var(--mrl-borde)]"
            >
                <li v-for="a in actas.data" :key="a.id">
                    <Link
                        :href="show.url(a.id)"
                        class="flex tarjeta-interactiva flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3"
                    >
                        <span
                            class="w-24 shrink-0 font-mono text-xs text-muted-foreground"
                            >{{ a.folio }}</span
                        >
                        <ColaboradorAvatar
                            :nombre="a.colaborador.nombre"
                            :foto-url="a.colaborador.foto_url"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{
                                a.colaborador.nombre
                            }}</span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                            >
                                {{ a.tipo_etiqueta
                                }}<template v-if="a.motivo">
                                    · {{ a.motivo }}</template
                                >
                            </span>
                        </span>
                        <span class="text-xs text-muted-foreground">{{
                            formatearFecha(a.fecha)
                        }}</span>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs"
                            :class="COLOR_ESTADO[a.estado]"
                            >{{ a.estado_etiqueta }}</span
                        >
                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                    </Link>
                </li>
            </ul>
            <p
                v-else
                class="px-4 py-10 text-center text-sm text-muted-foreground"
            >
                No hay actas con estos filtros.
            </p>
        </section>

        <PaginacionTabla :datos="actas" />
    </div>

    <Dialog v-model:open="abierto">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Acta administrativa circunstanciada</DialogTitle>
                <DialogDescription>
                    Los datos del trabajador, la empresa y el centro de trabajo
                    los pone PEOPLE. Captura la incidencia y los hechos; los
                    participantes y las manifestaciones siguen en el borrador.
                </DialogDescription>
            </DialogHeader>

            <form
                class="flex flex-col gap-5"
                @submit.prevent="paso === 1 ? (paso = 2) : levantar()"
            >
                <ActaPasos
                    :actual="paso"
                    :pasos="[1, 2]"
                    @ir="(p) => (p === 1 || paso1Listo) && (paso = p as 1 | 2)"
                />

                <div v-if="paso === 1" class="flex flex-col gap-1.5">
                    <Label>Trabajador involucrado</Label>
                    <BuscadorColaborador
                        :model-value="colaborador"
                        :buscar-url="
                            (texto) =>
                                colaboradores.url({ query: { q: texto } })
                        "
                        @update:model-value="elegirColaborador"
                    />
                    <InputError
                        :message="
                            (form.errors as Partial<Record<string, string>>)
                                .tipo
                        "
                    />
                </div>

                <div :key="paso" class="entrada-suave">
                    <ActaCircunstanciadaCampos
                        v-model="form"
                        :paso="paso"
                        :errores="form.errors"
                        :catalogos="catalogos"
                        :trabajador="{
                            id: colaborador?.id ?? 0,
                            nombre: colaborador?.nombre ?? '',
                        }"
                    />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        @click="paso === 1 ? (abierto = false) : (paso = 1)"
                    >
                        {{ paso === 1 ? 'Cancelar' : 'Atrás' }}
                    </Button>
                    <Button
                        v-if="paso === 1"
                        type="submit"
                        :disabled="!paso1Listo"
                    >
                        Continuar con los hechos <ChevronRight class="size-4" />
                    </Button>
                    <Button
                        v-else
                        type="submit"
                        :disabled="!form.hechos.trim() || form.processing"
                    >
                        Guardar borrador y continuar
                        <ChevronRight class="size-4" />
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
