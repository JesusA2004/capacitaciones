<script setup lang="ts">
import { ArrowLeft, Eye, FileSignature, Loader2, Printer } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DatePicker from '@/components/Common/DatePicker.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    CatalogosResponsiva,
    DatosResponsivaForm,
    OpcionResponsiva,
    ResponsivaForm,
    ResponsivaItem,
    ResponsivasColaboradorRespuesta,
    ValoresInicialesResponsiva,
} from '@/types';

/**
 * «Generar responsiva»: el gerente o RH elige la familia (bien mueble, equipo
 * electrónico o vehículo) y captura los campos de ese formato oficial; PEOPLE
 * llena el resto con los datos del colaborador, la empresa y quien entrega.
 * Vista previa → generar → abrir el PDF para imprimir y firmar. La
 * autorización real está en el backend.
 */
const props = defineProps<{ colaboradorId: number }>();

const abierto = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ generada: [responsiva: ResponsivaItem] }>();

const cargando = ref(false);
const enviando = ref(false);
const previsualizando = ref(false);
const vistaPreviaUrl = ref<string | null>(null);
const generada = ref<ResponsivaItem | null>(null);
const valores = ref<ValoresInicialesResponsiva | null>(null);
const tipos = ref<OpcionResponsiva[]>([]);
const catalogos = ref<CatalogosResponsiva | null>(null);
const error = ref<string | null>(null);

const hoy = new Date().toLocaleDateString('en-CA');

function datosVacios(): DatosResponsivaForm {
    return {
        tipo_mueble: '',
        tipo_mueble_otro: '',
        numero_inventario: '',
        material: '',
        tamano: '',
        ubicacion_lugar: '',
        ubicacion_area: '',
        tipo_equipo: '',
        tipo_equipo_otro: '',
        imei1: '',
        imei2: '',
        numero_telefonico: '',
        submarca: '',
        modelo_anio: '',
        niv: '',
        numero_motor: '',
        placas: '',
        kilometraje: '',
        combustible: 'lleno',
        estado_exterior: 'bueno',
        estado_interior: 'bueno',
        marca: '',
        modelo: '',
        numero_serie: '',
        color: '',
        estado: 'buen_estado',
        danos: '',
        accesorios: [],
        accesorios_otro: '',
    };
}

const form = ref<ResponsivaForm>({
    tipo: 'bien_mueble',
    lugar: '',
    fecha_entrega: hoy,
    responsable_entrega_id: 0,
    observaciones: '',
    datos: datosVacios(),
});

// Al cambiar de familia se reinician los campos (los accesorios, por ejemplo,
// pertenecen a catálogos distintos entre equipo y vehículo).
watch(
    () => form.value.tipo,
    () => {
        form.value.datos = datosVacios();
    },
);

const esMueble = computed(() => form.value.tipo === 'bien_mueble');
const esEquipo = computed(() => form.value.tipo === 'equipo_electronico');
const esVehiculo = computed(() => form.value.tipo === 'vehiculo');
const esCelular = computed(() =>
    ['celular', 'tablet'].includes(form.value.datos.tipo_equipo),
);
const accesoriosCatalogo = computed<OpcionResponsiva[]>(() =>
    esEquipo.value
        ? (catalogos.value?.accesorios_equipo ?? [])
        : (catalogos.value?.accesorios_vehiculo ?? []),
);
const muestraAccesorioOtro = computed(() =>
    form.value.datos.accesorios.includes('otro'),
);

const completo = computed(() => {
    const d = form.value.datos;
    const base =
        form.value.lugar.trim() !== '' && form.value.fecha_entrega !== '';

    if (!base) {
        return false;
    }

    if (esMueble.value) {
        return (
            d.tipo_mueble !== '' &&
            (d.tipo_mueble !== 'otro' || d.tipo_mueble_otro.trim() !== '') &&
            d.numero_inventario.trim() !== '' &&
            d.estado !== '' &&
            d.ubicacion_lugar.trim() !== ''
        );
    }

    if (esEquipo.value) {
        return (
            d.tipo_equipo !== '' &&
            (d.tipo_equipo !== 'otro' || d.tipo_equipo_otro.trim() !== '') &&
            d.marca.trim() !== '' &&
            d.estado !== ''
        );
    }

    return (
        d.marca.trim() !== '' &&
        d.placas.trim() !== '' &&
        d.combustible !== '' &&
        d.estado_exterior !== '' &&
        d.estado_interior !== ''
    );
});

const responsableSeleccionado = computed(
    () =>
        valores.value?.responsables.find(
            (r) => r.id === form.value.responsable_entrega_id,
        ) ?? null,
);

const responsableIdStr = computed<string>({
    get: () => String(form.value.responsable_entrega_id),
    set: (valor) => {
        form.value.responsable_entrega_id = Number(valor);
    },
});

function toggleAccesorio(value: string, marcado: boolean) {
    const set = new Set(form.value.datos.accesorios);

    if (marcado) {
        set.add(value);
    } else {
        set.delete(value);
    }

    form.value.datos.accesorios = [...set];
}

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
        catalogos.value = datos.catalogos;
        form.value = {
            tipo: 'bien_mueble',
            lugar: datos.valores_iniciales.lugar,
            fecha_entrega: datos.valores_iniciales.fecha_entrega,
            responsable_entrega_id:
                datos.valores_iniciales.responsable_entrega_id,
            observaciones: '',
            datos: datosVacios(),
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
                    Generar responsiva
                </DialogTitle>
                <DialogDescription>
                    Elige el formato y captura los datos del bien; la responsiva
                    se llena con la información del colaborador, se imprime y se
                    firma.
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
                            las firmas de quien recibe y del representante
                            legal de la empresa, y
                            márcala como firmada físicamente.
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
                                Responsable de la entrega
                            </p>
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
                                <p class="mt-1 text-xs text-muted-foreground">
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
                                        valores.otorgante.puesto ?? 'Sin puesto'
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

                    <!-- Familia, lugar y fecha -->
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-1.5">
                            <Label for="responsiva-tipo"
                                >Tipo de responsiva</Label
                            >
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

                    <!-- ============ BIEN MUEBLE ============ -->
                    <div v-if="esMueble" class="flex flex-col gap-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="mueble-tipo"
                                    >Tipo de mueble *</Label
                                >
                                <Select v-model="form.datos.tipo_mueble">
                                    <SelectTrigger
                                        id="mueble-tipo"
                                        class="w-full"
                                    >
                                        <SelectValue placeholder="Elige…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="o in catalogos?.tipos_mueble ??
                                            []"
                                            :key="o.value"
                                            :value="o.value"
                                        >
                                            {{ o.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div
                                v-if="form.datos.tipo_mueble === 'otro'"
                                class="grid gap-1.5"
                            >
                                <Label for="mueble-otro">¿Qué mueble? *</Label>
                                <Input
                                    id="mueble-otro"
                                    v-model="form.datos.tipo_mueble_otro"
                                    maxlength="160"
                                />
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="mueble-marca"
                                    >Marca (si tiene)</Label
                                >
                                <Input
                                    id="mueble-marca"
                                    v-model="form.datos.marca"
                                    maxlength="120"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="mueble-modelo"
                                    >Modelo (si tiene)</Label
                                >
                                <Input
                                    id="mueble-modelo"
                                    v-model="form.datos.modelo"
                                    maxlength="120"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="mueble-inv"
                                    >Número de inventario *</Label
                                >
                                <Input
                                    id="mueble-inv"
                                    v-model="form.datos.numero_inventario"
                                    maxlength="120"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="mueble-serie"
                                    >Número de serie (si tiene)</Label
                                >
                                <Input
                                    id="mueble-serie"
                                    v-model="form.datos.numero_serie"
                                    maxlength="120"
                                />
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div class="grid gap-1.5">
                                <Label for="mueble-color">Color</Label>
                                <Input
                                    id="mueble-color"
                                    v-model="form.datos.color"
                                    maxlength="60"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="mueble-material">Material</Label>
                                <Input
                                    id="mueble-material"
                                    v-model="form.datos.material"
                                    maxlength="120"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="mueble-tamano"
                                    >Tamaño / características</Label
                                >
                                <Input
                                    id="mueble-tamano"
                                    v-model="form.datos.tamano"
                                    maxlength="255"
                                />
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="mueble-estado"
                                    >Estado del mueble *</Label
                                >
                                <Select v-model="form.datos.estado">
                                    <SelectTrigger
                                        id="mueble-estado"
                                        class="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="o in catalogos?.estados_fisicos ??
                                            []"
                                            :key="o.value"
                                            :value="o.value"
                                        >
                                            {{ o.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="mueble-danos"
                                    >Daños o detalles</Label
                                >
                                <Input
                                    id="mueble-danos"
                                    v-model="form.datos.danos"
                                    maxlength="2000"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="mueble-lugar"
                                    >Lugar donde quedará *</Label
                                >
                                <Input
                                    id="mueble-lugar"
                                    v-model="form.datos.ubicacion_lugar"
                                    maxlength="255"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="mueble-area"
                                    >Sucursal / Oficina / Área</Label
                                >
                                <Input
                                    id="mueble-area"
                                    v-model="form.datos.ubicacion_area"
                                    maxlength="255"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- ============ EQUIPO ELECTRÓNICO ============ -->
                    <div v-else-if="esEquipo" class="flex flex-col gap-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="eq-tipo">Tipo de equipo *</Label>
                                <Select v-model="form.datos.tipo_equipo">
                                    <SelectTrigger id="eq-tipo" class="w-full">
                                        <SelectValue placeholder="Elige…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="o in catalogos?.tipos_equipo ??
                                            []"
                                            :key="o.value"
                                            :value="o.value"
                                        >
                                            {{ o.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div
                                v-if="form.datos.tipo_equipo === 'otro'"
                                class="grid gap-1.5"
                            >
                                <Label for="eq-otro">¿Qué equipo? *</Label>
                                <Input
                                    id="eq-otro"
                                    v-model="form.datos.tipo_equipo_otro"
                                    maxlength="160"
                                />
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="eq-marca">Marca *</Label>
                                <Input
                                    id="eq-marca"
                                    v-model="form.datos.marca"
                                    maxlength="120"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="eq-modelo">Modelo</Label>
                                <Input
                                    id="eq-modelo"
                                    v-model="form.datos.modelo"
                                    maxlength="120"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="eq-serie">Número de serie</Label>
                                <Input
                                    id="eq-serie"
                                    v-model="form.datos.numero_serie"
                                    maxlength="120"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="eq-inv">Número de inventario</Label>
                                <Input
                                    id="eq-inv"
                                    v-model="form.datos.numero_inventario"
                                    maxlength="120"
                                />
                            </div>
                        </div>
                        <div v-if="esCelular" class="grid gap-4 sm:grid-cols-3">
                            <div class="grid gap-1.5">
                                <Label for="eq-imei1">IMEI 1</Label>
                                <Input
                                    id="eq-imei1"
                                    v-model="form.datos.imei1"
                                    maxlength="60"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="eq-imei2">IMEI 2</Label>
                                <Input
                                    id="eq-imei2"
                                    v-model="form.datos.imei2"
                                    maxlength="60"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="eq-tel">Número telefónico</Label>
                                <Input
                                    id="eq-tel"
                                    v-model="form.datos.numero_telefonico"
                                    maxlength="40"
                                />
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="eq-estado"
                                    >Estado del equipo *</Label
                                >
                                <Select v-model="form.datos.estado">
                                    <SelectTrigger
                                        id="eq-estado"
                                        class="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="o in catalogos?.estados_fisicos ??
                                            []"
                                            :key="o.value"
                                            :value="o.value"
                                        >
                                            {{ o.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="eq-danos">Daños o detalles</Label>
                                <Input
                                    id="eq-danos"
                                    v-model="form.datos.danos"
                                    maxlength="2000"
                                />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label>Accesorios entregados</Label>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <label
                                    v-for="o in accesoriosCatalogo"
                                    :key="o.value"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        :model-value="
                                            form.datos.accesorios.includes(
                                                o.value,
                                            )
                                        "
                                        @update:model-value="
                                            (v: boolean | 'indeterminate') =>
                                                toggleAccesorio(
                                                    o.value,
                                                    v === true,
                                                )
                                        "
                                    />
                                    {{ o.label }}
                                </label>
                            </div>
                            <Input
                                v-if="muestraAccesorioOtro"
                                v-model="form.datos.accesorios_otro"
                                placeholder="¿Qué otro accesorio?"
                                maxlength="160"
                            />
                        </div>
                    </div>

                    <!-- ============ VEHÍCULO ============ -->
                    <div v-else-if="esVehiculo" class="flex flex-col gap-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="veh-marca">Marca *</Label>
                                <Input
                                    id="veh-marca"
                                    v-model="form.datos.marca"
                                    maxlength="120"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="veh-submarca"
                                    >Submarca / Tipo</Label
                                >
                                <Input
                                    id="veh-submarca"
                                    v-model="form.datos.submarca"
                                    maxlength="120"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="veh-modelo">Modelo / Año</Label>
                                <Input
                                    id="veh-modelo"
                                    v-model="form.datos.modelo_anio"
                                    maxlength="60"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="veh-color">Color</Label>
                                <Input
                                    id="veh-color"
                                    v-model="form.datos.color"
                                    maxlength="60"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="veh-niv">NIV / VIN</Label>
                                <Input
                                    id="veh-niv"
                                    v-model="form.datos.niv"
                                    maxlength="40"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="veh-motor">Número de motor</Label>
                                <Input
                                    id="veh-motor"
                                    v-model="form.datos.numero_motor"
                                    maxlength="60"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="veh-placas">Placas *</Label>
                                <Input
                                    id="veh-placas"
                                    v-model="form.datos.placas"
                                    maxlength="20"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="veh-km">Kilometraje</Label>
                                <Input
                                    id="veh-km"
                                    v-model="form.datos.kilometraje"
                                    type="number"
                                    min="0"
                                    inputmode="numeric"
                                />
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div class="grid gap-1.5">
                                <Label for="veh-comb">Combustible *</Label>
                                <Select v-model="form.datos.combustible">
                                    <SelectTrigger id="veh-comb" class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="o in catalogos?.niveles_combustible ??
                                            []"
                                            :key="o.value"
                                            :value="o.value"
                                        >
                                            {{ o.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="veh-ext">Estado exterior *</Label>
                                <Select v-model="form.datos.estado_exterior">
                                    <SelectTrigger id="veh-ext" class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="o in catalogos?.estados_conservacion ??
                                            []"
                                            :key="o.value"
                                            :value="o.value"
                                        >
                                            {{ o.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="veh-int">Estado interior *</Label>
                                <Select v-model="form.datos.estado_interior">
                                    <SelectTrigger id="veh-int" class="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="o in catalogos?.estados_conservacion ??
                                            []"
                                            :key="o.value"
                                            :value="o.value"
                                        >
                                            {{ o.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="veh-danos"
                                >Daños o detalles existentes</Label
                            >
                            <Textarea
                                id="veh-danos"
                                v-model="form.datos.danos"
                                rows="2"
                                maxlength="2000"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>Accesorios y documentos recibidos</Label>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <label
                                    v-for="o in accesoriosCatalogo"
                                    :key="o.value"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        :model-value="
                                            form.datos.accesorios.includes(
                                                o.value,
                                            )
                                        "
                                        @update:model-value="
                                            (v: boolean | 'indeterminate') =>
                                                toggleAccesorio(
                                                    o.value,
                                                    v === true,
                                                )
                                        "
                                    />
                                    {{ o.label }}
                                </label>
                            </div>
                            <Input
                                v-if="muestraAccesorioOtro"
                                v-model="form.datos.accesorios_otro"
                                placeholder="¿Qué otro accesorio o documento?"
                                maxlength="160"
                            />
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
