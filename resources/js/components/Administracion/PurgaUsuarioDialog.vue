<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Ban, Link2Off, ShieldAlert, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import purga from '@/routes/administracion/usuarios/purga';

type Revision = {
    usuario: string;
    confirmacion: string;
    puede: boolean;
    bloqueos: string[];
    eliminar: { que: string; filas: number }[];
    desvincular: { que: string; filas: number }[];
};

/**
 * «Eliminar definitivamente» (solo super_admin): muestra qué se elimina,
 * qué se desvincula y qué lo impide (PurgaUsuarioService), y pide escribir
 * ELIMINAR o el usuario exacto. No es «Revocar acceso».
 */
const abierto = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    usuario: { id: number; username: string; nombre: string } | null;
}>();

const revision = ref<Revision | null>(null);
const errorCarga = ref<string | null>(null);
const form = useForm({ confirmacion: '' });

watch(
    () => [abierto.value, props.usuario?.id] as const,
    async ([abiertoAhora, id]) => {
        revision.value = null;
        errorCarga.value = null;
        form.reset();
        form.clearErrors();

        if (!abiertoAhora || !id) {
            return;
        }

        try {
            const r = await fetch(purga.revisar.url(id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const cuerpo = (await r.json().catch(() => ({}))) as { data?: Revision; message?: string };

            if (!r.ok || !cuerpo.data) {
                errorCarga.value = cuerpo.message || 'No se pudo revisar la cuenta.';

                return;
            }

            revision.value = cuerpo.data;
        } catch {
            errorCarga.value = 'No se pudo revisar la cuenta. Revisa tu conexión.';
        }
    },
    { immediate: true },
);

const confirmado = computed(
    () =>
        !!revision.value &&
        (form.confirmacion.trim() === 'ELIMINAR' || form.confirmacion.trim() === revision.value.confirmacion),
);

function eliminar() {
    if (!props.usuario || !confirmado.value) {
        return;
    }

    form.delete(purga.eliminar.url(props.usuario.id), {
        preserveScroll: true,
        onSuccess: () => {
            abierto.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent class="max-h-[90vh] overflow-y-auto border-destructive/40 sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2 text-destructive">
                    <ShieldAlert class="size-5" /> Eliminar definitivamente
                </DialogTitle>
                <DialogDescription>
                    Borra la cuenta <strong>{{ usuario?.username }}</strong>
                    <template v-if="usuario?.nombre"> ({{ usuario.nombre }})</template>
                    de la base de datos. No se puede deshacer. Para solo
                    impedir el acceso usa «Revocar acceso».
                </DialogDescription>
            </DialogHeader>

            <div v-if="!revision && !errorCarga" class="flex flex-col gap-2">
                <Skeleton class="h-4 w-3/4" />
                <Skeleton class="h-4 w-2/3" />
                <Skeleton class="h-4 w-1/2" />
            </div>

            <p v-else-if="errorCarga" class="rounded-xl bg-danger-soft p-3 text-sm text-destructive">{{ errorCarga }}</p>

            <div v-else-if="revision" class="flex flex-col gap-4 text-sm">
                <div v-if="revision.bloqueos.length" class="flex flex-col gap-1.5 rounded-xl bg-danger-soft p-3">
                    <p class="flex items-center gap-1.5 font-medium text-destructive"><Ban class="size-4" /> No se puede eliminar</p>
                    <ul class="list-disc pl-5 text-xs">
                        <li v-for="b in revision.bloqueos" :key="b">{{ b }}</li>
                    </ul>
                    <p class="text-xs">Su historial debe conservarse: usa «Revocar acceso».</p>
                </div>

                <template v-else>
                    <div>
                        <p class="mb-1 flex items-center gap-1.5 font-medium"><Trash2 class="size-4 text-destructive" /> Se eliminará</p>
                        <ul class="list-disc pl-5 text-xs text-muted-foreground">
                            <li>La cuenta de acceso y su contraseña.</li>
                            <li v-for="e in revision.eliminar" :key="e.que">{{ e.filas }} · {{ e.que }}</li>
                        </ul>
                    </div>
                    <div v-if="revision.desvincular.length">
                        <p class="mb-1 flex items-center gap-1.5 font-medium"><Link2Off class="size-4" /> Quedará sin autor</p>
                        <ul class="list-disc pl-5 text-xs text-muted-foreground">
                            <li v-for="d in revision.desvincular" :key="d.que">{{ d.filas }} · {{ d.que }}</li>
                        </ul>
                    </div>
                    <p class="rounded-xl bg-crema/50 p-3 text-xs text-muted-foreground">
                        El expediente del colaborador no se elimina. Esta acción
                        no queda en la bitácora de la aplicación; los respaldos
                        y registros del servidor quedan fuera de su alcance.
                    </p>
                    <div class="flex flex-col gap-1.5">
                        <Label for="purga-confirmacion">Escribe <strong>ELIMINAR</strong> o el usuario <strong>{{ revision.confirmacion }}</strong></Label>
                        <Input id="purga-confirmacion" v-model="form.confirmacion" autocomplete="off" @keydown.enter.prevent="eliminar" />
                        <InputError :message="form.errors.confirmacion" />
                    </div>
                </template>
            </div>

            <DialogFooter class="gap-2">
                <Button variant="ghost" :disabled="form.processing" @click="abierto = false">Cancelar</Button>
                <Button v-if="revision?.puede" variant="destructive" :disabled="!confirmado || form.processing" @click="eliminar">
                    <Spinner v-if="form.processing" class="size-4" /><Trash2 v-else class="size-4" /> Eliminar definitivamente
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
