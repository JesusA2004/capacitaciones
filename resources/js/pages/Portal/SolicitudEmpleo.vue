<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    ChevronDown,
    Lock,
    PartyPopper,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CampoSolicitudControl from '@/components/incorporacion/CampoSolicitudControl.vue';
import FilaListaSolicitud from '@/components/incorporacion/FilaListaSolicitud.vue';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { enviarJson } from '@/lib/http';
import { dashboard } from '@/routes';
import { show as rutaRegistro } from '@/routes/mi-registro';
import {
    index as rutaPortal,
    solicitudEmpleo as rutaPagina,
} from '@/routes/portal';
import { guardar as rutaGuardar } from '@/routes/portal/solicitud-empleo';
import { index as indexSolicitudes } from '@/routes/solicitudes';
import type {
    CampoListaSolicitud,
    CampoSolicitud,
    EstadoMiSolicitudEmpleo,
    SeccionSolicitud,
    ValorSolicitud,
} from '@/types';

/**
 * «Mi solicitud de empleo»: lo mismo que pide la app, sección por sección,
 * para quien entra desde la web (iPhone/Mac). Solo muestra lo que falta;
 * lo ya registrado queda guardado y se corrige por solicitud de corrección.
 * Reglas en el backend (SolicitudEmpleoRegistroService).
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

const secciones = computed(() => estado.value.secciones);
const pendientes = computed(() => secciones.value.filter((s) => !s.completa));
const faltanTotal = computed(
    () => estado.value.completitud.total - estado.value.completitud.completos,
);
const terminado = computed(() => pendientes.value.length === 0);

const actual = ref<string>(
    pendientes.value[0]?.clave ?? secciones.value[0]?.clave ?? '',
);
const seccion = computed<SeccionSolicitud | undefined>(() =>
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

/** Valor vigente para evaluar condiciones (borrador o lo ya guardado). */
function valorDe(clave: string): ValorSolicitud {
    if (clave in borrador.value) {
        return borrador.value[clave] ?? null;
    }

    for (const s of secciones.value) {
        const c = s.campos.find((x) => x.campo === clave);

        if (c) {
            return c.valor;
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

const porLlenar = computed(() =>
    (seccion.value?.campos ?? []).filter(
        (c) =>
            !c.bloqueado &&
            c.origen === 'solicitud' &&
            (condicionCumple(c) || c.valor !== null),
    ),
);
const registrados = computed(() =>
    (seccion.value?.campos ?? []).filter((c) => c.bloqueado),
);
/** Datos personales (domicilio, CURP…) que faltan: se piden por solicitud. */
const faltanPersonales = computed(() =>
    (seccion.value?.campos ?? []).filter(
        (c) =>
            c.origen === 'datos_personales' &&
            c.requerido &&
            (c.valor === null || c.valor === ''),
    ),
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

/** Lo que falta en pantalla ahora mismo (para el contador del botón). */
const faltanAqui = computed(
    () =>
        porLlenar.value.filter(
            (c) =>
                c.requerido &&
                condicionCumple(c) &&
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

    try {
        const respuesta = await enviarJson<{
            data: EstadoMiSolicitudEmpleo;
            message: string;
        }>('POST', rutaGuardar.url({ seccion: s.clave }), {
            datos,
            no_aplica: noAplica.value.filter((c) => permitidos.includes(c)),
        });
        estado.value = respuesta.data;

        const siguiente = avanzar
            ? (respuesta.data.secciones.find(
                  (x, i) => !x.completa && i > indice.value,
              ) ?? respuesta.data.secciones.find((x) => !x.completa))
            : undefined;

        abrir(siguiente?.clave ?? s.clave);
    } catch (e) {
        errorSeccion.value =
            e instanceof Error ? e.message : 'No se pudo guardar.';
        await mostrarError(errorSeccion.value);
    } finally {
        guardando.value = false;
    }
}

const anterior = computed(() => secciones.value[indice.value - 1]);

// Anillo de avance (SVG): circunferencia de r=26.
const circunferencia = 2 * Math.PI * 26;
const trazo = computed(
    () => circunferencia * (1 - estado.value.completitud.porcentaje / 100),
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
            class="flex flex-wrap items-center gap-5 rounded-3xl border border-border/60 bg-gradient-to-br from-crema via-card to-salvia p-5 sm:p-7"
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
                    >{{ estado.completitud.porcentaje }}%</span
                >
            </div>
            <div class="min-w-0 flex-1">
                <h1 class="text-xl font-semibold sm:text-2xl">
                    Mi solicitud de empleo
                </h1>
                <p class="text-sm text-muted-foreground">
                    <template v-if="terminado"
                        >Completa. Recursos Humanos ya puede generar tus
                        documentos.</template
                    >
                    <template v-else
                        >{{
                            faltanTotal === 1
                                ? 'Te falta 1 dato'
                                : `Te faltan ${faltanTotal} datos`
                        }}
                        en {{ pendientes.length }}
                        {{ pendientes.length === 1 ? 'sección' : 'secciones' }}.
                        Se guarda por sección: puedes seguir después.</template
                    >
                </p>
            </div>
        </header>

        <!-- Terminado -->
        <section
            v-if="terminado"
            class="flex entrada-suave flex-col items-center gap-3 rounded-3xl border border-border/60 bg-success-soft p-8 text-center"
        >
            <PartyPopper class="size-10 text-[var(--mrl-success)]" />
            <h2 class="text-lg font-semibold">¡Listo, gracias!</h2>
            <p class="max-w-md text-sm text-muted-foreground">
                Tu solicitud de empleo y tu estudio socioeconómico tienen todos
                sus datos. Si algo está mal, pide una corrección.
            </p>
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
                            s.completa
                                ? 'bg-[var(--mrl-success)] text-white'
                                : s.clave === actual
                                  ? 'bg-[var(--mrl-primary)] text-white'
                                  : 'bg-muted text-muted-foreground'
                        "
                    >
                        <Check v-if="s.completa" class="size-4" />
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <span class="min-w-0">
                        <span
                            class="block text-sm font-medium whitespace-nowrap"
                            >{{ s.titulo }}</span
                        >
                        <span
                            class="block text-xs whitespace-nowrap text-muted-foreground"
                            >{{
                                s.completa
                                    ? 'Listo'
                                    : `${s.faltantes.length} por llenar`
                            }}</span
                        >
                    </span>
                </button>
            </nav>

            <!-- Sección actual -->
            <section
                v-if="seccion"
                :key="seccion.clave"
                class="flex min-w-0 entrada-suave flex-col gap-5 rounded-3xl border border-border/60 bg-card p-5 sm:p-7"
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
                </div>

                <div
                    v-if="porLlenar.length"
                    class="grid gap-x-5 gap-y-4 md:grid-cols-2"
                >
                    <div
                        v-for="campo in porLlenar"
                        :key="campo.campo"
                        class="flex min-w-0 flex-col gap-1.5"
                        :class="{
                            'md:col-span-2':
                                campo.tipo === 'texto_largo' ||
                                campo.tipo === 'multiselect' ||
                                campo.opciones.length > 4,
                        }"
                    >
                        <div class="flex items-baseline justify-between gap-2">
                            <label
                                :for="`mse-${campo.campo}`"
                                class="text-sm font-medium"
                                >{{ campo.etiqueta
                                }}<span
                                    v-if="
                                        campo.requerido &&
                                        condicionCumple(campo)
                                    "
                                    class="text-[var(--mrl-rojo)]"
                                    aria-hidden="true"
                                >
                                    *</span
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
                                :aria-pressed="noAplica.includes(campo.campo)"
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
                                (v) => (borrador[campo.campo] = v)
                            "
                        />
                        <p
                            v-if="campo.ayuda"
                            class="text-xs text-muted-foreground"
                        >
                            {{ campo.ayuda }}
                        </p>
                    </div>
                </div>

                <!-- Lista (dependientes, empleos, referencias) -->
                <div v-if="seccion.lista" class="flex flex-col gap-3">
                    <h3 class="text-sm font-semibold">
                        {{ seccion.lista.etiqueta }}
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

                <!-- Datos personales faltantes: se piden aparte -->
                <div
                    v-if="faltanPersonales.length"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-warning-soft px-4 py-3 text-sm"
                >
                    <span
                        >Falta en tus datos personales:
                        <strong>{{
                            faltanPersonales.map((c) => c.etiqueta).join(', ')
                        }}</strong></span
                    >
                    <Link
                        :href="urlCorreccion"
                        class="font-medium text-[var(--mrl-primary)] underline-offset-2 hover:underline"
                        >Enviarlos a RH</Link
                    >
                </div>

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
                            Ya registrado ({{ registrados.length }})
                        </span>
                        <ChevronDown
                            class="size-4 transition-transform"
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
                    v-if="
                        !porLlenar.length &&
                        !listaEditable &&
                        !faltanPersonales.length
                    "
                    class="rounded-2xl bg-success-soft px-4 py-3 text-sm"
                >
                    Esta sección ya está completa.
                </p>

                <!-- Navegación -->
                <div
                    class="sticky bottom-0 -mx-5 -mb-5 flex flex-wrap items-center justify-between gap-2 rounded-b-3xl border-t border-border/60 bg-card/95 px-5 py-4 backdrop-blur sm:-mx-7 sm:-mb-7 sm:px-7"
                >
                    <Button
                        v-if="anterior"
                        type="button"
                        variant="ghost"
                        :disabled="guardando"
                        @click="abrir(anterior.clave)"
                    >
                        <ArrowLeft class="size-4" /> {{ anterior.titulo }}
                    </Button>
                    <span v-else />
                    <div class="flex items-center gap-3">
                        <span
                            v-if="faltanAqui > 0"
                            class="hidden text-xs text-muted-foreground sm:inline"
                            >{{
                                faltanAqui === 1
                                    ? 'Falta 1 dato aquí'
                                    : `Faltan ${faltanAqui} datos aquí`
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
