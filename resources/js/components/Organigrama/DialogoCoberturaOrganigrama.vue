<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Hourglass, Layers, Shuffle } from '@lucide/vue';
import { computed, watch } from 'vue';
import BuscadorPersonaOrganigrama from '@/components/Organigrama/BuscadorPersonaOrganigrama.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { lugarDeNodo } from '@/lib/organigramaEditable';
import { store as storeCobertura } from '@/routes/administracion/jerarquia-puestos/coberturas';
import type { NodoOrganigramaPersona } from '@/types';

/**
 * Una persona en dos puestos: se elige a la persona (sin fechas ni motivo:
 * CoberturaPuestoService usa «Puesto vacante» y hoy) y RH decide qué pasa
 * con la plaza (`ocupa_plaza`):
 *  - «Ocupa ambos puestos (sin vacante)»: la plaza cuenta como ocupada.
 *  - «Cubre mientras se contrata (con vacante)»: la plaza sigue vacante.
 * Por omisión, un puesto de empresa (sin sucursal) ocupa la plaza y uno de
 * sucursal la cubre mientras se contrata.
 */
const props = defineProps<{
    nodo: NodoOrganigramaPersona | null;
    candidatos: { value: string; label: string }[];
}>();

const abierto = defineModel<boolean>('open', { required: true });

const form = useForm({
    colaborador_id: '',
    puesto_id: 0,
    sucursal_id: null as number | null,
    region_id: null as number | null,
    ocupa_plaza: false,
});

watch(
    () => [abierto.value, props.nodo] as const,
    ([abrir, nodo]) => {
        if (abrir && nodo) {
            form.reset();
            form.clearErrors();
            form.puesto_id = nodo.puesto.id;
            form.sucursal_id = nodo.sucursal?.id ?? null;
            form.region_id = nodo.sucursal ? null : (nodo.region?.id ?? null);
            form.ocupa_plaza = !nodo.sucursal;
        }
    },
);

const lugar = computed(() => (props.nodo ? lugarDeNodo(props.nodo) : null));

const modalidades = [
    {
        valor: true,
        titulo: 'Ocupa ambos puestos',
        etiqueta: 'Sin vacante',
        detalle:
            'Tiene los dos puestos de forma permanente (p. ej. un Gerente de Sucursal que también es Gerente de Auditoría). La plaza cuenta como ocupada y no se abre vacante.',
        icono: Layers,
    },
    {
        valor: false,
        titulo: 'Cubre mientras se contrata',
        etiqueta: 'Con vacante',
        detalle:
            'Lo atiende de forma temporal. La plaza sigue vacante en headcount y en RH → Vacantes hasta contratar a alguien.',
        icono: Hourglass,
    },
] as const;

function guardar(): void {
    form.transform((datos) => ({
        ...datos,
        colaborador_id: Number(datos.colaborador_id) || null,
    })).post(storeCobertura.url(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            abierto.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <span
                        class="flex size-8 items-center justify-center rounded-full bg-salvia text-primary"
                    >
                        <Shuffle class="size-4" />
                    </span>
                    Asignar a otra persona en este puesto
                </DialogTitle>
                <DialogDescription v-if="nodo">
                    <strong class="text-foreground">{{
                        nodo.puesto.nombre
                    }}</strong>
                    <template v-if="lugar"> · {{ lugar }}</template> no tiene
                    titular. Elige a la persona: conserva su propio puesto y,
                    mientras dure, a ella le reportan los subordinados de esta
                    plaza.
                </DialogDescription>
            </DialogHeader>

            <BuscadorPersonaOrganigrama
                v-model="form.colaborador_id"
                :items="candidatos"
            />

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">
                    ¿Qué pasa con la plaza?
                </legend>
                <div class="grid gap-2 sm:grid-cols-2" role="radiogroup">
                    <button
                        v-for="m in modalidades"
                        :key="String(m.valor)"
                        type="button"
                        role="radio"
                        :aria-checked="form.ocupa_plaza === m.valor"
                        class="flex tarjeta-interactiva flex-col items-start gap-1 rounded-2xl border bg-card p-3 text-left"
                        :class="
                            form.ocupa_plaza === m.valor
                                ? 'border-transparent bg-salvia ring-2 ring-primary/40'
                                : ''
                        "
                        @click="form.ocupa_plaza = m.valor"
                    >
                        <span
                            class="flex items-center gap-1.5 text-sm font-semibold"
                        >
                            <component :is="m.icono" class="size-4" />
                            {{ m.titulo }}
                        </span>
                        <span
                            class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="
                                m.valor
                                    ? 'bg-success-soft text-success'
                                    : 'bg-warning-soft text-warning'
                            "
                            >{{ m.etiqueta }}</span
                        >
                        <span class="text-xs text-muted-foreground">{{
                            m.detalle
                        }}</span>
                    </button>
                </div>
            </fieldset>

            <p
                v-if="
                    form.errors.colaborador_id ||
                    form.errors.sucursal_id ||
                    form.errors.region_id ||
                    form.errors.ocupa_plaza
                "
                class="text-sm text-destructive"
            >
                {{
                    form.errors.colaborador_id ??
                    form.errors.sucursal_id ??
                    form.errors.region_id ??
                    form.errors.ocupa_plaza
                }}
            </p>

            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="abierto = false"
                >
                    Cancelar
                </Button>
                <Button
                    :disabled="form.processing || !form.colaborador_id"
                    @click="guardar"
                >
                    <Spinner v-if="form.processing" />
                    {{
                        form.ocupa_plaza
                            ? 'Guardar: ocupa ambos puestos'
                            : 'Guardar: cubre mientras se contrata'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
