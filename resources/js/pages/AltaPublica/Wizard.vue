<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CheckCircle2, ChevronLeft, ChevronRight, Upload } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import * as rutas from '@/routes/alta-publica';
import type { AltaDigitalItem, DocumentoRequeridoAlta } from '@/types';

const props = defineProps<{
    alta: AltaDigitalItem;
    documentosRequeridos: DocumentoRequeridoAlta[];
    soloLectura: boolean;
}>();

const yaEnviado = computed(
    () => props.soloLectura && props.alta.estado !== 'requiere_correccion',
);

const paso = ref(1);
const TOTAL_PASOS = 5;

const formDatos = useForm({
    nombre: props.alta.nombre ?? '',
    apellidos: props.alta.apellidos ?? '',
    telefono: props.alta.telefono ?? '',
    correo: props.alta.correo ?? '',
    fecha_nacimiento: props.alta.fecha_nacimiento ?? '',
    curp: props.alta.curp ?? '',
    rfc: props.alta.rfc ?? '',
    nss: props.alta.nss ?? '',
    domicilio: props.alta.domicilio ?? '',
    contacto_emergencia_nombre: props.alta.contacto_emergencia_nombre ?? '',
    contacto_emergencia_telefono: props.alta.contacto_emergencia_telefono ?? '',
});

function guardarDatosPersonales() {
    formDatos.put(rutas.datosPersonales.url(props.alta.token), {
        preserveScroll: true,
        onSuccess: () => paso.value++,
    });
}

const formFoto = useForm({ foto: null as File | null });

function subirFoto(event: Event) {
    const input = event.target as HTMLInputElement;
    formFoto.foto = input.files?.[0] ?? null;

    if (!formFoto.foto) {
        return;
    }

    formFoto.post(rutas.foto.url(props.alta.token), {
        preserveScroll: true,
        forceFormData: true,
    });
}

const documentosSubidos = ref(
    new Set(props.alta.documentos.map((d) => d.document_type_id)),
);
const formDocumento = useForm({
    document_type_id: 0,
    archivo: null as File | null,
});

function subirDocumento(event: Event, tipoId: number) {
    const input = event.target as HTMLInputElement;
    const archivo = input.files?.[0] ?? null;

    if (!archivo) {
        return;
    }

    formDocumento.document_type_id = tipoId;
    formDocumento.archivo = archivo;
    formDocumento.post(rutas.documentos.url(props.alta.token), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => documentosSubidos.value.add(tipoId),
    });
}

const canvasRef = ref<HTMLCanvasElement | null>(null);
let dibujando = false;

function posicion(evento: MouseEvent | TouchEvent, canvas: HTMLCanvasElement) {
    const rect = canvas.getBoundingClientRect();
    const punto = 'touches' in evento ? evento.touches[0] : evento;

    return { x: punto.clientX - rect.left, y: punto.clientY - rect.top };
}

function iniciarTrazo(evento: MouseEvent | TouchEvent) {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    dibujando = true;
    const contexto = canvas.getContext('2d');
    const { x, y } = posicion(evento, canvas);
    contexto?.beginPath();
    contexto?.moveTo(x, y);
}

function trazar(evento: MouseEvent | TouchEvent) {
    if (!dibujando) {
        return;
    }

    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const contexto = canvas.getContext('2d');
    const { x, y } = posicion(evento, canvas);
    contexto?.lineTo(x, y);
    contexto?.stroke();
}

function terminarTrazo() {
    dibujando = false;
}

function limpiarFirma() {
    const canvas = canvasRef.value;
    const contexto = canvas?.getContext('2d');

    if (canvas && contexto) {
        contexto.clearRect(0, 0, canvas.width, canvas.height);
    }
}

onMounted(() => {
    const contexto = canvasRef.value?.getContext('2d');

    if (contexto) {
        contexto.lineWidth = 2;
        contexto.strokeStyle = '#111827';
    }
});

const formConsentimientos = useForm({
    aviso_privacidad_aceptado: false,
    consentimiento_datos_aceptado: false,
    firma: '',
});

function guardarConsentimientos() {
    const canvas = canvasRef.value;
    formConsentimientos.firma = canvas?.toDataURL('image/png') ?? '';

    formConsentimientos.put(rutas.consentimientos.url(props.alta.token), {
        preserveScroll: true,
        onSuccess: () => paso.value++,
    });
}

const formEnviar = useForm({});
function enviarAlta() {
    formEnviar.post(rutas.enviar.url(props.alta.token));
}
</script>

<template>
    <Head title="Alta digital" />

    <div class="mx-auto flex min-h-screen max-w-2xl flex-col gap-6 p-6">
        <div class="text-center">
            <h1 class="text-xl font-semibold">MR. LANA PEOPLE</h1>
            <p class="text-sm text-muted-foreground">
                Alta digital de colaborador
            </p>
        </div>

        <div
            v-if="yaEnviado"
            class="flex flex-col items-center gap-3 rounded-2xl border p-8 text-center"
        >
            <CheckCircle2 class="size-10 text-[var(--success)]" />
            <h2 class="text-lg font-semibold">Tu información ya fue enviada</h2>
            <p class="text-sm text-muted-foreground">
                Recursos Humanos está revisando tus datos. Te contactaremos si
                necesitamos algo más.
            </p>
        </div>

        <template v-else>
            <div
                class="flex items-center justify-between text-xs text-muted-foreground"
            >
                <span
                    v-for="n in TOTAL_PASOS"
                    :key="n"
                    :class="{ 'font-semibold text-foreground': paso === n }"
                >
                    Paso {{ n }}
                </span>
            </div>

            <div
                v-if="paso === 1"
                class="flex flex-col gap-4 rounded-2xl border p-6"
            >
                <h2 class="font-semibold">1. Datos personales</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="nombre">Nombre</Label>
                        <Input id="nombre" v-model="formDatos.nombre" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="apellidos">Apellidos</Label>
                        <Input id="apellidos" v-model="formDatos.apellidos" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="telefono">Teléfono</Label>
                        <Input id="telefono" v-model="formDatos.telefono" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="correo">Correo</Label>
                        <Input
                            id="correo"
                            v-model="formDatos.correo"
                            type="email"
                        />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="fecha_nacimiento"
                            >Fecha de nacimiento</Label
                        >
                        <Input
                            id="fecha_nacimiento"
                            v-model="formDatos.fecha_nacimiento"
                            type="date"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="curp">CURP</Label>
                        <Input id="curp" v-model="formDatos.curp" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="rfc">RFC</Label>
                        <Input id="rfc" v-model="formDatos.rfc" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="nss">NSS</Label>
                        <Input id="nss" v-model="formDatos.nss" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="domicilio">Domicilio</Label>
                    <Input id="domicilio" v-model="formDatos.domicilio" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="contacto_nombre"
                            >Contacto de emergencia</Label
                        >
                        <Input
                            id="contacto_nombre"
                            v-model="formDatos.contacto_emergencia_nombre"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="contacto_telefono"
                            >Teléfono de emergencia</Label
                        >
                        <Input
                            id="contacto_telefono"
                            v-model="formDatos.contacto_emergencia_telefono"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Fotografía</Label>
                    <label
                        class="inline-flex w-fit cursor-pointer items-center gap-1.5 rounded-md border px-3 py-2 text-sm"
                    >
                        <Upload class="size-4" />
                        {{ alta.tiene_foto ? 'Reemplazar foto' : 'Subir foto' }}
                        <input
                            type="file"
                            accept="image/*"
                            class="hidden"
                            @change="subirFoto"
                        />
                    </label>
                </div>

                <Button
                    :disabled="formDatos.processing"
                    @click="guardarDatosPersonales"
                >
                    Guardar y continuar
                    <ChevronRight class="size-4" />
                </Button>
            </div>

            <div
                v-else-if="paso === 2"
                class="flex flex-col gap-4 rounded-2xl border p-6"
            >
                <h2 class="font-semibold">2. Datos laborales (precargados)</h2>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-muted-foreground">Empresa</dt>
                        <dd>{{ alta.empresa?.nombre ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Sucursal</dt>
                        <dd>{{ alta.sucursal?.nombre ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Departamento</dt>
                        <dd>{{ alta.departamento?.nombre ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Puesto</dt>
                        <dd>{{ alta.puesto?.nombre ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            Fecha de ingreso propuesta
                        </dt>
                        <dd>{{ alta.fecha_ingreso_propuesta ?? '—' }}</dd>
                    </div>
                </dl>
                <p class="text-xs text-muted-foreground">
                    Estos datos los define Recursos Humanos y no se pueden
                    editar aquí.
                </p>
                <div class="flex justify-between">
                    <Button variant="secondary" @click="paso--"
                        ><ChevronLeft class="size-4" />Atrás</Button
                    >
                    <Button @click="paso++"
                        >Continuar<ChevronRight class="size-4"
                    /></Button>
                </div>
            </div>

            <div
                v-else-if="paso === 3"
                class="flex flex-col gap-4 rounded-2xl border p-6"
            >
                <h2 class="font-semibold">3. Documentos requeridos</h2>
                <div
                    v-for="tipo in documentosRequeridos"
                    :key="tipo.id"
                    class="flex items-center justify-between rounded-lg border p-3 text-sm"
                >
                    <span>{{ tipo.nombre }}</span>
                    <span
                        v-if="documentosSubidos.has(tipo.id)"
                        class="inline-flex items-center gap-1 text-[var(--success)]"
                    >
                        <CheckCircle2 class="size-4" /> Cargado
                    </span>
                    <label
                        v-else
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5"
                    >
                        <Upload class="size-4" />
                        Subir
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            class="hidden"
                            @change="(e) => subirDocumento(e, tipo.id)"
                        />
                    </label>
                </div>
                <p
                    v-if="!documentosRequeridos.length"
                    class="text-sm text-muted-foreground"
                >
                    No hay documentos configurados para el alta.
                </p>
                <div class="flex justify-between">
                    <Button variant="secondary" @click="paso--"
                        ><ChevronLeft class="size-4" />Atrás</Button
                    >
                    <Button @click="paso++"
                        >Continuar<ChevronRight class="size-4"
                    /></Button>
                </div>
            </div>

            <div
                v-else-if="paso === 4"
                class="flex flex-col gap-4 rounded-2xl border p-6"
            >
                <h2 class="font-semibold">
                    4. Aviso de privacidad y consentimiento
                </h2>
                <label class="flex items-start gap-2 text-sm">
                    <Checkbox
                        :model-value="
                            formConsentimientos.aviso_privacidad_aceptado
                        "
                        @update:model-value="
                            (v) =>
                                (formConsentimientos.aviso_privacidad_aceptado =
                                    !!v)
                        "
                    />
                    He leído y acepto el aviso de privacidad.
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <Checkbox
                        :model-value="
                            formConsentimientos.consentimiento_datos_aceptado
                        "
                        @update:model-value="
                            (v) =>
                                (formConsentimientos.consentimiento_datos_aceptado =
                                    !!v)
                        "
                    />
                    Doy mi consentimiento para el tratamiento de mis datos
                    personales.
                </label>

                <div class="grid gap-2">
                    <Label>Firma simple</Label>
                    <canvas
                        ref="canvasRef"
                        width="500"
                        height="160"
                        class="w-full touch-none rounded-lg border bg-white"
                        @mousedown="iniciarTrazo"
                        @mousemove="trazar"
                        @mouseup="terminarTrazo"
                        @mouseleave="terminarTrazo"
                        @touchstart.prevent="iniciarTrazo"
                        @touchmove.prevent="trazar"
                        @touchend.prevent="terminarTrazo"
                    />
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="w-fit"
                        @click="limpiarFirma"
                        >Limpiar firma</Button
                    >
                </div>

                <div class="flex justify-between">
                    <Button variant="secondary" @click="paso--"
                        ><ChevronLeft class="size-4" />Atrás</Button
                    >
                    <Button
                        :disabled="formConsentimientos.processing"
                        @click="guardarConsentimientos"
                        >Guardar y continuar<ChevronRight class="size-4"
                    /></Button>
                </div>
            </div>

            <div v-else class="flex flex-col gap-4 rounded-2xl border p-6">
                <h2 class="font-semibold">5. Revisión y envío</h2>
                <p class="text-sm text-muted-foreground">
                    Verifica que tu información esté completa antes de enviarla
                    a Recursos Humanos. Una vez enviada, RH la revisará y te
                    contactará.
                </p>
                <div class="flex justify-between">
                    <Button variant="secondary" @click="paso--"
                        ><ChevronLeft class="size-4" />Atrás</Button
                    >
                    <Button
                        :disabled="formEnviar.processing"
                        @click="enviarAlta"
                        >Enviar a Recursos Humanos</Button
                    >
                </div>
            </div>
        </template>
    </div>
</template>
