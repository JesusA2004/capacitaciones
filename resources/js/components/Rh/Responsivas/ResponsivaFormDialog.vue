<script setup lang="ts">
import {
    ArrowLeft,
    Eye,
    FileSignature,
    Loader2,
    Plus,
    Printer,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DatePicker from '@/components/Common/DatePicker.vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { enviarJson, getJson, leerCookie } from '@/lib/http';
import {
    index as indexResponsivas,
    pdf as pdfResponsiva,
    store as storeResponsiva,
    vistaPrevia as vistaPreviaResponsiva,
} from '@/routes/rh/responsivas';
import type {
    ActivoResponsivaForm,
    OpcionResponsiva,
    ResponsivaForm,
    ResponsivaItem,
    ResponsivasColaboradorRespuesta,
    ValoresInicialesResponsiva,
} from '@/types';

/**
 * «Generar responsiva»: el gerente o RH describe el equipo que entrega
 * (laptop, casco, celular…) y PEOPLE llena la responsiva con los datos del
 * colaborador, la empresa y quien entrega. Vista previa → generar → abrir
 * el PDF para imprimir y firmar. La autorización real está en el backend.
 */
const props = defineProps<{
    colaboradorId: number;
}>();

const abierto = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ generada: [responsiva: ResponsivaItem] }>();

const cargando = ref(false);
const enviando = ref(false);
const previsualizando = ref(false);
const vistaPreviaUrl = ref<string | null>(null);
const generada = ref<ResponsivaItem | null>(null);
const valores = ref<ValoresInicialesResponsiva | null>(null);
const tipos = ref<OpcionResponsiva[]>([]);
const condiciones = ref<OpcionResponsiva[]>([]);
const error = ref<string | null>(null);

const hoy = new Date().toLocaleDateString('en-CA');

function activoVacio(): ActivoResponsivaForm {
    return {
        descripcion: '',
        marca: '',
        modelo: '',
        numero_serie: '',
        color: '',
        cantidad: 1,
        estado: 'nuevo',
        caracteristicas: '',
        accesorios: '',
    };
}

const form = ref<ResponsivaForm>({
    tipo: 'equipo_trabajo',
    lugar: '',
    fecha_entrega: hoy,
    responsable_entrega_id: 0,
    observaciones: '',
    activos: [activoVacio()],
});

const completo = computed(
    () =>
        form.value.lugar.trim() !== '' &&
        form.value.fecha_entrega !== '' &&
        form.value.activos.length > 0 &&
        form.value.activos.every((a) => a.descripcion.trim() !== ''),
);

// Responsable de entrega elegido (quien realmente entregó): por defecto el
// capturador; RH puede elegir a otro dentro de su alcance.
const responsableSeleccionado = computed(
    () =>
        valores.value?.responsables.find(
            (r) => r.id === form.value.responsable_entrega_id,
        ) ?? null,
);

// El componente Select trabaja con strings; convertimos a número al guardar.
const responsableIdStr = computed<string>({
    get: () => String(form.value.responsable_entrega_id),
    set: (valor) => {
        form.value.responsable_entrega_id = Number(valor);
    },
});

async function cargar() {
    cargando.value = true;
    error.value = null;
    generada.value = null;
    limpiarVistaPrevia();

    try {
        const datos = await getJson<ResponsivasColaboradorRespuesta>(
            indexResponsivas.url(props.colaboradorId),
        );

        if (!datos.puede_generar || datos.valores_iniciales === null) {
            error.value =
                'No tienes permiso para generar responsivas de este colaborador.';

            return;
        }

        valores.value = datos.valores_iniciales;
        tipos.value = datos.tipos;
        condiciones.value = datos.condiciones;
        form.value = {
            tipo: 'equipo_trabajo',
            lugar: datos.valores_iniciales.lugar,
            fecha_entrega: datos.valores_iniciales.fecha_entrega,
            responsable_entrega_id:
                datos.valores_iniciales.responsable_entrega_id,
            observaciones: '',
            activos: [activoVacio()],
        };
    } catch {
        error.value =
            'No se pudo cargar la información del colaborador. Intenta de nuevo.';
    } finally {
        cargando.value = false;
    }
}

watch(abierto, (valor) => {
    if (valor) {
        void cargar();
    } else {
        limpiarVistaPrevia();
    }
});

function agregarActivo() {
    form.value.activos.push(activoVacio());
}

function quitarActivo(indice: number) {
    form.value.activos.splice(indice, 1);
}

function limpiarVistaPrevia() {
    if (vistaPreviaUrl.value !== null) {
        URL.revokeObjectURL(vistaPreviaUrl.value);
        vistaPreviaUrl.value = null;
    }
}

onBeforeUnmount(limpiarVistaPrevia);

async function verVistaPrevia() {
    previsualizando.value = true;

    try {
        const respuesta = await fetch(
            vistaPreviaResponsiva.url(props.colaboradorId),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/pdf, application/json',
                    'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
                },
                credentials: 'same-origin',
                body: JSON.stringify(form.value),
            },
        );

        if (!respuesta.ok) {
            const datos = (await respuesta.json().catch(() => null)) as {
                message?: string;
                errors?: Record<string, string[]>;
            } | null;
            const primero = datos?.errors
                ? Object.values(datos.errors)[0]?.[0]
                : undefined;

            throw new Error(
                primero ??
                    datos?.message ??
                    'No se pudo generar la vista previa.',
            );
        }

        limpiarVistaPrevia();
        vistaPreviaUrl.value = URL.createObjectURL(await respuesta.blob());
    } catch (e) {
        toast.error(
            e instanceof Error
                ? e.message
                : 'No se pudo generar la vista previa.',
        );
    } finally {
        previsualizando.value = false;
    }
}

async function generar() {
    enviando.value = true;

    try {
        const respuesta = await enviarJson<{
            message: string;
            responsiva: ResponsivaItem;
        }>('POST', storeResponsiva.url(props.colaboradorId), form.value);

        limpiarVistaPrevia();
        generada.value = respuesta.responsiva;
        toast.success(respuesta.message);
        emit('generada', respuesta.responsiva);
    } catch (e) {
        toast.error(
            e instanceof Error
                ? e.message
                : 'No se pudo generar la responsiva.',
        );
    } finally {
        enviando.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent
            class="flex max-h-[92vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl"
        >
            <DialogHeader class="border-b border-border/60 px-6 pt-6 pb-4">
                <DialogTitle class="flex items-center gap-2">
                    <FileSignature class="size-5 text-primary" />
                    Generar responsiva de equipo
                </DialogTitle>
                <DialogDescription>
                    Describe lo que entregas; la responsiva se llena con los
                    datos del colaborador, se imprime y se firma.
                </DialogDescription>
            </DialogHeader>

            <div class="flex-1 overflow-y-auto px-6 py-5">
                <div
                    v-if="cargando"
                    class="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" />
                    Cargando datos del colaborador…
                </div>

                <p
                    v-else-if="error"
                    class="rounded-xl bg-danger-soft px-4 py-3 text-sm text-destructive"
                >
                    {{ error }}
                </p>

                <!-- Ya generada: abrir para imprimir -->
                <div
                    v-else-if="generada"
                    class="flex flex-col items-center gap-4 py-10 text-center"
                >
                    <div
                        class="flex size-14 items-center justify-center rounded-full bg-success-soft text-success"
                    >
                        <FileSignature class="size-7" />
                    </div>
                    <div>
                        <p class="text-lg font-semibold">
                            Responsiva {{ generada.folio }} generada
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Quedó guardada en el expediente. Imprímela, recaba
                            las firmas de quien recibe y de quien entrega, y
                            después sube el escaneo firmado.
                        </p>
                    </div>
                    <Button as-child>
                        <a
                            :href="pdfResponsiva.url(generada.id)"
                            target="_blank"
                            rel="noopener"
                        >
                            <Printer class="size-4" />
                            Abrir para imprimir
                        </a>
                    </Button>
                </div>

                <!-- Vista previa -->
                <div v-else-if="vistaPreviaUrl" class="flex flex-col gap-3">
                    <iframe
                        :src="vistaPreviaUrl"
                        title="Vista previa de la responsiva"
                        class="h-[62vh] w-full rounded-xl border border-border/60 bg-card"
                    />
                </div>

                <div v-else-if="valores" class="flex flex-col gap-6">
                    <!-- Datos que vienen del sistema -->
                    <div
                        class="grid gap-3 rounded-2xl bg-crema/50 p-4 text-sm sm:grid-cols-3"
                    >
                        <div>
                            <p
                                class="text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                Recibe
                            </p>
                            <p class="font-semibold">
                                {{ valores.receptor.nombre }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    [
                                        valores.receptor.puesto,
                                        valores.receptor.sucursal,
                                        valores.receptor.numero_empleado
                                            ? `No. ${valores.receptor.numero_empleado}`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                Entrega
                            </p>
                            <!-- RH puede elegir al responsable real de entrega
                                 cuando captura por otro; si no, se asume quien
                                 captura. -->
                            <template v-if="valores.puede_elegir_responsable">
                                <Select v-model="responsableIdStr">
                                    <SelectTrigger
                                        class="mt-0.5 h-8 w-full"
                                        aria-label="Responsable de entrega"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="responsable in valores.responsables"
                                            :key="responsable.id"
                                            :value="String(responsable.id)"
                                        >
                                            {{ responsable.nombre }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    {{
                                        responsableSeleccionado?.puesto ??
                                        'Sin puesto'
                                    }}
                                </p>
                            </template>
                            <template v-else>
                                <p class="font-semibold">
                                    {{ valores.otorgante.nombre }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        valores.otorgante.puesto ??
                                        'Sin puesto'
                                    }}
                                </p>
                            </template>
                        </div>
                        <div>
                            <p
                                class="text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                Empresa
                            </p>
                            <p class="font-semibold">
                                {{ valores.empresa ?? 'Sin empresa' }}
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-1.5">
                            <Label for="responsiva-tipo">Tipo de equipo</Label>
                            <Select v-model="form.tipo">
                                <SelectTrigger
                                    id="responsiva-tipo"
                                    class="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="tipo in tipos"
                                        :key="tipo.value"
                                        :value="tipo.value"
                                    >
                                        {{ tipo.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="responsiva-lugar">Lugar</Label>
                            <Input
                                id="responsiva-lugar"
                                v-model="form.lugar"
                                placeholder="Cuernavaca, Morelos"
                                maxlength="160"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="responsiva-fecha"
                                >Fecha de entrega</Label
                            >
                            <DatePicker
                                id="responsiva-fecha"
                                v-model="form.fecha_entrega"
                                :max-value="hoy"
                            />
                        </div>
                    </div>

                    <div class="flex flex-col gap-3">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold">
                                Equipo que se entrega
                            </p>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="form.activos.length >= 20"
                                @click="agregarActivo"
                            >
                                <Plus class="size-3.5" />
                                Agregar otro equipo
                            </Button>
                        </div>

                        <div
                            v-for="(activo, i) in form.activos"
                            :key="i"
                            class="rounded-2xl border border-border/60 bg-card p-4"
                        >
                            <div class="mb-3 flex items-center justify-between">
                                <span
                                    class="rounded-full bg-salvia/60 px-2.5 py-0.5 text-xs font-semibold"
                                >
                                    Equipo {{ i + 1 }}
                                </span>
                                <Button
                                    v-if="form.activos.length > 1"
                                    type="button"
                                    size="icon-sm"
                                    variant="ghost"
                                    class="text-destructive"
                                    :aria-label="`Quitar equipo ${i + 1}`"
                                    @click="quitarActivo(i)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-6">
                                <div class="grid gap-1.5 sm:col-span-4">
                                    <Label :for="`activo-${i}-descripcion`"
                                        >Descripción *</Label
                                    >
                                    <Input
                                        :id="`activo-${i}-descripcion`"
                                        v-model="activo.descripcion"
                                        placeholder="Casco, laptop, celular, uniforme…"
                                        maxlength="160"
                                    />
                                </div>
                                <div class="grid gap-1.5 sm:col-span-2">
                                    <Label :for="`activo-${i}-cantidad`"
                                        >Cantidad</Label
                                    >
                                    <Input
                                        :id="`activo-${i}-cantidad`"
                                        v-model.number="activo.cantidad"
                                        type="number"
                                        min="1"
                                        max="999"
                                    />
                                </div>
                                <div class="grid gap-1.5 sm:col-span-2">
                                    <Label :for="`activo-${i}-marca`"
                                        >Marca</Label
                                    >
                                    <Input
                                        :id="`activo-${i}-marca`"
                                        v-model="activo.marca"
                                        maxlength="120"
                                    />
                                </div>
                                <div class="grid gap-1.5 sm:col-span-2">
                                    <Label :for="`activo-${i}-modelo`"
                                        >Modelo</Label
                                    >
                                    <Input
                                        :id="`activo-${i}-modelo`"
                                        v-model="activo.modelo"
                                        maxlength="120"
                                    />
                                </div>
                                <div class="grid gap-1.5 sm:col-span-2">
                                    <Label :for="`activo-${i}-serie`"
                                        >Número de serie</Label
                                    >
                                    <Input
                                        :id="`activo-${i}-serie`"
                                        v-model="activo.numero_serie"
                                        maxlength="120"
                                    />
                                </div>
                                <div class="grid gap-1.5 sm:col-span-2">
                                    <Label :for="`activo-${i}-color`"
                                        >Color</Label
                                    >
                                    <Input
                                        :id="`activo-${i}-color`"
                                        v-model="activo.color"
                                        maxlength="60"
                                    />
                                </div>
                                <div class="grid gap-1.5 sm:col-span-4">
                                    <Label :for="`activo-${i}-estado`"
                                        >Estado en que se entrega</Label
                                    >
                                    <Select v-model="activo.estado">
                                        <SelectTrigger
                                            :id="`activo-${i}-estado`"
                                            class="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="condicion in condiciones"
                                                :key="condicion.value"
                                                :value="condicion.value"
                                            >
                                                {{ condicion.label }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div class="grid gap-1.5 sm:col-span-3">
                                    <Label :for="`activo-${i}-caracteristicas`"
                                        >Características (una por
                                        renglón)</Label
                                    >
                                    <Textarea
                                        :id="`activo-${i}-caracteristicas`"
                                        v-model="activo.caracteristicas"
                                        rows="3"
                                        maxlength="2000"
                                        placeholder="Procesador: Intel Core i5&#10;Memoria RAM: 16 GB&#10;Talla: M"
                                    />
                                </div>
                                <div class="grid gap-1.5 sm:col-span-3">
                                    <Label :for="`activo-${i}-accesorios`"
                                        >Accesorios</Label
                                    >
                                    <Textarea
                                        :id="`activo-${i}-accesorios`"
                                        v-model="activo.accesorios"
                                        rows="3"
                                        maxlength="255"
                                        placeholder="Cargador original, funda…"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="responsiva-observaciones"
                            >Observaciones de la entrega (opcional)</Label
                        >
                        <Textarea
                            id="responsiva-observaciones"
                            v-model="form.observaciones"
                            rows="2"
                            maxlength="2000"
                            placeholder="Detalles visibles, condiciones especiales…"
                        />
                    </div>
                </div>
            </div>

            <DialogFooter
                v-if="!cargando && !error && !generada"
                class="gap-2 border-t border-border/60 px-6 py-4"
            >
                <Button
                    v-if="vistaPreviaUrl"
                    variant="outline"
                    @click="limpiarVistaPrevia"
                >
                    <ArrowLeft class="size-4" />
                    Volver a editar
                </Button>
                <template v-else>
                    <Button variant="ghost" @click="abierto = false">
                        Cancelar
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="!completo || previsualizando"
                        @click="verVistaPrevia"
                    >
                        <Loader2
                            v-if="previsualizando"
                            class="size-4 animate-spin"
                        />
                        <Eye v-else class="size-4" />
                        Vista previa
                    </Button>
                </template>
                <Button :disabled="!completo || enviando" @click="generar">
                    <Loader2 v-if="enviando" class="size-4 animate-spin" />
                    <FileSignature v-else class="size-4" />
                    Generar responsiva
                </Button>
            </DialogFooter>
            <DialogFooter
                v-else-if="generada"
                class="border-t border-border/60 px-6 py-4"
            >
                <Button variant="ghost" @click="abierto = false">Cerrar</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
