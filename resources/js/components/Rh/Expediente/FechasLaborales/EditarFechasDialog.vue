<script setup lang="ts">
import {
    AlertTriangle,
    ArrowRight,
    CalendarClock,
    CheckCircle2,
    Info,
    Lock,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ErrorCorreccion, pedirCorreccion } from '@/lib/correcciones';
import { aplicar, mostrar, vistaPrevia } from '@/routes/correcciones';
import type {
    AfectadoCorreccion,
    CampoCorreccion,
    DescripcionCorreccion,
    PeriodoPruebaEfectivo,
    VistaPreviaCorreccion,
} from '@/types';

/**
 * Edita las fechas laborales en tres pasos (captura → impacto → motivo).
 * Escribe por el sistema de correcciones (dominio fechas_laborales): el
 * servidor valida, guarda el historial con las fechas calculadas que se
 * movieron y nunca modifica un contrato en silencio.
 */
const props = defineProps<{
    open: boolean;
    colaboradorId: number;
    periodo: PeriodoPruebaEfectivo;
    /** Campo que se enfoca al abrir (p. ej. desde «Faltan fechas»). */
    campoInicial?: string | null;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
    guardado: [];
}>();

type Paso = 'captura' | 'impacto' | 'hecho';

const DOMINIO = 'fechas_laborales' as const;
const CAMPOS_FECHA = [
    {
        campo: 'fecha_ingreso',
        ayuda: 'Inicio de labores. De aquí salen la antigüedad, las vacaciones y la rotación.',
    },
    {
        campo: 'periodo_prueba_inicio',
        ayuda: 'Desde aquí corren los días del periodo de prueba y de los Seguimientos. Vacía = la fecha de ingreso.',
    },
    {
        campo: 'fecha_induccion',
        ayuda: 'Cuándo tomó la inducción. Vacía = la de su evaluación de inducción aprobada en PEOPLE, si existe.',
    },
    {
        campo: 'fecha_alta_imss',
        ayuda: 'Fecha del alta ante el IMSS.',
    },
] as const;

const paso = ref<Paso>('captura');
const cargando = ref(false);
const enviando = ref(false);
const descripcion = ref<DescripcionCorreccion | null>(null);
const previa = ref<VistaPreviaCorreccion | null>(null);
const valores = ref<Record<string, string | null>>({});
const originales = ref<Record<string, string>>({});
const especifico = ref(false);
const motivo = ref('');
const error = ref<string | null>(null);
const errores = ref<Record<string, string[]>>({});

const MOTIVO_MINIMO = 5;

function campo(nombre: string): CampoCorreccion | undefined {
    return descripcion.value?.campos.find((c) => c.campo === nombre);
}

const cambiados = computed<Record<string, string | null>>(() => {
    const salida: Record<string, string | null> = {};
    const actuales = { ...valores.value };

    if (!especifico.value) {
        actuales.periodo_prueba_cantidad = '';
        actuales.periodo_prueba_unidad = '';
    }

    for (const [nombre, crudo] of Object.entries(actuales)) {
        // Input numérico puede entregar number: se compara como texto.
        const valor =
            crudo === null || crudo === undefined ? '' : String(crudo);

        if ((originales.value[nombre] ?? '') !== valor) {
            salida[nombre] = valor === '' ? null : valor;
        }
    }

    return salida;
});

const hayCambios = computed(() => Object.keys(cambiados.value).length > 0);
const motivoValido = computed(
    () => motivo.value.trim().length >= MOTIVO_MINIMO,
);

const ETIQUETA_ACCION: Record<AfectadoCorreccion['accion'], string> = {
    se_actualiza: 'Se actualiza',
    requiere_rectificacion: 'Requiere rectificación',
    requiere_modificacion_contractual: 'Requiere nuevo contrato',
    revisar: 'Debes revisarlo',
    intacto: 'No cambia',
    nueva_version: 'Nueva versión',
};

const CLASE_ACCION: Record<AfectadoCorreccion['accion'], string> = {
    se_actualiza: 'bg-success-soft text-success',
    requiere_rectificacion: 'bg-warning-soft text-warning',
    requiere_modificacion_contractual: 'bg-danger-soft text-danger',
    revisar: 'bg-warning-soft text-warning',
    intacto: 'bg-muted text-muted-foreground',
    nueva_version: 'bg-crema text-bronce',
};

function primerError(nombre: string): string | null {
    return (
        errores.value[`valores.${nombre}`]?.[0] ??
        errores.value[nombre]?.[0] ??
        null
    );
}

function aplicarDescripcion(datos: DescripcionCorreccion): void {
    descripcion.value = datos;
    const base: Record<string, string> = {};

    for (const c of datos.campos) {
        base[c.campo] = c.valor ?? '';
    }

    originales.value = { ...base };
    valores.value = { ...base };
    especifico.value = (base.periodo_prueba_cantidad ?? '') !== '';

    if (!especifico.value) {
        valores.value.periodo_prueba_unidad = 'meses';
    }
}

async function cargar(): Promise<void> {
    paso.value = 'captura';
    previa.value = null;
    motivo.value = '';
    error.value = null;
    errores.value = {};
    cargando.value = true;

    try {
        aplicarDescripcion(
            await pedirCorreccion<DescripcionCorreccion>(
                mostrar.url({ dominio: DOMINIO, id: props.colaboradorId }),
            ),
        );

        if (props.campoInicial === 'periodo_prueba_cantidad') {
            especifico.value = true;
        }
    } catch (e) {
        error.value =
            e instanceof Error
                ? e.message
                : 'No se pudieron cargar las fechas.';
    } finally {
        cargando.value = false;
    }
}

watch(
    () => props.open,
    (abierto) => {
        if (abierto) {
            void cargar();
        }
    },
    { immediate: true },
);

function manejarError(e: unknown): void {
    if (e instanceof ErrorCorreccion && e.estado === 409 && e.actual) {
        const capturado = { ...valores.value };
        aplicarDescripcion(e.actual);
        valores.value = capturado;
        error.value = e.message;
        previa.value = null;
        paso.value = 'captura';

        return;
    }

    if (e instanceof ErrorCorreccion) {
        errores.value = e.errores;
        error.value =
            Object.keys(e.errores).length > 0
                ? (Object.values(e.errores)[0]?.[0] ?? e.message)
                : e.message;

        return;
    }

    error.value = 'No se pudo completar. Intenta de nuevo.';
}

async function revisar(): Promise<void> {
    if (!hayCambios.value || enviando.value) {
        return;
    }

    enviando.value = true;
    error.value = null;
    errores.value = {};

    try {
        previa.value = await pedirCorreccion<VistaPreviaCorreccion>(
            vistaPrevia.url({ dominio: DOMINIO, id: props.colaboradorId }),
            { valores: cambiados.value },
        );
        paso.value = 'impacto';
    } catch (e) {
        manejarError(e);
    } finally {
        enviando.value = false;
    }
}

async function confirmar(): Promise<void> {
    if (!previa.value || !descripcion.value || enviando.value) {
        return;
    }

    enviando.value = true;
    error.value = null;

    try {
        await pedirCorreccion(
            aplicar.url({ dominio: DOMINIO, id: props.colaboradorId }),
            {
                valores: cambiados.value,
                motivo: motivo.value,
                huella: previa.value.huella,
                huella_afectados: previa.value.huella_afectados,
                clave_idempotencia: descripcion.value.clave_idempotencia,
            },
        );
        paso.value = 'hecho';
        emit('guardado');
    } catch (e) {
        manejarError(e);
    } finally {
        enviando.value = false;
    }
}

const cambiosCapturados = computed(
    () =>
        previa.value?.cambios.filter((c) =>
            descripcion.value?.campos.some((d) => d.campo === c.campo),
        ) ?? [],
);
const cambiosCalculados = computed(
    () =>
        previa.value?.cambios.filter(
            (c) => !descripcion.value?.campos.some((d) => d.campo === c.campo),
        ) ?? [],
);
const hayContratoAfectado = computed(
    () =>
        previa.value?.afectados.some(
            (a) =>
                a.tipo === 'contrato' &&
                a.accion !== 'intacto' &&
                a.accion !== 'se_actualiza',
        ) ?? false,
);

function cerrar(): void {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <CalendarClock class="size-5 text-bronce" />
                    Editar fechas laborales
                </DialogTitle>
                <DialogDescription>
                    <template v-if="descripcion"
                        >{{ descripcion.sujeto }}.
                    </template>
                    Antes de guardar verás qué fechas se mueven y qué debes
                    actualizar. Todo queda en el historial con tu motivo.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="cargando"
                class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
                role="status"
            >
                <Spinner /> Cargando las fechas vigentes…
            </div>

            <p
                v-if="error"
                class="flex items-start gap-2 rounded-xl bg-danger-soft px-3 py-2 text-sm text-danger"
                role="alert"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                {{ error }}
            </p>

            <!-- Paso 1: captura -->
            <div
                v-if="!cargando && descripcion && paso === 'captura'"
                class="flex flex-col gap-5"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="f in CAMPOS_FECHA"
                        :key="f.campo"
                        class="flex flex-col gap-1.5"
                    >
                        <Label
                            :for="`fecha-${f.campo}`"
                            class="flex items-center gap-1.5"
                        >
                            {{ campo(f.campo)?.etiqueta }}
                            <Lock
                                v-if="
                                    campo(f.campo) && !campo(f.campo)?.editable
                                "
                                class="size-3.5 text-muted-foreground"
                            />
                        </Label>
                        <DatePicker
                            :id="`fecha-${f.campo}`"
                            v-model="valores[f.campo]"
                            :disabled="!campo(f.campo)?.editable"
                            :limpiable="f.campo !== 'fecha_ingreso'"
                            :anio-desde="1980"
                        />
                        <p
                            v-if="campo(f.campo)?.explicacion"
                            class="text-xs text-warning"
                        >
                            {{ campo(f.campo)?.explicacion }}
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            {{ f.ayuda }}
                        </p>
                        <p
                            v-if="primerError(f.campo)"
                            class="text-xs text-danger"
                        >
                            {{ primerError(f.campo) }}
                        </p>
                    </div>
                </div>

                <!-- Periodo de prueba: hereda vs específico -->
                <section
                    class="rounded-2xl border border-border bg-gradient-to-br from-crema/60 via-card to-salvia/30 p-4"
                >
                    <h3 class="text-sm font-semibold">Periodo de prueba</h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Es la misma etapa que la capacitación inicial. De él
                        salen el fin del periodo y cuándo se habilita su
                        evaluación.
                    </p>
                    <div
                        class="mt-3 grid gap-2 sm:grid-cols-2"
                        role="radiogroup"
                        aria-label="Periodo de prueba"
                    >
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="!especifico"
                            class="tarjeta-interactiva rounded-xl border px-3 py-2.5 text-left text-sm"
                            :class="
                                !especifico
                                    ? 'border-primary bg-card shadow-xs'
                                    : 'border-border bg-card/60'
                            "
                            @click="especifico = false"
                        >
                            <span class="block font-medium"
                                >Hereda del puesto</span
                            >
                            <span class="text-xs text-muted-foreground">
                                {{
                                    periodo.puesto_etiqueta ??
                                    'El puesto no tiene periodo configurado'
                                }}
                            </span>
                        </button>
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="especifico"
                            class="tarjeta-interactiva rounded-xl border px-3 py-2.5 text-left text-sm"
                            :class="
                                especifico
                                    ? 'border-primary bg-card shadow-xs'
                                    : 'border-border bg-card/60'
                            "
                            @click="especifico = true"
                        >
                            <span class="block font-medium"
                                >Específico para esta persona</span
                            >
                            <span class="text-xs text-muted-foreground"
                                >En meses o en días</span
                            >
                        </button>
                    </div>

                    <div
                        v-if="especifico"
                        class="mt-3 flex flex-wrap items-end gap-3"
                    >
                        <div class="flex flex-col gap-1.5">
                            <Label for="periodo-cantidad">Duración</Label>
                            <Input
                                id="periodo-cantidad"
                                :model-value="
                                    valores.periodo_prueba_cantidad ?? ''
                                "
                                type="number"
                                min="1"
                                max="730"
                                class="w-28"
                                @update:model-value="
                                    (v: string | number) =>
                                        (valores.periodo_prueba_cantidad =
                                            String(v))
                                "
                            />
                        </div>
                        <div
                            class="inline-flex rounded-xl border border-border bg-card p-1"
                            role="radiogroup"
                            aria-label="Unidad"
                        >
                            <button
                                v-for="u in [
                                    { valor: 'meses', etiqueta: 'Meses' },
                                    { valor: 'dias', etiqueta: 'Días' },
                                ]"
                                :key="u.valor"
                                type="button"
                                role="radio"
                                :aria-checked="
                                    valores.periodo_prueba_unidad === u.valor
                                "
                                class="rounded-lg px-3 py-1.5 text-sm transition-colors"
                                :class="
                                    valores.periodo_prueba_unidad === u.valor
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                @click="valores.periodo_prueba_unidad = u.valor"
                            >
                                {{ u.etiqueta }}
                            </button>
                        </div>
                    </div>
                    <p
                        v-if="primerError('periodo_prueba_cantidad')"
                        class="mt-2 text-xs text-danger"
                    >
                        {{ primerError('periodo_prueba_cantidad') }}
                    </p>
                </section>
            </div>

            <!-- Paso 2: impacto -->
            <div
                v-if="paso === 'impacto' && previa"
                class="flex flex-col gap-4"
            >
                <section v-if="cambiosCapturados.length > 0">
                    <h3 class="mb-2 text-sm font-semibold">
                        Lo que capturaste
                    </h3>
                    <ul class="flex flex-col gap-1.5">
                        <li
                            v-for="c in cambiosCapturados"
                            :key="c.campo"
                            class="flex flex-wrap items-center gap-2 rounded-xl bg-muted/50 px-3 py-2 text-sm"
                        >
                            <span class="min-w-0 flex-1 font-medium">{{
                                c.etiqueta
                            }}</span>
                            <span class="text-muted-foreground line-through">{{
                                c.antes ?? 'vacío'
                            }}</span>
                            <ArrowRight
                                class="size-3.5 text-muted-foreground"
                            />
                            <span class="font-semibold">{{
                                c.despues ?? 'vacío'
                            }}</span>
                        </li>
                    </ul>
                </section>

                <section>
                    <h3 class="mb-2 text-sm font-semibold">
                        Fechas que se recalculan
                    </h3>
                    <ul
                        v-if="cambiosCalculados.length > 0"
                        class="flex flex-col gap-1.5"
                    >
                        <li
                            v-for="c in cambiosCalculados"
                            :key="c.campo"
                            class="flex flex-wrap items-center gap-2 rounded-xl border border-info/20 bg-info-soft px-3 py-2 text-sm"
                        >
                            <span class="min-w-0 flex-1">{{ c.etiqueta }}</span>
                            <span class="text-muted-foreground">{{
                                c.antes ?? '—'
                            }}</span>
                            <ArrowRight class="size-3.5 text-info" />
                            <span class="font-semibold text-info">{{
                                c.despues ?? '—'
                            }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        Ninguna fecha calculada cambia.
                    </p>
                </section>

                <section v-if="previa.afectados.length > 0">
                    <h3 class="mb-2 text-sm font-semibold">
                        Qué más se afecta
                    </h3>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="(a, i) in previa.afectados"
                            :key="i"
                            class="rounded-xl border border-border bg-card px-3 py-2.5 text-sm"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="min-w-0 flex-1 font-medium">{{
                                    a.etiqueta
                                }}</span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="CLASE_ACCION[a.accion]"
                                    >{{ ETIQUETA_ACCION[a.accion] }}</span
                                >
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ a.detalle }}
                            </p>
                        </li>
                    </ul>
                </section>

                <p
                    v-if="hayContratoAfectado"
                    class="flex items-start gap-2 rounded-xl bg-warning-soft px-3 py-2 text-sm text-warning"
                >
                    <Info class="mt-0.5 size-4 shrink-0" />
                    El contrato no se cambia solo. Al guardar verás el aviso
                    «Debes actualizar el contrato» con el botón para hacerlo.
                </p>

                <div class="flex flex-col gap-1.5">
                    <Label for="motivo-fechas">Motivo del cambio</Label>
                    <Textarea
                        id="motivo-fechas"
                        v-model="motivo"
                        rows="3"
                        placeholder="Ej. Acuerdo con su gerente: periodo de prueba de 2 meses."
                    />
                    <p class="text-xs text-muted-foreground">
                        Obligatorio (mínimo {{ MOTIVO_MINIMO }} caracteres).
                        Queda en el historial.
                    </p>
                </div>
            </div>

            <!-- Paso 3: hecho -->
            <div
                v-if="paso === 'hecho'"
                class="flex flex-col items-center gap-2 py-8 text-center"
            >
                <CheckCircle2 class="size-10 text-success" />
                <p class="font-semibold">Fechas guardadas</p>
                <p class="text-sm text-muted-foreground">
                    Se recalcularon las fechas y las evaluaciones pendientes.
                    Revisa los avisos de la sección.
                </p>
            </div>

            <DialogFooter class="gap-2">
                <template v-if="paso === 'captura'">
                    <Button variant="outline" type="button" @click="cerrar"
                        >Cancelar</Button
                    >
                    <Button
                        type="button"
                        :disabled="!hayCambios || enviando || cargando"
                        @click="revisar"
                    >
                        <Spinner v-if="enviando" />
                        Ver impacto
                    </Button>
                </template>
                <template v-else-if="paso === 'impacto'">
                    <Button
                        variant="outline"
                        type="button"
                        @click="paso = 'captura'"
                        >Volver</Button
                    >
                    <Button
                        type="button"
                        :disabled="!motivoValido || enviando"
                        @click="confirmar"
                    >
                        <Spinner v-if="enviando" />
                        Guardar fechas
                    </Button>
                </template>
                <Button v-else type="button" @click="cerrar">Listo</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
