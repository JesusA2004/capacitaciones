<script setup lang="ts">
import { CheckCircle2, Circle } from '@lucide/vue';
import { computed } from 'vue';
import RadioMarca from '@/components/Common/RadioMarca.vue';
import EncabezadoFormulario from '@/components/formularios-evaluacion/EncabezadoFormulario.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { estiloAcento } from '@/lib/formulariosEvaluacion';
import { cn } from '@/lib/utils';
import type {
    ContenidoFormularioTecnico,
    PreguntaTecnica,
    RespuestasTecnicas,
} from '@/types';

/**
 * Formulario técnico («Seguimiento N») TAL CUAL lo contesta el colaborador
 * o su jefe directo: encabezado con el diseño de la versión, secciones en
 * tarjetas, barra de avance y cada tipo de pregunta (opción múltiple,
 * casillas, verdadero/falso, escala, respuesta corta y párrafo). Lo usan la
 * página real (Seguimiento/EvaluacionesTecnicas/Contestar) y la vista
 * previa del editor — un solo componente, para que la vista previa sea
 * idéntica. El botón de envío lo pone la página (slot `acciones`).
 */
const props = withDefaults(
    defineProps<{
        contenido: ContenidoFormularioTecnico;
        deshabilitado?: boolean;
        compacto?: boolean;
        /** Preguntas obligatorias sin contestar a resaltar (tras intentar enviar). */
        resaltarFaltantes?: boolean;
    }>(),
    { deshabilitado: false, compacto: false, resaltarFaltantes: false },
);

const respuestas = defineModel<RespuestasTecnicas>('respuestas', {
    required: true,
});

const preguntas = computed(() =>
    props.contenido.secciones.flatMap((s) => s.preguntas),
);

function contestada(p: PreguntaTecnica): boolean {
    const v = respuestas.value[p.clave];

    if (Array.isArray(v)) {
        return v.length > 0;
    }

    return v !== null && v !== undefined && String(v).trim() !== '';
}

const avance = computed(() => {
    const total = preguntas.value.length;
    const hechas = preguntas.value.filter(contestada).length;

    return {
        total,
        hechas,
        porcentaje: total ? Math.round((hechas / total) * 100) : 0,
    };
});

/** Numeración corrida entre secciones, como un formato impreso. */
const inicioSeccion = computed(() => {
    let acumulado = 0;

    return props.contenido.secciones.map((s) => {
        const inicio = acumulado;
        acumulado += s.preguntas.length;

        return inicio;
    });
});

function escala(p: PreguntaTecnica): number[] {
    return Array.from(
        { length: p.escala_max - p.escala_min + 1 },
        (_, i) => p.escala_min + i,
    );
}

function casillas(p: PreguntaTecnica): string[] {
    const v = respuestas.value[p.clave];

    return Array.isArray(v) ? v : [];
}

function unica(p: PreguntaTecnica): string | null {
    const v = respuestas.value[p.clave];

    return typeof v === 'string' ? v : null;
}

function texto(p: PreguntaTecnica): string {
    const v = respuestas.value[p.clave];

    return typeof v === 'string' ? v : '';
}

function alternarCasilla(p: PreguntaTecnica, clave: string, marcar: boolean) {
    const actuales = casillas(p).filter((c) => c !== clave);
    respuestas.value[p.clave] = marcar ? [...actuales, clave] : actuales;
}

function faltante(p: PreguntaTecnica): boolean {
    return props.resaltarFaltantes && p.obligatoria && !contestada(p);
}

const tarjeta =
    'rounded-2xl border bg-card shadow-xs transition-[box-shadow,border-color] duration-200 focus-within:shadow-md focus-within:ring-1 focus-within:ring-[var(--acento)]';
const opcionClase =
    'flex cursor-pointer items-center gap-3 rounded-xl border border-transparent px-3 py-2.5 text-sm transition-colors hover:border-border hover:bg-muted/40 has-[[data-state=checked]]:border-[color-mix(in_oklab,var(--acento)_45%,transparent)] has-[[data-state=checked]]:bg-[color-mix(in_oklab,var(--acento)_10%,transparent)]';
</script>

<template>
    <div
        class="flex flex-col gap-5"
        :style="estiloAcento(contenido.diseno)"
        :aria-disabled="deshabilitado || undefined"
    >
        <EncabezadoFormulario
            :titulo="contenido.titulo"
            :descripcion="contenido.descripcion"
            :diseno="contenido.diseno"
            :compacto="compacto"
        >
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span
                    class="rounded-full bg-[color-mix(in_oklab,var(--acento)_14%,transparent)] px-3 py-1 font-medium"
                >
                    {{ contenido.total_preguntas }}
                    {{ contenido.total_preguntas === 1 ? 'pregunta' : 'preguntas' }}
                </span>
                <span
                    v-if="contenido.total_puntos"
                    class="rounded-full bg-[color-mix(in_oklab,var(--acento)_14%,transparent)] px-3 py-1 font-medium"
                >
                    {{ contenido.total_puntos }} puntos
                </span>
                <slot name="etiquetas" />
            </div>
        </EncabezadoFormulario>

        <!-- Avance: se queda pegado arriba mientras se contesta -->
        <div
            class="sticky top-2 z-10 flex items-center gap-3 rounded-2xl border border-border bg-card/95 px-4 py-2.5 text-sm shadow-xs backdrop-blur"
            role="status"
        >
            <span class="font-medium tabular-nums">
                {{ avance.hechas }}/{{ avance.total }}
            </span>
            <span class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                <span
                    class="block h-full rounded-full bg-[var(--acento)] transition-[width] duration-500 ease-out"
                    :style="{ width: `${avance.porcentaje}%` }"
                />
            </span>
            <span class="text-muted-foreground tabular-nums">
                {{ avance.porcentaje }}%
            </span>
        </div>

        <section
            v-for="(seccion, si) in contenido.secciones"
            :key="seccion.clave"
            class="flex entrada-escalonada flex-col gap-3"
        >
            <div class="flex items-start gap-3 px-1">
                <span
                    class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-[var(--acento)] text-sm font-bold text-[var(--acento-texto)]"
                    aria-hidden="true"
                    >{{ si + 1 }}</span
                >
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-balance">
                        {{ seccion.titulo || `Sección ${si + 1}` }}
                    </h2>
                    <p
                        v-if="seccion.descripcion"
                        class="mt-0.5 text-sm text-pretty whitespace-pre-line text-muted-foreground"
                    >
                        {{ seccion.descripcion }}
                    </p>
                </div>
            </div>

            <fieldset
                v-for="(p, pi) in seccion.preguntas"
                :key="p.clave"
                :class="
                    cn(
                        tarjeta,
                        compacto ? 'p-4' : 'p-5',
                        faltante(p)
                            ? 'border-[var(--mrl-danger)]'
                            : 'border-border',
                    )
                "
                :disabled="deshabilitado"
            >
                <legend class="sr-only">
                    Pregunta {{ inicioSeccion[si] + pi + 1 }}
                </legend>
                <div class="mb-3 flex items-start justify-between gap-3">
                    <p class="text-[15px] font-medium text-pretty">
                        <span class="text-muted-foreground tabular-nums"
                            >{{ inicioSeccion[si] + pi + 1 }}.</span
                        >
                        {{ p.enunciado || 'Pregunta sin texto' }}
                        <span
                            v-if="p.obligatoria"
                            class="text-[var(--mrl-danger)]"
                            aria-label="obligatoria"
                            >*</span
                        >
                    </p>
                    <span class="flex shrink-0 items-center gap-1.5">
                        <span
                            v-if="p.puntos > 0"
                            class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground tabular-nums"
                            >{{ p.puntos }} pts</span
                        >
                        <CheckCircle2
                            v-if="contestada(p)"
                            class="size-4 aparece-zoom text-[var(--acento)]"
                            aria-hidden="true"
                        />
                        <Circle
                            v-else
                            class="size-4 text-muted-foreground/40"
                            aria-hidden="true"
                        />
                    </span>
                </div>
                <p
                    v-if="p.ayuda"
                    class="-mt-2 mb-3 text-xs text-pretty text-muted-foreground"
                >
                    {{ p.ayuda }}
                </p>

                <!-- Opción múltiple / verdadero o falso -->
                <div
                    v-if="
                        p.tipo === 'opcion_multiple' ||
                        p.tipo === 'verdadero_falso'
                    "
                    :class="
                        cn(
                            'flex flex-col gap-1',
                            p.tipo === 'verdadero_falso' &&
                                'sm:flex-row sm:gap-2',
                        )
                    "
                    role="radiogroup"
                >
                    <label
                        v-for="o in p.opciones"
                        :key="o.clave"
                        :class="
                            cn(
                                opcionClase,
                                p.tipo === 'verdadero_falso' && 'sm:flex-1',
                            )
                        "
                    >
                        <RadioMarca
                            :model-value="unica(p)"
                            :value="o.clave"
                            :name="p.clave"
                            :disabled="deshabilitado"
                            @update:model-value="respuestas[p.clave] = $event"
                        />
                        {{ o.texto }}
                    </label>
                </div>

                <!-- Casillas -->
                <div v-else-if="p.tipo === 'casillas'" class="flex flex-col gap-1">
                    <label
                        v-for="o in p.opciones"
                        :key="o.clave"
                        :class="opcionClase"
                    >
                        <Checkbox
                            :model-value="casillas(p).includes(o.clave)"
                            :disabled="deshabilitado"
                            @update:model-value="
                                alternarCasilla(p, o.clave, $event === true)
                            "
                        />
                        {{ o.texto }}
                    </label>
                    <p class="px-3 text-xs text-muted-foreground">
                        Marca todas las que apliquen.
                    </p>
                </div>

                <!-- Escala lineal -->
                <div v-else-if="p.tipo === 'escala'" class="flex flex-col gap-2">
                    <div
                        class="flex flex-wrap items-center gap-2"
                        role="radiogroup"
                    >
                        <button
                            v-for="n in escala(p)"
                            :key="n"
                            type="button"
                            role="radio"
                            :aria-checked="respuestas[p.clave] === n"
                            :disabled="deshabilitado"
                            :class="
                                cn(
                                    'flex size-11 presionable items-center justify-center rounded-xl border text-sm font-semibold tabular-nums transition-all duration-150 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60',
                                    respuestas[p.clave] === n
                                        ? 'scale-105 border-transparent bg-[var(--acento)] text-[var(--acento-texto)] shadow-sm'
                                        : 'border-border bg-card hover:bg-muted/50',
                                )
                            "
                            @click="respuestas[p.clave] = n"
                        >
                            {{ n }}
                        </button>
                    </div>
                    <div
                        v-if="p.etiqueta_min || p.etiqueta_max"
                        class="flex justify-between gap-3 text-xs text-muted-foreground"
                    >
                        <span>{{ p.escala_min }} = {{ p.etiqueta_min }}</span>
                        <span>{{ p.escala_max }} = {{ p.etiqueta_max }}</span>
                    </div>
                </div>

                <!-- Respuesta corta -->
                <Input
                    v-else-if="p.tipo === 'respuesta_corta'"
                    :model-value="texto(p)"
                    :disabled="deshabilitado"
                    maxlength="500"
                    placeholder="Tu respuesta"
                    @update:model-value="respuestas[p.clave] = String($event)"
                />

                <!-- Párrafo -->
                <Textarea
                    v-else
                    :model-value="texto(p)"
                    :disabled="deshabilitado"
                    rows="4"
                    maxlength="5000"
                    placeholder="Tu respuesta"
                    @update:model-value="respuestas[p.clave] = String($event)"
                />

                <p
                    v-if="faltante(p)"
                    class="mt-2 entrada-suave text-xs font-medium text-[var(--mrl-danger)]"
                >
                    Esta pregunta es obligatoria.
                </p>
            </fieldset>
        </section>

        <slot name="acciones" />
    </div>
</template>
