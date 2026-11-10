<script setup lang="ts">
import {
    BadgeCheck,
    BriefcaseBusiness,
    Building2,
    CalendarCheck,
    CalendarX,
    CircleCheck,
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
    UsersRound,
    XCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import DatoFicha from '@/components/Rh/Candidato/DatoFicha.vue';
import SeccionFicha from '@/components/Rh/Candidato/SeccionFicha.vue';
import TextoLargo from '@/components/Rh/Candidato/TextoLargo.vue';
import { formatearFecha } from '@/lib/fechas';
import type { CandidatoFicha } from '@/types';

/**
 * Datos del candidato agrupados por tema (contacto, vacante, reclutamiento)
 * y los requisitos indispensables que contestó al registrarse.
 */
const props = withDefaults(
    defineProps<{ candidato: CandidatoFicha; indice?: number }>(),
    { indice: 0 },
);

const telefonoHref = computed(() =>
    props.candidato.telefono
        ? `tel:${props.candidato.telefono.replace(/[^\d+]/g, '')}`
        : null,
);

const correoHref = computed(() =>
    props.candidato.correo ? `mailto:${props.candidato.correo}` : null,
);

const requisitosCumplidos = computed(
    () => props.candidato.respuestas_filtro.filter((r) => r.cumple).length,
);

const vacante = computed(() =>
    props.candidato.vacante_id
        ? 'Ligado a una vacante abierta'
        : props.candidato.espontaneo
          ? 'Sin vacante ligada todavía (registro espontáneo)'
          : null,
);
</script>

<template>
    <SeccionFicha
        titulo="Datos del candidato"
        :icono="IdCard"
        descripcion="Cómo contactarlo, a qué vacante aspira y quién lleva su proceso."
        :indice="indice"
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

        <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-2">
                <h3
                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <Phone class="size-3.5" aria-hidden="true" /> Contacto
                </h3>
                <dl class="grid gap-2">
                    <DatoFicha
                        :icono="Phone"
                        etiqueta="Teléfono"
                        :valor="candidato.telefono"
                        :href="telefonoHref"
                        copiable
                    />
                    <DatoFicha
                        :icono="Mail"
                        etiqueta="Correo"
                        :valor="candidato.correo"
                        :href="correoHref"
                        copiable
                    />
                </dl>
            </div>

            <div class="flex min-w-0 flex-col gap-2">
                <h3
                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <Target class="size-3.5" aria-hidden="true" /> Vacante
                </h3>
                <dl class="grid gap-2">
                    <DatoFicha
                        :icono="BriefcaseBusiness"
                        etiqueta="Puesto"
                        :valor="candidato.puesto"
                    />
                    <DatoFicha
                        :icono="MapPin"
                        etiqueta="Sucursal"
                        :valor="candidato.sucursal"
                    />
                    <DatoFicha
                        v-if="candidato.empresa"
                        :icono="Building2"
                        etiqueta="Empresa"
                        :valor="candidato.empresa"
                    />
                    <DatoFicha
                        v-if="candidato.departamento"
                        :icono="Hexagon"
                        etiqueta="Departamento"
                        :valor="candidato.departamento"
                    />
                    <DatoFicha
                        v-if="vacante"
                        :icono="BadgeCheck"
                        etiqueta="Vacante"
                        :valor="vacante"
                    />
                </dl>
            </div>

            <div class="flex min-w-0 flex-col gap-2">
                <h3
                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <UsersRound class="size-3.5" aria-hidden="true" />
                    Reclutamiento
                </h3>
                <dl class="grid gap-2">
                    <DatoFicha
                        :icono="Megaphone"
                        etiqueta="Fuente"
                        :valor="candidato.fuente_etiqueta"
                    />
                    <DatoFicha
                        :icono="Target"
                        etiqueta="Campaña"
                        :valor="candidato.campana"
                    />
                    <DatoFicha
                        :icono="UserRoundSearch"
                        etiqueta="Reclutamiento"
                        :valor="candidato.responsable_rh"
                    />
                    <DatoFicha
                        :icono="UserRoundCheck"
                        etiqueta="Gerente"
                        :valor="candidato.gerente"
                    />
                    <DatoFicha
                        v-if="candidato.contratado_en"
                        :icono="CalendarCheck"
                        etiqueta="Contratado"
                        :valor="formatearFecha(candidato.contratado_en)"
                    />
                </dl>
            </div>
        </div>

        <TextoLargo
            v-if="candidato.observaciones"
            etiqueta="Observaciones del registro"
            :texto="candidato.observaciones"
        />

        <div
            v-if="candidato.respuestas_filtro.length"
            class="flex flex-col gap-2"
        >
            <h3
                class="flex flex-wrap items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                <ClipboardCheck class="size-3.5" aria-hidden="true" />
                Requisitos del puesto
                <span
                    class="rounded-full px-2 py-0.5 text-[11px] font-semibold tracking-normal normal-case"
                    :class="
                        requisitosCumplidos ===
                        candidato.respuestas_filtro.length
                            ? 'bg-success-soft text-success'
                            : 'bg-warning-soft text-warning'
                    "
                    >Cumple {{ requisitosCumplidos }} de
                    {{ candidato.respuestas_filtro.length }}</span
                >
            </h3>
            <ul class="grid gap-2 md:grid-cols-2">
                <li
                    v-for="(r, i) in candidato.respuestas_filtro"
                    :key="i"
                    class="flex items-start gap-2.5 rounded-xl px-3 py-2 text-sm transicion-ui hover:-translate-y-0.5"
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
