<script setup lang="ts">
import { AlertTriangle, Eye, FileCheck2, Undo2 } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
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
import { enviarJson, getJson, vistaPreviaPost } from '@/lib/http';
import rutas from '@/routes/rh/documentos-edicion';
import type { CamposDocumento, CampoDocumento } from '@/types';

/**
 * «Editar datos» de cualquier documento generado: edita a la izquierda, vista
 * previa con marca de agua a la derecha, sin guardar nada. «Regenerar PDF» se
 * habilita cuando la vista previa corresponde a lo capturado. El PDF nunca se
 * edita: se emite otra versión con motivo y la anterior se conserva. Las reglas
 * viven en VersionadoDocumentoService.
 */
const abierto = defineModel<boolean>('open', { required: true });
const props = defineProps<{
    tipo: string;
    id: number;
    clave?: string | null;
    titulo?: string | null;
}>();
const emit = defineEmits<{ emitido: [creado: boolean, mensaje: string] }>();

const datos = ref<CamposDocumento | null>(null);
const campos = ref<CampoDocumento[]>([]);
const motivo = ref('');
const cargando = ref(false);
const generandoPrevia = ref(false);
const guardando = ref(false);
const confirmando = ref(false);
const error = ref<string | null>(null);
const errorEmision = ref<string | null>(null);
const urlPrevia = ref<string | null>(null);
const huellaPrevia = ref<string | null>(null);

const consulta = computed(() =>
    props.clave ? { query: { clave: props.clave } } : undefined,
);
const firmado = computed(() => datos.value?.vigente?.firmado ?? false);
const previaAlDia = computed(() => huellaPrevia.value === huellaActual());
const obsoletos = computed(() => campos.value.filter((c) => c.obsoleta));
const motivoValido = computed(() => motivo.value.trim().length >= 5);
const bloqueado = computed(
    () => firmado.value && !datos.value?.puede_rectificar,
);

function valores(): Record<string, string> {
    return Object.fromEntries(campos.value.map((c) => [c.clave, c.valor]));
}

function huellaActual(): string {
    return JSON.stringify(valores());
}

function cuerpo(): Record<string, unknown> {
    return { clave: props.clave ?? null, campos: valores() };
}

function revocar() {
    if (urlPrevia.value) {
        URL.revokeObjectURL(urlPrevia.value);
        urlPrevia.value = null;
    }
}

async function actualizarPrevia() {
    generandoPrevia.value = true;
    error.value = null;
    const huella = huellaActual();

    try {
        const url = await vistaPreviaPost(
            rutas.vistaPrevia.url({ tipo: props.tipo, id: props.id }),
            cuerpo(),
        );
        revocar();
        urlPrevia.value = url;
        huellaPrevia.value = huella;
    } catch (e) {
        error.value =
            e instanceof Error
                ? e.message
                : 'No se pudo generar la vista previa.';
    } finally {
        generandoPrevia.value = false;
    }
}

watch(abierto, async (visible) => {
    if (!visible) {
        revocar();

        return;
    }

    datos.value = null;
    campos.value = [];
    motivo.value = '';
    error.value = null;
    errorEmision.value = null;
    huellaPrevia.value = null;
    confirmando.value = false;
    cargando.value = true;

    try {
        const respuesta = await getJson<CamposDocumento>(
            rutas.campos.url(
                { tipo: props.tipo, id: props.id },
                consulta.value,
            ),
        );
        datos.value = respuesta;
        campos.value = respuesta.campos;
        await actualizarPrevia();
    } catch {
        error.value = 'No se pudieron cargar los datos del documento.';
    } finally {
        cargando.value = false;
    }
});

async function emitir() {
    guardando.value = true;
    errorEmision.value = null;

    try {
        const respuesta = await enviarJson<{
            creado: boolean;
            message: string;
        }>('POST', rutas.emitir.url({ tipo: props.tipo, id: props.id }), {
            ...cuerpo(),
            motivo: motivo.value,
            rectificar: firmado.value,
        });
        emit('emitido', respuesta.creado, respuesta.message);
        abierto.value = false;
    } catch (e) {
        errorEmision.value =
            e instanceof Error ? e.message : 'No se pudo generar el PDF.';
        confirmando.value = false;
    } finally {
        guardando.value = false;
    }
}

onBeforeUnmount(revocar);
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-6xl">
            <DialogHeader>
                <DialogTitle>
                    {{ firmado ? 'Rectificar' : 'Editar datos de' }}
                    {{ titulo ?? datos?.etiqueta ?? 'documento' }}
                </DialogTitle>
                <DialogDescription>
                    Nada se guarda hasta regenerar el PDF. Cada versión queda
                    con motivo, usuario y fecha, y la anterior se conserva.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="bloqueado"
                class="rounded-xl bg-danger-soft px-3 py-2 text-xs text-destructive"
                role="alert"
            >
                Este documento ya está firmado y el original se conserva. Tu
                perfil no puede emitir una rectificación: pídela a quien tenga
                ese permiso.
            </p>
            <p
                v-else-if="firmado"
                class="rounded-xl bg-crema px-3 py-2 text-xs"
                role="status"
            >
                <AlertTriangle class="mr-1 inline size-3.5" />
                El original firmado no se toca. Se emitirá una rectificación
                ligada a él, que se imprime y firma aparte.
            </p>
            <p
                v-if="obsoletos.length"
                class="rounded-xl bg-crema px-3 py-2 text-xs"
                role="status"
            >
                <AlertTriangle class="mr-1 inline size-3.5" />
                El expediente cambió después de corregir:
                {{ obsoletos.map((c) => c.etiqueta).join(', ') }}. Se muestra el
                dato nuevo; vuelve a capturar la corrección solo si sigue
                aplicando.
            </p>

            <div class="grid gap-5 lg:grid-cols-2">
                <form
                    class="flex flex-col gap-3"
                    @submit.prevent="confirmando = true"
                >
                    <div
                        v-for="c in campos"
                        :key="c.clave"
                        class="grid gap-1.5"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <Label :for="`dato-${c.clave}`">{{
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
                            :id="`dato-${c.clave}`"
                            v-model="c.valor"
                            rows="4"
                        />
                        <Input
                            v-else
                            :id="`dato-${c.clave}`"
                            v-model="c.valor"
                        />
                        <p
                            v-if="c.obsoleta"
                            class="text-xs text-muted-foreground"
                        >
                            Corrección anterior: {{ c.obsoleta }}
                        </p>
                    </div>
                    <p
                        v-if="!campos.length && !cargando"
                        class="text-sm text-muted-foreground"
                    >
                        Este documento no tiene datos que se corrijan aquí: se
                        corrigen en su módulo y aquí solo se regenera el PDF.
                    </p>

                    <div class="grid gap-1.5">
                        <Label for="dato-motivo">Motivo</Label>
                        <Textarea id="dato-motivo" v-model="motivo" rows="2" />
                        <InputError
                            v-if="motivo && !motivoValido"
                            message="Escribe al menos 5 caracteres."
                        />
                    </div>
                    <p
                        v-if="errorEmision"
                        class="rounded-xl bg-danger-soft px-3 py-2 text-xs text-destructive"
                        role="alert"
                    >
                        {{ errorEmision }}
                    </p>

                    <p
                        v-if="confirmando"
                        class="rounded-xl bg-crema px-3 py-2 text-xs"
                        role="alert"
                    >
                        ¿Generar la nueva versión? Si los datos no cambiaron no
                        se crea ninguna.
                    </p>
                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="generandoPrevia || cargando"
                            @click="actualizarPrevia"
                        >
                            <Spinner v-if="generandoPrevia" />
                            <Eye v-else class="size-4" />Actualizar vista previa
                        </Button>
                        <Button
                            v-if="!confirmando"
                            type="submit"
                            :disabled="!previaAlDia || bloqueado || cargando"
                            :title="
                                previaAlDia
                                    ? undefined
                                    : 'Actualiza la vista previa para ver estos datos antes de generar.'
                            "
                        >
                            <FileCheck2 class="size-4" />{{
                                firmado
                                    ? 'Emitir rectificación'
                                    : 'Regenerar PDF'
                            }}
                        </Button>
                        <template v-else>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="guardando"
                                @click="confirmando = false"
                                >Volver</Button
                            >
                            <Button
                                type="button"
                                :disabled="guardando"
                                @click="emitir"
                            >
                                <Spinner v-if="guardando" />Confirmar
                            </Button>
                        </template>
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
                        title="Vista previa del documento"
                        class="h-[70vh] w-full flex-1 rounded-xl border border-border/60"
                    />
                    <p
                        v-else
                        class="flex flex-1 items-center justify-center rounded-xl bg-muted text-sm text-muted-foreground"
                    >
                        {{
                            cargando || generandoPrevia
                                ? 'Generando vista previa…'
                                : 'Sin vista previa'
                        }}
                    </p>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
