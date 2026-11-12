<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    BriefcaseBusiness,
    Camera,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    FileText,
    PenLine,
    Send,
    ShieldCheck,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import CamposDomicilio from '@/components/Common/CamposDomicilio.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatearFecha } from '@/lib/fechas';
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

const PASOS: { titulo: string; corto: string; icono: Component }[] = [
    { titulo: 'Datos personales', corto: 'Datos', icono: UserRound },
    { titulo: 'Datos laborales', corto: 'Puesto', icono: BriefcaseBusiness },
    { titulo: 'Documentos requeridos', corto: 'Documentos', icono: FileText },
    {
        titulo: 'Aviso de privacidad y consentimiento',
        corto: 'Firma',
        icono: ShieldCheck,
    },
    { titulo: 'Revisión y envío', corto: 'Envío', icono: Send },
];
const TOTAL_PASOS = PASOS.length;

/** Formatos y peso que acepta el servidor (config/altas.php). */
const FORMATOS_FOTO = '.jpg,.jpeg,.png';
const FORMATOS_DOCUMENTO = '.pdf,.jpg,.jpeg,.png';
const PESO_MAXIMO_MB = 15;

const paso = ref(1);
const pasoActual = computed(() => PASOS[paso.value - 1]);
const avance = computed(() =>
    Math.round(((paso.value - 1) / (TOTAL_PASOS - 1)) * 100),
);

const formDatos = useForm({
    nombre: props.alta.nombre ?? '',
    apellidos: props.alta.apellidos ?? '',
    telefono: props.alta.telefono ?? '',
    correo: props.alta.correo ?? '',
    fecha_nacimiento: props.alta.fecha_nacimiento ?? '',
    curp: props.alta.curp ?? '',
    rfc: props.alta.rfc ?? '',
    nss: props.alta.nss ?? '',
    // Domicilio estructurado: el texto completo lo compone el backend.
    domicilio_calle: props.alta.domicilio_calle ?? '',
    domicilio_numero_exterior: props.alta.domicilio_numero_exterior ?? '',
    domicilio_numero_interior: props.alta.domicilio_numero_interior ?? '',
    domicilio_colonia: props.alta.domicilio_colonia ?? '',
    domicilio_cp: props.alta.domicilio_cp ?? '',
    domicilio_municipio: props.alta.domicilio_municipio ?? '',
    domicilio_estado: props.alta.domicilio_estado ?? '',
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

function subirFoto(archivos: File[]) {
    formFoto.foto = archivos[0] ?? null;

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
    archivo_reverso: null as File | null,
});
/** Documento que se está enviando: para pintar ahí el avance y el error. */
const documentoEnCurso = ref<number | null>(null);

function enviarDocumento(
    tipoId: number,
    archivo: File,
    reverso: File | null = null,
) {
    documentoEnCurso.value = tipoId;
    formDocumento.document_type_id = tipoId;
    formDocumento.archivo = archivo;
    formDocumento.archivo_reverso = reverso;
    formDocumento.post(rutas.documentos.url(props.alta.token), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            documentosSubidos.value.add(tipoId);
            delete dosCaras.value[tipoId];
        },
    });
}

function subirDocumento(archivos: File[], tipoId: number) {
    const archivo = archivos[0] ?? null;

    if (!archivo || formDocumento.processing) {
        return;
    }

    enviarDocumento(tipoId, archivo);
}

function errorDocumento(tipoId: number): string | null {
    if (documentoEnCurso.value !== tipoId) {
        return null;
    }

    return (
        formDocumento.errors.archivo ??
        formDocumento.errors.archivo_reverso ??
        formDocumento.errors.document_type_id ??
        null
    );
}

/**
 * Identificación oficial: frente (o un PDF con las dos caras) y reverso
 * opcional; se eligen antes de subir y el servidor los une en un PDF.
 */
const dosCaras = ref<
    Record<number, { frente: File | null; reverso: File | null }>
>({});

function archivosCara(tipoId: number, cara: 'frente' | 'reverso'): File[] {
    const archivo = dosCaras.value[tipoId]?.[cara];

    return archivo ? [archivo] : [];
}

function elegirCara(
    archivos: File[],
    tipoId: number,
    cara: 'frente' | 'reverso',
) {
    const actual = dosCaras.value[tipoId] ?? { frente: null, reverso: null };
    dosCaras.value[tipoId] = { ...actual, [cara]: archivos[0] ?? null };
}

function subirDosCaras(tipoId: number) {
    const caras = dosCaras.value[tipoId];

    if (caras?.frente) {
        enviarDocumento(tipoId, caras.frente, caras.reverso);
    }
}

const documentosCargados = computed(
    () =>
        props.documentosRequeridos.filter((tipo) =>
            documentosSubidos.value.has(tipo.id),
        ).length,
);

const canvasRef = ref<HTMLCanvasElement | null>(null);
let dibujando = false;
const firmaTrazada = ref(false);

/** Tinta de la firma: va dentro del PNG que se guarda, no es color de UI. */
const TINTA_FIRMA = 'rgb(17 24 39)';

/**
 * El lienzo mide 500×160 por dentro pero se estira al ancho de la tarjeta:
 * sin esta escala el trazo cae desfasado del dedo en pantallas angostas.
 */
function posicion(evento: MouseEvent | TouchEvent, canvas: HTMLCanvasElement) {
    const rect = canvas.getBoundingClientRect();
    const punto = 'touches' in evento ? evento.touches[0] : evento;

    return {
        x: (punto.clientX - rect.left) * (canvas.width / rect.width),
        y: (punto.clientY - rect.top) * (canvas.height / rect.height),
    };
}

function iniciarTrazo(evento: MouseEvent | TouchEvent) {
    const canvas = canvasRef.value;
    const contexto = canvas?.getContext('2d');

    if (!canvas || !contexto) {
        return;
    }

    dibujando = true;
    contexto.lineWidth = 2.5;
    contexto.lineCap = 'round';
    contexto.lineJoin = 'round';
    contexto.strokeStyle = TINTA_FIRMA;

    const { x, y } = posicion(evento, canvas);
    contexto.beginPath();
    contexto.moveTo(x, y);
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
    firmaTrazada.value = true;
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

    firmaTrazada.value = false;
}

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

function retroceder() {
    // El lienzo se vuelve a montar al regresar al paso 4: llega en blanco.
    firmaTrazada.value = false;
    paso.value--;
}

const formEnviar = useForm({});
// El backend rechaza el envío sin fotografía con el error `foto`.
const errorEnvioFoto = computed(
    () => (formEnviar.errors as Record<string, string | undefined>).foto,
);
function enviarAlta() {
    formEnviar.post(rutas.enviar.url(props.alta.token));
}

const datosLaborales = computed(() => [
    { etiqueta: 'Empresa', valor: props.alta.empresa?.nombre },
    { etiqueta: 'Sucursal', valor: props.alta.sucursal?.nombre },
    { etiqueta: 'Departamento', valor: props.alta.departamento?.nombre },
    { etiqueta: 'Puesto', valor: props.alta.puesto?.nombre },
    {
        etiqueta: 'Fecha de ingreso propuesta',
        valor: props.alta.fecha_ingreso_propuesta
            ? formatearFecha(props.alta.fecha_ingreso_propuesta)
            : null,
    },
]);
</script>

<template>
    <Head title="Alta digital" />

    <div class="pagina-ancha min-h-screen bg-background">
        <header class="mb-5">
            <p
                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                MR. LANA PEOPLE
            </p>
            <h1 class="text-lg font-semibold sm:text-xl">
                Alta digital de colaborador
            </h1>
        </header>

        <section
            v-if="yaEnviado"
            class="mx-auto flex max-w-xl entrada-suave flex-col items-center gap-3 rounded-3xl border border-border/60 bg-success-soft p-8 text-center"
        >
            <CheckCircle2 class="size-12 aparece-zoom text-success" />
            <h2 class="text-xl font-semibold">Tu información ya fue enviada</h2>
            <p class="text-sm text-pretty text-muted-foreground">
                Recursos Humanos está revisando tus datos. Te contactaremos si
                necesitamos algo más.
            </p>
        </section>

        <div v-else class="grid gap-5 lg:grid-cols-[16rem_minmax(0,1fr)]">
            <!-- Riel de pasos -->
            <nav aria-label="Pasos del alta" class="flex flex-col gap-3">
                <div class="rounded-2xl border border-border/60 bg-card p-4">
                    <div
                        class="mb-2 flex items-baseline justify-between text-sm"
                    >
                        <span class="font-medium">
                            Paso {{ paso }} de {{ TOTAL_PASOS }}
                        </span>
                        <span
                            class="font-semibold text-[var(--mrl-petroleo)] tabular-nums"
                            >{{ avance }}%</span
                        >
                    </div>
                    <div
                        class="h-2 overflow-hidden rounded-full bg-crema"
                        role="progressbar"
                        aria-label="Avance del alta"
                        :aria-valuenow="avance"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full origin-left rounded-full bg-[var(--mrl-petroleo)] transition-transform duration-500"
                            :style="{ transform: `scaleX(${avance / 100})` }"
                        />
                    </div>
                </div>
                <ol
                    class="-mx-4 scroll-x-limpio flex gap-2 px-4 lg:mx-0 lg:flex-col lg:px-0"
                >
                    <li
                        v-for="(p, i) in PASOS"
                        :key="p.titulo"
                        class="flex shrink-0 items-center gap-2.5 rounded-2xl border px-3 py-2 text-sm transicion-ui lg:w-full"
                        :class="
                            i + 1 === paso
                                ? 'border-[var(--mrl-petroleo)] bg-salvia font-medium'
                                : 'border-transparent text-muted-foreground'
                        "
                        :aria-current="i + 1 === paso ? 'step' : undefined"
                    >
                        <span
                            class="grid size-7 shrink-0 place-items-center rounded-full text-xs"
                            :class="
                                i + 1 < paso
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-crema text-[var(--mrl-petroleo)]'
                            "
                        >
                            <Check
                                v-if="i + 1 < paso"
                                class="size-4 aparece-zoom"
                            />
                            <template v-else>{{ i + 1 }}</template>
                        </span>
                        <span class="whitespace-nowrap">
                            <span class="lg:hidden">{{ p.corto }}</span>
                            <span class="hidden lg:inline">{{ p.titulo }}</span>
                        </span>
                    </li>
                </ol>
            </nav>

            <main class="min-w-0">
                <CambioSuave>
                    <section
                        :key="paso"
                        class="rounded-3xl border border-border/60 bg-card p-5 sm:p-7"
                    >
                        <div class="mb-5 flex items-start gap-3">
                            <span
                                class="grid size-11 shrink-0 place-items-center rounded-2xl bg-salvia text-[var(--mrl-petroleo)]"
                            >
                                <component
                                    :is="pasoActual.icono"
                                    class="size-5"
                                />
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs text-muted-foreground">
                                    Paso {{ paso }} de {{ TOTAL_PASOS }}
                                </p>
                                <h2 class="text-lg font-semibold sm:text-xl">
                                    {{ pasoActual.titulo }}
                                </h2>
                            </div>
                        </div>

                        <!-- 1. Datos personales -->
                        <div v-if="paso === 1" class="flex flex-col gap-5">
                            <div
                                class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3"
                            >
                                <div class="grid content-start gap-2">
                                    <Label for="nombre">Nombre</Label>
                                    <Input
                                        id="nombre"
                                        v-model="formDatos.nombre"
                                        autocomplete="given-name"
                                    />
                                    <InputError
                                        :message="formDatos.errors.nombre"
                                    />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="apellidos">Apellidos</Label>
                                    <Input
                                        id="apellidos"
                                        v-model="formDatos.apellidos"
                                        autocomplete="family-name"
                                    />
                                    <InputError
                                        :message="formDatos.errors.apellidos"
                                    />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="telefono">Teléfono</Label>
                                    <Input
                                        id="telefono"
                                        v-model="formDatos.telefono"
                                        type="tel"
                                        inputmode="tel"
                                        autocomplete="tel"
                                    />
                                    <InputError
                                        :message="formDatos.errors.telefono"
                                    />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="correo">
                                        Correo
                                        <span
                                            class="font-normal text-muted-foreground"
                                            >(opcional)</span
                                        >
                                    </Label>
                                    <Input
                                        id="correo"
                                        v-model="formDatos.correo"
                                        type="email"
                                        autocomplete="email"
                                    />
                                    <InputError
                                        :message="formDatos.errors.correo"
                                    />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="fecha_nacimiento"
                                        >Fecha de nacimiento</Label
                                    >
                                    <DatePicker
                                        id="fecha_nacimiento"
                                        v-model="formDatos.fecha_nacimiento"
                                        :anio-desde="1940"
                                        :anio-hasta="new Date().getFullYear()"
                                    />
                                    <InputError
                                        :message="
                                            formDatos.errors.fecha_nacimiento
                                        "
                                    />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="curp">CURP</Label>
                                    <Input
                                        id="curp"
                                        v-model="formDatos.curp"
                                        class="uppercase"
                                        maxlength="18"
                                        autocomplete="off"
                                    />
                                    <InputError
                                        :message="formDatos.errors.curp"
                                    />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="rfc">RFC</Label>
                                    <Input
                                        id="rfc"
                                        v-model="formDatos.rfc"
                                        class="uppercase"
                                        maxlength="13"
                                        autocomplete="off"
                                    />
                                    <InputError
                                        :message="formDatos.errors.rfc"
                                    />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="nss">NSS</Label>
                                    <Input
                                        id="nss"
                                        v-model="formDatos.nss"
                                        inputmode="numeric"
                                        maxlength="11"
                                        autocomplete="off"
                                    />
                                    <InputError
                                        :message="formDatos.errors.nss"
                                    />
                                </div>
                            </div>

                            <div class="grid gap-2">
                                <p class="text-sm font-medium">Domicilio</p>
                                <CamposDomicilio
                                    v-model:calle="formDatos.domicilio_calle"
                                    v-model:numero-exterior="
                                        formDatos.domicilio_numero_exterior
                                    "
                                    v-model:numero-interior="
                                        formDatos.domicilio_numero_interior
                                    "
                                    v-model:colonia="
                                        formDatos.domicilio_colonia
                                    "
                                    v-model:cp="formDatos.domicilio_cp"
                                    v-model:municipio="
                                        formDatos.domicilio_municipio
                                    "
                                    v-model:estado="formDatos.domicilio_estado"
                                    :errores="formDatos.errors"
                                    :texto-anterior="alta.domicilio"
                                    requerido
                                />
                            </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <div class="grid content-start gap-2">
                                    <Label for="contacto_nombre"
                                        >Contacto de emergencia</Label
                                    >
                                    <Input
                                        id="contacto_nombre"
                                        v-model="
                                            formDatos.contacto_emergencia_nombre
                                        "
                                    />
                                    <InputError
                                        :message="
                                            formDatos.errors
                                                .contacto_emergencia_nombre
                                        "
                                    />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="contacto_telefono"
                                        >Teléfono de emergencia</Label
                                    >
                                    <Input
                                        id="contacto_telefono"
                                        v-model="
                                            formDatos.contacto_emergencia_telefono
                                        "
                                        type="tel"
                                        inputmode="tel"
                                    />
                                    <InputError
                                        :message="
                                            formDatos.errors
                                                .contacto_emergencia_telefono
                                        "
                                    />
                                </div>
                            </div>

                            <div
                                class="grid gap-3 rounded-2xl border border-border/60 bg-crema/40 p-4"
                            >
                                <div class="flex items-start gap-3">
                                    <span
                                        class="grid size-9 shrink-0 place-items-center rounded-xl bg-card text-[var(--mrl-petroleo)]"
                                    >
                                        <Camera class="size-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium">
                                            Fotografía para expediente
                                            (obligatoria)
                                        </p>
                                        <p
                                            class="text-sm text-pretty text-muted-foreground"
                                        >
                                            Será tu foto de perfil en el
                                            sistema. Usa una foto clara y
                                            formal: de frente, con el rostro
                                            descubierto y fondo claro. Se
                                            recorta en cuadro al centro.
                                        </p>
                                    </div>
                                </div>
                                <p
                                    v-if="alta.tiene_foto"
                                    class="flex aparece-zoom items-center gap-1.5 rounded-xl bg-success-soft px-3 py-2 text-sm"
                                >
                                    <CheckCircle2
                                        class="size-4 shrink-0 text-success"
                                    />
                                    Fotografía para expediente cargada.
                                </p>
                                <PeopleFileDropzone
                                    :model-value="[]"
                                    :accept="FORMATOS_FOTO"
                                    :max-size-mb="PESO_MAXIMO_MB"
                                    :loading="formFoto.processing"
                                    :progreso="
                                        formFoto.progress?.percentage ?? null
                                    "
                                    :error="formFoto.errors.foto"
                                    :label="
                                        formFoto.processing
                                            ? 'Subiendo tu fotografía…'
                                            : alta.tiene_foto
                                              ? 'Arrastra aquí otra fotografía para reemplazarla'
                                              : 'Arrastra aquí tu fotografía'
                                    "
                                    @update:model-value="subirFoto"
                                />
                            </div>

                            <footer class="flex border-t border-border/60 pt-5">
                                <Button
                                    class="ml-auto w-full sm:w-auto sm:min-w-40"
                                    size="lg"
                                    :disabled="formDatos.processing"
                                    @click="guardarDatosPersonales"
                                >
                                    <Spinner v-if="formDatos.processing" />
                                    Guardar y continuar
                                    <ChevronRight class="size-4 icono-avanza" />
                                </Button>
                            </footer>
                        </div>

                        <!-- 2. Datos laborales -->
                        <div v-else-if="paso === 2" class="flex flex-col gap-5">
                            <dl
                                class="grid entrada-escalonada gap-3 sm:grid-cols-2 xl:grid-cols-3"
                            >
                                <div
                                    v-for="dato in datosLaborales"
                                    :key="dato.etiqueta"
                                    class="rounded-2xl border border-border/60 bg-crema/40 px-4 py-3"
                                >
                                    <dt class="text-xs text-muted-foreground">
                                        {{ dato.etiqueta }}
                                    </dt>
                                    <dd class="text-sm font-medium break-words">
                                        {{ dato.valor ?? '—' }}
                                    </dd>
                                </div>
                            </dl>
                            <p class="text-xs text-muted-foreground">
                                Estos datos los define Recursos Humanos y no se
                                pueden editar aquí.
                            </p>
                            <footer
                                class="flex items-center gap-2 border-t border-border/60 pt-5"
                            >
                                <Button variant="ghost" @click="retroceder">
                                    <ChevronLeft class="size-4" />Atrás
                                </Button>
                                <Button
                                    class="ml-auto sm:min-w-40"
                                    size="lg"
                                    @click="paso++"
                                >
                                    Continuar
                                    <ChevronRight class="size-4 icono-avanza" />
                                </Button>
                            </footer>
                        </div>

                        <!-- 3. Documentos -->
                        <div v-else-if="paso === 3" class="flex flex-col gap-5">
                            <p
                                v-if="documentosRequeridos.length"
                                class="text-sm text-muted-foreground"
                            >
                                {{ documentosCargados }} de
                                {{ documentosRequeridos.length }} cargados. PDF
                                o foto legible, máximo {{ PESO_MAXIMO_MB }} MB
                                por archivo.
                            </p>
                            <div
                                v-if="documentosRequeridos.length"
                                class="grid entrada-escalonada gap-3"
                            >
                                <article
                                    v-for="tipo in documentosRequeridos"
                                    :key="tipo.id"
                                    class="rounded-2xl border border-border/60 p-4"
                                >
                                    <div class="flex items-start gap-3">
                                        <FileText
                                            class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                        />
                                        <p
                                            class="min-w-0 flex-1 text-sm font-medium"
                                        >
                                            {{ tipo.nombre }}
                                        </p>
                                        <span
                                            v-if="
                                                documentosSubidos.has(tipo.id)
                                            "
                                            class="inline-flex shrink-0 aparece-zoom items-center gap-1 rounded-full bg-success-soft px-2.5 py-1 text-xs font-medium"
                                        >
                                            <CheckCircle2
                                                class="size-3.5 text-success"
                                            />
                                            Cargado
                                        </span>
                                        <span
                                            v-else
                                            class="shrink-0 rounded-full bg-crema px-2.5 py-1 text-xs font-medium"
                                        >
                                            Falta
                                        </span>
                                    </div>

                                    <template
                                        v-if="!documentosSubidos.has(tipo.id)"
                                    >
                                        <div
                                            v-if="tipo.admite_reverso"
                                            class="mt-3 grid gap-3"
                                        >
                                            <div
                                                class="grid gap-3 sm:grid-cols-2"
                                            >
                                                <div class="grid gap-1.5">
                                                    <p
                                                        class="text-xs font-medium text-muted-foreground"
                                                    >
                                                        Frente (o PDF con las
                                                        dos caras)
                                                    </p>
                                                    <PeopleFileDropzone
                                                        :model-value="
                                                            archivosCara(
                                                                tipo.id,
                                                                'frente',
                                                            )
                                                        "
                                                        :accept="
                                                            FORMATOS_DOCUMENTO
                                                        "
                                                        :max-size-mb="
                                                            PESO_MAXIMO_MB
                                                        "
                                                        :disabled="
                                                            formDocumento.processing
                                                        "
                                                        label="Arrastra aquí el frente"
                                                        @update:model-value="
                                                            (archivos) =>
                                                                elegirCara(
                                                                    archivos,
                                                                    tipo.id,
                                                                    'frente',
                                                                )
                                                        "
                                                    />
                                                </div>
                                                <div class="grid gap-1.5">
                                                    <p
                                                        class="text-xs font-medium text-muted-foreground"
                                                    >
                                                        Reverso (opcional)
                                                    </p>
                                                    <PeopleFileDropzone
                                                        :model-value="
                                                            archivosCara(
                                                                tipo.id,
                                                                'reverso',
                                                            )
                                                        "
                                                        :accept="
                                                            FORMATOS_DOCUMENTO
                                                        "
                                                        :max-size-mb="
                                                            PESO_MAXIMO_MB
                                                        "
                                                        :disabled="
                                                            formDocumento.processing
                                                        "
                                                        label="Arrastra aquí el reverso"
                                                        @update:model-value="
                                                            (archivos) =>
                                                                elegirCara(
                                                                    archivos,
                                                                    tipo.id,
                                                                    'reverso',
                                                                )
                                                        "
                                                    />
                                                </div>
                                            </div>
                                            <InputError
                                                :message="
                                                    errorDocumento(tipo.id) ??
                                                    undefined
                                                "
                                            />
                                            <Button
                                                class="w-full sm:ml-auto sm:w-auto"
                                                :disabled="
                                                    !dosCaras[tipo.id]
                                                        ?.frente ||
                                                    formDocumento.processing
                                                "
                                                @click="subirDosCaras(tipo.id)"
                                            >
                                                <Spinner
                                                    v-if="
                                                        formDocumento.processing &&
                                                        documentoEnCurso ===
                                                            tipo.id
                                                    "
                                                />
                                                Subir identificación
                                            </Button>
                                        </div>
                                        <PeopleFileDropzone
                                            v-else
                                            class="mt-3"
                                            :model-value="[]"
                                            :accept="FORMATOS_DOCUMENTO"
                                            :max-size-mb="PESO_MAXIMO_MB"
                                            :loading="
                                                formDocumento.processing &&
                                                documentoEnCurso === tipo.id
                                            "
                                            :disabled="formDocumento.processing"
                                            :progreso="
                                                formDocumento.progress
                                                    ?.percentage ?? null
                                            "
                                            :error="errorDocumento(tipo.id)"
                                            :label="
                                                formDocumento.processing &&
                                                documentoEnCurso === tipo.id
                                                    ? 'Subiendo tu archivo…'
                                                    : 'Arrastra aquí tu archivo'
                                            "
                                            @update:model-value="
                                                (archivos) =>
                                                    subirDocumento(
                                                        archivos,
                                                        tipo.id,
                                                    )
                                            "
                                        />
                                    </template>
                                </article>
                            </div>
                            <EmptyState
                                v-else
                                :icono="FileText"
                                titulo="Sin documentos por subir"
                                descripcion="No hay documentos configurados para el alta. Puedes continuar."
                            />
                            <footer
                                class="flex items-center gap-2 border-t border-border/60 pt-5"
                            >
                                <Button variant="ghost" @click="retroceder">
                                    <ChevronLeft class="size-4" />Atrás
                                </Button>
                                <Button
                                    class="ml-auto sm:min-w-40"
                                    size="lg"
                                    @click="paso++"
                                >
                                    Continuar
                                    <ChevronRight class="size-4 icono-avanza" />
                                </Button>
                            </footer>
                        </div>

                        <!-- 4. Consentimientos y firma -->
                        <div v-else-if="paso === 4" class="flex flex-col gap-5">
                            <div class="grid gap-2">
                                <label
                                    class="flex min-h-11 cursor-pointer items-start gap-3 rounded-2xl border border-border/60 px-4 py-3 text-sm transition-colors hover:bg-crema/50"
                                >
                                    <Checkbox
                                        class="mt-0.5"
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
                                <InputError
                                    :message="
                                        formConsentimientos.errors
                                            .aviso_privacidad_aceptado
                                    "
                                />
                                <label
                                    class="flex min-h-11 cursor-pointer items-start gap-3 rounded-2xl border border-border/60 px-4 py-3 text-sm transition-colors hover:bg-crema/50"
                                >
                                    <Checkbox
                                        class="mt-0.5"
                                        :model-value="
                                            formConsentimientos.consentimiento_datos_aceptado
                                        "
                                        @update:model-value="
                                            (v) =>
                                                (formConsentimientos.consentimiento_datos_aceptado =
                                                    !!v)
                                        "
                                    />
                                    Doy mi consentimiento para el tratamiento de
                                    mis datos personales.
                                </label>
                                <InputError
                                    :message="
                                        formConsentimientos.errors
                                            .consentimiento_datos_aceptado
                                    "
                                />
                            </div>

                            <div class="grid gap-2">
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <p
                                        class="flex items-center gap-1.5 text-sm font-medium"
                                    >
                                        <PenLine class="size-4" /> Firma simple
                                    </p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        :disabled="!firmaTrazada"
                                        @click="limpiarFirma"
                                        >Limpiar firma</Button
                                    >
                                </div>
                                <div class="relative max-w-2xl">
                                    <canvas
                                        ref="canvasRef"
                                        width="500"
                                        height="160"
                                        role="img"
                                        aria-label="Recuadro para trazar tu firma"
                                        class="block w-full touch-none rounded-2xl border border-dashed border-border bg-white"
                                        @mousedown="iniciarTrazo"
                                        @mousemove="trazar"
                                        @mouseup="terminarTrazo"
                                        @mouseleave="terminarTrazo"
                                        @touchstart.prevent="iniciarTrazo"
                                        @touchmove.prevent="trazar"
                                        @touchend.prevent="terminarTrazo"
                                    />
                                    <p
                                        v-if="!firmaTrazada"
                                        class="pointer-events-none absolute inset-0 grid place-items-center px-4 text-center text-sm text-muted-foreground"
                                    >
                                        Firma aquí con el dedo o el ratón
                                    </p>
                                </div>
                                <InputError
                                    :message="formConsentimientos.errors.firma"
                                />
                            </div>

                            <footer
                                class="flex items-center gap-2 border-t border-border/60 pt-5"
                            >
                                <Button variant="ghost" @click="retroceder">
                                    <ChevronLeft class="size-4" />Atrás
                                </Button>
                                <Button
                                    class="ml-auto sm:min-w-40"
                                    size="lg"
                                    :disabled="formConsentimientos.processing"
                                    @click="guardarConsentimientos"
                                >
                                    <Spinner
                                        v-if="formConsentimientos.processing"
                                    />
                                    Guardar y continuar
                                    <ChevronRight class="size-4 icono-avanza" />
                                </Button>
                            </footer>
                        </div>

                        <!-- 5. Revisión y envío -->
                        <div v-else class="flex flex-col gap-5">
                            <p
                                class="text-sm text-pretty text-muted-foreground"
                            >
                                Verifica que tu información esté completa antes
                                de enviarla a Recursos Humanos. Una vez enviada,
                                RH la revisará y te contactará.
                            </p>
                            <p
                                v-if="!alta.tiene_foto || errorEnvioFoto"
                                class="rounded-xl bg-warning-soft px-3 py-2.5 text-sm"
                                role="alert"
                            >
                                {{
                                    errorEnvioFoto ??
                                    'Agrega tu fotografía para expediente (paso 1) antes de enviar.'
                                }}
                            </p>
                            <footer
                                class="flex flex-wrap items-center gap-2 border-t border-border/60 pt-5"
                            >
                                <Button variant="ghost" @click="retroceder">
                                    <ChevronLeft class="size-4" />Atrás
                                </Button>
                                <Button
                                    class="ml-auto"
                                    size="lg"
                                    :disabled="
                                        formEnviar.processing ||
                                        !alta.tiene_foto
                                    "
                                    @click="enviarAlta"
                                >
                                    <Spinner v-if="formEnviar.processing" />
                                    <Send v-else class="size-4" />
                                    Enviar a Recursos Humanos
                                </Button>
                            </footer>
                        </div>
                    </section>
                </CambioSuave>
            </main>
        </div>
    </div>
</template>
