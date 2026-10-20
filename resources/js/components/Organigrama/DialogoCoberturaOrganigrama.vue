<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Shuffle } from '@lucide/vue';
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
 * «Cubre temporalmente este puesto»: solo se elige a la persona — sin
 * fechas ni motivo (CoberturaPuestoService usa «Puesto vacante» y hoy).
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
        }
    },
);

const lugar = computed(() => (props.nodo ? lugarDeNodo(props.nodo) : null));

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
                    Cubre temporalmente este puesto
                </DialogTitle>
                <DialogDescription v-if="nodo">
                    <strong class="text-foreground">{{
                        nodo.puesto.nombre
                    }}</strong>
                    <template v-if="lugar"> · {{ lugar }}</template> está
                    vacante. Elige quién lo cubre: conserva su propio puesto, la
                    plaza sigue contando como vacante en headcount y, mientras
                    dure, a esa persona le reportan los subordinados de esta
                    plaza.
                </DialogDescription>
            </DialogHeader>

            <BuscadorPersonaOrganigrama
                v-model="form.colaborador_id"
                :items="candidatos"
            />
            <p
                v-if="
                    form.errors.colaborador_id ||
                    form.errors.sucursal_id ||
                    form.errors.region_id
                "
                class="text-sm text-destructive"
            >
                {{
                    form.errors.colaborador_id ??
                    form.errors.sucursal_id ??
                    form.errors.region_id
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
                    Marcar «Cubre temporalmente»
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
