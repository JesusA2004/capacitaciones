<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    Eye,
    FileSearch,
    FileUp,
    History,
    Loader2,
    Lock,
    PlayCircle,
    Power,
    ShieldAlert,
    ShieldCheck,
    XCircle,
} from '@lucide/vue';
import { computed, ref, useTemplateRef, watch } from 'vue';
import { toast } from 'vue-sonner';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import DisenoPaginaMaestro from '@/components/documentos/maestros/DisenoPaginaMaestro.vue';
import EstadoDisenoBadge from '@/components/documentos/maestros/EstadoDisenoBadge.vue';
import EstadoMaestroBadge from '@/components/documentos/maestros/EstadoMaestroBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useDocumentosMaestros } from '@/composables/useDocumentosMaestros';
import { formatearFecha } from '@/lib/fechas';
import { originalPdf } from '@/routes/rh/documentos-maestros';
import type {
    ColaboradorBusqueda,
    MasterDetalle,
    ResultadoPruebaMaster,
} from '@/types';

/**
 * Detalle de UNA versión de documento maestro. Primero lo que decide RH
 * (resumen, diseño validado, activación segura y prueba con un
 * colaborador); el diagnóstico técnico (campos, reglas, SHA, motor,
 * fuentes) queda plegado al final.
 */
const props = defineProps<{ detalle: MasterDetalle }>();

const emit = defineEmits<{
    actualizado: [detalle: MasterDetalle];
    nuevaVersion: [];
    verVersion: [id: number];
}>();

const api = useDocumentosMaestros();
const ocupado = ref<null | 'validar' | 'activar' | 'desactivar' | 'probar'>(
    null,
);
const colaborador = ref<ColaboradorBusqueda | null>(null);
const prueba = ref<ResultadoPruebaMaster | null>(null);
const pestana = ref<'resultado' | 'original'>('resultado');
const motivoExcepcion = ref('');
const mostrarExcepcion = ref(false);
const tecnicoAbierto = ref(false);
const seccionPrueba = useTemplateRef<HTMLElement>('seccionPrueba');
const seccionVersiones = useTemplateRef<HTMLElement>('seccionVersiones');

function irA(elemento: HTMLElement | null) {
    elemento?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

watch(
    () => props.detalle.id,
    () => {
        prueba.value = null;
        motivoExcepcion.value = '';
        mostrarExcepcion.value = false;
    },
);

const esDocx = computed(() => props.detalle.motor === 'docx');
const operativo = computed(() => props.detalle.estado !== 'referencia');
const urlOriginal = computed(() => originalPdf.url(props.detalle.id));

const etiquetaFidelidad: Record<string, string> = {
    nativa: 'Nativa',
    alta: 'Alta (LibreOffice validado)',
    aproximada: 'Aproximada (solo vista previa)',
};

const etiquetaAccion: Record<string, string> = {
    documento_maestro_importado: 'Versión cargada',
    documento_maestro_activado: 'Activada',
    documento_maestro_activado_excepcion: 'Activada por excepción',
    documento_maestro_desactivado: 'Desactivada',
    documento_maestro_probado: 'Probada con colaborador',
    documento_maestro_qa_visual: 'Prueba de diseño',
};

async function ejecutar(
    accion: 'validar' | 'activar' | 'desactivar',
    fn: () => Promise<{ message: string; data: MasterDetalle }>,
) {
    ocupado.value = accion;

    try {
        const r = await fn();
        toast.success(r.message);
        emit('actualizado', r.data);
        mostrarExcepcion.value = false;
        motivoExcepcion.value = '';
    } catch (e) {
        toast.error(
            e instanceof Error ? e.message : 'No se pudo completar la acción.',
        );
    } finally {
        ocupado.value = null;
    }
}

function validar() {
    void ejecutar('validar', () => api.validarDiseno(props.detalle.id));
}

function activar(motivo?: string) {
    void ejecutar('activar', () => api.activar(props.detalle.id, motivo));
}

function desactivar() {
    void ejecutar('desactivar', () => api.desactivar(props.detalle.id));
}

async function probar() {
    if (!colaborador.value) {
        return;
    }

    ocupado.value = 'probar';

    try {
        prueba.value = await api.probar(props.detalle.id, colaborador.value.id);
        pestana.value = 'resultado';
    } catch (e) {
        toast.error(
            e instanceof Error
                ? e.message
                : 'No se pudo generar la vista previa.',
        );
    } finally {
        ocupado.value = null;
    }
}

function porcentaje(valor: number | null | undefined): string {
    return valor === null || valor === undefined
        ? '—'
        : `${(valor * 100).toFixed(1)} %`;
}

const fuenteDomicilio: Record<string, string> = {
    fiscal: 'Domicilio fiscal de la empresa',
    sucursal: 'Domicilio de la sucursal',
    predeterminado: 'Predeterminado del registro jurídico',
};
</script>

<template>
    <div class="flex flex-col gap-5 text-sm">
        <!-- Encabezado -->
        <div class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <EstadoMaestroBadge :estado="detalle.estado_ejecutivo" />
                <EstadoDisenoBadge :diseno="detalle.diseno" />
                <Badge :variant="detalle.activo ? 'success' : 'outline'">
                    {{
                        detalle.activo
                            ? `v${detalle.version} activa`
                            : `v${detalle.version} inactiva`
                    }}
                </Badge>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    as="a"
                    :href="urlOriginal"
                    target="_blank"
                    rel="noopener"
                >
                    <Eye class="size-4" /> Ver original
                </Button>
                <Button
                    v-if="operativo"
                    size="sm"
                    variant="outline"
                    @click="irA(seccionPrueba)"
                >
                    <FileSearch class="size-4" /> Previsualizar
                </Button>
                <Button
                    v-if="operativo"
                    size="sm"
                    variant="outline"
                    @click="emit('nuevaVersion')"
                >
                    <FileUp class="size-4" /> Nueva versión
                </Button>
                <Button
                    v-if="operativo && !detalle.activo"
                    size="sm"
                    :disabled="!detalle.activacion.puede || ocupado !== null"
                    @click="activar()"
                >
                    <Loader2
                        v-if="ocupado === 'activar'"
                        class="size-4 animate-spin"
                    />
                    <Power v-else class="size-4" /> Activar
                </Button>
                <Button
                    v-else-if="operativo && detalle.activo"
                    size="sm"
                    variant="outline"
                    :disabled="ocupado !== null"
                    @click="desactivar"
                >
                    <Loader2
                        v-if="ocupado === 'desactivar'"
                        class="size-4 animate-spin"
                    />
                    <Power v-else class="size-4" /> Desactivar
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    @click="irA(seccionVersiones)"
                >
                    <History class="size-4" /> Historial
                </Button>
            </div>
        </div>

        <!-- Por qué no se puede activar -->
        <div
            v-if="
                operativo &&
                !detalle.activo &&
                detalle.activacion.bloqueos.length
            "
            class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-950 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100"
        >
            <p class="flex items-center gap-2 font-medium">
                <Lock class="size-4" /> Aún no se puede activar
            </p>
            <ul class="mt-1 list-disc pl-6 text-xs">
                <li
                    v-for="b in detalle.activacion.bloqueos"
                    :key="b.clave + b.mensaje"
                >
                    {{ b.mensaje }}
                </li>
            </ul>
            <div v-if="detalle.activacion.excepcion_permitida" class="mt-3">
                <Button
                    v-if="!mostrarExcepcion"
                    size="sm"
                    variant="outline"
                    @click="mostrarExcepcion = true"
                >
                    <ShieldAlert class="size-4" /> Activar por excepción (super
                    administrador)
                </Button>
                <div v-else class="flex flex-col gap-2">
                    <Label for="motivo-excepcion" class="text-xs">
                        Motivo (obligatorio, queda en la auditoría y visible en
                        la versión)
                    </Label>
                    <Textarea
                        id="motivo-excepcion"
                        v-model="motivoExcepcion"
                        rows="2"
                        maxlength="1000"
                    />
                    <div class="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            @click="mostrarExcepcion = false"
                            >Cancelar</Button
                        >
                        <Button
                            size="sm"
                            variant="destructive"
                            :disabled="
                                motivoExcepcion.trim().length < 15 ||
                                ocupado !== null
                            "
                            @click="activar(motivoExcepcion.trim())"
                        >
                            <Loader2
                                v-if="ocupado === 'activar'"
                                class="size-4 animate-spin"
                            />
                            Activar por excepción
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- RESUMEN -->
        <section class="rounded-2xl border border-[var(--mrl-borde)] p-4">
            <h3
                class="mb-3 text-xs font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
            >
                Resumen
            </h3>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-[var(--mrl-texto-suave)]">
                        Documento
                    </dt>
                    <dd class="font-medium">{{ detalle.resumen.documento }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--mrl-texto-suave)]">
                        Versión
                    </dt>
                    <dd class="font-medium">v{{ detalle.resumen.version }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--mrl-texto-suave)]">
                        Proceso
                    </dt>
                    <dd>{{ detalle.resumen.proceso ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--mrl-texto-suave)]">
                        Puesto / grupo
                    </dt>
                    <dd>{{ detalle.resumen.aplica_a }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--mrl-texto-suave)]">
                        Páginas
                    </dt>
                    <dd>{{ detalle.resumen.paginas ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--mrl-texto-suave)]">
                        Última prueba de diseño
                    </dt>
                    <dd>
                        {{
                            detalle.resumen.ultima_prueba_en
                                ? formatearFecha(
                                      detalle.resumen.ultima_prueba_en,
                                  )
                                : 'Nunca'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--mrl-texto-suave)]">
                        Cargada
                    </dt>
                    <dd>
                        {{
                            detalle.resumen.cargada_en
                                ? formatearFecha(detalle.resumen.cargada_en)
                                : '—'
                        }}
                        <span class="text-[var(--mrl-texto-suave)]"
                            >·
                            {{
                                detalle.resumen.cargada_por ?? 'Importador'
                            }}</span
                        >
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-[var(--mrl-texto-suave)]">
                        Activada
                    </dt>
                    <dd>
                        <template v-if="detalle.resumen.activada_en">
                            {{ formatearFecha(detalle.resumen.activada_en) }}
                            <span class="text-[var(--mrl-texto-suave)]"
                                >·
                                {{
                                    detalle.resumen.activada_por ?? 'Importador'
                                }}</span
                            >
                        </template>
                        <template v-else>—</template>
                    </dd>
                </div>
            </dl>
        </section>

        <!-- DISEÑO (QA visual) -->
        <section
            v-if="operativo"
            class="rounded-2xl border border-[var(--mrl-borde)] p-4"
        >
            <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3
                        class="text-xs font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                    >
                        Diseño
                    </h3>
                    <p class="mt-1 flex items-center gap-2 font-medium">
                        <ShieldCheck
                            v-if="detalle.calidad.estado === 'passed'"
                            class="size-4 text-[var(--mrl-verde)]"
                        />
                        <XCircle
                            v-else-if="detalle.calidad.estado === 'failed'"
                            class="size-4 text-destructive"
                        />
                        <AlertTriangle v-else class="size-4 text-amber-600" />
                        {{ detalle.calidad.etiqueta }}
                    </p>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        ORIGINAL vs GENERADO, página por página. Corre al cargar
                        la versión y aquí a demanda; no en cada contrato.
                    </p>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="
                        ocupado !== null || detalle.calidad.impedimento !== null
                    "
                    @click="validar"
                >
                    <Loader2
                        v-if="ocupado === 'validar'"
                        class="size-4 animate-spin"
                    />
                    <PlayCircle v-else class="size-4" />
                    {{
                        ocupado === 'validar'
                            ? 'Probando diseño… (puede tardar ~30 s)'
                            : 'Probar diseño'
                    }}
                </Button>
            </div>

            <p
                v-if="detalle.calidad.impedimento"
                class="mb-3 rounded-lg bg-[var(--mrl-fondo)] p-2 text-xs"
            >
                {{ detalle.calidad.impedimento }}
            </p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl bg-[var(--mrl-fondo)] p-3">
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        Coincidencia mínima
                    </p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ porcentaje(detalle.calidad.similitud) }}
                    </p>
                </div>
                <div class="rounded-xl bg-[var(--mrl-fondo)] p-3">
                    <p class="text-xs text-[var(--mrl-texto-suave)]">Páginas</p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ detalle.calidad.paginas_original ?? '—' }} →
                        {{ detalle.calidad.paginas_prueba ?? '—' }}
                    </p>
                </div>
                <div class="rounded-xl bg-[var(--mrl-fondo)] p-3">
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        Fidelidad
                    </p>
                    <p class="font-semibold">
                        {{
                            detalle.calidad.fidelidad
                                ? (etiquetaFidelidad[
                                      detalle.calidad.fidelidad
                                  ] ?? detalle.calidad.fidelidad)
                                : '—'
                        }}
                    </p>
                </div>
                <div class="rounded-xl bg-[var(--mrl-fondo)] p-3">
                    <p class="text-xs text-[var(--mrl-texto-suave)]">Fuentes</p>
                    <p class="font-semibold">
                        <template v-if="!esDocx">No aplica</template>
                        <template
                            v-else-if="
                                detalle.fuentes_documento.every(
                                    (f) => f.disponible,
                                )
                            "
                            >OK</template
                        >
                        <template v-else>
                            <span class="text-destructive"
                                >{{
                                    detalle.fuentes_documento.filter(
                                        (f) => !f.disponible,
                                    ).length
                                }}
                                faltante(s)</span
                            >
                        </template>
                    </p>
                </div>
            </div>

            <ul
                v-if="detalle.calidad.problemas.length"
                class="mt-3 flex flex-col gap-1 text-xs text-destructive"
            >
                <li
                    v-for="(p, i) in detalle.calidad.problemas"
                    :key="`p${i}`"
                    class="flex gap-2"
                >
                    <XCircle class="mt-0.5 size-3.5 shrink-0" /> {{ p }}
                </li>
            </ul>
            <ul
                v-if="detalle.calidad.advertencias.length"
                class="mt-2 flex flex-col gap-1 text-xs text-amber-700 dark:text-amber-300"
            >
                <li
                    v-for="(a, i) in detalle.calidad.advertencias"
                    :key="`a${i}`"
                    class="flex gap-2"
                >
                    <AlertTriangle class="mt-0.5 size-3.5 shrink-0" /> {{ a }}
                </li>
            </ul>
            <p
                v-if="detalle.calidad.excepcion"
                class="mt-2 text-xs text-amber-700"
            >
                Activada por excepción: «{{ detalle.calidad.excepcion }}»
            </p>
        </section>

        <!-- DISEÑO DE PÁGINA (preset, fondo, sangría) -->
        <section
            v-if="detalle.familia && detalle.motor !== 'pdf_overlay'"
            class="rounded-2xl border border-[var(--mrl-borde)] p-4"
        >
            <h3
                class="mb-1 text-xs font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
            >
                Diseño de página
            </h3>
            <p class="mb-3 text-xs text-[var(--mrl-texto-suave)]">
                Fondo, márgenes y sangría que se aplican al generar. El texto
                jurídico nunca cambia; pruébalo abajo con un colaborador.
            </p>
            <DisenoPaginaMaestro :familia="detalle.familia" puede-editar />
        </section>

        <!-- PROBAR CON COLABORADOR -->
        <section
            v-if="operativo"
            ref="seccionPrueba"
            class="rounded-2xl border border-[var(--mrl-borde)] p-4"
        >
            <h3
                class="mb-1 text-xs font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
            >
                Probar con colaborador
            </h3>
            <p class="mb-3 text-xs text-[var(--mrl-texto-suave)]">
                Vista previa con datos reales de una persona. No se guarda en
                ningún expediente.
            </p>
            <div class="flex flex-col gap-2 sm:flex-row">
                <div class="flex-1">
                    <BuscadorColaborador v-model="colaborador" />
                </div>
                <Button
                    :disabled="!colaborador || ocupado !== null"
                    @click="probar"
                >
                    <Loader2
                        v-if="ocupado === 'probar'"
                        class="size-4 animate-spin"
                    />
                    <FileSearch v-else class="size-4" /> Generar vista previa
                </Button>
            </div>

            <div v-if="prueba" class="mt-4 flex flex-col gap-3">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                    <div class="rounded-xl bg-[var(--mrl-fondo)] p-2">
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            Páginas
                        </p>
                        <p
                            class="font-semibold tabular-nums"
                            :class="
                                prueba.paginas.original !== null &&
                                prueba.paginas.original !==
                                    prueba.paginas.generado
                                    ? 'text-destructive'
                                    : ''
                            "
                        >
                            {{ prueba.paginas.original ?? '—' }} →
                            {{ prueba.paginas.generado }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-[var(--mrl-fondo)] p-2">
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            Fidelidad
                        </p>
                        <p
                            class="font-semibold"
                            :class="
                                prueba.fidelidad === 'aproximada'
                                    ? 'text-amber-700'
                                    : ''
                            "
                        >
                            {{
                                etiquetaFidelidad[prueba.fidelidad] ??
                                prueba.fidelidad
                            }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-[var(--mrl-fondo)] p-2">
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            Campos
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ prueba.campos.llenos }}/{{ prueba.campos.total }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-[var(--mrl-fondo)] p-2">
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            Desbordes
                        </p>
                        <p
                            class="font-semibold tabular-nums"
                            :class="
                                prueba.desbordes.length
                                    ? 'text-destructive'
                                    : ''
                            "
                        >
                            {{ prueba.desbordes.length }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-[var(--mrl-fondo)] p-2">
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            Fuentes
                        </p>
                        <p
                            class="font-semibold"
                            :class="prueba.fuentes.ok ? '' : 'text-destructive'"
                        >
                            {{ prueba.fuentes.ok ? 'OK' : 'Faltan' }}
                        </p>
                    </div>
                </div>

                <div
                    class="grid gap-2 rounded-xl border border-[var(--mrl-borde)] p-3 text-xs sm:grid-cols-2"
                >
                    <div>
                        <p class="text-[var(--mrl-texto-suave)]">
                            Domicilio del patrón usado
                        </p>
                        <p class="font-medium">
                            {{ prueba.patron.domicilio.valor || '—' }}
                        </p>
                        <p class="text-[var(--mrl-texto-suave)]">
                            Fuente:
                            {{
                                fuenteDomicilio[
                                    prueba.patron.domicilio.fuente
                                ] ?? prueba.patron.domicilio.fuente
                            }}
                            (configurado:
                            {{
                                prueba.patron.domicilio.configurado ===
                                'sucursal'
                                    ? 'sucursal'
                                    : 'fiscal'
                            }})
                        </p>
                    </div>
                    <div>
                        <p class="text-[var(--mrl-texto-suave)]">
                            Representante legal
                        </p>
                        <p
                            v-if="
                                prueba.patron.representante.modo ===
                                'fijo_juridico'
                            "
                            class="text-[var(--mrl-texto-suave)]"
                        >
                            Escrito en las declaraciones del original de
                            Jurídico (no cambia por empresa).
                        </p>
                        <p
                            v-else-if="
                                prueba.patron.representante.modo === 'no_aplica'
                            "
                            class="text-[var(--mrl-texto-suave)]"
                        >
                            Este documento no lleva representante legal.
                        </p>
                        <template v-else>
                            <p class="font-medium">
                                {{ prueba.patron.representante.valor || '—' }}
                            </p>
                            <p
                                v-if="
                                    prueba.patron.representante.fuente ===
                                    'predeterminado'
                                "
                                class="flex items-center gap-1 text-amber-700 dark:text-amber-300"
                            >
                                <AlertTriangle class="size-3.5" /> Usando
                                representante legal predeterminado (captúralo en
                                Empresas).
                            </p>
                            <p v-else class="text-[var(--mrl-texto-suave)]">
                                Fuente: capturado en la empresa
                            </p>
                        </template>
                    </div>
                </div>

                <ul
                    v-if="prueba.faltantes.length || prueba.desbordes.length"
                    class="flex flex-col gap-1 text-xs"
                >
                    <li
                        v-if="prueba.faltantes.length"
                        class="text-amber-700 dark:text-amber-300"
                    >
                        Faltan datos del colaborador (en el PDF quedan en
                        blanco): {{ prueba.faltantes.join(', ') }}
                    </li>
                    <li
                        v-for="d in prueba.desbordes"
                        :key="d.campo"
                        class="text-destructive"
                    >
                        {{ d.etiqueta ?? d.campo }}: {{ d.razon }}
                    </li>
                </ul>

                <Tabs v-model="pestana">
                    <TabsList>
                        <TabsTrigger value="resultado">Generado</TabsTrigger>
                        <TabsTrigger value="original">Original</TabsTrigger>
                    </TabsList>
                    <TabsContent value="resultado">
                        <iframe
                            :src="prueba.url_resultado"
                            title="Documento generado (vista previa)"
                            class="h-[70vh] w-full rounded-xl border border-[var(--mrl-borde)] bg-white"
                        />
                    </TabsContent>
                    <TabsContent value="original">
                        <iframe
                            :src="prueba.url_original"
                            title="Documento original de Jurídico"
                            class="h-[70vh] w-full rounded-xl border border-[var(--mrl-borde)] bg-white"
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </section>

        <!-- VERSIONES -->
        <section
            ref="seccionVersiones"
            class="rounded-2xl border border-[var(--mrl-borde)] p-4"
        >
            <h3
                class="mb-3 text-xs font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
            >
                Versiones
            </h3>
            <ul class="flex flex-col divide-y divide-[var(--mrl-borde)]">
                <li
                    v-for="v in detalle.versiones"
                    :key="v.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                >
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 font-medium">
                            v{{ v.version }}
                            <Badge
                                :variant="v.activo ? 'success' : 'outline'"
                                >{{ v.activo ? 'Activa' : 'Inactiva' }}</Badge
                            >
                            <EstadoDisenoBadge :diseno="v.diseno" compacto />
                        </p>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            Cargada
                            {{
                                v.cargado_en
                                    ? formatearFecha(v.cargado_en)
                                    : '—'
                            }}
                            · {{ v.cargado_por ?? 'Importador' }}
                            <template v-if="v.activado_en">
                                · activada
                                {{ formatearFecha(v.activado_en) }} por
                                {{ v.activado_por ?? 'Importador' }}</template
                            >
                            <template v-if="v.ultima_prueba_en">
                                · última prueba
                                {{
                                    formatearFecha(v.ultima_prueba_en)
                                }}</template
                            >
                            <template v-if="v.fidelidad">
                                · fidelidad
                                {{
                                    etiquetaFidelidad[v.fidelidad] ??
                                    v.fidelidad
                                }}</template
                            >
                        </p>
                    </div>
                    <Button
                        v-if="v.id !== detalle.id"
                        size="sm"
                        variant="ghost"
                        @click="emit('verVersion', v.id)"
                        >Ver</Button
                    >
                    <span v-else class="text-xs text-[var(--mrl-texto-suave)]"
                        >Viendo</span
                    >
                </li>
            </ul>
        </section>

        <!-- DIAGNÓSTICO TÉCNICO -->
        <Collapsible
            v-model:open="tecnicoAbierto"
            class="rounded-2xl border border-[var(--mrl-borde)]"
        >
            <CollapsibleTrigger
                class="flex w-full items-center justify-between p-4 text-left"
            >
                <span
                    class="text-xs font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                    >Diagnóstico técnico</span
                >
                <ChevronDown
                    class="size-4 transition-transform"
                    :class="tecnicoAbierto ? 'rotate-180' : ''"
                />
            </CollapsibleTrigger>
            <CollapsibleContent class="flex flex-col gap-4 px-4 pb-4 text-xs">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div>
                        <p class="text-[var(--mrl-texto-suave)]">Detectados</p>
                        <p class="font-semibold">
                            {{ detalle.reporte.detectados }}
                        </p>
                    </div>
                    <div>
                        <p class="text-[var(--mrl-texto-suave)]">Mapeados</p>
                        <p class="font-semibold">
                            {{ detalle.reporte.mapeados }}
                        </p>
                    </div>
                    <div>
                        <p class="text-[var(--mrl-texto-suave)]">Pendientes</p>
                        <p
                            class="font-semibold"
                            :class="
                                detalle.reporte.pendientes.length +
                                detalle.reporte.reglas_pendientes.length
                                    ? 'text-destructive'
                                    : ''
                            "
                        >
                            {{
                                detalle.reporte.pendientes.length +
                                detalle.reporte.reglas_pendientes.length
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-[var(--mrl-texto-suave)]">
                            Firmas en blanco
                        </p>
                        <p class="font-semibold">
                            {{ detalle.reporte.firmas }}
                        </p>
                    </div>
                </div>
                <ul
                    v-if="
                        detalle.reporte.pendientes.length ||
                        detalle.reporte.reglas_pendientes.length
                    "
                    class="list-disc pl-5 text-destructive"
                >
                    <li
                        v-for="(p, i) in detalle.reporte.pendientes"
                        :key="`dp${i}`"
                    >
                        {{ p.tipo }}: {{ p.contexto }}
                    </li>
                    <li
                        v-for="(p, i) in detalle.reporte.reglas_pendientes"
                        :key="`dr${i}`"
                    >
                        Regla {{ p.regla }} ({{ p.campo }}) sin contexto
                        {{ p.contexto }}
                    </li>
                </ul>
                <div>
                    <p class="mb-1 text-[var(--mrl-texto-suave)]">
                        Datos que llena PEOPLE
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="c in detalle.reporte.campos"
                            :key="c"
                            variant="outline"
                            >{{ c }}</Badge
                        >
                    </div>
                </div>
                <div v-if="esDocx && detalle.fuentes_documento.length">
                    <p class="mb-1 text-[var(--mrl-texto-suave)]">
                        Fuentes del documento
                    </p>
                    <ul class="grid gap-1 sm:grid-cols-2">
                        <li
                            v-for="f in detalle.fuentes_documento"
                            :key="f.fuente"
                            class="flex items-center gap-2"
                        >
                            <CheckCircle2
                                v-if="f.disponible"
                                class="size-3.5 text-[var(--mrl-verde)]"
                            />
                            <AlertTriangle
                                v-else
                                class="size-3.5 text-destructive"
                            />
                            {{ f.fuente }}
                            <span v-if="!f.disponible" class="text-destructive"
                                >no disponible{{
                                    f.sustitucion
                                        ? ` (se sustituiría por ${f.sustitucion})`
                                        : ''
                                }}</span
                            >
                            <span
                                v-else-if="
                                    f.familia_encontrada &&
                                    f.familia_encontrada !== f.fuente
                                "
                                class="text-[var(--mrl-texto-suave)]"
                                >(resuelta como {{ f.familia_encontrada }})</span
                            >
                            <span
                                v-if="f.archivo"
                                class="truncate text-[var(--mrl-texto-suave)]"
                                :title="f.archivo"
                                >· {{ f.archivo.split(/[\\/]/).pop() }}</span
                            >
                        </li>
                    </ul>
                </div>
                <div v-if="detalle.calidad.por_pagina.length">
                    <p class="mb-1 text-[var(--mrl-texto-suave)]">
                        Coincidencia por página (original vs master preparado)
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="p in detalle.calidad.por_pagina"
                            :key="p.pagina"
                            :variant="
                                p.similitud >= 0.985 ? 'outline' : 'destructive'
                            "
                        >
                            p{{ p.pagina }} · {{ porcentaje(p.similitud) }}
                        </Badge>
                    </div>
                </div>
                <dl class="grid gap-1">
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-[var(--mrl-texto-suave)]">Motor:</dt>
                        <dd>
                            {{
                                esDocx
                                    ? 'Word original preparado'
                                    : 'PDF original + overlay'
                            }}
                        </dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-[var(--mrl-texto-suave)]">
                            Validado con:
                        </dt>
                        <dd>{{ detalle.calidad.motor ?? '—' }}</dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-[var(--mrl-texto-suave)]">Reglas:</dt>
                        <dd>{{ detalle.reporte.reglas }}</dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-[var(--mrl-texto-suave)]">Original:</dt>
                        <dd class="break-all">
                            {{ detalle.original.nombre }} · SHA-256
                            {{ detalle.original.sha256 }}
                        </dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-[var(--mrl-texto-suave)]">Master:</dt>
                        <dd class="break-all">
                            SHA-256 {{ detalle.master_hash }}
                        </dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-[var(--mrl-texto-suave)]">
                            Imágenes conservadas:
                        </dt>
                        <dd>{{ detalle.reporte.imagenes_conservadas }}</dd>
                    </div>
                </dl>
                <div v-if="detalle.observaciones.length">
                    <p class="mb-1 text-[var(--mrl-texto-suave)]">
                        Observaciones para RH/Jurídico
                    </p>
                    <ul class="list-disc pl-5">
                        <li v-for="(o, i) in detalle.observaciones" :key="i">
                            {{ o }}
                        </li>
                    </ul>
                </div>
                <div v-if="detalle.historial.length">
                    <p class="mb-1 text-[var(--mrl-texto-suave)]">Auditoría</p>
                    <ul>
                        <li v-for="(h, i) in detalle.historial" :key="i">
                            {{ h.en ? formatearFecha(h.en) : '' }} ·
                            {{ etiquetaAccion[h.accion] ?? h.accion }} ·
                            {{ h.por ?? 'Sistema (importador)' }}
                        </li>
                    </ul>
                </div>
            </CollapsibleContent>
        </Collapsible>
    </div>
</template>
