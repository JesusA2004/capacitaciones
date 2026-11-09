<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CircleCheck,
    CircleMinus,
    Info,
    Route,
    UserRoundPlus,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import type { OpcionBuscable } from '@/components/ui/combobox-buscable';
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
import { Textarea } from '@/components/ui/textarea';
import { getJson, leerCookie } from '@/lib/http';
import { postJsonConErrores } from '@/lib/organigramaEditable';
import {
    aplicar,
    opciones as opcionesUrl,
    vistaPrevia as vistaPreviaUrl,
} from '@/routes/administracion/jerarquia-puestos/traspaso';
import type {
    NodoOrganigramaPersona,
    OpcionesTraspasoPuesto,
    VistaPreviaTraspasoPuesto,
} from '@/types';

/**
 * «Cambiar de puesto (traspaso)»: una o varias personas pasan a CUALQUIER
 * puesto activo (p. ej. Gestores de sucursal → Auditora, en Corporativo).
 * No es baja ni alta. La vista previa (TraspasoPuestoService::vistaPrevia)
 * muestra de → a, jefe actual → nuevo, rutas que quedan libres y el efecto
 * en headcount, sin guardar nada.
 */
const props = defineProps<{
    nodo: NodoOrganigramaPersona | null;
}>();

const abierto = defineModel<boolean>('open', { required: true });

const opciones = ref<OpcionesTraspasoPuesto | null>(null);
const cargando = ref(false);
const errorCarga = ref<string | null>(null);

const personas = ref<number[]>([]);
const agregar = ref<number | null>(null);
const puestoId = ref<number | null>(null);
const sucursalId = ref<number | null>(null);
const comentario = ref('');

const previa = ref<VistaPreviaTraspasoPuesto | null>(null);
const errorPrevia = ref<string | null>(null);
const calculando = ref(false);
const enviando = ref(false);

const AMBITOS: Record<string, string> = {
    sucursal: 'Puesto de sucursal',
    region: 'Puesto regional',
    corporativo: 'Puesto corporativo',
};

const porId = computed(
    () => new Map((opciones.value?.colaboradores ?? []).map((c) => [c.id, c])),
);

const elegidas = computed(() =>
    personas.value.map(
        (id) =>
            porId.value.get(id) ?? {
                id,
                nombre: props.nodo?.persona?.nombre ?? 'Persona',
                numero_empleado: null,
                puesto_id: null,
                puesto: props.nodo?.puesto.nombre ?? null,
                sucursal_id: null,
                sucursal: null,
                departamento: null,
            },
    ),
);

const opcionesPersonas = computed<OpcionBuscable[]>(() =>
    (opciones.value?.colaboradores ?? [])
        .filter((c) => !personas.value.includes(c.id))
        .map((c) => ({
            value: c.id,
            label: c.nombre,
            descripcion: [c.puesto, c.sucursal, c.numero_empleado]
                .filter(Boolean)
                .join(' · '),
            alias: [c.sucursal, c.puesto, c.departamento].filter(
                (v): v is string => !!v,
            ),
            grupo: c.sucursal ?? 'Sin sucursal',
        })),
);

const opcionesPuestos = computed<OpcionBuscable[]>(() =>
    (opciones.value?.puestos ?? []).map((p) => ({
        value: p.id,
        label: p.nombre,
        descripcion: [p.departamento, AMBITOS[p.ambito] ?? null]
            .filter(Boolean)
            .join(' · '),
        alias: [p.departamento ?? ''].filter(Boolean),
        grupo: p.departamento ?? 'Sin área',
    })),
);

const puestoElegido = computed(
    () => opciones.value?.puestos.find((p) => p.id === puestoId.value) ?? null,
);

const opcionesSucursales = computed<OpcionBuscable[]>(() =>
    (opciones.value?.sucursales ?? [])
        .filter(
            (s) =>
                puestoElegido.value?.ambito !== 'sucursal' || !s.es_corporativo,
        )
        .map((s) => ({ value: s.id, label: s.nombre })),
);

const limite = computed(() => opciones.value?.maximo ?? 50);

watch(agregar, (id) => {
    if (id !== null && !personas.value.includes(id)) {
        if (personas.value.length < limite.value) {
            personas.value = [...personas.value, id];
        }
    }

    if (id !== null) {
        agregar.value = null;
    }
});

watch(puestoId, () => {
    sucursalId.value = null;
});

function quitar(id: number): void {
    personas.value = personas.value.filter((p) => p !== id);
}

async function cargar(): Promise<void> {
    cargando.value = true;
    errorCarga.value = null;
    previa.value = null;
    errorPrevia.value = null;
    puestoId.value = null;
    sucursalId.value = null;
    comentario.value = '';
    const inicial = props.nodo?.persona?.id;
    personas.value = inicial ? [inicial] : [];

    try {
        opciones.value = await getJson<OpcionesTraspasoPuesto>(
            opcionesUrl.url(),
        );
    } catch {
        errorCarga.value =
            'No se pudieron cargar los puestos. Revisa tu permiso o intenta de nuevo.';
    } finally {
        cargando.value = false;
    }
}

watch(abierto, (valor) => {
    if (valor) {
        void cargar();
    }
});

const datos = computed(() => {
    if (puestoId.value === null || personas.value.length === 0) {
        return null;
    }

    return {
        colaborador_ids: personas.value,
        puesto_id: puestoId.value,
        sucursal_id:
            puestoElegido.value?.ambito === 'corporativo'
                ? null
                : sucursalId.value,
    };
});

let temporizador: ReturnType<typeof setTimeout> | undefined;

watch(datos, (cuerpo) => {
    clearTimeout(temporizador);
    previa.value = null;
    errorPrevia.value = null;

    if (!cuerpo) {
        return;
    }

    temporizador = setTimeout(async () => {
        calculando.value = true;

        try {
            previa.value = await postJsonConErrores<VistaPreviaTraspasoPuesto>(
                vistaPreviaUrl.url(),
                cuerpo,
                leerCookie('XSRF-TOKEN') ?? '',
            );
        } catch (e) {
            errorPrevia.value =
                e instanceof Error ? e.message : 'Revisa los datos.';
        } finally {
            calculando.value = false;
        }
    }, 300);
});

const TONOS: Record<string, { icono: Component; clase: string }> = {
    vacante: { icono: CircleMinus, clase: 'text-warning' },
    ocupa: { icono: CircleCheck, clase: 'text-success' },
    fuera: { icono: AlertTriangle, clase: 'text-danger' },
    info: { icono: Info, clase: 'text-muted-foreground' },
};

const puedeConfirmar = computed(
    () =>
        previa.value !== null &&
        !previa.value.sin_cambios &&
        !previa.value.personas.some((p) => p.sin_cambios) &&
        !calculando.value &&
        !enviando.value,
);

function confirmar(): void {
    if (!datos.value) {
        return;
    }

    enviando.value = true;
    router.post(
        aplicar.url(),
        { ...datos.value, comentario: comentario.value || null },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                abierto.value = false;
            },
            onError: (errores) => {
                errorPrevia.value =
                    Object.values(errores)[0] ?? 'No se pudo traspasar.';
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
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Cambiar de puesto (traspaso)</DialogTitle>
                <DialogDescription>
                    Pasa a una o varias personas a cualquier puesto. No es baja
                    ni alta: conservan su antigüedad y no cuenta en rotación. Su
                    jefe directo sale del organigrama.
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
                <!-- Personas -->
                <div class="grid gap-2">
                    <Label>¿A quién traspasas? ({{ personas.length }})</Label>
                    <ul class="flex flex-wrap gap-1.5">
                        <li
                            v-for="p in elegidas"
                            :key="p.id"
                            class="inline-flex items-center gap-1.5 rounded-full border border-border bg-crema py-1 pr-1 pl-3 text-sm"
                        >
                            <span class="font-medium">{{ p.nombre }}</span>
                            <span
                                v-if="p.puesto || p.sucursal"
                                class="text-xs text-muted-foreground"
                            >
                                ·
                                {{
                                    [p.puesto, p.sucursal]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </span>
                            <button
                                type="button"
                                class="rounded-full p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                :aria-label="`Quitar a ${p.nombre}`"
                                @click="quitar(p.id)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </li>
                    </ul>
                    <div class="flex items-center gap-2">
                        <UserRoundPlus
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        <ComboboxBuscable
                            v-model="agregar"
                            :opciones="opcionesPersonas"
                            numerico
                            class="flex-1"
                            placeholder="Agregar a otra persona…"
                            placeholder-busqueda="Nombre, número, puesto, sucursal o área…"
                            texto-vacio="Nadie más con ese criterio"
                            aria-label="Agregar persona al traspaso"
                            :disabled="personas.length >= limite"
                        />
                    </div>
                </div>

                <!-- Puesto destino -->
                <div class="grid gap-2">
                    <Label
                        >¿A qué puesto pasa{{
                            personas.length > 1 ? 'n' : ''
                        }}?</Label
                    >
                    <ComboboxBuscable
                        v-model="puestoId"
                        :opciones="opcionesPuestos"
                        numerico
                        placeholder="Elige el puesto"
                        placeholder-busqueda="Puesto o área (Contraloría, Comercial…)"
                        aria-label="Puesto destino"
                    />
                </div>

                <!-- Sucursal -->
                <div v-if="puestoElegido" class="grid gap-2">
                    <template v-if="puestoElegido.ambito === 'corporativo'">
                        <p
                            class="flex items-center gap-2 rounded-xl border border-border bg-crema px-3 py-2 text-sm"
                        >
                            <Building2 class="size-4 text-primary" />
                            Puesto corporativo: su plaza queda en Corporativo.
                        </p>
                    </template>
                    <template v-else>
                        <Label>¿En qué sucursal?</Label>
                        <ComboboxBuscable
                            v-model="sucursalId"
                            :opciones="opcionesSucursales"
                            numerico
                            limpiable
                            :placeholder="
                                personas.length > 1
                                    ? 'Cada quien en su sucursal actual'
                                    : 'Su sucursal actual'
                            "
                            placeholder-busqueda="Buscar sucursal…"
                            aria-label="Sucursal destino"
                        />
                        <p
                            v-if="puestoElegido.ambito === 'sucursal'"
                            class="text-xs text-muted-foreground"
                        >
                            Es un puesto de sucursal: nunca va en Corporativo.
                        </p>
                    </template>
                </div>

                <div class="grid gap-2">
                    <Label for="traspaso-comentario"
                        >Comentario (opcional)</Label
                    >
                    <Textarea
                        id="traspaso-comentario"
                        v-model="comentario"
                        rows="2"
                        maxlength="500"
                        placeholder="Por qué se traspasa (queda en su Historial RH)"
                    />
                </div>

                <!-- Vista previa -->
                <div
                    v-if="calculando"
                    class="flex items-center gap-2 rounded-2xl border border-border bg-muted/50 p-4 text-sm text-muted-foreground"
                >
                    <Spinner /> Calculando jefes y plantilla…
                </div>
                <div
                    v-else-if="previa"
                    class="flex flex-col gap-3 rounded-2xl border border-primary/30 bg-menta p-4 text-sm"
                >
                    <p class="font-semibold text-primary">
                        {{ previa.descripcion }}
                    </p>

                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="p in previa.personas"
                            :key="p.id"
                            class="rounded-xl border border-border bg-card p-3"
                        >
                            <p class="flex flex-wrap items-center gap-2">
                                <strong>{{ p.nombre }}</strong>
                                <span
                                    class="rounded-full bg-salvia px-2 py-0.5 text-xs text-primary"
                                    >{{ p.tipo_movimiento }}</span
                                >
                            </p>
                            <p
                                class="mt-1 flex flex-wrap items-center gap-1.5 text-xs"
                            >
                                <span>{{ p.de }}</span>
                                <ArrowRight
                                    class="size-3.5 text-muted-foreground"
                                />
                                <strong>{{ p.a }}</strong>
                            </p>
                            <p
                                v-if="p.departamento_de !== p.departamento_a"
                                class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                Área: {{ p.departamento_de ?? 'Sin área' }}
                                <ArrowRight class="size-3" />
                                {{ p.departamento_a ?? 'Sin área' }}
                            </p>
                            <p
                                class="flex flex-wrap items-center gap-1.5 text-xs"
                            >
                                <span class="text-muted-foreground"
                                    >Jefe directo:</span
                                >
                                <span>{{ p.jefe_actual ?? 'Sin jefe' }}</span>
                                <ArrowRight
                                    class="size-3 text-muted-foreground"
                                />
                                <strong>{{
                                    p.jefe_nuevo ?? 'Sin jefe'
                                }}</strong>
                            </p>
                            <p
                                v-for="ruta in p.rutas"
                                :key="ruta"
                                class="mt-1 flex items-start gap-1.5 text-xs text-warning"
                            >
                                <Route class="mt-0.5 size-3.5 shrink-0" />
                                {{ ruta }} La ruta se verá sin cubrir en Matriz
                                comercial.
                            </p>
                        </li>
                    </ul>

                    <div
                        v-if="previa.headcount.length"
                        class="flex flex-col gap-1"
                    >
                        <p
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Headcount
                        </p>
                        <p
                            v-for="h in previa.headcount"
                            :key="h.texto"
                            class="flex items-start gap-1.5 text-xs"
                            :class="TONOS[h.tono]?.clase"
                        >
                            <component
                                :is="TONOS[h.tono]?.icono ?? Info"
                                class="mt-0.5 size-3.5 shrink-0"
                            />
                            {{ h.texto }}
                        </p>
                    </div>

                    <p
                        v-for="aviso in previa.avisos"
                        :key="aviso"
                        class="flex items-start gap-1.5 text-xs text-warning"
                    >
                        <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                        {{ aviso }}
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
                    Sí, traspasar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
