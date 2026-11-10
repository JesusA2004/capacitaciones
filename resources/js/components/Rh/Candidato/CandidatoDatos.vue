<script setup lang="ts">
import {
    BadgeCheck,
    BriefcaseBusiness,
    Building2,
    CalendarCheck,
    CalendarX,
    ChevronDown,
    CircleCheck,
    CircleDashed,
    CircleX,
    ClipboardCheck,
    Hexagon,
    IdCard,
    Mail,
    MapPin,
    Megaphone,
    Phone,
    Target,
    UserRoundCheck,
    UserRoundSearch,
    XCircle,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import DatoFicha from '@/components/Rh/Candidato/DatoFicha.vue';
import SeccionFicha from '@/components/Rh/Candidato/SeccionFicha.vue';
import TextoLargo from '@/components/Rh/Candidato/TextoLargo.vue';
import { formatearFecha } from '@/lib/fechas';
import type { CandidatoFicha } from '@/types';

/**
 * Datos del candidato en una rejilla pareja (contacto, vacante,
 * reclutamiento): solo se pintan los que tienen valor; los vacíos se juntan
 * en un renglón «Sin registrar: …». Los requisitos del puesto van con barra
 * de avance y se despliegan a petición (los que no cumple siempre se ven).
 */
const props = withDefaults(
    defineProps<{ candidato: CandidatoFicha; indice?: number }>(),
    { indice: 0 },
);

type Dato = {
    icono: Component;
    etiqueta: string;
    valor: string | null;
    href?: string | null;
    copiable?: boolean;
    /** Si está vacío no se anuncia como faltante (dato que no siempre aplica). */
    opcional?: boolean;
};

const datos = computed<Dato[]>(() => {
    const c = props.candidato;

    return [
        {
            icono: Phone,
            etiqueta: 'Teléfono',
            valor: c.telefono,
            href: c.telefono
                ? `tel:${c.telefono.replace(/[^\d+]/g, '')}`
                : null,
            copiable: true,
        },
        {
            icono: Mail,
            etiqueta: 'Correo',
            valor: c.correo,
            href: c.correo ? `mailto:${c.correo}` : null,
            copiable: true,
        },
        { icono: BriefcaseBusiness, etiqueta: 'Puesto', valor: c.puesto },
        { icono: MapPin, etiqueta: 'Sucursal', valor: c.sucursal },
        {
            icono: Building2,
            etiqueta: 'Empresa',
            valor: c.empresa,
            opcional: true,
        },
        {
            icono: Hexagon,
            etiqueta: 'Departamento',
            valor: c.departamento,
            opcional: true,
        },
        {
            icono: BadgeCheck,
            etiqueta: 'Vacante',
            valor: c.vacante_id
                ? 'Ligado a una vacante abierta'
                : c.espontaneo
                  ? 'Sin vacante ligada (registro espontáneo)'
                  : null,
            opcional: true,
        },
        { icono: Megaphone, etiqueta: 'Fuente', valor: c.fuente_etiqueta },
        { icono: Target, etiqueta: 'Campaña', valor: c.campana },
        {
            icono: UserRoundSearch,
            etiqueta: 'Reclutador',
            valor: c.responsable_rh,
        },
        { icono: UserRoundCheck, etiqueta: 'Gerente', valor: c.gerente },
        {
            icono: CalendarCheck,
            etiqueta: 'Contratado',
            valor: c.contratado_en ? formatearFecha(c.contratado_en) : null,
            opcional: true,
        },
    ];
});

function tieneValor(dato: Dato): boolean {
    return !!dato.valor && dato.valor.trim() !== '';
}

const conValor = computed(() => datos.value.filter(tieneValor));
const sinRegistrar = computed(() =>
    datos.value
        .filter((d) => !tieneValor(d) && !d.opcional)
        .map((d) => d.etiqueta.toLowerCase()),
);

/** Lo esencial en una línea, para cuando la sección está plegada (móvil). */
const resumen = computed(() =>
    [props.candidato.telefono, props.candidato.correo]
        .filter((t): t is string => !!t)
        .join(' · '),
);

const requisitos = computed(() => props.candidato.respuestas_filtro);
const cumplidos = computed(
    () => requisitos.value.filter((r) => r.cumple).length,
);
const cumpleTodo = computed(() => cumplidos.value === requisitos.value.length);
const verRequisitos = ref(false);
/** Plegado: solo los que NO cumple; desplegado: todos. */
const requisitosVisibles = computed(() =>
    verRequisitos.value
        ? requisitos.value
        : requisitos.value.filter((r) => !r.cumple),
);
</script>

<template>
    <SeccionFicha
        titulo="Datos del candidato"
        :icono="IdCard"
        :indice="indice"
        plegable
        :resumen="resumen"
    >
        <!-- Ya no continuó: lo primero que hay que ver. -->
        <div
            v-if="candidato.motivo_rechazo"
            class="flex items-start gap-3 rounded-xl bg-danger-soft px-3.5 py-3 text-sm"
            role="note"
        >
            <XCircle
                class="mt-0.5 size-5 shrink-0 text-destructive"
                aria-hidden="true"
            />
            <div class="min-w-0">
                <p class="font-semibold text-destructive">
                    ¿Por qué ya no continuó?
                </p>
                <p class="break-words">{{ candidato.motivo_rechazo }}</p>
                <p
                    v-if="candidato.motivo_salida"
                    class="mt-0.5 text-xs break-words text-muted-foreground"
                >
                    {{ candidato.motivo_salida }}
                </p>
                <p
                    v-if="candidato.salida_en"
                    class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"
                >
                    <CalendarX class="size-3.5" aria-hidden="true" />
                    {{ formatearFecha(candidato.salida_en) }}
                </p>
            </div>
        </div>

        <dl class="grid gap-x-3 gap-y-0.5 sm:grid-cols-2 2xl:grid-cols-3">
            <DatoFicha
                v-for="dato in conValor"
                :key="dato.etiqueta"
                :icono="dato.icono"
                :etiqueta="dato.etiqueta"
                :valor="dato.valor"
                :href="dato.href"
                :copiable="dato.copiable"
            />
        </dl>

        <p
            v-if="sinRegistrar.length"
            class="flex items-start gap-1.5 text-xs text-muted-foreground"
            data-sin-registrar
        >
            <CircleDashed class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            <span class="min-w-0 break-words"
                >Sin registrar: {{ sinRegistrar.join(', ') }}</span
            >
        </p>

        <TextoLargo
            v-if="candidato.observaciones"
            etiqueta="Observaciones del registro"
            :texto="candidato.observaciones"
        />

        <div
            v-if="requisitos.length"
            class="flex flex-col gap-2 rounded-xl bg-[var(--mrl-fondo)] p-3"
            data-requisitos
        >
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <h3
                    class="flex items-center gap-1.5 text-sm font-semibold"
                    id="requisitos-puesto"
                >
                    <ClipboardCheck
                        class="size-4 text-bronce"
                        aria-hidden="true"
                    />
                    Requisitos del puesto
                </h3>
                <div
                    class="h-2 min-w-24 flex-1 overflow-hidden rounded-full bg-[var(--mrl-superficie)]"
                    role="progressbar"
                    aria-labelledby="requisitos-puesto"
                    :aria-valuenow="cumplidos"
                    aria-valuemin="0"
                    :aria-valuemax="requisitos.length"
                >
                    <div
                        class="h-full rounded-full transicion-ui"
                        :class="cumpleTodo ? 'bg-success' : 'bg-warning'"
                        :style="{
                            width: `${(cumplidos / requisitos.length) * 100}%`,
                        }"
                    />
                </div>
                <span
                    class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums"
                    :class="
                        cumpleTodo
                            ? 'bg-success-soft text-success'
                            : 'bg-warning-soft text-warning'
                    "
                    >Cumple {{ cumplidos }} de {{ requisitos.length }}</span
                >
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md text-xs font-semibold text-[var(--mrl-petroleo)] hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-expanded="verRequisitos"
                    @click="verRequisitos = !verRequisitos"
                >
                    {{ verRequisitos ? 'Ocultar' : 'Ver detalle' }}
                    <ChevronDown
                        class="size-3.5 transicion-ui"
                        :class="verRequisitos ? 'rotate-180' : ''"
                        aria-hidden="true"
                    />
                </button>
            </div>
            <ul
                v-if="requisitosVisibles.length"
                class="grid gap-1.5 md:grid-cols-2"
            >
                <li
                    v-for="(r, i) in requisitosVisibles"
                    :key="i"
                    class="flex entrada-suave items-start gap-2 rounded-lg px-2.5 py-1.5 text-sm"
                    :class="r.cumple ? 'bg-success-soft' : 'bg-danger-soft'"
                >
                    <component
                        :is="r.cumple ? CircleCheck : CircleX"
                        class="mt-0.5 size-4 shrink-0"
                        :class="r.cumple ? 'text-success' : 'text-destructive'"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1 break-words">{{
                        r.pregunta
                    }}</span>
                    <span
                        class="shrink-0 rounded-full bg-[var(--mrl-superficie)] px-2 py-0.5 text-xs font-semibold"
                        >{{ r.respuesta ? 'Sí' : 'No' }}</span
                    >
                </li>
            </ul>
        </div>
    </SeccionFicha>
</template>
