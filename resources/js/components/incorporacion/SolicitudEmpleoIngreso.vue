<script setup lang="ts">
import {
    CheckCircle2,
    ChevronDown,
    ClipboardList,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { enviarJson, getJson } from '@/lib/http';
import {
    guardar as rutaGuardar,
    show as rutaEstado,
} from '@/routes/rh/solicitud-empleo';
import type {
    CampoListaSolicitud,
    CampoSolicitud,
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

const opcionesSiNo = [
    { value: 'si', label: 'Sí' },
    { value: 'no', label: 'No' },
];

const documentos = computed(() =>
    Object.values(estado.value?.completitud.documentos ?? {}),
);

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

function valorSiNo(valor: ValorSolicitud): string | undefined {
    return valor === true ? 'si' : valor === false ? 'no' : undefined;
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
        class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        aria-label="Solicitud de empleo y estudio socioeconómico"
    >
        <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <ClipboardList class="size-4" /> Solicitud de empleo y
                    estudio socioeconómico
                </h2>
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    Lo que la persona capturó en su registro. RH solo completa
                    lo que falta; la vista previa y la generación están en
                    «Documentos».
                </p>
            </div>
            <Badge
                v-if="estado"
                :variant="estado.completitud.completo ? 'success' : 'warning'"
            >
                {{ estado.completitud.porcentaje }} % completo
            </Badge>
        </div>

        <p v-if="cargando" class="text-sm text-[var(--mrl-texto-suave)]">
            Cargando…
        </p>
        <p v-else-if="!estado" class="text-sm text-[var(--mrl-texto-suave)]">
            No tienes acceso a la solicitud de empleo de esta persona.
        </p>

        <template v-else>
            <ul class="mb-4 flex flex-col gap-1.5">
                <li
                    v-for="doc in documentos"
                    :key="doc.clave"
                    class="flex items-start gap-2 text-sm"
                >
                    <CheckCircle2
                        v-if="doc.completo"
                        class="mt-0.5 size-4 shrink-0 text-[var(--mrl-verde)]"
                    />
                    <span
                        v-else
                        class="mt-1.5 size-2 shrink-0 rounded-full bg-[var(--mrl-dorado)]"
                    />
                    <span>{{ doc.mensaje }}</span>
                </li>
            </ul>

            <div class="flex flex-col gap-2">
                <article
                    v-for="seccion in estado.secciones"
                    :key="seccion.clave"
                    class="rounded-xl bg-[var(--mrl-fondo)]"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 p-3 text-left"
                        :aria-expanded="abierta === seccion.clave"
                        @click="
                            abierta =
                                abierta === seccion.clave ? null : seccion.clave
                        "
                    >
                        <span class="min-w-0">
                            <span class="block text-sm font-medium">{{
                                seccion.titulo
                            }}</span>
                            <span
                                class="block truncate text-xs text-[var(--mrl-texto-suave)]"
                            >
                                {{
                                    seccion.completa
                                        ? seccion.origen === 'rh'
                                            ? 'Completa (RH completó datos)'
                                            : 'Completa'
                                        : `Falta: ${seccion.faltantes.join(', ')}`
                                }}
                            </span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2">
                            <Badge
                                :variant="
                                    seccion.completa ? 'success' : 'secondary'
                                "
                                >{{ seccion.porcentaje }} %</Badge
                            >
                            <ChevronDown
                                class="size-4 transition-transform"
                                :class="{
                                    'rotate-180': abierta === seccion.clave,
                                }"
                            />
                        </span>
                    </button>

                    <div
                        v-if="abierta === seccion.clave"
                        class="flex flex-col gap-4 border-t border-[var(--mrl-borde)] p-3"
                    >
                        <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                            <div
                                v-for="campo in seccion.campos.filter(
                                    (c) => c.bloqueado,
                                )"
                                :key="campo.campo"
                                class="min-w-0"
                            >
                                <dt
                                    class="text-xs text-[var(--mrl-texto-suave)]"
                                >
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

                        <div
                            v-if="editables(seccion).length"
                            class="grid gap-3 sm:grid-cols-2"
                        >
                            <div
                                v-for="campo in editables(seccion)"
                                :key="campo.campo"
                                class="flex min-w-0 flex-col gap-1"
                                :class="{
                                    'sm:col-span-2':
                                        campo.tipo === 'texto_largo' ||
                                        campo.tipo === 'multiselect',
                                }"
                            >
                                <Label :for="`se-${campo.campo}`"
                                    >{{ campo.etiqueta
                                    }}<span
                                        v-if="campo.requerido"
                                        class="text-[var(--mrl-rojo)]"
                                    >
                                        *</span
                                    ></Label
                                >
                                <template
                                    v-if="
                                        !(
                                            noAplica[seccion.clave] ?? []
                                        ).includes(campo.campo)
                                    "
                                >
                                    <Textarea
                                        v-if="campo.tipo === 'texto_largo'"
                                        :id="`se-${campo.campo}`"
                                        v-model="
                                            borradores[seccion.clave][
                                                campo.campo
                                            ] as string
                                        "
                                        :maxlength="campo.max ?? undefined"
                                        rows="2"
                                    />
                                    <SelectSimple
                                        v-else-if="campo.tipo === 'select'"
                                        :id="`se-${campo.campo}`"
                                        :model-value="
                                            (borradores[seccion.clave][
                                                campo.campo
                                            ] as string | null) ?? undefined
                                        "
                                        :opciones="campo.opciones"
                                        placeholder="Elige una opción"
                                        @update:model-value="
                                            (v) =>
                                                (borradores[seccion.clave][
                                                    campo.campo
                                                ] = (v as string) ?? null)
                                        "
                                    />
                                    <SelectSimple
                                        v-else-if="campo.tipo === 'booleano'"
                                        :id="`se-${campo.campo}`"
                                        :model-value="
                                            valorSiNo(
                                                borradores[seccion.clave][
                                                    campo.campo
                                                ] ?? null,
                                            )
                                        "
                                        :opciones="opcionesSiNo"
                                        placeholder="Sí / No"
                                        @update:model-value="
                                            (v) =>
                                                (borradores[seccion.clave][
                                                    campo.campo
                                                ] =
                                                    v === 'si'
                                                        ? true
                                                        : v === 'no'
                                                          ? false
                                                          : null)
                                        "
                                    />
                                    <div
                                        v-else-if="campo.tipo === 'multiselect'"
                                        class="flex flex-wrap gap-3"
                                    >
                                        <label
                                            v-for="opcion in campo.opciones"
                                            :key="opcion.value"
                                            class="flex items-center gap-1.5 text-sm"
                                        >
                                            <Casilla
                                                :model-value="
                                                    (borradores[seccion.clave][
                                                        campo.campo
                                                    ] as string[] | null) ?? []
                                                "
                                                :value="opcion.value"
                                                @update:model-value="
                                                    (v) =>
                                                        (borradores[
                                                            seccion.clave
                                                        ][campo.campo] =
                                                            v as string[])
                                                "
                                            />
                                            {{ opcion.label }}
                                        </label>
                                    </div>
                                    <Input
                                        v-else
                                        :id="`se-${campo.campo}`"
                                        v-model="
                                            borradores[seccion.clave][
                                                campo.campo
                                            ] as string
                                        "
                                        :type="
                                            campo.tipo === 'entero' ||
                                            campo.tipo === 'moneda'
                                                ? 'number'
                                                : campo.tipo === 'fecha'
                                                  ? 'date'
                                                  : 'text'
                                        "
                                        :min="campo.min ?? undefined"
                                        :maxlength="
                                            campo.tipo === 'texto'
                                                ? (campo.max ?? undefined)
                                                : undefined
                                        "
                                    />
                                </template>
                                <p
                                    v-else
                                    class="text-sm text-[var(--mrl-texto-suave)]"
                                >
                                    No aplica
                                </p>
                                <label
                                    v-if="campo.permite_no_aplica"
                                    class="flex items-center gap-1.5 text-xs"
                                >
                                    <Casilla
                                        :model-value="
                                            (
                                                noAplica[seccion.clave] ?? []
                                            ).includes(campo.campo)
                                        "
                                        @update:model-value="
                                            (v) =>
                                                marcarNoAplica(
                                                    seccion.clave,
                                                    campo.campo,
                                                    v === true,
                                                )
                                        "
                                    />
                                    No aplica
                                </label>
                                <p
                                    v-if="campo.ayuda"
                                    class="text-xs text-[var(--mrl-texto-suave)]"
                                >
                                    {{ campo.ayuda }}
                                </p>
                            </div>
                        </div>

                        <div v-if="seccion.lista" class="flex flex-col gap-2">
                            <p class="text-xs font-semibold">
                                {{ seccion.lista.etiqueta }} (máx.
                                {{ seccion.lista.maximo }})
                            </p>
                            <p
                                v-if="
                                    seccion.lista.bloqueada &&
                                    seccion.lista.vacia
                                "
                                class="text-sm"
                            >
                                {{ seccion.lista.etiqueta_vacia }}
                            </p>
                            <div
                                v-for="(fila, indice) in filas[seccion.clave] ??
                                []"
                                :key="indice"
                                class="rounded-lg border border-[var(--mrl-borde)] p-2"
                            >
                                <div
                                    class="mb-1 flex items-center justify-between"
                                >
                                    <span class="text-xs font-medium"
                                        >{{ seccion.lista.singular }}
                                        {{ indice + 1 }}</span
                                    >
                                    <Button
                                        v-if="!seccion.lista.bloqueada"
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        aria-label="Quitar renglón"
                                        @click="
                                            quitarFila(seccion.clave, indice)
                                        "
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                                <dl
                                    v-if="seccion.lista.bloqueada"
                                    class="grid gap-x-4 gap-y-1 sm:grid-cols-3"
                                >
                                    <div
                                        v-for="c in seccion.lista.campos"
                                        :key="c.campo"
                                    >
                                        <dt
                                            class="text-xs text-[var(--mrl-texto-suave)]"
                                        >
                                            {{ c.etiqueta }}
                                        </dt>
                                        <dd class="text-sm break-words">
                                            {{
                                                texto(c, fila[c.campo] ?? null)
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                                <div v-else class="grid gap-2 sm:grid-cols-3">
                                    <div
                                        v-for="c in seccion.lista.campos"
                                        :key="c.campo"
                                        class="flex flex-col gap-1"
                                    >
                                        <Label
                                            :for="`se-${seccion.clave}-${indice}-${c.campo}`"
                                            class="text-xs"
                                            >{{ c.etiqueta }}</Label
                                        >
                                        <SelectSimple
                                            v-if="c.tipo === 'select'"
                                            :id="`se-${seccion.clave}-${indice}-${c.campo}`"
                                            :model-value="
                                                (fila[c.campo] as
                                                    string | null) ?? undefined
                                            "
                                            :opciones="c.opciones"
                                            size="sm"
                                            @update:model-value="
                                                (v) =>
                                                    (fila[c.campo] =
                                                        (v as string) ?? null)
                                            "
                                        />
                                        <Input
                                            v-else
                                            :id="`se-${seccion.clave}-${indice}-${c.campo}`"
                                            v-model="fila[c.campo] as string"
                                            :type="
                                                c.tipo === 'entero'
                                                    ? 'number'
                                                    : c.tipo === 'fecha'
                                                      ? 'date'
                                                      : c.tipo === 'mes'
                                                        ? 'month'
                                                        : 'text'
                                            "
                                        />
                                    </div>
                                </div>
                            </div>
                            <div
                                v-if="!seccion.lista.bloqueada"
                                class="flex flex-wrap items-center gap-3"
                            >
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    :disabled="
                                        (filas[seccion.clave] ?? []).length >=
                                            seccion.lista.maximo ||
                                        listaVacia[seccion.clave]
                                    "
                                    @click="agregarFila(seccion)"
                                >
                                    <Plus class="size-4" /> Agregar
                                    {{ seccion.lista.singular.toLowerCase() }}
                                </Button>
                                <label
                                    v-if="
                                        seccion.lista.bandera_vacia &&
                                        !(filas[seccion.clave] ?? []).length
                                    "
                                    class="flex items-center gap-1.5 text-sm"
                                >
                                    <Casilla
                                        :model-value="
                                            listaVacia[seccion.clave] ?? false
                                        "
                                        @update:model-value="
                                            (v) =>
                                                (listaVacia[seccion.clave] =
                                                    v === true)
                                        "
                                    />
                                    {{ seccion.lista.etiqueta_vacia }}
                                </label>
                            </div>
                        </div>

                        <div
                            v-if="
                                editables(seccion).length ||
                                (seccion.lista && !seccion.lista.bloqueada)
                            "
                            class="flex justify-end"
                        >
                            <Button
                                type="button"
                                :disabled="guardando === seccion.clave"
                                @click="guardarSeccion(seccion)"
                            >
                                {{
                                    guardando === seccion.clave
                                        ? 'Guardando…'
                                        : 'Guardar sección'
                                }}
                            </Button>
                        </div>
                    </div>
                </article>
            </div>
        </template>
    </section>
</template>
