<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Eye, Undo2 } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
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
import { Textarea } from '@/components/ui/textarea';
import { getJson, vistaPreviaPost } from '@/lib/http';
import rutasSalida from '@/routes/rh/bajas/salida';
import type { PanelDocumentoSalida } from '@/types';

/**
 * Corrige el contenido de un documento de salida antes de firmarlo: edita a la
 * izquierda, regenera la vista previa a la derecha sin guardar y, al confirmar,
 * genera una versión nueva con motivo (la anterior se conserva). Lo que se deja
 * igual sigue saliendo del expediente. Reglas: BajaDocumentoSalidaService.
 */
type Campo = {
    clave: string;
    etiqueta: string;
    tipo: string;
    valor: string;
    original: string;
};

const abierto = defineModel<boolean>('open', { required: true });
const props = defineProps<{ panel: PanelDocumentoSalida }>();

const campos = ref<Campo[]>([]);
const motivo = ref('');
const cargando = ref(false);
const guardando = ref(false);
const error = ref<string | null>(null);
const errores = ref<Record<string, string>>({});
const urlPrevia = ref<string | null>(null);

function valores(): Record<string, string> {
    return Object.fromEntries(campos.value.map((c) => [c.clave, c.valor]));
}

function revocar() {
    if (urlPrevia.value) {
        URL.revokeObjectURL(urlPrevia.value);
        urlPrevia.value = null;
    }
}

async function regenerar() {
    cargando.value = true;
    error.value = null;

    try {
        const url = await vistaPreviaPost(
            rutasSalida.vistaPreviaEditada.url(props.panel.baja_id),
            { campos: valores() },
        );
        revocar();
        urlPrevia.value = url;
    } catch (e) {
        error.value =
            e instanceof Error
                ? e.message
                : 'No se pudo generar la vista previa.';
    } finally {
        cargando.value = false;
    }
}

watch(abierto, async (visible) => {
    if (!visible) {
        revocar();

        return;
    }

    campos.value = [];
    motivo.value = '';
    errores.value = {};
    error.value = null;
    cargando.value = true;

    try {
        const respuesta = await getJson<{ campos: Campo[] }>(
            rutasSalida.campos.url(props.panel.baja_id),
        );
        campos.value = respuesta.campos;
        await regenerar();
    } catch {
        error.value = 'No se pudieron cargar los datos del documento.';
        cargando.value = false;
    }
});

function guardar() {
    guardando.value = true;
    errores.value = {};
    router.post(
        rutasSalida.generar.url(props.panel.baja_id),
        { campos: valores(), motivo: motivo.value },
        {
            preserveScroll: true,
            onSuccess: () => (abierto.value = false),
            onError: (e) => (errores.value = e),
            onFinish: () => (guardando.value = false),
        },
    );
}

onBeforeUnmount(revocar);
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-6xl">
            <DialogHeader>
                <DialogTitle
                    >Corregir {{ panel.documento?.etiqueta }} ·
                    {{ panel.colaborador }}</DialogTitle
                >
                <DialogDescription
                    >Edita lo que esté mal y actualiza la vista previa. Nada se
                    guarda hasta generar la nueva versión; cada corrección queda
                    con motivo, usuario y fecha.</DialogDescription
                >
            </DialogHeader>

            <div class="grid gap-5 lg:grid-cols-2">
                <form class="flex flex-col gap-3" @submit.prevent="guardar">
                    <div
                        v-for="c in campos"
                        :key="c.clave"
                        class="grid gap-1.5"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <Label :for="`doc-${c.clave}`">{{
                                c.etiqueta
                            }}</Label>
                            <button
                                v-if="c.valor !== c.original"
                                type="button"
                                class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                @click="c.valor = c.original"
                            >
                                <Undo2 class="size-3" />Restaurar
                            </button>
                        </div>
                        <Textarea
                            v-if="c.tipo === 'area'"
                            :id="`doc-${c.clave}`"
                            v-model="c.valor"
                            rows="4"
                        />
                        <Input
                            v-else
                            :id="`doc-${c.clave}`"
                            v-model="c.valor"
                        />
                    </div>
                    <p
                        v-if="!campos.length && !cargando"
                        class="text-sm text-muted-foreground"
                    >
                        Este documento no tiene datos que se puedan corregir
                        aquí.
                    </p>

                    <div class="grid gap-1.5">
                        <Label for="doc-motivo">Motivo de la corrección</Label>
                        <Textarea id="doc-motivo" v-model="motivo" rows="2" />
                        <InputError :message="errores.motivo" />
                    </div>
                    <p
                        v-if="errores.documento"
                        class="rounded-xl bg-danger-soft px-3 py-2 text-xs text-destructive"
                        role="alert"
                    >
                        {{ errores.documento }}
                    </p>

                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="cargando"
                            @click="regenerar"
                            ><Eye class="size-4" />Actualizar vista
                            previa</Button
                        >
                        <Button type="submit" :disabled="guardando"
                            >Guardar y generar versión</Button
                        >
                    </DialogFooter>
                </form>

                <div class="flex min-h-[28rem] flex-col gap-2">
                    <p
                        v-if="error"
                        class="rounded-xl bg-danger-soft px-3 py-2 text-xs text-destructive"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <iframe
                        v-if="urlPrevia"
                        :src="urlPrevia"
                        title="Vista previa del documento corregido"
                        class="h-[70vh] w-full flex-1 rounded-xl border border-border/60"
                    />
                    <p
                        v-else
                        class="flex flex-1 items-center justify-center rounded-xl bg-muted text-sm text-muted-foreground"
                    >
                        {{
                            cargando
                                ? 'Generando vista previa…'
                                : 'Sin vista previa'
                        }}
                    </p>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
