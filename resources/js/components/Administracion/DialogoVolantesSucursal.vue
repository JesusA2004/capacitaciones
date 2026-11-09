<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Bike, CheckCircle2, XCircle } from '@lucide/vue';
import { watch } from 'vue';
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
import { update as actualizarVolantes } from '@/routes/administracion/sucursales/volantes';

/**
 * «¿Esta sucursal tendrá volantes?»: Sí/No + cuántos. Fija las plazas
 * autorizadas del puesto Gestor volante de la sucursal (0 si no tendrá) y
 * eso abre o cierra sus vacantes «Gestor volante» (VolantesSucursalService,
 * solo RH; el backend vuelve a autorizar).
 */
const props = defineProps<{
    sucursal: { id: number; nombre: string } | null;
    /** Plazas de volante autorizadas hoy (null = nunca se ha definido). */
    actuales: number | null;
}>();

const abierto = defineModel<boolean>('open', { required: true });

const form = useForm({
    tendra_volantes: null as boolean | null,
    cantidad: 1,
    motivo: '',
});

watch(abierto, (abrir) => {
    if (abrir) {
        form.reset();
        form.clearErrors();
        form.tendra_volantes =
            props.actuales === null ? null : props.actuales > 0;
        form.cantidad =
            props.actuales && props.actuales > 0 ? props.actuales : 1;
    }
});

function guardar(): void {
    if (!props.sucursal || form.tendra_volantes === null) {
        form.setError('tendra_volantes', 'Elige «Sí» o «No».');

        return;
    }

    form.transform((datos) => ({
        ...datos,
        cantidad: datos.tendra_volantes ? Number(datos.cantidad) : 0,
    })).put(actualizarVolantes.url(props.sucursal.id), {
        preserveScroll: true,
        onSuccess: () => {
            abierto.value = false;
        },
    });
}

const opciones = [
    {
        valor: true,
        titulo: 'Sí, tendrá volantes',
        detalle: 'Se abren vacantes «Gestor volante» por las plazas libres.',
        icono: CheckCircle2,
    },
    {
        valor: false,
        titulo: 'No tendrá volantes',
        detalle: 'Sus plazas de Gestor volante quedan en 0.',
        icono: XCircle,
    },
] as const;
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <span
                        class="flex size-8 items-center justify-center rounded-full bg-salvia text-primary"
                    >
                        <Bike class="size-4" />
                    </span>
                    ¿Esta sucursal tendrá volantes?
                </DialogTitle>
                <DialogDescription v-if="sucursal">
                    <strong class="text-foreground">{{
                        sucursal.nombre
                    }}</strong>
                    · El gestor volante no tiene ruta fija: cubre faltas y apoya
                    la operación. Sus plazas son aparte de las de Gestor fijo
                    (el que tiene ruta en Matriz comercial).
                    <template v-if="actuales !== null">
                        Hoy tiene
                        <strong class="text-foreground">{{ actuales }}</strong>
                        {{ actuales === 1 ? 'plaza' : 'plazas' }} de volante.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2 sm:grid-cols-2" role="radiogroup">
                <button
                    v-for="opcion in opciones"
                    :key="String(opcion.valor)"
                    type="button"
                    role="radio"
                    :aria-checked="form.tendra_volantes === opcion.valor"
                    class="flex tarjeta-interactiva flex-col items-start gap-1 rounded-2xl border bg-card p-3 text-left"
                    :class="
                        form.tendra_volantes === opcion.valor
                            ? 'border-transparent bg-salvia ring-2 ring-primary/40'
                            : ''
                    "
                    @click="form.tendra_volantes = opcion.valor"
                >
                    <span
                        class="flex items-center gap-1.5 text-sm font-semibold"
                    >
                        <component :is="opcion.icono" class="size-4" />
                        {{ opcion.titulo }}
                    </span>
                    <span class="text-xs text-muted-foreground">{{
                        opcion.detalle
                    }}</span>
                </button>
            </div>
            <InputError :message="form.errors.tendra_volantes" />

            <div v-if="form.tendra_volantes" class="grid gap-1.5">
                <Label for="volantes-cantidad">¿Cuántos?</Label>
                <Input
                    id="volantes-cantidad"
                    v-model.number="form.cantidad"
                    type="number"
                    min="1"
                    max="50"
                    class="w-32"
                />
                <InputError :message="form.errors.cantidad" />
            </div>

            <div class="grid gap-1.5">
                <Label for="volantes-motivo">Motivo del cambio</Label>
                <Textarea
                    id="volantes-motivo"
                    v-model="form.motivo"
                    rows="2"
                    placeholder="Ej. Dirección autorizó 1 volante para cubrir faltas"
                />
                <InputError :message="form.errors.motivo" />
            </div>

            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="abierto = false"
                >
                    Cancelar
                </Button>
                <Button
                    :disabled="
                        form.processing ||
                        form.tendra_volantes === null ||
                        form.motivo.trim().length < 5
                    "
                    @click="guardar"
                >
                    <Spinner v-if="form.processing" />
                    Guardar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
