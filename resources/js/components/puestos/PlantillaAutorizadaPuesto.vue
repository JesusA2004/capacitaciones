<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import {
    Building2,
    ChevronDown,
    History,
    MapPin,
    Pencil,
    Plus,
    UsersRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { fuentePlantillaEtiqueta } from '@/lib/fuentesPlantilla';
import { update as actualizarPlantilla } from '@/routes/administracion/puestos/plantilla';
import { index as indexVacantes } from '@/routes/rh/vacantes';
import type { PlantillaPuesto, UbicacionPlantillaPuesto } from '@/types';

/**
 * «Plantilla autorizada» del puesto: cuántas plazas tiene en cada
 * ubicación (sucursales y Corporativo), cuántas están ocupadas y cuántas
 * son vacantes. RH (headcount.editar) autoriza N plazas de cualquier
 * puesto —también corporativos, p. ej. Auditora: 4 → 3 vacantes— con
 * motivo obligatorio; la misma bitácora y la misma regla que el detalle
 * de sucursal (PlantillaAutorizadaService). Las vacantes se ajustan solas.
 */
const props = defineProps<{
    puestoId: number;
    puestoNombre: string;
    plantilla: PlantillaPuesto;
    puedeEditar: boolean;
}>();

const editando = ref<{ sucursal_id: number | null; sucursal: string } | null>(
    null,
);
const verHistorial = ref(false);
const form = useForm({
    sucursal_id: null as number | null,
    plantilla_autorizada: 1,
    motivo: '',
});

const opcionesUbicacion = computed(() =>
    props.plantilla.ubicaciones_disponibles.map((u) => ({
        value: u.id,
        label: u.es_corporativo ? `${u.nombre} (Corporativo)` : u.nombre,
    })),
);

function editar(fila: UbicacionPlantillaPuesto | null): void {
    form.reset();
    form.clearErrors();

    if (fila) {
        editando.value = {
            sucursal_id: fila.sucursal_id,
            sucursal: fila.sucursal,
        };
        form.sucursal_id = fila.sucursal_id;
        form.plantilla_autorizada = fila.autorizadas;

        return;
    }

    // Puesto de empresa: Corporativo es la ubicación natural.
    const corporativo = props.plantilla.ubicaciones_disponibles.find(
        (u) => u.es_corporativo,
    );
    editando.value = { sucursal_id: null, sucursal: '' };
    form.sucursal_id = props.plantilla.es_de_empresa
        ? (corporativo?.id ?? null)
        : null;
    form.plantilla_autorizada = 1;
}

function guardar(): void {
    if (!form.sucursal_id) {
        form.setError('sucursal_id', 'Elige la ubicación.');

        return;
    }

    form.put(actualizarPlantilla.url(props.puestoId), {
        preserveScroll: true,
        onSuccess: () => (editando.value = null),
    });
}

const vistaPrevia = computed(() => {
    const fila = props.plantilla.ubicaciones.find(
        (u) => u.sucursal_id === form.sucursal_id,
    );
    const ocupadas = fila ? fila.ocupadas + fila.excedentes : 0;
    const plazas = Number(form.plantilla_autorizada) || 0;

    return {
        ocupadas,
        vacantes: Math.max(plazas - ocupadas, 0),
    };
});

const formatoFecha = new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function plazasTexto(n: number): string {
    return n === 1 ? '1 plaza' : `${n} plazas`;
}
</script>

<template>
    <section
        class="rounded-3xl border bg-card p-4 sm:p-5"
        aria-labelledby="plantilla-autorizada-puesto"
    >
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2
                    id="plantilla-autorizada-puesto"
                    class="flex items-center gap-2 font-semibold"
                >
                    <UsersRound class="size-4 text-primary" />
                    Plantilla autorizada
                </h2>
                <p class="text-sm text-muted-foreground">
                    Plazas de {{ puestoNombre }} por ubicación. Cada plaza sin
                    persona es una vacante
                    <template
                        v-if="plantilla.etiqueta_vacante !== puestoNombre"
                    >
                        («{{ plantilla.etiqueta_vacante }}» en
                        Vacantes)</template
                    >.
                    <template v-if="plantilla.comparte_con">
                        Comparte lugar con {{ plantilla.comparte_con }}: si no
                        hay plaza libre de un puesto, la persona ocupa una libre
                        del otro.
                    </template>
                </p>
            </div>
            <Button
                v-if="puedeEditar"
                size="sm"
                variant="outline"
                @click="editar(null)"
            >
                <Plus class="size-4" /> Autorizar en otra ubicación
            </Button>
        </header>

        <dl class="mt-3 grid grid-cols-3 gap-2 text-center sm:max-w-md">
            <div class="rounded-2xl bg-crema/60 px-2 py-2">
                <dt class="text-[11px] text-muted-foreground">Autorizadas</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ plantilla.totales.autorizadas }}
                </dd>
            </div>
            <div class="rounded-2xl bg-menta/70 px-2 py-2">
                <dt class="text-[11px] text-muted-foreground">Ocupadas</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ plantilla.totales.ocupadas }}
                </dd>
            </div>
            <Link
                :href="indexVacantes.url({ query: { puesto_id: puestoId } })"
                class="tarjeta-interactiva rounded-2xl px-2 py-2"
                :class="
                    plantilla.totales.vacantes > 0
                        ? 'bg-warning-soft/70'
                        : 'bg-success-soft/60'
                "
                :title="`Ver vacantes de ${puestoNombre}`"
            >
                <dt class="text-[11px] text-muted-foreground">Vacantes</dt>
                <dd
                    class="text-xl font-semibold tabular-nums"
                    :class="
                        plantilla.totales.vacantes > 0
                            ? 'text-warning'
                            : 'text-success'
                    "
                >
                    {{ plantilla.totales.vacantes }}
                </dd>
            </Link>
        </dl>

        <p
            v-if="plantilla.ubicaciones.length === 0"
            class="mt-3 rounded-2xl bg-crema/40 px-4 py-6 text-center text-sm text-muted-foreground"
        >
            Este puesto todavía no tiene plazas autorizadas en ninguna
            ubicación.
            <template v-if="puedeEditar">
                Usa «Autorizar en otra ubicación» para abrir sus vacantes.
            </template>
        </p>
        <ul v-else class="mt-3 divide-y rounded-2xl border">
            <li
                v-for="fila in plantilla.ubicaciones"
                :key="fila.sucursal_id"
                class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-3 py-2.5"
            >
                <p
                    class="flex min-w-0 items-center gap-1.5 text-sm font-medium"
                >
                    <Building2
                        v-if="fila.es_corporativo"
                        class="size-4 shrink-0 text-primary"
                    />
                    <MapPin
                        v-else
                        class="size-4 shrink-0 text-muted-foreground"
                    />
                    <span class="truncate">{{ fila.sucursal }}</span>
                    <span
                        v-if="fila.es_corporativo"
                        class="rounded-full bg-crema px-2 py-0.5 text-[11px] font-medium"
                        >Corporativo</span
                    >
                </p>
                <div class="flex items-center gap-1.5">
                    <p class="text-right text-sm tabular-nums">
                        <span class="font-semibold">{{ fila.ocupadas }}</span>
                        <span class="text-muted-foreground">
                            / {{ fila.autorizadas }} ocupadas</span
                        >
                    </p>
                    <Button
                        v-if="puedeEditar"
                        size="icon-sm"
                        variant="ghost"
                        :aria-label="`Editar plazas de ${puestoNombre} en ${fila.sucursal}`"
                        @click="editar(fila)"
                    >
                        <Pencil class="size-3.5" />
                    </Button>
                </div>
                <p
                    v-if="fila.autorizadas === 0 && fila.excedentes > 0"
                    class="col-span-2 text-xs text-warning"
                >
                    {{ fila.excedentes }} sin plaza autorizada aquí (fuera de
                    plantilla)
                </p>
                <p
                    v-else-if="fila.excedentes > 0"
                    class="col-span-2 text-xs text-muted-foreground"
                >
                    {{ fila.excedentes }} por encima de lo autorizado
                </p>
                <p
                    v-else-if="fila.vacantes > 0"
                    class="col-span-2 text-xs text-warning"
                >
                    {{ fila.vacantes }}
                    {{ fila.vacantes === 1 ? 'vacante' : 'vacantes' }}
                </p>
            </li>
        </ul>

        <div v-if="plantilla.historial.length > 0" class="mt-3">
            <button
                type="button"
                class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"
                :aria-expanded="verHistorial"
                @click="verHistorial = !verHistorial"
            >
                <History class="size-3.5" />
                Histórico de plazas ({{ plantilla.historial.length }})
                <ChevronDown
                    class="size-3.5 transition-transform"
                    :class="verHistorial && 'rotate-180'"
                />
            </button>
            <ul v-if="verHistorial" class="mt-2 divide-y rounded-2xl border">
                <li
                    v-for="cambio in plantilla.historial"
                    :key="cambio.id"
                    class="grid gap-1 px-3 py-2 text-sm sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                >
                    <div class="min-w-0">
                        <p class="font-medium break-words">
                            {{ cambio.sucursal ?? '—' }}:
                            <span class="text-muted-foreground tabular-nums">{{
                                cambio.valor_anterior ?? 'sin plazas'
                            }}</span>
                            →
                            <span class="font-semibold tabular-nums">{{
                                cambio.valor_nuevo
                            }}</span>
                        </p>
                        <p
                            v-if="cambio.motivo"
                            class="text-xs break-words text-muted-foreground"
                        >
                            {{ cambio.motivo }}
                        </p>
                    </div>
                    <p class="text-xs text-muted-foreground sm:text-right">
                        {{ cambio.usuario ?? 'Sistema' }} ·
                        {{ fuentePlantillaEtiqueta(cambio.fuente) }}<br />
                        {{ formatoFecha.format(new Date(cambio.fecha)) }}
                    </p>
                </li>
            </ul>
        </div>
    </section>

    <Dialog
        :open="editando !== null"
        @update:open="(abierto: boolean) => !abierto && (editando = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>
                    {{
                        editando?.sucursal
                            ? `Plazas de ${puestoNombre} en ${editando.sucursal}`
                            : `Autorizar plazas de ${puestoNombre}`
                    }}
                </DialogTitle>
                <DialogDescription>
                    El cambio queda en el histórico con tu nombre y el motivo.
                    Las vacantes se abren o se cierran solas.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="guardar">
                <div v-if="editando && !editando.sucursal" class="grid gap-1.5">
                    <Label for="plantilla-ubicacion">Ubicación</Label>
                    <SelectSimple
                        id="plantilla-ubicacion"
                        v-model="form.sucursal_id"
                        numerico
                        :opciones="opcionesUbicacion"
                        placeholder="Elige sucursal o Corporativo"
                    />
                    <InputError :message="form.errors.sucursal_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="plantilla-plazas">Plazas autorizadas</Label>
                    <Input
                        id="plantilla-plazas"
                        v-model.number="form.plantilla_autorizada"
                        type="number"
                        min="0"
                        max="999"
                        inputmode="numeric"
                        class="w-32"
                    />
                    <p
                        v-if="form.sucursal_id"
                        class="text-xs text-muted-foreground"
                    >
                        {{ vistaPrevia.ocupadas }}
                        {{
                            vistaPrevia.ocupadas === 1
                                ? 'persona la ocupa'
                                : 'personas las ocupan'
                        }}
                        → quedarán
                        <strong class="text-foreground">{{
                            plazasTexto(vistaPrevia.vacantes)
                        }}</strong>
                        vacante{{ vistaPrevia.vacantes === 1 ? '' : 's' }}.
                    </p>
                    <InputError :message="form.errors.plantilla_autorizada" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="plantilla-motivo">Motivo del cambio</Label>
                    <Textarea
                        id="plantilla-motivo"
                        v-model="form.motivo"
                        rows="2"
                        placeholder="Ej. Dirección autorizó 4 auditoras para el corporativo"
                    />
                    <InputError :message="form.errors.motivo" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="editando = null"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :disabled="
                            form.processing || form.motivo.trim().length < 5
                        "
                    >
                        <Spinner v-if="form.processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
