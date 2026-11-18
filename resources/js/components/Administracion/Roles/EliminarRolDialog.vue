<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { TriangleAlert, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
import { destroy } from '@/routes/administracion/roles';
import type { RolItem } from '@/types/roles';

/**
 * «Eliminar definitivamente» un rol (solo super_admin; el backend lo vuelve a
 * exigir): se confirma escribiendo su nombre y, si alguien lo tiene, se elige
 * a qué rol pasan esas personas. Nunca borra usuarios ni expedientes.
 */
const props = defineProps<{
    rol: RolItem;
    roles: RolItem[];
}>();

const abierto = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ eliminado: [] }>();

const confirmacion = ref('');
const destino = ref('');
const errores = ref<Record<string, string>>({});
const enviando = ref(false);

watch(abierto, (valor) => {
    if (valor) {
        confirmacion.value = '';
        destino.value = '';
        errores.value = {};
    }
});

const opcionesDestino = computed(() =>
    props.roles
        .filter((r) => r.id !== props.rol.id && r.activo)
        .map((r) => ({
            value: String(r.id),
            label: `${r.nombre} · ${r.usuarios_count} usuario(s)`,
        })),
);

const listo = computed(
    () =>
        confirmacion.value.trim() === props.rol.nombre &&
        (props.rol.usuarios_count === 0 || destino.value !== ''),
);

function eliminar() {
    if (!listo.value) {
        return;
    }

    enviando.value = true;
    router.delete(destroy.url(props.rol.id), {
        data: {
            confirmacion: confirmacion.value.trim(),
            reasignar_a: destino.value === '' ? null : Number(destino.value),
        },
        preserveScroll: true,
        onSuccess: () => {
            abierto.value = false;
            emit('eliminado');
        },
        onError: (e) => (errores.value = e),
        onFinish: () => (enviando.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <TriangleAlert class="size-5 text-destructive" />
                    Eliminar definitivamente «{{ rol.nombre }}»
                </DialogTitle>
                <DialogDescription>
                    Se borran el rol, sus {{ rol.permisos.length }} permiso(s) y
                    su lista de avisos. No se puede deshacer. Si solo quieres
                    que deje de dar permisos, mejor desactívalo: se puede
                    reactivar.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="eliminar">
                <div
                    v-if="rol.usuarios_count > 0"
                    class="grid gap-1.5 rounded-xl bg-warning-soft p-3"
                >
                    <Label for="destino-rol"
                        >{{ rol.usuarios_count }} persona(s) tienen este rol.
                        ¿A qué rol pasan?</Label
                    >
                    <SelectSimple
                        id="destino-rol"
                        v-model="destino"
                        :opciones="opcionesDestino"
                        placeholder="Elige el rol destino…"
                        aria-label="Rol al que pasan las personas"
                    />
                    <InputError :message="errores.reasignar_a" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="confirmar-rol"
                        >Escribe <strong>{{ rol.nombre }}</strong> para
                        confirmar</Label
                    >
                    <Input
                        id="confirmar-rol"
                        v-model="confirmacion"
                        autocomplete="off"
                        :placeholder="rol.nombre"
                    />
                    <InputError :message="errores.confirmacion ?? errores.rol" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="abierto = false"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="!listo || enviando"
                    >
                        <Trash2 class="size-4" />
                        Eliminar definitivamente
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
