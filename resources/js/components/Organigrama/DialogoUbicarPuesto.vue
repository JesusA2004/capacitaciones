<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowRight, MoveRight, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
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
import { postJsonConErrores } from '@/lib/organigramaEditable';
import {
    aplicar as aplicarUrl,
    opciones as opcionesUrl,
    vistaPrevia as vistaPreviaUrl,
} from '@/routes/administracion/jerarquia-puestos/ubicacion';
import type {
    OpcionesUbicacionPuesto,
    VistaPreviaUbicacionPuesto,
} from '@/types';

/**
 * «Ubicar / mover puesto» (UbicacionPuestoService): 1) ¿debajo de quién va?
 * (buscador con todos los puestos activos, su área y quién los ocupa);
 * 2) departamento, propuesto del superior elegido; 3) vista previa sin
 * guardar (superior y departamento antes → después, personas del puesto y
 * cambios de jefe directo) y confirmar. El backend valida ciclos y permiso.
 */
const props = defineProps<{
    puesto: { id: number; nombre: string } | null;
}>();

const abierto = defineModel<boolean>('open', { default: false });

const opciones = ref<OpcionesUbicacionPuesto | null>(null);
const cargando = ref(false);
const errorCarga = ref<string | null>(null);
const superiorId = ref('');
const departamentoId = ref<number | null>(null);
const previa = ref<VistaPreviaUbicacionPuesto | null>(null);
const calculando = ref(false);
const errorPrevia = ref<string | null>(null);
const enviando = ref(false);

const items = computed(() =>
    (opciones.value?.superiores ?? []).map((s) => ({
        value: s.value,
        label: s.label,
        palabrasClave: [s.departamento, ...s.ocupantes]
            .filter(Boolean)
            .join(' '),
    })),
);

const departamentos = computed(() => opciones.value?.departamentos ?? []);

watch(
    () => [abierto.value, props.puesto?.id] as const,
    async ([visible, id]) => {
        if (!visible || !id) {
            return;
        }

        opciones.value = null;
        previa.value = null;
        errorPrevia.value = null;
        errorCarga.value = null;
        superiorId.value = '';
        departamentoId.value = null;
        cargando.value = true;

        try {
            opciones.value = await getJson<OpcionesUbicacionPuesto>(
                opcionesUrl.url(id),
            );
            superiorId.value = opciones.value.actual.superior_id
                ? String(opciones.value.actual.superior_id)
                : '';
            departamentoId.value = opciones.value.actual.departamento_id;
        } catch {
            errorCarga.value =
                'No se pudieron cargar los puestos. Intenta de nuevo.';
        } finally {
            cargando.value = false;
        }
    },
    { immediate: true },
);

// Paso 2: al elegir superior se propone su departamento (RH lo puede cambiar).
watch(superiorId, (valor, anterior) => {
    if (!anterior && opciones.value?.actual.superior_id === Number(valor)) {
        return;
    }

    const elegido = opciones.value?.superiores.find((s) => s.value === valor);

    if (elegido?.departamento_id) {
        departamentoId.value = elegido.departamento_id;
    }
});

let temporizador: ReturnType<typeof setTimeout> | undefined;

watch([superiorId, departamentoId], () => {
    clearTimeout(temporizador);
    previa.value = null;
    errorPrevia.value = null;

    const id = props.puesto?.id;

    if (!id || !superiorId.value) {
        return;
    }

    temporizador = setTimeout(async () => {
        calculando.value = true;

        try {
            previa.value = await postJsonConErrores<VistaPreviaUbicacionPuesto>(
                vistaPreviaUrl.url(id),
                {
                    puesto_superior_id: Number(superiorId.value),
                    departamento_id: departamentoId.value,
                },
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
    const id = props.puesto?.id;

    if (!id || !puedeConfirmar.value) {
        return;
    }

    enviando.value = true;
    router.post(
        aplicarUrl.url(id),
        {
            puesto_superior_id: Number(superiorId.value),
            departamento_id: departamentoId.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                abierto.value = false;
            },
            onError: (errores) => {
                errorPrevia.value =
                    Object.values(errores)[0] ?? 'No se pudo mover el puesto.';
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
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Ubicar / mover puesto</DialogTitle>
                <DialogDescription v-if="puesto">
                    Elige debajo de quién va
                    <strong>{{ puesto.nombre }}</strong
                    >. Quien ocupa el puesto se mueve con él y su jefe directo
                    se recalcula solo.
                </DialogDescription>
            </DialogHeader>

            <div v-if="cargando" class="flex flex-col gap-3">
                <Skeleton class="h-10 w-full rounded-xl" />
                <Skeleton class="h-10 w-full rounded-xl" />
            </div>

            <p
                v-else-if="errorCarga"
                class="rounded-xl bg-destructive/10 px-3 py-2 text-sm text-destructive"
            >
                {{ errorCarga }}
            </p>

            <div v-else-if="opciones" class="grid gap-4">
                <div class="grid gap-2">
                    <Label>1. ¿Debajo de quién va?</Label>
                    <Combobox
                        v-model="superiorId"
                        :items="items"
                        :limite="1000"
                        placeholder="Busca un puesto o a quien lo ocupa…"
                        empty-text="Ningún puesto coincide."
                    />
                    <p class="text-xs text-muted-foreground">
                        Puesto · área — quién lo ocupa. No aparecen el propio
                        puesto ni los que le reportan.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>2. Departamento</Label>
                    <SelectSimple
                        :model-value="departamentoId ?? undefined"
                        :opciones="departamentos"
                        numerico
                        placeholder="Sin departamento"
                        @update:model-value="
                            (v) =>
                                (departamentoId = v === null ? null : Number(v))
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Se propone el del puesto superior; cámbialo si va en
                        otra área.
                    </p>
                </div>

                <div
                    v-if="calculando"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Spinner /> Calculando qué cambia…
                </div>

                <p
                    v-else-if="errorPrevia"
                    class="flex items-start gap-2 rounded-xl border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    {{ errorPrevia }}
                </p>

                <div
                    v-else-if="previa"
                    class="flex flex-col gap-3 rounded-2xl border border-border bg-crema p-3 text-sm"
                >
                    <p class="font-semibold">3. Revisa antes de confirmar</p>
                    <p v-if="previa.sin_cambios" class="text-muted-foreground">
                        El puesto ya está ahí: no hay nada que cambiar.
                    </p>
                    <template v-else>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-muted-foreground"
                                >Reporta a:</span
                            >
                            <span class="font-medium">{{
                                previa.superior_antes ?? 'nadie'
                            }}</span>
                            <ArrowRight
                                class="size-3.5 text-muted-foreground"
                            />
                            <span class="font-semibold text-primary">{{
                                previa.superior_despues
                            }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-muted-foreground"
                                >Departamento:</span
                            >
                            <span class="font-medium">{{
                                previa.departamento_antes ?? 'sin departamento'
                            }}</span>
                            <ArrowRight
                                class="size-3.5 text-muted-foreground"
                            />
                            <span class="font-semibold text-primary">{{
                                previa.departamento_despues ??
                                'sin departamento'
                            }}</span>
                        </div>
                        <p>
                            <span class="text-muted-foreground"
                                >Personas en el puesto:</span
                            >
                            {{
                                previa.personas.length
                                    ? previa.personas.join(', ')
                                    : 'nadie (plaza vacante)'
                            }}
                        </p>
                        <div v-if="previa.cambios_jefe.length">
                            <p class="mb-1 text-muted-foreground">
                                Cambia su jefe directo:
                            </p>
                            <ul class="flex flex-col gap-1">
                                <li
                                    v-for="cambio in previa.cambios_jefe"
                                    :key="cambio.colaborador"
                                    class="flex flex-wrap items-center gap-1 rounded-lg bg-card px-2 py-1"
                                >
                                    <span class="font-medium">{{
                                        cambio.colaborador
                                    }}</span>
                                    <span class="text-xs text-muted-foreground"
                                        >({{ cambio.puesto }})</span
                                    >
                                    <span class="text-xs">{{
                                        cambio.antes ?? 'sin jefe'
                                    }}</span>
                                    <MoveRight
                                        class="size-3 text-muted-foreground"
                                    />
                                    <span class="text-xs font-semibold">{{
                                        cambio.despues ?? 'sin jefe'
                                    }}</span>
                                </li>
                            </ul>
                        </div>
                        <p v-else class="text-muted-foreground">
                            No cambia el jefe directo de nadie.
                        </p>
                    </template>
                </div>
            </div>

            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="enviando"
                    @click="abierto = false"
                >
                    Cancelar
                </Button>
                <Button :disabled="!puedeConfirmar" @click="confirmar">
                    <Spinner v-if="enviando" />
                    Mover puesto
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
