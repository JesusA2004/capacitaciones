<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    ChevronDown,
    Hourglass,
    Lock,
    PartyPopper,
    Plus,
    Trash2,
    UserRound,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import CampoSolicitudControl from '@/components/incorporacion/CampoSolicitudControl.vue';
import FilaListaSolicitud from '@/components/incorporacion/FilaListaSolicitud.vue';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { avisarExito } from '@/lib/flashToast';
import { enviarJson, ErrorSolicitud } from '@/lib/http';
import { dashboard } from '@/routes';
import { show as rutaRegistro } from '@/routes/mi-registro';
import {
    index as rutaPortal,
    solicitudEmpleo as rutaPagina,
} from '@/routes/portal';
import { guardar as rutaGuardar } from '@/routes/portal/solicitud-empleo';
import {
    index as indexSolicitudes,
    show as verSolicitud,
} from '@/routes/solicitudes';
import type {
    CampoListaSolicitud,
    CampoSolicitud,
    EstadoMiSolicitudEmpleo,
    SeccionMiSolicitud,
    ValorSolicitud,
} from '@/types';

/**
 * «Mi solicitud de empleo»: lo mismo que pide la app, sección por sección,
 * para quien entra desde la web (iPhone/Mac). TODO se captura aquí mismo:
 * los datos personales que faltan en el expediente (domicilio, teléfono…)
 * se piden dentro de su sección y, al guardar, viajan a Recursos Humanos
 * para revisión — sin mandar a la persona a otra pantalla.
 *
 * Nunca se pide dos veces un dato: lo que ya está en el expediente sale
 * como «Ya registrado» y lo que ya envió, «En revisión por RH». Los números
 * («te faltan N datos») vienen del backend (`estado.avance`): los mismos de
 * Mis pendientes. Reglas en SolicitudEmpleoRegistroService.
 */
const props = defineProps<{ estado: EstadoMiSolicitudEmpleo }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi solicitud de empleo', href: rutaPagina() },
        ],
    },
});

const { mostrarError } = useAlertas();
const estado = ref<EstadoMiSolicitudEmpleo>(props.estado);
const guardando = ref(false);
const borrador = ref<Record<string, ValorSolicitud>>({});
const noAplica = ref<string[]>([]);
const filas = ref<Record<string, ValorSolicitud>[]>([]);
const listaVacia = ref(false);
const verRegistrados = ref(false);
const errorSeccion = ref<string | null>(null);
/** Error de validación de cada dato (campo → mensaje), junto a su control. */
const errores = ref<Record<string, string>>({});

const secciones = computed(() => estado.value.secciones);
const avance = computed(() => estado.value.avance);
/** Secciones donde a la PERSONA todavía le falta algo. */
const pendientes = computed(() =>
    secciones.value.filter((s) => !s.lista_propia),
);
const terminado = computed(() => avance.value.listo);

const actual = ref<string>(
    pendientes.value[0]?.clave ?? secciones.value[0]?.clave ?? '',
);
const seccion = computed<SeccionMiSolicitud | undefined>(() =>
    secciones.value.find((s) => s.clave === actual.value),
);
const indice = computed(() =>
    secciones.value.findIndex((s) => s.clave === actual.value),
);

const urlCorreccion = indexSolicitudes.url({
    query: { nueva: 'actualizacion_datos' },
});

function abrir(clave: string): void {
    const s = secciones.value.find((x) => x.clave === clave);

    if (!s) {
        return;
    }

    actual.value = clave;
    errorSeccion.value = null;
    errores.value = {};
    verRegistrados.value = false;
    borrador.value = {};
    noAplica.value = s.campos.filter((c) => c.no_aplica).map((c) => c.campo);

    for (const campo of s.campos) {
        if (!campo.bloqueado) {
            borrador.value[campo.campo] = campo.valor ?? campo.sugerencia;
        }
    }

    filas.value = (s.lista?.filas ?? []).map((f) => ({ ...f }));
    listaVacia.value = s.lista?.vacia ?? false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

abrir(actual.value);

/**
 * Valor vigente para evaluar condiciones: lo que se está escribiendo, lo ya
 * guardado o lo que ya envió y RH revisa.
 */
function valorDe(clave: string): ValorSolicitud {
    if (clave in borrador.value) {
        return borrador.value[clave] ?? null;
    }

    for (const s of secciones.value) {
        const c = s.campos.find((x) => x.campo === clave);

        if (c) {
            return c.valor ?? c.propuesto_valor ?? null;
        }
    }

    return null;
}

/** Misma regla que PerfilIngresoService::esRequerido (requerido_si). */
function condicionCumple(campo: CampoSolicitud): boolean {
    const si = campo.requerido_si;

    if (!si) {
        return true;
    }

    const referencia = valorDe(si.campo);

    switch (si.operador) {
        case 'igual':
            return referencia === si.valor;
        case 'en':
            return Array.isArray(si.valor) && si.valor.includes(referencia);
        case 'distinto':
            return referencia !== null && referencia !== si.valor;
        case 'positivo':
            return Number(referencia) > 0;
        case 'contiene':
            return (
                Array.isArray(referencia) &&
                referencia.includes(si.valor as string)
            );
        default:
            return true;
    }
}

function obligatorio(campo: CampoSolicitud): boolean {
    return campo.requerido && condicionCumple(campo);
}

/** Todo lo que la persona puede escribir en esta sección. */
const porLlenar = computed(() =>
    (seccion.value?.campos ?? []).filter(
        (c) => !c.bloqueado && (condicionCumple(c) || c.valor !== null),
    ),
);

/**
 * Dos bloques en la misma pantalla: primero los datos personales que faltan
 * en el expediente (van a revisión de RH) y luego lo propio de la solicitud.
 */
const grupos = computed(() =>
    [
        {
            clave: 'personales',
            personales: true,
            campos: porLlenar.value.filter(
                (c) => c.origen === 'datos_personales',
            ),
        },
        {
            clave: 'solicitud',
            personales: false,
            campos: porLlenar.value.filter((c) => c.origen === 'solicitud'),
        },
    ].filter((g) => g.campos.length > 0),
);

/** Lo que ya envió y Recursos Humanos está revisando. */
const enRevision = computed(() =>
    (seccion.value?.campos ?? []).filter((c) => c.en_revision),
);
/** Lo que ya está en su expediente o ya capturó (no se vuelve a pedir). */
const registrados = computed(() =>
    (seccion.value?.campos ?? []).filter(
        (c) => c.bloqueado && !c.en_revision && !c.solo_rh,
    ),
);
/** Falta, pero no le toca a la persona. */
const soloRh = computed(() =>
    (seccion.value?.campos ?? []).filter((c) => c.solo_rh),
);
/** RH pidió corregir una solicitud de datos: se responde en su detalle. */
const porResponder = computed(() => {
    const ids = enRevision.value
        .filter((c) => c.requiere_respuesta && c.solicitud_id)
        .map((c) => c.solicitud_id as number);

    return [...new Set(ids)];
});
/** Todo lo que está en revisión, de todas las secciones (pantalla final). */
const enRevisionTotal = computed(() =>
    secciones.value.flatMap((s) => s.campos.filter((c) => c.en_revision)),
);

const listaEditable = computed(
    () => !!seccion.value?.lista && !seccion.value.lista.bloqueada,
);

function vacio(valor: ValorSolicitud): boolean {
    return (
        valor === null ||
        valor === '' ||
        (Array.isArray(valor) && valor.length === 0)
    );
}

/** Obligatorios sin llenar en pantalla ahora mismo (contador del botón). */
const faltanAqui = computed(
    () =>
        porLlenar.value.filter(
            (c) =>
                obligatorio(c) &&
                !noAplica.value.includes(c.campo) &&
                vacio(borrador.value[c.campo] ?? null),
        ).length +
        (listaEditable.value &&
        filas.value.length < Math.max(1, seccion.value?.lista?.minimo ?? 1) &&
        !listaVacia.value
            ? 1
            : 0),
);

function texto(
    campo: CampoSolicitud | CampoListaSolicitud,
    v: ValorSolicitud,
): string {
    if (vacio(v)) {
        return '—';
    }

    if (typeof v === 'boolean') {
        return v ? 'Sí' : 'No';
    }

    const etiqueta = (x: string) =>
        campo.opciones.find((o) => o.value === x)?.label ?? x;

    return Array.isArray(v) ? v.map(etiqueta).join(', ') : etiqueta(String(v));
}

function alternarNoAplica(campo: string): void {
    noAplica.value = noAplica.value.includes(campo)
        ? noAplica.value.filter((c) => c !== campo)
        : [...noAplica.value, campo];
}

function agregarFila(): void {
    const lista = seccion.value?.lista;

    if (!lista) {
        return;
    }

    const nueva: Record<string, ValorSolicitud> = {};
    lista.campos.forEach((c) => (nueva[c.campo] = null));
    filas.value = [...filas.value, nueva];
    listaVacia.value = false;
}

function quitarFila(i: number): void {
    filas.value = filas.value.filter((_, x) => x !== i);
}

function escribir(campo: string, valor: ValorSolicitud): void {
    borrador.value[campo] = valor;

    if (errores.value[campo]) {
        const resto = { ...errores.value };
        delete resto[campo];
        errores.value = resto;
    }
}

/** `datos.domicilio_cp` → `domicilio_cp` (errores que tienen control propio). */
function erroresPorCampo(
    todos: Record<string, string>,
): Record<string, string> {
    const propios: Record<string, string> = {};

    for (const [llave, mensaje] of Object.entries(todos)) {
        const campo = llave.replace(/^datos\./, '');

        if (porLlenar.value.some((c) => c.campo === campo)) {
            propios[campo] = mensaje;
        }
    }

    return propios;
}

async function guardar(avanzar: boolean): Promise<void> {
    const s = seccion.value;

    if (!s) {
        return;
    }

    const datos: Record<string, unknown> = {};

    for (const campo of porLlenar.value) {
        const v = borrador.value[campo.campo];

        if (!vacio(v ?? null) && !noAplica.value.includes(campo.campo)) {
            datos[campo.campo] = v;
        }
    }

    if (listaEditable.value && s.lista) {
        datos[s.lista.clave] = filas.value;

        if (s.lista.bandera_vacia) {
            datos[s.lista.bandera_vacia] = listaVacia.value;
        }
    }

    const permitidos = porLlenar.value
        .filter((c) => c.permite_no_aplica)
        .map((c) => c.campo);

    guardando.value = true;
    errorSeccion.value = null;
    errores.value = {};

    try {
        const respuesta = await enviarJson<{
            data: EstadoMiSolicitudEmpleo;
            message: string;
        }>('POST', rutaGuardar.url({ seccion: s.clave }), {
            datos,
            no_aplica: noAplica.value.filter((c) => permitidos.includes(c)),
        });
        estado.value = respuesta.data;
        avisarExito(respuesta.message);

        const siguiente = avanzar
            ? (respuesta.data.secciones.find(
                  (x, i) => !x.lista_propia && i > indice.value,
              ) ?? respuesta.data.secciones.find((x) => !x.lista_propia))
            : undefined;

        abrir(siguiente?.clave ?? s.clave);
    } catch (e) {
        const mensaje = e instanceof Error ? e.message : 'No se pudo guardar.';
        errores.value =
            e instanceof ErrorSolicitud ? erroresPorCampo(e.errores) : {};

        // Con el error pintado junto a su dato basta; si no tiene control
        // en pantalla (una lista, la sección), se avisa arriba y con el modal.
        if (Object.keys(errores.value).length) {
            await nextTick();
            document
                .querySelector('[data-input-error]')
                ?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        } else {
            errorSeccion.value = mensaje;
            await mostrarError(mensaje);
        }
    } finally {
        guardando.value = false;
    }
}

const anterior = computed(() => secciones.value[indice.value - 1]);

function subtituloPaso(s: SeccionMiSolicitud): string {
    if (!s.lista_propia) {
        return `${s.por_llenar.length} por llenar`;
    }

    return s.en_revision.length ? 'En revisión por RH' : 'Listo';
}

// Anillo de avance (SVG): circunferencia de r=26.
const circunferencia = 2 * Math.PI * 26;
const trazo = computed(
    () => circunferencia * (1 - avance.value.porcentaje / 100),
);
</script>

<template>
    <Head title="Mi solicitud de empleo" />

    <div class="pagina-ancha flex flex-col gap-5">
        <!-- Registro por QR en la web: regresar al asistente. -->
        <Link
            v-if="estado.registro_abierto"
            :href="rutaRegistro()"
            class="inline-flex items-center gap-1.5 self-start rounded-full bg-salvia px-4 py-2 text-sm font-medium text-[var(--mrl-petroleo)] transicion-ui hover:bg-menta"
        >
            <ArrowLeft class="size-4" /> Volver a mi registro
        </Link>
        <!-- Encabezado con avance -->
        <header
            class="flex flex-wrap items-center gap-4 rounded-3xl border border-border/60 bg-gradient-to-br from-crema via-card to-salvia p-5 sm:gap-5 sm:p-7"
        >
            <div class="relative size-16 shrink-0" aria-hidden="true">
                <svg viewBox="0 0 64 64" class="size-16 -rotate-90">
                    <circle
                        cx="32"
                        cy="32"
                        r="26"
                        fill="none"
                        stroke-width="7"
                        class="stroke-[var(--mrl-borde)]"
                    />
                    <circle
                        cx="32"
                        cy="32"
                        r="26"
                        fill="none"
                        stroke-width="7"
                        stroke-linecap="round"
                        class="stroke-[var(--mrl-primary)] transition-[stroke-dashoffset] duration-700"
                        :stroke-dasharray="circunferencia"
                        :stroke-dashoffset="trazo"
                    />
                </svg>
                <span
                    class="absolute inset-0 grid place-items-center text-sm font-bold"
                    >{{ avance.porcentaje }}%</span
                >
            </div>
            <div class="min-w-0 flex-1 basis-56">
                <h1 class="text-xl font-semibold sm:text-2xl">
                    Mi solicitud de empleo
                </h1>
                <p class="text-sm text-muted-foreground" role="status">
                    <template v-if="avance.completo"
                        >Completa. Recursos Humanos ya puede generar tus
                        documentos.</template
                    >
                    <template v-else-if="terminado"
                        >Ya capturaste todo lo que te toca.</template
                    >
                    <template v-else
                        >{{ avance.frase }}. Se guarda por sección: puedes
                        seguir después.</template
                    >
                </p>
                <p
                    v-if="avance.en_revision > 0"
                    class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-info-soft px-3 py-1 text-xs font-medium"
                >
                    <Hourglass class="size-3.5 shrink-0" />
                    {{
                        avance.en_revision === 1
                            ? '1 dato en revisión por RH'
                            : `${avance.en_revision} datos en revisión por RH`
                    }}
                </p>
            </div>
        </header>

        <!-- Terminado -->
        <section
            v-if="terminado"
            class="flex entrada-suave flex-col items-center gap-3 rounded-3xl border border-border/60 bg-success-soft p-6 text-center sm:p-8"
        >
            <PartyPopper class="size-10 text-[var(--mrl-success)]" />
            <h2 class="text-lg font-semibold">¡Listo, gracias!</h2>
            <p class="max-w-md text-sm text-muted-foreground">
                <template v-if="avance.completo"
                    >Tu solicitud de empleo y tu estudio socioeconómico tienen
                    todos sus datos. Si algo está mal, pide una
                    corrección.</template
                >
                <template v-else-if="avance.en_revision > 0"
                    >Ya no tienes nada que capturar. Recursos Humanos está
                    revisando los datos personales que enviaste; te avisamos
                    cuando queden guardados en tu expediente.</template
                >
                <template v-else
                    >Ya no tienes nada que capturar. Lo que falta lo completa
                    Recursos Humanos.</template
                >
            </p>
            <dl
                v-if="enRevisionTotal.length"
                class="grid w-full max-w-xl gap-x-5 gap-y-2 rounded-2xl bg-card/70 p-4 text-left sm:grid-cols-2"
            >
                <div
                    v-for="c in enRevisionTotal"
                    :key="c.campo"
                    class="min-w-0"
                >
                    <dt class="text-xs text-muted-foreground">
                        {{ c.etiqueta }} · en revisión
                    </dt>
                    <dd class="text-sm break-words">
                        {{ c.propuesto ?? '—' }}
                    </dd>
                </div>
            </dl>
            <div class="flex flex-wrap justify-center gap-2">
                <Button as-child
                    ><Link
                        :href="
                            estado.registro_abierto
                                ? rutaRegistro()
                                : rutaPortal()
                        "
                        >{{
                            estado.registro_abierto
                                ? 'Volver a mi registro'
                                : 'Volver a mi portal'
                        }}</Link
                    ></Button
                >
                <Button as-child variant="outline"
                    ><Link :href="urlCorreccion">Pedir corrección</Link></Button
                >
            </div>
        </section>

        <div v-else class="grid gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <!-- Pasos -->
            <nav
                aria-label="Secciones"
                class="-mx-4 scroll-x-limpio flex gap-2 overflow-x-auto px-4 lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0"
            >
                <button
                    v-for="(s, i) in secciones"
                    :key="s.clave"
                    type="button"
                    class="flex shrink-0 items-center gap-3 rounded-2xl border px-3 py-2.5 text-left transicion-ui focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:w-full"
                    :class="
                        s.clave === actual
                            ? 'border-[var(--mrl-primary)] bg-card shadow-sm'
                            : 'border-transparent bg-card/60 hover:bg-card'
                    "
                    :aria-current="s.clave === actual ? 'step' : undefined"
                    @click="abrir(s.clave)"
                >
                    <span
                        class="grid size-7 shrink-0 place-items-center rounded-full text-xs font-semibold"
                        :class="
                            s.lista_propia
                                ? 'bg-[var(--mrl-success)] text-white'
                                : s.clave === actual
                                  ? 'bg-[var(--mrl-primary)] text-white'
                                  : 'bg-muted text-muted-foreground'
                        "
                    >
                        <Check v-if="s.lista_propia" class="size-4" />
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <span class="min-w-0">
                        <span
                            class="block text-sm font-medium whitespace-nowrap"
                            >{{ s.titulo }}</span
                        >
                        <span
                            class="block text-xs whitespace-nowrap text-muted-foreground"
                            >{{ subtituloPaso(s) }}</span
                        >
                    </span>
                </button>
            </nav>

            <!-- Sección actual -->
            <section
                v-if="seccion"
                :key="seccion.clave"
                class="flex min-w-0 entrada-suave flex-col gap-5 rounded-3xl border border-border/60 bg-card p-4 sm:p-7"
            >
                <div>
                    <p class="text-xs font-medium text-muted-foreground">
                        Paso {{ indice + 1 }} de {{ secciones.length }}
                    </p>
                    <h2 class="text-lg font-semibold">{{ seccion.titulo }}</h2>
                    <p
                        v-if="seccion.descripcion"
                        class="text-sm text-muted-foreground"
                    >
                        {{ seccion.descripcion }}
                    </p>
                    <p
                        v-if="porLlenar.length || listaEditable"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        <span class="font-semibold text-[var(--mrl-rojo)]"
                            >*</span
                        >
                        Obligatorio. Lo demás es opcional. Puedes guardar aunque
                        te falte algo y terminar después.
                    </p>
                </div>

                <p
                    v-if="errorSeccion"
                    class="rounded-2xl bg-danger-soft px-4 py-3 text-sm"
                    role="alert"
                >
                    {{ errorSeccion }}
                </p>

                <!-- Datos por llenar: los personales (a revisión de RH) y los de la solicitud -->
                <div
                    v-for="grupo in grupos"
                    :key="grupo.clave"
                    class="flex flex-col gap-4"
                    :class="
                        grupo.personales
                            ? 'rounded-2xl border border-border/60 bg-salvia/40 p-4'
                            : ''
                    "
                >
                    <div
                        v-if="grupo.personales"
                        class="flex items-start gap-2.5"
                    >
                        <UserRound
                            class="mt-0.5 size-4 shrink-0 text-[var(--mrl-petroleo)]"
                        />
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold">
                                Faltan en tu expediente
                            </h3>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="estado.registro_abierto"
                                    >Escríbelos aquí: se guardan con esta
                                    sección.</template
                                >
                                <template v-else
                                    >Escríbelos aquí mismo. Al guardar se envían
                                    a Recursos Humanos para revisión; no tienes
                                    que ir a otra pantalla ni capturarlos otra
                                    vez.</template
                                >
                            </p>
                        </div>
                    </div>
                    <div class="grid gap-x-5 gap-y-4 md:grid-cols-2">
                        <div
                            v-for="campo in grupo.campos"
                            :key="campo.campo"
                            class="flex min-w-0 flex-col gap-1.5"
                            :class="{
                                'md:col-span-2':
                                    campo.tipo === 'texto_largo' ||
                                    campo.tipo === 'multiselect' ||
                                    (campo.tipo === 'select' &&
                                        !grupo.personales &&
                                        campo.opciones.length > 4),
                            }"
                        >
                            <div
                                class="flex items-baseline justify-between gap-2"
                            >
                                <label
                                    :for="`mse-${campo.campo}`"
                                    class="text-sm font-medium"
                                    >{{ campo.etiqueta }}
                                    <span
                                        v-if="obligatorio(campo)"
                                        class="text-[var(--mrl-rojo)]"
                                        title="Obligatorio"
                                        >*<span class="sr-only">
                                            (obligatorio)</span
                                        ></span
                                    >
                                    <span
                                        v-else
                                        class="text-xs font-normal text-muted-foreground"
                                        >(opcional)</span
                                    ></label
                                >
                                <button
                                    v-if="campo.permite_no_aplica"
                                    type="button"
                                    class="shrink-0 rounded-full border px-2 py-0.5 text-xs transicion-ui"
                                    :class="
                                        noAplica.includes(campo.campo)
                                            ? 'border-[var(--mrl-primary)] bg-[var(--mrl-primary)] text-white'
                                            : 'border-border text-muted-foreground hover:border-[var(--mrl-primary)]'
                                    "
                                    :aria-pressed="
                                        noAplica.includes(campo.campo)
                                    "
                                    @click="alternarNoAplica(campo.campo)"
                                >
                                    No aplica
                                </button>
                            </div>
                            <CampoSolicitudControl
                                v-if="!noAplica.includes(campo.campo)"
                                :id="`mse-${campo.campo}`"
                                :campo="campo"
                                :model-value="borrador[campo.campo] ?? null"
                                @update:model-value="
                                    (v) => escribir(campo.campo, v)
                                "
                            />
                            <p
                                v-if="errores[campo.campo]"
                                class="text-xs text-destructive"
                                data-input-error
                                role="alert"
                            >
                                {{ errores[campo.campo] }}
                            </p>
                            <p
                                v-else-if="campo.ayuda"
                                class="text-xs text-muted-foreground"
                            >
                                {{ campo.ayuda }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Lista (dependientes, empleos, referencias) -->
                <div v-if="seccion.lista" class="flex flex-col gap-3">
                    <h3 class="text-sm font-semibold">
                        {{ seccion.lista.etiqueta }}
                        <span
                            v-if="listaEditable && seccion.lista.minimo > 0"
                            class="text-xs font-normal text-muted-foreground"
                            >(al menos {{ seccion.lista.minimo }})</span
                        >
                    </h3>
                    <template v-if="listaEditable">
                        <div
                            v-for="(fila, i) in filas"
                            :key="i"
                            class="rounded-2xl border border-border/70 bg-crema/40 p-4"
                        >
                            <div class="mb-3 flex items-center justify-between">
                                <span class="text-sm font-medium"
                                    >{{ seccion.lista.singular }}
                                    {{ i + 1 }}</span
                                >
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    :aria-label="`Quitar ${seccion.lista.singular.toLowerCase()} ${i + 1}`"
                                    @click="quitarFila(i)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                            <FilaListaSolicitud
                                v-model="filas[i]"
                                :campos="seccion.lista.campos"
                                :id-base="`mse-${seccion.clave}-${i}`"
                            />
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                v-if="!listaVacia"
                                type="button"
                                variant="outline"
                                :disabled="filas.length >= seccion.lista.maximo"
                                @click="agregarFila"
                            >
                                <Plus class="size-4" /> Agregar
                                {{ seccion.lista.singular.toLowerCase() }}
                            </Button>
                            <button
                                v-if="
                                    seccion.lista.bandera_vacia && !filas.length
                                "
                                type="button"
                                class="rounded-full border px-3 py-1.5 text-sm transicion-ui"
                                :class="
                                    listaVacia
                                        ? 'border-[var(--mrl-primary)] bg-[var(--mrl-primary)] text-white'
                                        : 'border-border hover:border-[var(--mrl-primary)]'
                                "
                                :aria-pressed="listaVacia"
                                @click="listaVacia = !listaVacia"
                            >
                                <Check
                                    v-if="listaVacia"
                                    class="mr-1 inline size-4"
                                />
                                {{ seccion.lista.etiqueta_vacia }}
                            </button>
                        </div>
                    </template>
                    <p v-else-if="seccion.lista.vacia" class="text-sm">
                        {{ seccion.lista.etiqueta_vacia }}
                    </p>
                    <ul v-else class="flex flex-col gap-2">
                        <li
                            v-for="(fila, i) in seccion.lista.filas"
                            :key="i"
                            class="rounded-2xl bg-muted/50 px-4 py-2 text-sm"
                        >
                            {{
                                seccion.lista.campos
                                    .slice(0, 3)
                                    .map((c) => texto(c, fila[c.campo] ?? null))
                                    .join(' · ')
                            }}
                        </li>
                    </ul>
                </div>

                <!-- Lo que ya envió y RH está revisando: no se pide otra vez -->
                <div
                    v-if="enRevision.length"
                    class="rounded-2xl bg-info-soft px-4 py-3"
                >
                    <p class="flex items-center gap-2 text-sm font-medium">
                        <Hourglass class="size-4 shrink-0" />
                        En revisión por Recursos Humanos ({{
                            enRevision.length
                        }})
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Ya los enviaste: no tienes que capturarlos otra vez.
                        Quedan en tu expediente cuando RH los apruebe.
                    </p>
                    <dl class="mt-3 grid gap-x-5 gap-y-2 sm:grid-cols-2">
                        <div
                            v-for="c in enRevision"
                            :key="c.campo"
                            class="min-w-0"
                        >
                            <dt class="text-xs text-muted-foreground">
                                {{ c.etiqueta }}
                            </dt>
                            <dd class="text-sm break-words">
                                {{ c.propuesto ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-for="id in porResponder"
                        :key="id"
                        class="mt-3 text-xs"
                    >
                        Recursos Humanos te pidió corregir algo de lo que
                        enviaste.
                        <Link
                            :href="verSolicitud.url(id)"
                            class="font-medium text-[var(--mrl-primary)] underline-offset-2 hover:underline"
                            >Ver y responder</Link
                        >
                    </p>
                </div>

                <!-- Falta, pero no le toca a la persona -->
                <p
                    v-if="soloRh.length"
                    class="rounded-2xl bg-muted/40 px-4 py-3 text-xs text-muted-foreground"
                >
                    Lo completa Recursos Humanos (no tienes que hacer nada):
                    {{ soloRh.map((c) => c.etiqueta).join(', ') }}.
                </p>

                <!-- Lo que ya está registrado -->
                <div v-if="registrados.length" class="rounded-2xl bg-muted/40">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm"
                        :aria-expanded="verRegistrados"
                        @click="verRegistrados = !verRegistrados"
                    >
                        <span class="flex items-center gap-2">
                            <Lock class="size-4 text-muted-foreground" />
                            Ya registrado ({{ registrados.length }}): no se
                            vuelve a pedir
                        </span>
                        <ChevronDown
                            class="size-4 shrink-0 transition-transform"
                            :class="{ 'rotate-180': verRegistrados }"
                        />
                    </button>
                    <div v-if="verRegistrados" class="px-4 pb-4">
                        <dl class="grid gap-x-5 gap-y-2 sm:grid-cols-2">
                            <div
                                v-for="c in registrados"
                                :key="c.campo"
                                class="min-w-0"
                            >
                                <dt class="text-xs text-muted-foreground">
                                    {{ c.etiqueta }}
                                </dt>
                                <dd class="text-sm break-words">
                                    {{
                                        c.no_aplica
                                            ? 'No aplica'
                                            : (c.valor_legible ??
                                              texto(c, c.valor))
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-xs text-muted-foreground">
                            ¿Algo está mal?
                            <Link
                                :href="urlCorreccion"
                                class="font-medium text-[var(--mrl-primary)] hover:underline"
                                >Pide una corrección</Link
                            >.
                        </p>
                    </div>
                </div>

                <p
                    v-if="!porLlenar.length && !listaEditable"
                    class="rounded-2xl bg-success-soft px-4 py-3 text-sm"
                >
                    {{
                        seccion.lista_propia
                            ? 'En esta sección ya no te falta nada.'
                            : 'Esta sección no tiene datos que puedas capturar.'
                    }}
                </p>

                <!-- Navegación -->
                <div
                    class="sticky bottom-0 -mx-4 -mb-4 flex flex-wrap items-center justify-between gap-2 rounded-b-3xl border-t border-border/60 bg-card/95 px-4 py-3 backdrop-blur sm:-mx-7 sm:-mb-7 sm:px-7 sm:py-4"
                >
                    <Button
                        v-if="anterior"
                        type="button"
                        variant="ghost"
                        :disabled="guardando"
                        @click="abrir(anterior.clave)"
                    >
                        <ArrowLeft class="size-4" />
                        <span class="max-w-28 truncate sm:max-w-none">{{
                            anterior.titulo
                        }}</span>
                    </Button>
                    <span v-else />
                    <div class="flex items-center gap-3">
                        <span
                            v-if="faltanAqui > 0"
                            class="hidden text-xs text-muted-foreground sm:inline"
                            >{{
                                faltanAqui === 1
                                    ? 'Falta 1 obligatorio aquí'
                                    : `Faltan ${faltanAqui} obligatorios aquí`
                            }}</span
                        >
                        <Button
                            type="button"
                            :disabled="guardando"
                            @click="guardar(true)"
                        >
                            {{ guardando ? 'Guardando…' : 'Guardar y seguir' }}
                            <ArrowRight class="size-4" />
                        </Button>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
