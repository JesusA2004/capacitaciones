<script setup lang="ts">
import {
    CalendarDays,
    CheckCircle2,
    ClipboardList,
    Info,
    Lock,
    Plus,
    RotateCcw,
    Smartphone,
    Trash2,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
import Casilla from '@/components/Common/Casilla.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import CampoSolicitudControl from '@/components/incorporacion/CampoSolicitudControl.vue';
import FilaListaSolicitud from '@/components/incorporacion/FilaListaSolicitud.vue';
import PedirInformacionBoton from '@/components/Rh/PedirInformacionBoton.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useAlertas } from '@/composables/useAlertas';
import { enviarJson, getJson } from '@/lib/http';
import {
    guardar as rutaGuardar,
    show as rutaEstado,
} from '@/routes/rh/solicitud-empleo';
import type {
    CampoListaSolicitud,
    CampoSolicitud,
    DocumentoCompletitudSolicitud,
    EmisionDocumentoIngreso,
    EstadoSolicitudEmpleo,
    SeccionSolicitud,
    ValorSolicitud,
} from '@/types';

/**
 * RH: lo que la persona capturó para la Solicitud de empleo y el Estudio
 * socioeconómico, sección por sección, y completar SOLO lo que falta (lo ya
 * capturado se muestra bloqueado: se corrige por solicitud de corrección).
 * La vista previa y la generación de ambos PDF están en «Documentos»
 * (sección «Solicitud de empleo y estudio socioeconómico»).
 *
 * Resumen compacto: un renglón por documento (avance, «faltan N datos» y su
 * fecha de emisión) y una rejilla de secciones; los datos vacíos se ven
 * solo al abrir la sección, como los campos por llenar.
 */
const props = defineProps<{ colaboradorId: number }>();
const emit = defineEmits<{ actualizado: [] }>();

const { mostrarExito, mostrarError } = useAlertas();
const estado = ref<EstadoSolicitudEmpleo | null>(null);
const cargando = ref(true);
const guardando = ref<string | null>(null);
const abierta = ref<string | null>(null);
// Borradores por sección: solo lo que RH captura en campos vacíos.
const borradores = ref<Record<string, Record<string, ValorSolicitud>>>({});
const noAplica = ref<Record<string, string[]>>({});
const filas = ref<Record<string, Record<string, ValorSolicitud>[]>>({});
const listaVacia = ref<Record<string, boolean>>({});

const documentos = computed(() =>
    Object.values(estado.value?.completitud.documentos ?? {}),
);

const seccionAbierta = computed<SeccionSolicitud | null>(
    () =>
        estado.value?.secciones.find((s) => s.clave === abierta.value) ?? null,
);

/**
 * Avance aproximado de un documento para el anillo: datos del catálogo que
 * lo alimentan contra los que faltan. El número exacto es «faltan N datos».
 */
function avanceDocumento(doc: DocumentoCompletitudSolicitud): number {
    if (doc.completo) {
        return 100;
    }

    const total = (estado.value?.secciones ?? []).reduce(
        (suma, s) =>
            suma +
            s.campos.filter(
                (c) =>
                    c.documentos.includes(doc.clave) &&
                    (c.requerido || c.requerido_si !== null),
            ).length,
        0,
    );

    if (total === 0) {
        return 0;
    }

    const faltan = Math.min(doc.faltantes.length, total);

    return Math.floor(((total - faltan) * 100) / total);
}

const RADIO = 16;
const CIRCUNFERENCIA = 2 * Math.PI * RADIO;

// «Fecha de emisión» de cada documento (la elige RH; se imprime en todas
// las fechas del PDF). Se guarda al elegirla; la generación (en
// «Documentos») ya la usa.
const emision = computed<EmisionDocumentoIngreso[]>(
    () => estado.value?.emision ?? [],
);
const guardandoFecha = ref<string | null>(null);
const editandoFecha = ref<string | null>(null);
const formatoCorto = new Intl.DateTimeFormat('es-MX', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

function fechaCorta(iso: string | null): string {
    return iso
        ? formatoCorto.format(new Date(`${iso}T00:00:00Z`)).replace('.', '')
        : '';
}

/** Cada documento con su fecha de emisión (misma clave). */
const tarjetasDocumento = computed(() =>
    documentos.value.map((doc) => ({
        doc,
        em: emision.value.find((e) => e.clave === doc.clave) ?? null,
    })),
);

async function guardarFecha(
    doc: EmisionDocumentoIngreso,
    fecha: string | null,
): Promise<void> {
    if ((fecha ?? '') === '' && !doc.elegida) {
        return;
    }

    if (fecha === doc.fecha && doc.elegida) {
        editandoFecha.value = null;

        return;
    }

    guardandoFecha.value = doc.clave;

    try {
        const respuesta = await enviarJson<{
            data: EstadoSolicitudEmpleo;
            message: string;
        }>(
            'POST',
            rutaGuardar.url({
                colaborador: props.colaboradorId,
                seccion: estado.value?.secciones[0]?.clave ?? 'personal',
            }),
            { fechas_emision: { [doc.clave]: fecha || null } },
        );

        // Solo la fecha: los borradores de las secciones no se pierden.
        if (estado.value) {
            estado.value = { ...estado.value, emision: respuesta.data.emision };
        }

        editandoFecha.value = null;
        emit('actualizado');
    } catch (e) {
        await mostrarError(
            e instanceof Error
                ? e.message
                : 'No se pudo guardar la fecha de emisión.',
        );
    } finally {
        guardandoFecha.value = null;
    }
}

async function cargar(): Promise<void> {
    cargando.value = true;

    try {
        const respuesta = await getJson<{ data: EstadoSolicitudEmpleo }>(
            rutaEstado.url(props.colaboradorId),
        );
        aplicar(respuesta.data);
    } catch {
        estado.value = null;
    } finally {
        cargando.value = false;
    }
}

function aplicar(nuevo: EstadoSolicitudEmpleo): void {
    estado.value = nuevo;
    borradores.value = {};
    noAplica.value = {};
    filas.value = {};
    listaVacia.value = {};

    for (const seccion of nuevo.secciones) {
        borradores.value[seccion.clave] = {};
        noAplica.value[seccion.clave] = seccion.campos
            .filter((c) => c.no_aplica)
            .map((c) => c.campo);

        for (const campo of seccion.campos) {
            if (!campo.bloqueado) {
                borradores.value[seccion.clave][campo.campo] =
                    campo.valor ?? campo.sugerencia;
            }
        }

        if (seccion.lista) {
            filas.value[seccion.clave] = seccion.lista.filas.map((f) => ({
                ...f,
            }));
            listaVacia.value[seccion.clave] = seccion.lista.vacia;
        }
    }
}

function editables(seccion: SeccionSolicitud): CampoSolicitud[] {
    return seccion.campos.filter(
        (c) => !c.bloqueado && c.origen === 'solicitud',
    );
}

/** Vacíos que viven en los datos personales del expediente (no aquí). */
function deExpediente(seccion: SeccionSolicitud): CampoSolicitud[] {
    return seccion.campos.filter(
        (c) => !c.bloqueado && c.origen === 'datos_personales',
    );
}

function capturados(seccion: SeccionSolicitud): CampoSolicitud[] {
    return seccion.campos.filter((c) => c.bloqueado);
}

function puedeGuardar(seccion: SeccionSolicitud): boolean {
    return (
        editables(seccion).length > 0 ||
        (seccion.lista !== null && !seccion.lista.bloqueada)
    );
}

function texto(
    campo: CampoSolicitud | CampoListaSolicitud,
    valor: ValorSolicitud,
): string {
    if (valor === null || valor === '') {
        return '—';
    }

    if (typeof valor === 'boolean') {
        return valor ? 'Sí' : 'No';
    }

    if (Array.isArray(valor)) {
        return valor.length === 0
            ? 'Ninguno'
            : valor
                  .map(
                      (v) =>
                          campo.opciones.find((o) => o.value === v)?.label ?? v,
                  )
                  .join(', ');
    }

    return (
        campo.opciones.find((o) => o.value === String(valor))?.label ??
        String(valor)
    );
}

function vacio(valor: ValorSolicitud | undefined): boolean {
    return (
        valor === null ||
        valor === undefined ||
        valor === '' ||
        (Array.isArray(valor) && valor.length === 0)
    );
}

function esAncho(campo: CampoSolicitud): boolean {
    return (
        campo.tipo === 'texto_largo' ||
        campo.tipo === 'multiselect' ||
        (campo.tipo === 'select' &&
            campo.opciones.length <= 4 &&
            campo.opciones.length > 2)
    );
}

function marcarNoAplica(seccion: string, campo: string, si: boolean): void {
    const actuales = noAplica.value[seccion] ?? [];
    noAplica.value[seccion] = si
        ? [...actuales.filter((c) => c !== campo), campo]
        : actuales.filter((c) => c !== campo);
}

function agregarFila(seccion: SeccionSolicitud): void {
    if (!seccion.lista) {
        return;
    }

    const vacia: Record<string, ValorSolicitud> = {};
    seccion.lista.campos.forEach((c) => (vacia[c.campo] = null));
    filas.value[seccion.clave] = [...(filas.value[seccion.clave] ?? []), vacia];
    listaVacia.value[seccion.clave] = false;
}

function quitarFila(seccion: string, indice: number): void {
    filas.value[seccion] = (filas.value[seccion] ?? []).filter(
        (_, i) => i !== indice,
    );
}

function cambiarFila(
    seccion: string,
    indice: number,
    fila: Record<string, ValorSolicitud>,
): void {
    const actuales = [...(filas.value[seccion] ?? [])];
    actuales[indice] = fila;
    filas.value[seccion] = actuales;
}

async function abrirSeccion(clave: string): Promise<void> {
    abierta.value = abierta.value === clave ? null : clave;

    if (abierta.value) {
        await nextTick();
        document
            .getElementById('se-editor')
            ?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

async function guardarSeccion(seccion: SeccionSolicitud): Promise<void> {
    guardando.value = seccion.clave;
    const datos: Record<string, unknown> = {};

    for (const campo of editables(seccion)) {
        const valor = borradores.value[seccion.clave]?.[campo.campo];

        if (valor !== null && valor !== undefined && valor !== '') {
            datos[campo.campo] = valor;
        }
    }

    if (seccion.lista && !seccion.lista.bloqueada) {
        datos[seccion.lista.clave] = filas.value[seccion.clave] ?? [];

        if (seccion.lista.bandera_vacia) {
            datos[seccion.lista.bandera_vacia] =
                listaVacia.value[seccion.clave] ?? false;
        }
    }

    const permitidos = editables(seccion)
        .filter((c) => c.permite_no_aplica)
        .map((c) => c.campo);

    try {
        const respuesta = await enviarJson<{
            data: EstadoSolicitudEmpleo;
            message: string;
        }>(
            'POST',
            rutaGuardar.url({
                colaborador: props.colaboradorId,
                seccion: seccion.clave,
            }),
            {
                datos,
                no_aplica: (noAplica.value[seccion.clave] ?? []).filter((c) =>
                    permitidos.includes(c),
                ),
            },
        );
        aplicar(respuesta.data);
        await mostrarExito(respuesta.message);
        emit('actualizado');
    } catch (e) {
        await mostrarError(
            e instanceof Error ? e.message : 'No se pudo guardar la sección.',
        );
    } finally {
        guardando.value = null;
    }
}

onMounted(cargar);
</script>

<template>
    <section
        class="flex min-w-0 flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 sm:p-5"
        aria-label="Solicitud de empleo y estudio socioeconómico"
    >
        <header class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <ClipboardList class="size-4 shrink-0" />
                    Solicitud de empleo y estudio socioeconómico
                </h2>
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    Lo capturado por la persona. Tú solo llenas lo vacío; la
                    vista previa está en «Documentos».
                </p>
            </div>
            <Badge
                v-if="estado"
                :variant="estado.completitud.completo ? 'success' : 'warning'"
            >
                {{ estado.completitud.porcentaje }} % completo
            </Badge>
        </header>

        <p v-if="cargando" class="text-sm text-[var(--mrl-texto-suave)]">
            Cargando…
        </p>
        <p v-else-if="!estado" class="text-sm text-[var(--mrl-texto-suave)]">
            No tienes acceso a la solicitud de empleo de esta persona.
        </p>

        <template v-else>
            <div
                v-if="!estado.completitud.completo"
                class="flex flex-wrap items-center gap-3 rounded-xl bg-info-soft/70 px-3 py-2 text-xs"
            >
                <Smartphone class="size-3.5 shrink-0" />
                <span class="min-w-0 flex-1">
                    La persona puede llenarla ella misma en
                    <strong>Mi portal → Mi solicitud de empleo</strong>; no hace
                    falta que RH la capture.
                </span>
                <PedirInformacionBoton :colaborador-id="colaboradorId" />
            </div>

            <!-- Un renglón por documento: avance + fecha de emisión -->
            <ul class="grid grid-cols-1 gap-2 md:grid-cols-2">
                <li
                    v-for="{ doc, em } in tarjetasDocumento"
                    :key="doc.clave"
                    class="flex min-w-0 flex-col gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] p-3"
                >
                    <div class="flex items-center gap-3">
                        <svg
                            viewBox="0 0 40 40"
                            class="size-10 shrink-0 -rotate-90"
                            role="img"
                            :aria-label="`${avanceDocumento(doc)} % de ${doc.nombre}`"
                        >
                            <circle
                                cx="20"
                                cy="20"
                                :r="RADIO"
                                fill="none"
                                stroke-width="4"
                                class="stroke-[var(--mrl-borde)]"
                            />
                            <circle
                                cx="20"
                                cy="20"
                                :r="RADIO"
                                fill="none"
                                stroke-width="4"
                                stroke-linecap="round"
                                :stroke-dasharray="CIRCUNFERENCIA"
                                :stroke-dashoffset="
                                    CIRCUNFERENCIA *
                                    (1 - avanceDocumento(doc) / 100)
                                "
                                :class="
                                    doc.completo
                                        ? 'stroke-[var(--mrl-verde)]'
                                        : 'stroke-[var(--mrl-dorado)]'
                                "
                                class="transition-[stroke-dashoffset] duration-300 motion-reduce:transition-none"
                            />
                        </svg>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ doc.nombre }}
                            </p>
                            <p
                                v-if="doc.completo"
                                class="flex items-center gap-1 text-xs text-[var(--mrl-verde)]"
                            >
                                <CheckCircle2 class="size-3.5" /> Lista para
                                generar
                            </p>
                            <p
                                v-else
                                class="text-xs text-[var(--mrl-texto-suave)]"
                            >
                                Faltan
                                <strong class="text-[var(--mrl-texto)]">{{
                                    doc.faltantes.length
                                }}</strong>
                                {{
                                    doc.faltantes.length === 1
                                        ? 'dato'
                                        : 'datos'
                                }}
                            </p>
                        </div>
                    </div>

                    <template v-if="em">
                        <div
                            class="flex flex-col gap-1.5 border-t border-[var(--mrl-borde)] pt-2 text-xs"
                        >
                            <p class="flex flex-wrap items-center gap-x-1.5">
                                <CalendarDays
                                    class="size-3.5 shrink-0 text-[var(--mrl-navy)]"
                                />
                                <span class="text-[var(--mrl-texto-suave)]"
                                    >Fecha de emisión:</span
                                >
                                <strong>{{ fechaCorta(em.fecha) }}</strong>
                                <span class="text-[var(--mrl-texto-suave)]">{{
                                    guardandoFecha === em.clave
                                        ? '(guardando…)'
                                        : em.elegida
                                          ? '(elegida)'
                                          : '(hoy)'
                                }}</span>
                                <span aria-hidden="true">·</span>
                                <button
                                    type="button"
                                    class="font-medium text-[var(--mrl-navy)] underline-offset-2 hover:underline"
                                    :aria-expanded="editandoFecha === em.clave"
                                    :aria-controls="`emision-${em.clave}`"
                                    @click="
                                        editandoFecha =
                                            editandoFecha === em.clave
                                                ? null
                                                : em.clave
                                    "
                                >
                                    {{
                                        editandoFecha === em.clave
                                            ? 'Cerrar'
                                            : 'Cambiar'
                                    }}
                                </button>
                                <AyudaBoton
                                    titulo="Fecha de emisión"
                                    texto="Es la que se imprime en el documento (y la edad a esa fecha); sin elegir, sale con la del día en que se genere."
                                />
                            </p>
                            <div
                                v-if="editandoFecha === em.clave"
                                class="flex flex-col gap-1.5"
                            >
                                <DatePicker
                                    :id="`emision-${em.clave}`"
                                    :model-value="em.fecha"
                                    :min-value="em.minimo"
                                    :max-value="em.maximo"
                                    :disabled="guardandoFecha === em.clave"
                                    atajos
                                    dia-semana
                                    @update:model-value="
                                        (v) => guardarFecha(em, v)
                                    "
                                />
                                <p
                                    class="flex flex-wrap items-center gap-x-2 text-[var(--mrl-texto-suave)]"
                                >
                                    <span v-if="em.fecha_ingreso"
                                        >Ingreso:
                                        {{ fechaCorta(em.fecha_ingreso) }}</span
                                    >
                                    <button
                                        v-if="em.elegida"
                                        type="button"
                                        class="inline-flex items-center gap-1 font-medium text-[var(--mrl-navy)] underline-offset-2 hover:underline"
                                        :disabled="guardandoFecha === em.clave"
                                        @click="guardarFecha(em, null)"
                                    >
                                        <RotateCcw class="size-3" /> Usar la del
                                        día en que se genere
                                    </button>
                                </p>
                            </div>
                            <p
                                v-if="em.aviso || em.desactualizado"
                                class="flex flex-wrap gap-1.5"
                            >
                                <span
                                    v-if="em.aviso"
                                    class="inline-flex max-w-full items-start gap-1 rounded-full bg-warning-soft px-2 py-0.5"
                                >
                                    <TriangleAlert
                                        class="mt-0.5 size-3 shrink-0"
                                    />
                                    <span class="min-w-0">{{ em.aviso }}</span>
                                </span>
                                <span
                                    v-if="em.desactualizado"
                                    class="inline-flex max-w-full items-start gap-1 rounded-full bg-info-soft px-2 py-0.5"
                                >
                                    <Info class="mt-0.5 size-3 shrink-0" />
                                    <span class="min-w-0"
                                        >Generado con
                                        {{ fechaCorta(em.generado_con) }}:
                                        regenéralo en «Documentos»</span
                                    >
                                </span>
                            </p>
                        </div>
                    </template>
                </li>
            </ul>

            <!-- Rejilla de secciones -->
            <div>
                <p
                    class="mb-2 text-xs font-semibold text-[var(--mrl-texto-suave)]"
                >
                    Secciones
                </p>
                <ul
                    class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-5"
                >
                    <li
                        v-for="seccion in estado.secciones"
                        :key="seccion.clave"
                    >
                        <button
                            type="button"
                            class="flex h-full w-full tarjeta-interactiva flex-col gap-1.5 rounded-xl border p-2.5 text-left"
                            :class="
                                abierta === seccion.clave
                                    ? 'border-[var(--mrl-petroleo)] bg-salvia'
                                    : 'border-[var(--mrl-borde)] bg-[var(--mrl-fondo)]'
                            "
                            :aria-expanded="abierta === seccion.clave"
                            aria-controls="se-editor"
                            @click="abrirSeccion(seccion.clave)"
                        >
                            <span
                                class="line-clamp-2 text-xs leading-tight font-medium"
                                >{{ seccion.titulo }}</span
                            >
                            <span
                                class="mt-auto h-1 overflow-hidden rounded-full bg-[var(--mrl-borde)]"
                                aria-hidden="true"
                            >
                                <span
                                    class="block h-full rounded-full"
                                    :class="
                                        seccion.completa
                                            ? 'bg-[var(--mrl-verde)]'
                                            : 'bg-[var(--mrl-dorado)]'
                                    "
                                    :style="{
                                        width: `${seccion.completa ? 100 : seccion.porcentaje}%`,
                                    }"
                                />
                            </span>
                            <span
                                v-if="seccion.completa"
                                class="flex items-center gap-1 text-[11px] text-[var(--mrl-verde)]"
                            >
                                <CheckCircle2 class="size-3" />
                                {{
                                    seccion.origen === 'rh'
                                        ? 'Completa (RH)'
                                        : 'Completa'
                                }}
                            </span>
                            <span
                                v-else
                                class="text-[11px] text-[var(--mrl-texto-suave)]"
                                >{{ seccion.faltantes.length }} por llenar</span
                            >
                        </button>
                    </li>
                </ul>
            </div>

            <!-- Editor de la sección abierta -->
            <article
                v-if="seccionAbierta"
                id="se-editor"
                :key="seccionAbierta.clave"
                class="flex scroll-mt-4 flex-col gap-4 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] p-3 sm:p-4"
                :aria-label="seccionAbierta.titulo"
            >
                <header class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold">
                            {{ seccionAbierta.titulo }}
                        </h3>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            {{ seccionAbierta.descripcion }}
                        </p>
                    </div>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label="Cerrar sección"
                        @click="abierta = null"
                    >
                        <X class="size-4" />
                    </Button>
                </header>

                <!-- Por llenar (RH) -->
                <div
                    v-if="editables(seccionAbierta).length"
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2"
                >
                    <div
                        v-for="campo in editables(seccionAbierta)"
                        :key="campo.campo"
                        class="flex min-w-0 flex-col gap-1.5"
                        :class="{ 'sm:col-span-2': esAncho(campo) }"
                    >
                        <div class="flex flex-wrap items-center gap-1.5">
                            <Label :for="`se-${campo.campo}`"
                                >{{ campo.etiqueta
                                }}<span
                                    v-if="campo.requerido"
                                    class="text-[var(--mrl-rojo)]"
                                >
                                    *</span
                                ></Label
                            >
                            <AyudaBoton
                                v-if="campo.ayuda"
                                :titulo="campo.etiqueta"
                                :texto="campo.ayuda"
                            />
                            <span
                                v-if="
                                    campo.requerido &&
                                    vacio(
                                        borradores[seccionAbierta.clave]?.[
                                            campo.campo
                                        ],
                                    ) &&
                                    !(
                                        noAplica[seccionAbierta.clave] ?? []
                                    ).includes(campo.campo)
                                "
                                class="rounded-full bg-warning-soft px-1.5 py-0.5 text-[10px] font-medium"
                                >Falta</span
                            >
                        </div>
                        <CampoSolicitudControl
                            v-if="
                                !(
                                    noAplica[seccionAbierta.clave] ?? []
                                ).includes(campo.campo)
                            "
                            :id="`se-${campo.campo}`"
                            :campo="campo"
                            :model-value="
                                borradores[seccionAbierta.clave]?.[
                                    campo.campo
                                ] ?? null
                            "
                            @update:model-value="
                                (v) =>
                                    (borradores[seccionAbierta!.clave][
                                        campo.campo
                                    ] = v)
                            "
                        />
                        <p v-else class="text-sm text-[var(--mrl-texto-suave)]">
                            No aplica
                        </p>
                        <label
                            v-if="campo.permite_no_aplica"
                            class="flex items-center gap-1.5 text-xs"
                        >
                            <Casilla
                                :model-value="
                                    (
                                        noAplica[seccionAbierta.clave] ?? []
                                    ).includes(campo.campo)
                                "
                                @update:model-value="
                                    (v) =>
                                        marcarNoAplica(
                                            seccionAbierta!.clave,
                                            campo.campo,
                                            v === true,
                                        )
                                "
                            />
                            No aplica
                        </label>
                    </div>
                </div>

                <!-- Vacíos que se capturan en el expediente -->
                <p
                    v-if="deExpediente(seccionAbierta).length"
                    class="flex items-start gap-2 rounded-lg bg-warning-soft/60 px-3 py-2 text-xs"
                >
                    <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        Se capturan en los datos personales del expediente:
                        <strong>{{
                            deExpediente(seccionAbierta)
                                .map((c) => c.etiqueta)
                                .join(' · ')
                        }}</strong>
                    </span>
                </p>

                <!-- Lista (dependientes, empleos, referencias) -->
                <div v-if="seccionAbierta.lista" class="flex flex-col gap-2">
                    <p class="text-xs font-semibold">
                        {{ seccionAbierta.lista.etiqueta }}
                        <span class="font-normal text-[var(--mrl-texto-suave)]"
                            >(máx. {{ seccionAbierta.lista.maximo }})</span
                        >
                    </p>
                    <p
                        v-if="
                            seccionAbierta.lista.bloqueada &&
                            seccionAbierta.lista.vacia
                        "
                        class="text-sm"
                    >
                        {{ seccionAbierta.lista.etiqueta_vacia }}
                    </p>
                    <div
                        v-for="(fila, indice) in filas[seccionAbierta.clave] ??
                        []"
                        :key="indice"
                        class="rounded-lg border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3"
                    >
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-medium"
                                >{{ seccionAbierta.lista.singular }}
                                {{ indice + 1 }}</span
                            >
                            <Button
                                v-if="!seccionAbierta.lista.bloqueada"
                                type="button"
                                size="sm"
                                variant="ghost"
                                aria-label="Quitar renglón"
                                @click="
                                    quitarFila(seccionAbierta.clave, indice)
                                "
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                        <dl
                            v-if="seccionAbierta.lista.bloqueada"
                            class="grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-3"
                        >
                            <div
                                v-for="c in seccionAbierta.lista.campos"
                                :key="c.campo"
                                class="min-w-0"
                            >
                                <dt
                                    class="text-xs text-[var(--mrl-texto-suave)]"
                                >
                                    {{ c.etiqueta }}
                                </dt>
                                <dd class="text-sm break-words">
                                    {{ texto(c, fila[c.campo] ?? null) }}
                                </dd>
                            </div>
                        </dl>
                        <FilaListaSolicitud
                            v-else
                            :campos="seccionAbierta.lista.campos"
                            :model-value="fila"
                            :id-base="`se-${seccionAbierta.clave}-${indice}`"
                            @update:model-value="
                                (f) =>
                                    cambiarFila(
                                        seccionAbierta!.clave,
                                        indice,
                                        f,
                                    )
                            "
                        />
                    </div>
                    <div
                        v-if="!seccionAbierta.lista.bloqueada"
                        class="flex flex-wrap items-center gap-3"
                    >
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="
                                (filas[seccionAbierta.clave] ?? []).length >=
                                    seccionAbierta.lista.maximo ||
                                listaVacia[seccionAbierta.clave]
                            "
                            @click="agregarFila(seccionAbierta)"
                        >
                            <Plus class="size-4" /> Agregar
                            {{ seccionAbierta.lista.singular.toLowerCase() }}
                        </Button>
                        <label
                            v-if="
                                seccionAbierta.lista.bandera_vacia &&
                                !(filas[seccionAbierta.clave] ?? []).length
                            "
                            class="flex items-center gap-1.5 text-sm"
                        >
                            <Casilla
                                :model-value="
                                    listaVacia[seccionAbierta.clave] ?? false
                                "
                                @update:model-value="
                                    (v) =>
                                        (listaVacia[seccionAbierta!.clave] =
                                            v === true)
                                "
                            />
                            {{ seccionAbierta.lista.etiqueta_vacia }}
                        </label>
                    </div>
                </div>

                <!-- Ya capturado (bloqueado: se corrige por solicitud) -->
                <details
                    v-if="capturados(seccionAbierta).length"
                    class="group rounded-lg border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
                    :open="!puedeGuardar(seccionAbierta)"
                >
                    <summary
                        class="flex cursor-pointer items-center gap-2 px-3 py-2 text-xs font-medium"
                    >
                        <Lock class="size-3.5 text-[var(--mrl-texto-suave)]" />
                        Ya capturado ({{ capturados(seccionAbierta).length }})
                    </summary>
                    <dl
                        class="grid grid-cols-1 gap-x-4 gap-y-2 border-t border-[var(--mrl-borde)] p-3 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <div
                            v-for="campo in capturados(seccionAbierta)"
                            :key="campo.campo"
                            class="min-w-0"
                        >
                            <dt class="text-xs text-[var(--mrl-texto-suave)]">
                                {{ campo.etiqueta }}
                            </dt>
                            <dd class="text-sm break-words">
                                {{
                                    campo.no_aplica
                                        ? 'No aplica'
                                        : texto(campo, campo.valor)
                                }}
                            </dd>
                        </div>
                    </dl>
                </details>

                <div
                    v-if="puedeGuardar(seccionAbierta)"
                    class="flex justify-end"
                >
                    <Button
                        type="button"
                        class="w-full sm:w-auto"
                        :disabled="guardando === seccionAbierta.clave"
                        @click="guardarSeccion(seccionAbierta)"
                    >
                        {{
                            guardando === seccionAbierta.clave
                                ? 'Guardando…'
                                : 'Guardar sección'
                        }}
                    </Button>
                </div>
            </article>
        </template>
    </section>
</template>
