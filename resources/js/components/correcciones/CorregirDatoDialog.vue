<script setup lang="ts">
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    Info,
    Lock,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ErrorCorreccion, pedirCorreccion } from '@/lib/correcciones';
import { aplicar, mostrar, vistaPrevia } from '@/routes/correcciones';
import type {
    AfectadoCorreccion,
    DescripcionCorreccion,
    DominioCorreccion,
    RegistroCorreccion,
    VistaPreviaCorreccion,
} from '@/types';

/**
 * Corrección en tres pasos: dato actual y nuevo → antes/después con los
 * registros afectados → motivo y confirmación. El servidor decide qué se puede
 * corregir y vuelve a validarlo todo. Un 409 regresa al primer paso con el valor
 * vigente, sin perder lo capturado.
 */
const props = defineProps<{
    open: boolean;
    dominio: DominioCorreccion;
    sujetoId: number;
    campoInicial?: string;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
    corregido: [registro: RegistroCorreccion];
}>();

type Paso = 'captura' | 'confirmar' | 'hecho';

const paso = ref<Paso>('captura');
const cargando = ref(false);
const enviando = ref(false);
const descripcion = ref<DescripcionCorreccion | null>(null);
const previa = ref<VistaPreviaCorreccion | null>(null);
const campoElegido = ref('');
const valorNuevo = ref('');
const tipo = ref('');
const vigenteDesde = ref('');
const motivo = ref('');
const error = ref<string | null>(null);
const errores = ref<Record<string, string[]>>({});
const conflicto = ref<string | null>(null);

const MOTIVO_MINIMO = 5;

const campo = computed(
    () =>
        descripcion.value?.campos.find(
            (c) => c.campo === campoElegido.value,
        ) ?? null,
);

const puedeRevisar = computed(
    () =>
        campo.value?.editable === true &&
        valorNuevo.value !== comoEntrada(campo.value.valor, campo.value.tipo),
);

const motivoValido = computed(
    () => motivo.value.trim().length >= MOTIVO_MINIMO,
);

const tipoInput = computed(() => {
    switch (campo.value?.tipo) {
        case 'fecha':
            return 'date';
        case 'fecha_hora':
            return 'datetime-local';
        case 'moneda':
        case 'numero':
        case 'entero':
            return 'number';
        default:
            return 'text';
    }
});

const ETIQUETA_ACCION: Record<AfectadoCorreccion['accion'], string> = {
    se_actualiza: 'Se actualiza',
    requiere_rectificacion: 'Requiere rectificación',
    requiere_modificacion_contractual: 'Requiere modificación contractual',
    revisar: 'Revisar',
    intacto: 'No cambia',
    nueva_version: 'Nueva versión',
};

const CLASE_ACCION: Record<AfectadoCorreccion['accion'], string> = {
    se_actualiza: 'bg-success-soft text-success',
    requiere_rectificacion: 'bg-warning/15 text-warning',
    requiere_modificacion_contractual: 'bg-warning/15 text-warning',
    revisar: 'bg-crema text-bronce',
    intacto: 'bg-muted text-muted-foreground',
    nueva_version: 'bg-crema text-bronce',
};

function primerError(campoError: string): string | null {
    return errores.value[campoError]?.[0] ?? null;
}

function comoEntrada(valor: string | null, tipoCampo?: string): string {
    if (valor === null) {
        return '';
    }

    return tipoCampo === 'fecha_hora' ? valor.replace(' ', 'T') : valor;
}

function elegirCampo(nombre: string): void {
    campoElegido.value = nombre;
    const elegido = descripcion.value?.campos.find((c) => c.campo === nombre);
    valorNuevo.value = comoEntrada(elegido?.valor ?? null, elegido?.tipo);
    errores.value = {};
}

function aplicarDescripcion(datos: DescripcionCorreccion): void {
    descripcion.value = datos;
    tipo.value = datos.tipos_cambio[0]?.valor ?? '';

    const inicial =
        datos.campos.find((c) => c.campo === props.campoInicial) ??
        datos.campos.find((c) => c.editable) ??
        datos.campos[0];

    elegirCampo(inicial?.campo ?? '');
}

async function cargar(): Promise<void> {
    paso.value = 'captura';
    previa.value = null;
    motivo.value = '';
    vigenteDesde.value = '';
    error.value = null;
    errores.value = {};
    conflicto.value = null;
    cargando.value = true;
    descripcion.value = null;

    try {
        aplicarDescripcion(
            await pedirCorreccion<DescripcionCorreccion>(
                mostrar.url({ dominio: props.dominio, id: props.sujetoId }),
            ),
        );
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'No se pudo cargar el dato.';
    } finally {
        cargando.value = false;
    }
}

watch(
    () => props.open,
    (abierto) => {
        if (abierto) {
            void cargar();
        }
    },
    { immediate: true },
);

function opcionesEnvio(): Record<string, string | null> {
    return {
        tipo: tipo.value || null,
        vigente_desde: vigenteDesde.value || null,
    };
}

async function revisar(): Promise<void> {
    if (!campo.value || enviando.value) {
        return;
    }

    enviando.value = true;
    error.value = null;
    errores.value = {};

    try {
        previa.value = await pedirCorreccion<VistaPreviaCorreccion>(
            vistaPrevia.url({ dominio: props.dominio, id: props.sujetoId }),
            {
                valores: { [campo.value.campo]: valorNuevo.value || null },
                ...opcionesEnvio(),
            },
        );
        paso.value = 'confirmar';
    } catch (e) {
        manejarError(e);
    } finally {
        enviando.value = false;
    }
}

async function confirmar(): Promise<void> {
    if (!campo.value || !previa.value || !descripcion.value || enviando.value) {
        return;
    }

    enviando.value = true;
    error.value = null;
    errores.value = {};

    try {
        const registro = await pedirCorreccion<RegistroCorreccion>(
            aplicar.url({ dominio: props.dominio, id: props.sujetoId }),
            {
                valores: { [campo.value.campo]: valorNuevo.value || null },
                motivo: motivo.value,
                huella: previa.value.huella,
                huella_afectados: previa.value.huella_afectados,
                clave_idempotencia: descripcion.value.clave_idempotencia,
                ...opcionesEnvio(),
            },
        );
        paso.value = 'hecho';
        emit('corregido', registro);
    } catch (e) {
        manejarError(e);
    } finally {
        enviando.value = false;
    }
}

function manejarError(e: unknown): void {
    if (e instanceof ErrorCorreccion && e.estado === 409 && e.actual) {
        const capturado = valorNuevo.value;
        const elegido = campoElegido.value;
        aplicarDescripcion(e.actual);
        elegirCampo(elegido);
        valorNuevo.value = capturado;
        conflicto.value = e.message;
        previa.value = null;
        paso.value = 'captura';

        return;
    }

    if (e instanceof ErrorCorreccion) {
        errores.value = e.errores;
        error.value = Object.keys(e.errores).length > 0 ? null : e.message;

        if (Object.keys(e.errores).length > 0 && !primerError('motivo')) {
            error.value = Object.values(e.errores)[0]?.[0] ?? e.message;
        }

        return;
    }

    error.value = 'No se pudo completar la corrección. Intenta de nuevo.';
}

function cerrar(): void {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>
                    Corregir{{
                        descripcion
                            ? ` ${descripcion.dominio_etiqueta.toLowerCase()}`
                            : ' dato'
                    }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="descripcion">
                        {{ descripcion.sujeto }}.
                    </template>
                    Revisa el antes y el después antes de confirmar: queda en
                    el historial.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="cargando"
                class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
                role="status"
            >
                <Spinner /> Cargando el dato vigente…
            </div>

            <p
                v-if="error"
                class="flex items-start gap-2 rounded-xl bg-destructive/10 px-3 py-2 text-sm text-destructive"
                role="alert"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                {{ error }}
            </p>

            <p
                v-if="conflicto"
                class="flex items-start gap-2 rounded-xl bg-warning/15 px-3 py-2 text-sm text-warning"
                role="alert"
                data-correccion-conflicto
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                {{ conflicto }}
            </p>

            <!-- Paso 1: dato actual y nuevo -->
            <form
                v-if="descripcion && paso === 'captura'"
                class="grid gap-4"
                @submit.prevent="revisar"
            >
                <div class="grid gap-1.5">
                    <Label for="correccion-campo">Dato a corregir</Label>
                    <NativeSelect
                        id="correccion-campo"
                        :model-value="campoElegido"
                        class="w-full"
                        @update:model-value="elegirCampo(String($event))"
                    >
                        <NativeSelectOption
                            v-for="c in descripcion.campos"
                            :key="c.campo"
                            :value="c.campo"
                        >
                            {{ c.etiqueta }}{{ c.editable ? '' : ' (bloqueado)' }}
                        </NativeSelectOption>
                    </NativeSelect>
                </div>

                <template v-if="campo">
                    <div class="rounded-xl bg-crema px-3 py-2 text-sm">
                        <p class="text-xs text-muted-foreground">Dato actual</p>
                        <p class="font-medium break-words" data-correccion-actual>
                            {{ campo.legible ?? 'Sin capturar' }}
                        </p>
                    </div>

                    <p
                        v-if="campo.explicacion"
                        class="flex items-start gap-2 rounded-xl bg-muted px-3 py-2 text-sm"
                        data-correccion-explicacion
                    >
                        <Lock
                            v-if="!campo.editable"
                            class="mt-0.5 size-4 shrink-0"
                        />
                        <Info v-else class="mt-0.5 size-4 shrink-0" />
                        <span>
                            <span class="font-semibold"
                                >{{ campo.modo_etiqueta }}.</span
                            >
                            {{ campo.explicacion }}
                        </span>
                    </p>

                    <template v-if="campo.editable">
                        <div
                            v-if="descripcion.tipos_cambio.length"
                            class="grid gap-1.5"
                        >
                            <Label for="correccion-tipo">¿Qué es este cambio?</Label>
                            <NativeSelect
                                id="correccion-tipo"
                                v-model="tipo"
                                class="w-full"
                            >
                                <NativeSelectOption
                                    v-for="t in descripcion.tipos_cambio"
                                    :key="t.valor"
                                    :value="t.valor"
                                >
                                    {{ t.etiqueta }}
                                </NativeSelectOption>
                            </NativeSelect>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    descripcion.tipos_cambio.find(
                                        (t) => t.valor === tipo,
                                    )?.descripcion
                                }}
                            </p>
                        </div>

                        <div
                            v-if="tipo === 'ajuste_con_vigencia'"
                            class="grid gap-1.5"
                        >
                            <Label for="correccion-vigencia">Aplica desde</Label>
                            <Input
                                id="correccion-vigencia"
                                v-model="vigenteDesde"
                                type="date"
                            />
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="correccion-valor">Dato nuevo</Label>
                            <NativeSelect
                                v-if="campo.tipo === 'seleccion'"
                                id="correccion-valor"
                                v-model="valorNuevo"
                                class="w-full"
                            >
                                <NativeSelectOption
                                    v-for="o in campo.opciones"
                                    :key="o.valor"
                                    :value="o.valor"
                                >
                                    {{ o.etiqueta }}
                                </NativeSelectOption>
                            </NativeSelect>
                            <Textarea
                                v-else-if="campo.tipo === 'area'"
                                id="correccion-valor"
                                v-model="valorNuevo"
                                rows="3"
                            />
                            <Input
                                v-else
                                id="correccion-valor"
                                v-model="valorNuevo"
                                :type="tipoInput"
                                :step="campo.tipo === 'entero' ? '1' : '0.01'"
                                :aria-invalid="primerError(campo.campo) !== null"
                            />
                        </div>
                    </template>
                </template>

                <DialogFooter>
                    <Button type="button" variant="secondary" @click="cerrar">
                        Cerrar
                    </Button>
                    <Button
                        v-if="campo?.editable"
                        type="submit"
                        :disabled="!puedeRevisar || enviando"
                    >
                        <Spinner v-if="enviando" />
                        Revisar cambio
                    </Button>
                </DialogFooter>
            </form>

            <!-- Paso 2: antes/después, afectados, motivo -->
            <form
                v-else-if="previa && paso === 'confirmar'"
                class="grid gap-4"
                @submit.prevent="confirmar"
            >
                <div>
                    <h3 class="mb-2 text-sm font-semibold">Antes y después</h3>
                    <ul class="grid gap-2">
                        <li
                            v-for="cambio in previa.cambios"
                            :key="cambio.campo"
                            class="rounded-xl border border-border/60 p-3 text-sm"
                            data-correccion-cambio
                        >
                            <p class="text-xs text-muted-foreground">
                                {{ cambio.etiqueta }}
                            </p>
                            <p class="flex flex-wrap items-center gap-2">
                                <span class="text-muted-foreground line-through">
                                    {{ cambio.antes ?? 'Sin capturar' }}
                                </span>
                                <ArrowRight
                                    class="size-3.5 shrink-0"
                                    aria-label="cambia a"
                                />
                                <span class="font-semibold">
                                    {{ cambio.despues ?? 'Sin capturar' }}
                                </span>
                            </p>
                        </li>
                    </ul>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-semibold">Qué más se afecta</h3>
                    <p
                        v-if="previa.afectados.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No se detectaron otros registros afectados.
                    </p>
                    <ul v-else class="grid gap-2">
                        <li
                            v-for="(afectado, i) in previa.afectados"
                            :key="i"
                            class="rounded-xl border border-border/60 p-3 text-sm"
                            data-correccion-afectado
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span class="font-medium">
                                    {{ afectado.etiqueta }}
                                </span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="CLASE_ACCION[afectado.accion]"
                                >
                                    {{ ETIQUETA_ACCION[afectado.accion] }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ afectado.detalle }}
                            </p>
                        </li>
                    </ul>
                </div>

                <div class="grid gap-1.5">
                    <Label for="correccion-motivo">Motivo de la corrección</Label>
                    <Textarea
                        id="correccion-motivo"
                        v-model="motivo"
                        rows="3"
                        maxlength="500"
                        required
                        :aria-invalid="primerError('motivo') !== null"
                        aria-describedby="correccion-motivo-ayuda"
                    />
                    <p
                        id="correccion-motivo-ayuda"
                        class="text-xs"
                        :class="
                            primerError('motivo')
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                    >
                        {{
                            primerError('motivo') ??
                            `Obligatorio, mínimo ${MOTIVO_MINIMO} caracteres. Queda en el historial.`
                        }}
                    </p>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="enviando"
                        @click="paso = 'captura'"
                    >
                        Regresar
                    </Button>
                    <Button type="submit" :disabled="!motivoValido || enviando">
                        <Spinner v-if="enviando" />
                        Confirmar corrección
                    </Button>
                </DialogFooter>
            </form>

            <!-- Paso 3: éxito -->
            <div
                v-else-if="paso === 'hecho'"
                class="grid justify-items-center gap-3 py-6 text-center"
                role="status"
            >
                <CheckCircle2 class="size-10 text-success" />
                <p class="text-sm font-semibold">Corrección guardada</p>
                <p class="text-xs text-muted-foreground">
                    Quedó en el historial con el motivo, quién y cuándo.
                </p>
                <Button @click="cerrar">Cerrar</Button>
            </div>

            <DialogFooter v-else-if="!cargando && !descripcion">
                <Button variant="secondary" @click="cerrar">Cerrar</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
