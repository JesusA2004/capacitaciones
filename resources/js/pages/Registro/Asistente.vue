<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Camera,
    Check,
    CircleAlert,
    ClipboardList,
    Clock,
    FileText,
    HeartPulse,
    Home,
    Lock,
    LogOut,
    PartyPopper,
    Phone,
    User,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
import { toast } from 'vue-sonner';
import CamposDomicilio from '@/components/Common/CamposDomicilio.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import FotoPerfilCaptura from '@/components/Common/FotoPerfilCaptura.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Toaster } from '@/components/ui/sonner';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    enviarJson,
    ErrorSolicitud,
    leerCookie,
    vistaPreviaPost,
} from '@/lib/http';
import {
    camposDelPaso,
    datosParaEnviar,
    documentosPorSubir,
    documentosSinResolver,
    documentosSinTener,
    fotoPendiente,
    PASOS_REGISTRO,
    pasoListo,
    pasoParaReanudar,
    puedeFinalizarRegistro,
    sinTenerVigentes,
    solicitudPendiente,
    textoDocumentos,
    validarPaso,
    valoresIniciales,
} from '@/lib/registroWizard';
import type { ClavePaso, PasoDatos } from '@/lib/registroWizard';
import { logout } from '@/routes';
import {
    datos as rutaDatos,
    finalizar as rutaFinalizar,
} from '@/routes/mi-registro';
import { vistaPrevia as rutaVistaPreviaCarta } from '@/routes/mi-registro/carta-compromiso';
import { subir as rutaSubirDocumento } from '@/routes/mi-registro/documentos';
import {
    foto as rutaFoto,
    solicitudEmpleo as rutaSolicitud,
} from '@/routes/portal';
import type {
    CampoRegistro,
    DocumentoRegistro,
    EstadoRegistroWeb,
} from '@/types/registro';

/**
 * Registro completo en la WEB (quien no puede instalar la APK): los mismos
 * pasos que la app (src/app/registro.tsx), uno a la vez, con «Guardar y
 * seguir». Reglas en RegistroAppService (AsistenteRegistroController); la
 * Solicitud de empleo es su propia pantalla (portal.solicitud-empleo).
 */
const props = defineProps<{
    estado: EstadoRegistroWeb;
    usuario: { username: string; nombre: string };
}>();

const estado = ref<EstadoRegistroWeb>(props.estado);
watch(
    () => props.estado,
    (nuevo) => {
        estado.value = nuevo;
    },
);

// «No tengo este documento»: se recuerda en este navegador hasta terminar.
const LLAVE_SIN_TENER = `registro-web:sin-tener:${props.usuario.username}`;

function leerSinTener(): number[] {
    try {
        const crudo = window.localStorage.getItem(LLAVE_SIN_TENER);
        const lista: unknown = crudo ? JSON.parse(crudo) : [];

        return Array.isArray(lista)
            ? lista.filter((n): n is number => typeof n === 'number')
            : [];
    } catch {
        return [];
    }
}

const sinTener = ref<number[]>(sinTenerVigentes(props.estado, leerSinTener()));
watch(
    sinTener,
    (lista) => {
        try {
            window.localStorage.setItem(LLAVE_SIN_TENER, JSON.stringify(lista));
        } catch {
            // Sin almacenamiento local: la marca vive solo en esta pestaña.
        }
    },
    { deep: true },
);

const ICONOS: Record<ClavePaso, Component> = {
    personales: User,
    domicilio: Home,
    emergencia: Phone,
    medicos: HeartPulse,
    foto: Camera,
    solicitud: ClipboardList,
    documentos: FileText,
    revision: Check,
};

const indice = ref(pasoParaReanudar(props.estado, sinTener.value));
const paso = computed(() => PASOS_REGISTRO[indice.value]);
const guardando = ref(false);
const errores = ref<Record<string, string>>({});
const errorGeneral = ref<string | null>(null);
const terminado = ref(false);

const valores = ref<Record<string, string>>(
    valoresIniciales(props.estado.datos.campos),
);

const listos = computed(
    () =>
        PASOS_REGISTRO.filter((p) =>
            pasoListo(p.clave, estado.value, sinTener.value),
        ).length,
);
const avance = computed(() =>
    Math.round((listos.value / (PASOS_REGISTRO.length - 1)) * 100),
);

function irA(nuevo: number): void {
    errores.value = {};
    errorGeneral.value = null;
    indice.value = Math.max(0, Math.min(PASOS_REGISTRO.length - 1, nuevo));
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ---------- Datos (personales, domicilio, emergencia) ---------- */

const esPasoDatos = (clave: ClavePaso): clave is PasoDatos =>
    clave === 'personales' || clave === 'domicilio' || clave === 'emergencia';

const camposPaso = computed<CampoRegistro[]>(() =>
    esPasoDatos(paso.value.clave)
        ? camposDelPaso(paso.value.clave, estado.value.datos.campos)
        : [],
);

const camposDomicilio = computed(() =>
    camposDelPaso('domicilio', estado.value.datos.campos),
);
const domicilioBloqueado = computed(
    () =>
        camposDomicilio.value.length > 0 &&
        camposDomicilio.value.every((c) => c.bloqueado),
);
const textoDomicilioBloqueado = computed(() =>
    camposDomicilio.value
        .map((c) => c.actual_legible ?? c.actual)
        .filter((v): v is string => !!v)
        .join(', '),
);

function valorModelo(campo: string) {
    return computed({
        get: () => valores.value[campo] ?? '',
        set: (v: string) => {
            valores.value[campo] = v;
        },
    });
}

const domCalle = valorModelo('domicilio_calle');
const domExterior = valorModelo('domicilio_numero_exterior');
const domInterior = valorModelo('domicilio_numero_interior');
const domColonia = valorModelo('domicilio_colonia');
const domCp = valorModelo('domicilio_cp');
const domMunicipio = valorModelo('domicilio_municipio');
const domEstado = valorModelo('domicilio_estado');

const PARENTESCOS = ['Madre', 'Padre', 'Pareja', 'Hijo(a)', 'Hermano(a)'];
const PILDORAS_MAX = 6;

function esParentesco(campo: string): boolean {
    return campo.endsWith('_parentesco');
}

function soloDigitos(campo: string, valor: string | number, max: number): void {
    valores.value[campo] = String(valor).replace(/\D/g, '').slice(0, max);
}

function maxDigitos(campo: CampoRegistro): number | null {
    if (campo.tipo === 'tel') {
        return 10;
    }

    return campo.campo === 'nss' ? 11 : null;
}

/* ---------- Datos médicos ---------- */

const tieneCondicion = ref<boolean | null>(null);
const tieneAlergias = ref<boolean | null>(null);
const condicion = ref('');
const alergias = ref('');

/* ---------- Guardar ---------- */

function aplicarEstado(nuevo: EstadoRegistroWeb): void {
    estado.value = nuevo;
    sinTener.value = sinTenerVigentes(nuevo, sinTener.value);
}

async function guardarDatos(cuerpo: Record<string, unknown>): Promise<boolean> {
    guardando.value = true;
    errorGeneral.value = null;

    try {
        const respuesta = await enviarJson<{ data: EstadoRegistroWeb }>(
            'POST',
            rutaDatos.url(),
            cuerpo,
        );
        aplicarEstado(respuesta.data);

        return true;
    } catch (e) {
        errorGeneral.value =
            e instanceof Error
                ? e.message
                : 'No se pudo guardar. Intenta de nuevo.';

        return false;
    } finally {
        guardando.value = false;
    }
}

async function guardarYSeguir(): Promise<void> {
    const clave = paso.value.clave;

    if (esPasoDatos(clave)) {
        errores.value = validarPaso(
            clave,
            estado.value.datos.campos,
            valores.value,
        );

        if (Object.keys(errores.value).length > 0) {
            errorGeneral.value = 'Revisa los datos marcados.';

            return;
        }

        const datos = datosParaEnviar(
            clave,
            estado.value.datos.campos,
            valores.value,
        );

        if (Object.keys(datos).length > 0 && !(await guardarDatos({ datos }))) {
            return;
        }
    }

    if (clave === 'medicos') {
        const medicos = {
            condicion_medica: tieneCondicion.value
                ? condicion.value.trim()
                : '',
            alergias: tieneAlergias.value ? alergias.value.trim() : '',
        };

        if (tieneCondicion.value && !medicos.condicion_medica) {
            errores.value = { condicion_medica: 'Escribe cuál, o elige «No».' };

            return;
        }

        if (tieneAlergias.value && !medicos.alergias) {
            errores.value = { alergias: 'Escribe a qué, o elige «No».' };

            return;
        }

        if (
            (medicos.condicion_medica || medicos.alergias) &&
            !(await guardarDatos({ datos_medicos: medicos }))
        ) {
            return;
        }
    }

    if (clave === 'foto' && fotoPendiente(estado.value)) {
        errorGeneral.value = 'Agrega tu fotografía para continuar.';

        return;
    }

    if (clave === 'revision') {
        await finalizar();

        return;
    }

    irA(indice.value + 1);
}

/* ---------- Documentos ---------- */

const listaDocumentos = computed(() => estado.value.documentos.lista);
const maxCarta = computed(() =>
    Math.max(1, estado.value.max_documentos_carta || 2),
);
const marcados = computed(() =>
    documentosSinTener(estado.value, sinTener.value),
);
const sinResolver = computed(() =>
    documentosSinResolver(estado.value, sinTener.value),
);
const subiendo = ref<number | null>(null);
const reversos = ref<Record<number, File | null>>({});
const conPar = ref<Record<number, boolean>>({});
const erroresDocumento = ref<Record<number, string>>({});

function estadoDocumento(d: DocumentoRegistro): {
    texto: string;
    clase: string;
} {
    if (d.generado_por_sistema !== null) {
        return { texto: 'Se genera con tus datos', clase: 'bg-info-soft' };
    }

    if (
        sinTener.value.includes(d.id) &&
        documentosPorSubir(estado.value).some((x) => x.id === d.id)
    ) {
        return { texto: 'No lo tengo', clase: 'bg-warning-soft' };
    }

    switch (d.estado) {
        case 'aprobado':
            return { texto: 'Aprobado', clase: 'bg-success-soft' };
        case 'en_revision':
            return { texto: 'Enviado', clase: 'bg-success-soft' };
        case 'rechazado':
        case 'requiere_correccion':
            return { texto: 'Corrígelo', clase: 'bg-danger-soft' };
        case 'vencido':
            return { texto: 'Vencido', clase: 'bg-danger-soft' };
        default:
            return { texto: 'Falta', clase: 'bg-crema' };
    }
}

function porSubir(d: DocumentoRegistro): boolean {
    return documentosPorSubir(estado.value).some((x) => x.id === d.id);
}

function alternarSinTener(d: DocumentoRegistro): void {
    if (sinTener.value.includes(d.id)) {
        sinTener.value = sinTener.value.filter((id) => id !== d.id);

        return;
    }

    if (marcados.value.length >= maxCarta.value) {
        toast.error(
            `Solo puedes marcar hasta ${textoDocumentos(maxCarta.value)} en la carta compromiso.`,
        );

        return;
    }

    sinTener.value = [...sinTener.value, d.id];
}

/** Formatos y peso que acepta el servidor (config/expedientes.php). */
const FORMATOS_DOCUMENTO = '.pdf,.jpg,.jpeg,.png';
const PESO_MAXIMO_MB = 20;

function archivosReverso(d: DocumentoRegistro): File[] {
    const reverso = reversos.value[d.id];

    return reverso ? [reverso] : [];
}

function alElegirReverso(d: DocumentoRegistro, archivos: File[]): void {
    reversos.value = { ...reversos.value, [d.id]: archivos[0] ?? null };
}

async function alElegirArchivo(
    d: DocumentoRegistro,
    archivos: File[],
): Promise<void> {
    const archivo = archivos[0];

    if (!archivo || subiendo.value !== null) {
        return;
    }

    const cuerpo = new FormData();
    cuerpo.append('archivo', archivo);

    const reverso = reversos.value[d.id];

    if (d.admite_reverso && reverso) {
        cuerpo.append('archivo_reverso', reverso);
    }

    if (d.par?.puede_subir && conPar.value[d.id]) {
        cuerpo.append('incluye_par', '1');
    }

    subiendo.value = d.id;
    erroresDocumento.value = { ...erroresDocumento.value, [d.id]: '' };

    try {
        const respuesta = await fetch(rutaSubirDocumento.url(d.id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
            },
            credentials: 'same-origin',
            body: cuerpo,
        });
        const datos = (await respuesta.json().catch(() => null)) as {
            data?: EstadoRegistroWeb;
            message?: string;
            errors?: Record<string, string[]>;
        } | null;

        if (!respuesta.ok || !datos?.data) {
            const primero = datos?.errors
                ? Object.values(datos.errors)[0]?.[0]
                : undefined;

            throw new ErrorSolicitud(
                primero ?? datos?.message ?? 'No se pudo subir el archivo.',
                respuesta.status,
            );
        }

        sinTener.value = sinTener.value.filter((id) => id !== d.id);
        aplicarEstado(datos.data);
        toast.success(`${d.nombre}: enviado.`);
    } catch (e) {
        erroresDocumento.value = {
            ...erroresDocumento.value,
            [d.id]:
                e instanceof Error ? e.message : 'No se pudo subir el archivo.',
        };
    } finally {
        subiendo.value = null;
    }
}

/* ---------- Carta compromiso (vista previa) ---------- */

const urlCarta = ref<string | null>(null);
const preparandoCarta = ref(false);

async function verCarta(): Promise<void> {
    if (urlCarta.value) {
        URL.revokeObjectURL(urlCarta.value);
        urlCarta.value = null;
    }

    preparandoCarta.value = true;

    try {
        urlCarta.value = await vistaPreviaPost(rutaVistaPreviaCarta.url(), {
            documentos: marcados.value.map((d) => d.id),
        });
        window.open(urlCarta.value, '_blank', 'noopener');
    } catch (e) {
        toast.error(
            e instanceof Error ? e.message : 'No se pudo preparar la carta.',
        );
    } finally {
        preparandoCarta.value = false;
    }
}

watch(marcados, () => {
    if (urlCarta.value) {
        URL.revokeObjectURL(urlCarta.value);
        urlCarta.value = null;
    }
});

/* ---------- Revisión y cierre ---------- */

const puedeTerminar = computed(() =>
    puedeFinalizarRegistro(estado.value, sinTener.value),
);

const resumen = computed(() =>
    PASOS_REGISTRO.filter((p) => p.clave !== 'revision').map((p, i) => ({
        ...p,
        indice: i,
        listo: pasoListo(p.clave, estado.value, sinTener.value),
    })),
);

async function finalizar(): Promise<void> {
    if (!puedeTerminar.value) {
        errorGeneral.value =
            'Todavía falta algo: revisa los pasos sin palomita.';

        return;
    }

    guardando.value = true;
    errorGeneral.value = null;

    try {
        const respuesta = await enviarJson<{
            redirect: string;
            message: string;
        }>('POST', rutaFinalizar.url(), {
            documentos_sin_tener: marcados.value.map((d) => d.id),
        });

        try {
            window.localStorage.removeItem(LLAVE_SIN_TENER);
        } catch {
            // Nada que limpiar.
        }

        terminado.value = true;
        setTimeout(() => router.visit(respuesta.redirect), 2200);
    } catch (e) {
        errorGeneral.value =
            e instanceof Error
                ? e.message
                : 'No se pudo terminar. Intenta de nuevo.';
    } finally {
        guardando.value = false;
    }
}

const claseBoton =
    'inline-flex min-h-10 items-center justify-center gap-1.5 rounded-full border px-4 py-2 text-sm transicion-ui focus-visible:outline-2 focus-visible:outline-oro';
const claseActivo =
    'border-[var(--mrl-petroleo)] bg-salvia font-medium text-[var(--mrl-petroleo)]';
const claseInactivo =
    'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] hover:border-oro/60';
</script>

<template>
    <Head title="Mi registro" />
    <Toaster />

    <div class="pagina-ancha min-h-screen bg-background">
        <!-- Encabezado -->
        <header class="mb-5 flex items-center gap-3">
            <div class="min-w-0 flex-1">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    MR. LANA PEOPLE · Mi registro
                </p>
                <p class="truncate text-base font-semibold">
                    Hola, {{ usuario.nombre }}
                </p>
            </div>
            <Link
                :href="logout()"
                as="button"
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-2 text-sm text-muted-foreground hover:bg-crema hover:text-foreground"
            >
                <LogOut class="size-4" /> Salir
            </Link>
        </header>

        <!-- Terminado -->
        <section
            v-if="terminado"
            class="mx-auto flex max-w-xl entrada-suave flex-col items-center gap-3 rounded-3xl border border-border/60 bg-success-soft p-8 text-center"
        >
            <PartyPopper class="size-12 text-[var(--mrl-success)]" />
            <h1 class="text-xl font-semibold">
                ¡Listo, terminaste tu registro!
            </h1>
            <p class="text-sm text-muted-foreground">
                Recursos Humanos revisará tus documentos. Tu usuario es
                <strong>{{ usuario.username }}</strong
                >.
            </p>
            <Spinner />
        </section>

        <div v-else class="grid gap-5 lg:grid-cols-[16rem_minmax(0,1fr)]">
            <!-- Riel de pasos -->
            <nav aria-label="Pasos del registro" class="flex flex-col gap-3">
                <div class="rounded-2xl border border-border/60 bg-card p-4">
                    <div
                        class="mb-2 flex items-baseline justify-between text-sm"
                    >
                        <span class="font-medium">Tu avance</span>
                        <span class="font-semibold text-[var(--mrl-petroleo)]"
                            >{{ avance }}%</span
                        >
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-crema">
                        <div
                            class="h-full rounded-full bg-[var(--mrl-petroleo)] transition-[width] duration-700"
                            :style="{ width: `${avance}%` }"
                        />
                    </div>
                </div>
                <ol
                    class="-mx-4 scroll-x-limpio flex gap-2 px-4 lg:mx-0 lg:flex-col lg:px-0"
                >
                    <li
                        v-for="(p, i) in PASOS_REGISTRO"
                        :key="p.clave"
                        class="shrink-0 lg:w-full"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-2xl border px-3 py-2 text-left text-sm transicion-ui focus-visible:outline-2 focus-visible:outline-oro"
                            :class="
                                i === indice
                                    ? 'border-[var(--mrl-petroleo)] bg-salvia font-medium'
                                    : 'border-transparent hover:bg-crema/60'
                            "
                            :aria-current="i === indice ? 'step' : undefined"
                            @click="irA(i)"
                        >
                            <span
                                class="grid size-7 shrink-0 place-items-center rounded-full text-xs"
                                :class="
                                    pasoListo(p.clave, estado, sinTener)
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-crema text-[var(--mrl-petroleo)]'
                                "
                            >
                                <Check
                                    v-if="pasoListo(p.clave, estado, sinTener)"
                                    class="size-4"
                                />
                                <template v-else>{{ i + 1 }}</template>
                            </span>
                            <span class="whitespace-nowrap">
                                <span class="lg:hidden">{{ p.corto }}</span>
                                <span class="hidden lg:inline">{{
                                    p.titulo
                                }}</span>
                            </span>
                        </button>
                    </li>
                </ol>
            </nav>

            <!-- Paso actual -->
            <main class="min-w-0">
                <section
                    :key="paso.clave"
                    class="entrada-suave rounded-3xl border border-border/60 bg-card p-5 sm:p-7"
                >
                    <div class="mb-5 flex items-start gap-3">
                        <span
                            class="grid size-11 shrink-0 place-items-center rounded-2xl bg-salvia text-[var(--mrl-petroleo)]"
                        >
                            <component
                                :is="ICONOS[paso.clave]"
                                class="size-5"
                            />
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs text-muted-foreground">
                                Paso {{ indice + 1 }} de
                                {{ PASOS_REGISTRO.length }}
                                <template v-if="paso.opcional">
                                    · Opcional</template
                                >
                            </p>
                            <h1 class="text-lg font-semibold sm:text-xl">
                                {{ paso.titulo }}
                            </h1>
                            <p class="text-sm text-muted-foreground">
                                {{ paso.descripcion }}
                            </p>
                        </div>
                    </div>

                    <!-- Personales / emergencia -->
                    <div
                        v-if="
                            paso.clave === 'personales' ||
                            paso.clave === 'emergencia'
                        "
                        class="grid gap-5 sm:grid-cols-2"
                    >
                        <div
                            v-for="campo in camposPaso"
                            :key="campo.campo"
                            class="grid content-start gap-2"
                            :class="
                                campo.tipo === 'opciones' ? 'sm:col-span-2' : ''
                            "
                        >
                            <Label :for="`campo_${campo.campo}`">
                                {{ campo.etiqueta }}
                                <span
                                    v-if="!campo.obligatorio"
                                    class="font-normal text-muted-foreground"
                                    >(opcional)</span
                                >
                            </Label>

                            <!-- Ya registrado por RH -->
                            <div
                                v-if="campo.bloqueado"
                                class="flex items-center gap-2 rounded-xl bg-crema/60 px-3 py-2.5 text-sm"
                            >
                                <Lock
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    campo.actual_legible ?? campo.actual
                                }}</span>
                                <span class="text-xs text-muted-foreground"
                                    >Registrado por RH</span
                                >
                            </div>

                            <div
                                v-else-if="
                                    campo.tipo === 'opciones' &&
                                    (campo.opciones?.length ?? 0) <=
                                        PILDORAS_MAX
                                "
                                :id="`campo_${campo.campo}`"
                                role="radiogroup"
                                :aria-label="campo.etiqueta"
                                class="flex flex-wrap gap-2"
                            >
                                <button
                                    v-for="op in campo.opciones"
                                    :key="op.value"
                                    type="button"
                                    role="radio"
                                    :aria-checked="
                                        valores[campo.campo] === op.value
                                    "
                                    :class="[
                                        claseBoton,
                                        valores[campo.campo] === op.value
                                            ? claseActivo
                                            : claseInactivo,
                                    ]"
                                    @click="valores[campo.campo] = op.value"
                                >
                                    <Check
                                        v-if="valores[campo.campo] === op.value"
                                        class="size-4"
                                    />
                                    {{ op.label }}
                                </button>
                            </div>

                            <SelectSimple
                                v-else-if="campo.tipo === 'opciones'"
                                :id="`campo_${campo.campo}`"
                                :model-value="valores[campo.campo] || undefined"
                                :opciones="campo.opciones ?? []"
                                placeholder="Elige una opción"
                                @update:model-value="
                                    (v) =>
                                        (valores[campo.campo] = v
                                            ? String(v)
                                            : '')
                                "
                            />

                            <DatePicker
                                v-else-if="campo.tipo === 'date'"
                                :id="`campo_${campo.campo}`"
                                :model-value="valores[campo.campo] || null"
                                :anio-desde="1940"
                                @update:model-value="
                                    (v) => (valores[campo.campo] = v ?? '')
                                "
                            />

                            <template v-else>
                                <Input
                                    :id="`campo_${campo.campo}`"
                                    :model-value="valores[campo.campo] ?? ''"
                                    :type="
                                        campo.tipo === 'email'
                                            ? 'email'
                                            : campo.tipo === 'tel'
                                              ? 'tel'
                                              : 'text'
                                    "
                                    :inputmode="
                                        maxDigitos(campo) !== null
                                            ? 'numeric'
                                            : undefined
                                    "
                                    :maxlength="
                                        maxDigitos(campo) ??
                                        (['curp', 'clave_elector'].includes(
                                            campo.campo,
                                        )
                                            ? 18
                                            : campo.campo === 'rfc'
                                              ? 13
                                              : 150)
                                    "
                                    :class="
                                        [
                                            'curp',
                                            'rfc',
                                            'clave_elector',
                                        ].includes(campo.campo)
                                            ? 'uppercase'
                                            : ''
                                    "
                                    autocomplete="off"
                                    @update:model-value="
                                        (v) => {
                                            const max = maxDigitos(campo);
                                            if (max !== null)
                                                soloDigitos(
                                                    campo.campo,
                                                    v,
                                                    max,
                                                );
                                            else
                                                valores[campo.campo] =
                                                    String(v);
                                        }
                                    "
                                />
                                <div
                                    v-if="esParentesco(campo.campo)"
                                    class="flex flex-wrap gap-1.5"
                                >
                                    <button
                                        v-for="p in PARENTESCOS"
                                        :key="p"
                                        type="button"
                                        class="rounded-full border px-3 py-1 text-xs transicion-ui"
                                        :class="
                                            valores[campo.campo] === p
                                                ? claseActivo
                                                : claseInactivo
                                        "
                                        @click="valores[campo.campo] = p"
                                    >
                                        {{ p }}
                                    </button>
                                </div>
                            </template>
                            <InputError :message="errores[campo.campo]" />
                        </div>
                    </div>

                    <!-- Domicilio -->
                    <div v-else-if="paso.clave === 'domicilio'">
                        <div
                            v-if="domicilioBloqueado"
                            class="flex items-start gap-2 rounded-xl bg-crema/60 px-3 py-3 text-sm"
                        >
                            <Lock
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            />
                            <span
                                >{{ textoDomicilioBloqueado }}
                                <span class="text-xs text-muted-foreground"
                                    >· Registrado por RH</span
                                ></span
                            >
                        </div>
                        <CamposDomicilio
                            v-else
                            v-model:calle="domCalle"
                            v-model:numero-exterior="domExterior"
                            v-model:numero-interior="domInterior"
                            v-model:colonia="domColonia"
                            v-model:cp="domCp"
                            v-model:municipio="domMunicipio"
                            v-model:estado="domEstado"
                            :errores="errores"
                            requerido
                            prefijo="registro_domicilio"
                        />
                    </div>

                    <!-- Datos médicos -->
                    <div
                        v-else-if="paso.clave === 'medicos'"
                        class="grid gap-6"
                    >
                        <p
                            v-if="estado.datos_medicos.capturados"
                            class="flex items-center gap-2 rounded-xl bg-success-soft px-3 py-2 text-sm"
                        >
                            <Check class="size-4" /> Ya guardaste tus datos
                            médicos. Puedes cambiarlos o seguir.
                        </p>
                        <div
                            v-for="pregunta in [
                                {
                                    clave: 'condicion_medica',
                                    texto: '¿Tienes alguna condición médica?',
                                    ayuda: 'Por ejemplo: diabetes, hipertensión, asma.',
                                    si: tieneCondicion,
                                },
                                {
                                    clave: 'alergias',
                                    texto: '¿Tienes alergias?',
                                    ayuda: 'A medicamentos, alimentos u otras.',
                                    si: tieneAlergias,
                                },
                            ]"
                            :key="pregunta.clave"
                            class="grid gap-2"
                        >
                            <p class="text-sm font-medium">
                                {{ pregunta.texto }}
                            </p>
                            <div
                                role="group"
                                :aria-label="pregunta.texto"
                                class="grid grid-cols-2 gap-2 sm:inline-grid sm:w-56"
                            >
                                <button
                                    v-for="op in [
                                        { v: true, t: 'Sí' },
                                        { v: false, t: 'No' },
                                    ]"
                                    :key="op.t"
                                    type="button"
                                    :aria-pressed="pregunta.si === op.v"
                                    :class="[
                                        claseBoton,
                                        'rounded-xl',
                                        pregunta.si === op.v
                                            ? claseActivo
                                            : claseInactivo,
                                    ]"
                                    @click="
                                        pregunta.clave === 'condicion_medica'
                                            ? (tieneCondicion = op.v)
                                            : (tieneAlergias = op.v)
                                    "
                                >
                                    {{ op.t }}
                                </button>
                            </div>
                            <template v-if="pregunta.si">
                                <Textarea
                                    v-if="pregunta.clave === 'condicion_medica'"
                                    v-model="condicion"
                                    rows="2"
                                    maxlength="1000"
                                    :placeholder="pregunta.ayuda"
                                />
                                <Textarea
                                    v-else
                                    v-model="alergias"
                                    rows="2"
                                    maxlength="1000"
                                    :placeholder="pregunta.ayuda"
                                />
                            </template>
                            <InputError :message="errores[pregunta.clave]" />
                        </div>
                    </div>

                    <!-- Foto -->
                    <div v-else-if="paso.clave === 'foto'" class="grid gap-4">
                        <FotoPerfilCaptura
                            :nombre="usuario.nombre"
                            :foto-url="estado.foto.url"
                            :url-subida="rutaFoto.url()"
                        />
                        <p class="text-xs text-muted-foreground">
                            De frente, con buena luz y fondo claro.
                            <template v-if="estado.foto.cubre_documento">
                                Cubre «{{
                                    estado.foto.cubre_documento.nombre
                                }}».
                            </template>
                        </p>
                    </div>

                    <!-- Solicitud de empleo -->
                    <div
                        v-else-if="paso.clave === 'solicitud'"
                        class="grid gap-4"
                    >
                        <template v-if="estado.solicitud_empleo">
                            <div class="flex items-center gap-3">
                                <div
                                    class="h-2 flex-1 overflow-hidden rounded-full bg-crema"
                                >
                                    <div
                                        class="h-full crece-barra rounded-full bg-[var(--mrl-petroleo)]"
                                        :style="{
                                            width: `${estado.solicitud_empleo.porcentaje}%`,
                                        }"
                                    />
                                </div>
                                <span class="text-sm font-semibold"
                                    >{{
                                        estado.solicitud_empleo.porcentaje
                                    }}%</span
                                >
                            </div>
                            <ul
                                class="grid entrada-escalonada gap-2 sm:grid-cols-2"
                            >
                                <li
                                    v-for="s in estado.solicitud_empleo
                                        .secciones"
                                    :key="s.clave"
                                    class="flex items-center gap-2 rounded-xl border border-border/60 px-3 py-2 text-sm"
                                >
                                    <Check
                                        v-if="s.completa"
                                        class="size-4 text-[var(--mrl-success)]"
                                    />
                                    <Clock
                                        v-else
                                        class="size-4 text-muted-foreground"
                                    />
                                    <span class="min-w-0 flex-1 truncate">{{
                                        s.titulo
                                    }}</span>
                                    <span class="text-xs text-muted-foreground"
                                        >{{ s.porcentaje }}%</span
                                    >
                                </li>
                            </ul>
                        </template>
                        <Button
                            v-if="solicitudPendiente(estado)"
                            as-child
                            size="lg"
                            class="w-full sm:w-auto"
                        >
                            <Link :href="rutaSolicitud()">
                                <ClipboardList class="size-4" /> Llenar mi
                                solicitud
                            </Link>
                        </Button>
                        <p
                            v-else
                            class="flex items-center gap-2 rounded-xl bg-success-soft px-3 py-2 text-sm"
                        >
                            <Check class="size-4" /> Tu solicitud está completa.
                        </p>
                    </div>

                    <!-- Documentos -->
                    <div
                        v-else-if="paso.clave === 'documentos'"
                        class="grid gap-3"
                    >
                        <article
                            v-for="d in listaDocumentos"
                            :key="d.id"
                            class="rounded-2xl border border-border/60 p-4"
                        >
                            <div class="flex items-start gap-3">
                                <FileText
                                    class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium">{{ d.nombre }}</p>
                                    <p
                                        v-if="d.generado_por_sistema"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ d.generado_por_sistema.mensaje }}
                                    </p>
                                    <p
                                        v-else-if="d.motivo_rechazo"
                                        class="text-xs text-destructive"
                                    >
                                        {{ d.motivo_rechazo }}
                                    </p>
                                    <p
                                        v-else-if="d.mensaje && porSubir(d)"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ d.mensaje }}
                                    </p>
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="estadoDocumento(d).clase"
                                >
                                    {{ estadoDocumento(d).texto }}
                                </span>
                            </div>

                            <div
                                v-if="
                                    porSubir(d) &&
                                    (d.puede_subir || d.puede_reemplazar)
                                "
                                class="mt-3 grid gap-3"
                            >
                                <div
                                    v-if="d.admite_reverso"
                                    class="grid gap-1.5"
                                >
                                    <p
                                        class="text-xs font-medium text-muted-foreground"
                                    >
                                        Reverso (opcional): elígelo antes de
                                        subir el frente
                                    </p>
                                    <PeopleFileDropzone
                                        :model-value="archivosReverso(d)"
                                        :accept="FORMATOS_DOCUMENTO"
                                        :max-size-mb="PESO_MAXIMO_MB"
                                        :disabled="subiendo !== null"
                                        label="Arrastra aquí el reverso"
                                        @update:model-value="
                                            (archivos) =>
                                                alElegirReverso(d, archivos)
                                        "
                                    />
                                </div>
                                <label
                                    v-if="d.par?.puede_subir"
                                    class="flex min-h-10 items-center gap-2.5 text-sm"
                                >
                                    <Checkbox
                                        :model-value="conPar[d.id] ?? false"
                                        @update:model-value="
                                            (v) => (conPar[d.id] = v === true)
                                        "
                                    />
                                    Este archivo trae también «{{
                                        d.par.nombre
                                    }}»
                                </label>
                                <PeopleFileDropzone
                                    :model-value="[]"
                                    :accept="FORMATOS_DOCUMENTO"
                                    :max-size-mb="PESO_MAXIMO_MB"
                                    :loading="subiendo === d.id"
                                    :disabled="subiendo !== null"
                                    :label="
                                        subiendo === d.id
                                            ? 'Subiendo tu archivo…'
                                            : d.estado === 'pendiente'
                                              ? 'Arrastra aquí tu archivo'
                                              : 'Arrastra aquí el archivo corregido'
                                    "
                                    @update:model-value="
                                        (archivos) =>
                                            alElegirArchivo(d, archivos)
                                    "
                                />
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        :class="[
                                            claseBoton,
                                            sinTener.includes(d.id)
                                                ? claseActivo
                                                : claseInactivo,
                                        ]"
                                        :aria-pressed="sinTener.includes(d.id)"
                                        @click="alternarSinTener(d)"
                                    >
                                        <Check
                                            v-if="sinTener.includes(d.id)"
                                            class="size-4"
                                        />
                                        No tengo este documento
                                    </button>
                                </div>
                                <InputError
                                    :message="
                                        erroresDocumento[d.id] || undefined
                                    "
                                />
                            </div>
                        </article>

                        <!-- Carta compromiso -->
                        <div
                            v-if="marcados.length > 0"
                            class="flex flex-col gap-3 rounded-2xl bg-warning-soft p-4 sm:flex-row sm:items-center"
                        >
                            <p class="flex-1 text-sm">
                                Firmarás una
                                <strong>carta compromiso</strong> por
                                {{ textoDocumentos(marcados.length) }} (máximo
                                {{ maxCarta }}).
                            </p>
                            <Button
                                variant="outline"
                                :disabled="preparandoCarta"
                                @click="verCarta"
                            >
                                <Spinner v-if="preparandoCarta" />
                                Ver carta
                            </Button>
                        </div>
                    </div>

                    <!-- Revisión -->
                    <div
                        v-else-if="paso.clave === 'revision'"
                        class="grid gap-3"
                    >
                        <ul class="grid entrada-escalonada gap-2">
                            <li v-for="r in resumen" :key="r.clave">
                                <button
                                    type="button"
                                    class="flex w-full tarjeta-interactiva items-center gap-3 rounded-2xl border border-border/60 px-4 py-3 text-left text-sm"
                                    @click="irA(r.indice)"
                                >
                                    <span
                                        class="grid size-7 shrink-0 place-items-center rounded-full"
                                        :class="
                                            r.listo
                                                ? 'bg-primary text-primary-foreground'
                                                : r.opcional
                                                  ? 'bg-crema'
                                                  : 'bg-warning-soft'
                                        "
                                    >
                                        <Check v-if="r.listo" class="size-4" />
                                        <CircleAlert
                                            v-else-if="!r.opcional"
                                            class="size-4"
                                        />
                                    </span>
                                    <span class="flex-1">{{ r.titulo }}</span>
                                    <span class="text-xs text-muted-foreground">
                                        {{
                                            r.listo
                                                ? 'Listo'
                                                : r.opcional
                                                  ? 'Opcional'
                                                  : 'Falta'
                                        }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                        <p
                            v-if="marcados.length > 0"
                            class="rounded-xl bg-warning-soft px-3 py-2 text-sm"
                        >
                            Al terminar se genera tu carta compromiso por
                            {{ textoDocumentos(marcados.length) }}.
                        </p>
                        <p
                            v-if="!puedeTerminar && sinResolver.length > 0"
                            class="text-sm text-muted-foreground"
                        >
                            Sube o marca «No tengo»:
                            {{ sinResolver.map((d) => d.nombre).join(', ') }}.
                        </p>
                    </div>

                    <p
                        v-if="errorGeneral"
                        class="mt-5 rounded-xl bg-danger-soft px-3 py-2 text-sm"
                        role="alert"
                    >
                        {{ errorGeneral }}
                    </p>

                    <!-- Navegación -->
                    <footer
                        class="mt-6 flex items-center gap-2 border-t border-border/60 pt-5"
                    >
                        <Button
                            v-if="indice > 0"
                            variant="ghost"
                            :disabled="guardando"
                            @click="irA(indice - 1)"
                        >
                            <ArrowLeft class="size-4" /> Atrás
                        </Button>
                        <Button
                            class="ml-auto min-w-40"
                            size="lg"
                            :disabled="
                                guardando ||
                                (paso.clave === 'revision' && !puedeTerminar)
                            "
                            data-test="guardar-seguir"
                            @click="guardarYSeguir"
                        >
                            <Spinner v-if="guardando" />
                            <template v-if="paso.clave === 'revision'"
                                >Terminar registro</template
                            >
                            <template v-else-if="paso.opcional"
                                >Seguir</template
                            >
                            <template v-else>Guardar y seguir</template>
                            <ArrowRight
                                v-if="paso.clave !== 'revision'"
                                class="size-4"
                            />
                        </Button>
                    </footer>
                </section>
            </main>
        </div>
    </div>
</template>
