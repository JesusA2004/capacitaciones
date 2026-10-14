<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ChevronRight, FilePen, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import ActaCampos from '@/components/actas/ActaCampos.vue';
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
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { colaboradores, index, show, store } from '@/routes/rh/actas';
import type {
    ActaResumen,
    ColaboradorBusqueda,
    EstadoActa,
    FormularioActa,
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

// ───── Levantar acta ─────
const abierto = ref(false);
const colaborador = ref<ColaboradorBusqueda | null>(null);
const hoy = new Date();
const hoyIso = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-${String(hoy.getDate()).padStart(2, '0')}`;
const horaAhora = `${String(hoy.getHours()).padStart(2, '0')}:${String(hoy.getMinutes()).padStart(2, '0')}`;

const form = useForm<FormularioActa & { tipo: string }>({
    tipo: 'administrativa',
    motivo: '',
    fecha: hoyIso,
    hora: horaAhora,
    lugar: '',
    hechos: '',
    testigos: [
        { colaborador_id: null, nombre: '', puesto: '' },
        { colaborador_id: null, nombre: '', puesto: '' },
    ],
    declaraciones: [{ persona: '', declaracion: '' }],
});

const pideMotivo = computed(() => form.tipo === 'administrativa');

// Dos testigos distintos entre sí y distintos del colaborador del acta.
const testigosValidos = computed(() => {
    const [a, b] = form.testigos;

    if (!a?.colaborador_id || !b?.colaborador_id) {
return false;
}

    if (a.colaborador_id === b.colaborador_id) {
return false;
}

    if (colaborador.value && [a.colaborador_id, b.colaborador_id].includes(colaborador.value.id)) {
return false;
}

    return true;
});

function abrir() {
    form.reset();
    form.clearErrors();
    colaborador.value = null;
    abierto.value = true;
}

// La primera manifestación es la del colaborador: se propone su nombre.
// El lugar se precarga con la ciudad de su sucursal, editable.
function elegirColaborador(c: ColaboradorBusqueda | null) {
    colaborador.value = c;
    const primera = form.declaraciones[0];

    if (c && primera && primera.persona.trim() === '') {
        primera.persona = c.nombre;
    }

    if (c?.ciudad && form.lugar.trim() === '') {
        form.lugar = c.ciudad;
    }
}

function levantar() {
    if (!colaborador.value || !testigosValidos.value) {
        return;
    }

    form.transform((datos) => ({
        ...datos,
        lugar: datos.lugar || null,
        hora: datos.hora || null,
        declaraciones: datos.declaraciones.filter(
            (d) => d.persona.trim() !== '' || d.declaracion.trim() !== '',
        ),
    })).post(store.url(colaborador.value.id), {
        onSuccess: () => {
            abierto.value = false;
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
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Levantar acta</DialogTitle>
                <DialogDescription>
                    Nombre, puesto y contrato del colaborador los pone el
                    sistema. Después generas el formato oficial para firmar.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="levantar">
                <div class="flex flex-col gap-1.5">
                    <Label>Colaborador</Label>
                    <BuscadorColaborador
                        :model-value="colaborador"
                        :buscar-url="
                            (texto) =>
                                colaboradores.url({ query: { q: texto } })
                        "
                        @update:model-value="elegirColaborador"
                    />
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="acta-tipo">Tipo</Label>
                    <SelectSimple
                        id="acta-tipo"
                        v-model="form.tipo"
                        :opciones="tipos"
                    />
                    <InputError :message="form.errors.tipo" />
                </div>

                <ActaCampos
                    :model-value="form"
                    :errores="form.errors"
                    :pide-motivo="pideMotivo"
                    :sujeto-id="colaborador?.id ?? null"
                />

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="abierto = false"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :disabled="!colaborador || !testigosValidos || form.processing"
                    >
                        Levantar acta
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
