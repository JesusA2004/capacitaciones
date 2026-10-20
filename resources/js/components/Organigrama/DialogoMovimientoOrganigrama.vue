<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDownToLine,
    ArrowRight,
    ArrowUpToLine,
    MoveRight,
    UserRoundCheck,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { getJson, leerCookie } from '@/lib/http';
import { claseChipRegion, postJsonConErrores } from '@/lib/organigramaEditable';
import {
    mover,
    opciones as opcionesUrl,
    vistaPrevia as vistaPreviaUrl,
} from '@/routes/administracion/jerarquia-puestos/personas';
import type {
    AccionMovimientoOrganigrama,
    NodoOrganigramaPersona,
    OpcionesMovimientoOrganigrama,
    OpcionPuestoOrganigrama,
    VistaPreviaMovimientoOrganigrama,
} from '@/types';

/**
 * Subir / Bajar / Mover bajo… con confirmación clara («Vas a mover a X de Y
 * a Z» + quién será su jefe). La vista previa la calcula el backend sin
 * guardar nada (MovimientoOrganigramaService::vistaPrevia); aplicar pasa
 * por el movimiento laboral y resincroniza el jefe directo.
 */
const props = defineProps<{
    nodo: NodoOrganigramaPersona | null;
    accionInicial: AccionMovimientoOrganigrama;
}>();

const abierto = defineModel<boolean>('open', { required: true });

const ACCIONES: {
    valor: AccionMovimientoOrganigrama;
    etiqueta: string;
    icono: Component;
}[] = [
    { valor: 'subir', etiqueta: 'Subir de puesto', icono: ArrowUpToLine },
    { valor: 'bajar', etiqueta: 'Bajar de puesto', icono: ArrowDownToLine },
    { valor: 'mover', etiqueta: 'Mover bajo…', icono: MoveRight },
];

const accion = ref<AccionMovimientoOrganigrama>('subir');
const opciones = ref<OpcionesMovimientoOrganigrama | null>(null);
const cargando = ref(false);
const errorCarga = ref<string | null>(null);

const puestoId = ref<number | null>(null);
const sucursalId = ref<number | null>(null);
const regionId = ref<number | null>(null);
const destinoClave = ref<string | null>(null);

const previa = ref<VistaPreviaMovimientoOrganigrama | null>(null);
const errorPrevia = ref<string | null>(null);
const calculando = ref(false);
const enviando = ref(false);

const personaId = computed(() => props.nodo?.persona?.id ?? null);

const puestosDeAccion = computed<OpcionPuestoOrganigrama[]>(() => {
    if (!opciones.value) {
        return [];
    }

    return accion.value === 'subir'
        ? opciones.value.subir
        : accion.value === 'bajar'
          ? opciones.value.bajar
          : [];
});

const puestoElegido = computed(
    () => puestosDeAccion.value.find((p) => p.id === puestoId.value) ?? null,
);

const destinoElegido = computed(
    () =>
        opciones.value?.mover_bajo.find(
            (d) => d.clave === destinoClave.value,
        ) ?? null,
);

const sucursalesDeSucursal = computed(() => opciones.value?.sucursales ?? []);

function disponibles(a: AccionMovimientoOrganigrama): number {
    if (!opciones.value) {
        return 0;
    }

    return a === 'subir'
        ? opciones.value.subir.length
        : a === 'bajar'
          ? opciones.value.bajar.length
          : opciones.value.mover_bajo.length;
}

async function cargar(): Promise<void> {
    if (personaId.value === null) {
        return;
    }

    cargando.value = true;
    errorCarga.value = null;
    opciones.value = null;
    previa.value = null;

    try {
        opciones.value = await getJson<OpcionesMovimientoOrganigrama>(
            opcionesUrl.url(personaId.value),
        );
        elegirAccion(props.accionInicial);
    } catch {
        errorCarga.value =
            'No se pudieron cargar las opciones. Revisa tu permiso o intenta de nuevo.';
    } finally {
        cargando.value = false;
    }
}

function elegirAccion(valor: AccionMovimientoOrganigrama): void {
    accion.value = valor;
    previa.value = null;
    errorPrevia.value = null;
    destinoClave.value = null;
    const actual = opciones.value?.actual;
    sucursalId.value = actual?.sucursal_id ?? null;
    regionId.value = actual?.region_id ?? null;
    puestoId.value =
        puestosDeAccion.value.length === 1
            ? (puestosDeAccion.value[0]?.id ?? null)
            : null;
}

watch(abierto, (valor) => {
    if (valor) {
        void cargar();
    }
});

const datosDestino = computed(() => {
    if (accion.value === 'mover') {
        const destino = destinoElegido.value;

        return destino
            ? {
                  puesto_id: destino.puesto_id,
                  sucursal_id: destino.sucursal_id,
                  region_id: destino.region_id,
              }
            : null;
    }

    if (puestoId.value === null) {
        return null;
    }

    return {
        puesto_id: puestoId.value,
        sucursal_id:
            puestoElegido.value?.ambito === 'sucursal'
                ? sucursalId.value
                : null,
        region_id: puestoElegido.value?.clasificable ? regionId.value : null,
    };
});

let temporizador: ReturnType<typeof setTimeout> | undefined;

watch(datosDestino, (datos) => {
    clearTimeout(temporizador);
    previa.value = null;
    errorPrevia.value = null;

    if (!datos || personaId.value === null) {
        return;
    }

    const id = personaId.value;
    temporizador = setTimeout(async () => {
        calculando.value = true;

        try {
            previa.value =
                await postJsonConErrores<VistaPreviaMovimientoOrganigrama>(
                    vistaPreviaUrl.url(id),
                    { accion: accion.value, ...datos },
                    leerCookie('XSRF-TOKEN') ?? '',
                );
        } catch (e) {
            errorPrevia.value =
                e instanceof Error ? e.message : 'Revisa los datos.';
        } finally {
            calculando.value = false;
        }
    }, 250);
});

const puedeConfirmar = computed(
    () =>
        previa.value !== null &&
        !previa.value.sin_cambios &&
        !calculando.value &&
        !enviando.value,
);

function confirmar(): void {
    if (!datosDestino.value || personaId.value === null) {
        return;
    }

    enviando.value = true;
    router.post(
        mover.url(personaId.value),
        { accion: accion.value, ...datosDestino.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                abierto.value = false;
            },
            onError: (errores) => {
                errorPrevia.value =
                    Object.values(errores)[0] ?? 'No se pudo mover.';
            },
            onFinish: () => {
                enviando.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Mover en el organigrama</DialogTitle>
                <DialogDescription v-if="nodo?.persona">
                    {{ nodo.persona.nombre }} · {{ nodo.puesto.nombre }}. Su
                    jefe directo no se elige: sale del organigrama al cambiar su
                    plaza.
                </DialogDescription>
            </DialogHeader>

            <div v-if="cargando" class="flex flex-col gap-2">
                <Skeleton class="h-11 w-full rounded-xl" />
                <Skeleton class="h-24 w-full rounded-xl" />
            </div>
            <p v-else-if="errorCarga" class="text-sm text-destructive">
                {{ errorCarga }}
            </p>

            <div v-else-if="opciones" class="flex flex-col gap-4">
                <div class="grid grid-cols-3 gap-2" role="tablist">
                    <button
                        v-for="a in ACCIONES"
                        :key="a.valor"
                        type="button"
                        role="tab"
                        :aria-selected="accion === a.valor"
                        :disabled="disponibles(a.valor) === 0"
                        class="flex flex-col items-center gap-1 rounded-xl border px-2 py-2.5 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                        :class="
                            accion === a.valor
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-card hover:border-primary/40 hover:bg-menta'
                        "
                        @click="elegirAccion(a.valor)"
                    >
                        <component :is="a.icono" class="size-4" />
                        {{ a.etiqueta }}
                    </button>
                </div>

                <!-- Subir / Bajar: a qué puesto -->
                <div v-if="accion !== 'mover'" class="grid gap-2">
                    <Label>¿A qué puesto?</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="p in puestosDeAccion"
                            :key="p.id"
                            type="button"
                            class="rounded-xl border px-3 py-2 text-sm transition-colors"
                            :class="
                                puestoId === p.id
                                    ? 'border-primary bg-salvia font-semibold text-primary'
                                    : 'border-border bg-card hover:bg-muted'
                            "
                            @click="puestoId = p.id"
                        >
                            {{ p.nombre }}
                        </button>
                        <p
                            v-if="!puestosDeAccion.length"
                            class="text-sm text-muted-foreground"
                        >
                            No hay un puesto
                            {{ accion === 'subir' ? 'superior' : 'inferior' }}
                            en el catálogo.
                        </p>
                    </div>
                </div>

                <div
                    v-if="
                        accion !== 'mover' &&
                        puestoElegido?.ambito === 'sucursal'
                    "
                    class="grid gap-2"
                >
                    <Label for="mov-sucursal">¿En qué sucursal?</Label>
                    <select
                        id="mov-sucursal"
                        v-model.number="sucursalId"
                        class="h-10 rounded-xl border border-input bg-card px-3 text-sm"
                    >
                        <option :value="null" disabled>
                            Elige la sucursal
                        </option>
                        <option
                            v-for="s in sucursalesDeSucursal"
                            :key="s.id"
                            :value="s.id"
                        >
                            {{ s.nombre }}
                        </option>
                    </select>
                </div>

                <div
                    v-if="accion !== 'mover' && puestoElegido?.clasificable"
                    class="grid gap-2"
                >
                    <Label>¿De qué lado? (Q1 / Q3)</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="r in opciones.regiones"
                            :key="r.id"
                            type="button"
                            class="rounded-full border px-3 py-1.5 text-sm font-bold"
                            :class="
                                regionId === r.id
                                    ? claseChipRegion(r.codigo)
                                    : 'border-border bg-card text-muted-foreground'
                            "
                            @click="regionId = r.id"
                        >
                            {{ r.codigo }}
                        </button>
                        <button
                            type="button"
                            class="rounded-full border border-dashed px-3 py-1.5 text-sm"
                            :class="
                                regionId === null
                                    ? 'border-warning/50 bg-warning-soft text-warning'
                                    : 'border-border bg-card text-muted-foreground'
                            "
                            @click="regionId = null"
                        >
                            Por clasificar
                        </button>
                    </div>
                </div>

                <!-- Mover bajo…: a qué plaza -->
                <div v-if="accion === 'mover'" class="grid gap-2">
                    <Label>¿Bajo qué plaza queda?</Label>
                    <ul
                        class="max-h-64 overflow-y-auto rounded-2xl border border-border bg-card p-1.5"
                    >
                        <li v-for="d in opciones.mover_bajo" :key="d.clave">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm transition-colors"
                                :class="
                                    destinoClave === d.clave
                                        ? 'bg-salvia font-semibold text-primary'
                                        : 'hover:bg-muted'
                                "
                                @click="destinoClave = d.clave"
                            >
                                <UserRoundCheck class="size-4 shrink-0" />
                                <span class="min-w-0 flex-1">{{
                                    d.etiqueta
                                }}</span>
                            </button>
                        </li>
                        <li
                            v-if="!opciones.mover_bajo.length"
                            class="px-3 py-4 text-center text-sm text-muted-foreground"
                        >
                            No hay otra plaza del mismo nivel donde pueda
                            quedar.
                        </li>
                    </ul>
                </div>

                <!-- Confirmación clara -->
                <div
                    v-if="calculando"
                    class="flex items-center gap-2 rounded-2xl border border-border bg-muted/50 p-4 text-sm text-muted-foreground"
                >
                    <Spinner /> Calculando a quién reportaría…
                </div>
                <div
                    v-else-if="previa"
                    class="flex flex-col gap-2 rounded-2xl border border-primary/30 bg-menta p-4 text-sm"
                >
                    <p class="font-semibold text-primary">
                        {{ previa.descripcion }}
                    </p>
                    <p class="flex flex-wrap items-center gap-1.5">
                        <span class="text-muted-foreground">Jefe directo:</span>
                        <span>{{ previa.jefe_actual ?? 'Sin jefe' }}</span>
                        <ArrowRight class="size-3.5 text-muted-foreground" />
                        <strong>{{ previa.jefe_nuevo ?? 'Sin jefe' }}</strong>
                    </p>
                    <p
                        v-for="aviso in previa.avisos"
                        :key="aviso"
                        class="flex items-start gap-1.5 text-xs text-warning"
                    >
                        <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                        {{ aviso }}
                    </p>
                    <p
                        v-if="previa.sin_cambios"
                        class="text-xs text-muted-foreground"
                    >
                        Ya está en esa plaza: no hay nada que mover.
                    </p>
                </div>
                <p v-if="errorPrevia" class="text-sm text-destructive">
                    {{ errorPrevia }}
                </p>
            </div>

            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    :disabled="enviando"
                    @click="abierto = false"
                >
                    Cancelar
                </Button>
                <Button :disabled="!puedeConfirmar" @click="confirmar">
                    <Spinner v-if="enviando" />
                    Sí, mover
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
