<script setup lang="ts">
import {
    Brain,
    Building2,
    CalendarDays,
    CircleCheck,
    CircleX,
    ClipboardList,
    Eye,
    Home,
    Hourglass,
    LayoutList,
    Link2,
    MapPin,
    MessagesSquare,
    Paperclip,
    Phone,
    ShieldCheck,
    TriangleAlert,
    UserCheck,
    UserRound,
    Video,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import SeccionFicha from '@/components/Rh/Candidato/SeccionFicha.vue';
import TextoLargo from '@/components/Rh/Candidato/TextoLargo.vue';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { formatearFecha, formatearFechaHora } from '@/lib/fechas';
import { TONO_SUAVE } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';
import type { CandidatoFicha, EvidenciaCandidato } from '@/types';

/**
 * Lo que se ha evaluado del candidato (entrevistas, psicométricas,
 * socioeconómico y referencias) en tarjetas con ícono, resultado en color y
 * los archivos de soporte a un clic. Las pestañas filtran por tipo; «Todas»
 * muestra todo, en el mismo orden de siempre.
 */
const props = withDefaults(
    defineProps<{ candidato: CandidatoFicha; indice?: number }>(),
    { indice: 0 },
);

const emit = defineEmits<{ ver: [evidencia: EvidenciaCandidato] }>();

type Pestana =
    | 'todas'
    | 'entrevistas'
    | 'psicometricas'
    | 'socioeconomicos'
    | 'referencias';

const pestana = ref<Pestana>('todas');

const pestanas = computed(() =>
    (
        [
            {
                clave: 'todas',
                etiqueta: 'Todas',
                icono: LayoutList,
                total:
                    props.candidato.entrevistas.length +
                    props.candidato.psicometricas.length +
                    props.candidato.socioeconomicos.length +
                    props.candidato.referencias.length,
            },
            {
                clave: 'entrevistas',
                etiqueta: 'Entrevistas',
                icono: MessagesSquare,
                total: props.candidato.entrevistas.length,
            },
            {
                clave: 'psicometricas',
                etiqueta: 'Psicométricas',
                icono: Brain,
                total: props.candidato.psicometricas.length,
            },
            {
                clave: 'socioeconomicos',
                etiqueta: 'Socioeconómico',
                icono: Home,
                total: props.candidato.socioeconomicos.length,
            },
            {
                clave: 'referencias',
                etiqueta: 'Referencias',
                icono: UserCheck,
                total: props.candidato.referencias.length,
            },
        ] satisfies {
            clave: Pestana;
            etiqueta: string;
            icono: Component;
            total: number;
        }[]
    ).filter((p) => p.clave === 'todas' || p.total > 0),
);

const totalEvaluaciones = computed(() => pestanas.value[0]?.total ?? 0);

function ve(tipo: Pestana): boolean {
    return pestana.value === 'todas' || pestana.value === tipo;
}

function tonoViable(resultado: string | null): Tono {
    if (resultado === 'viable' || resultado === 'positiva') {
        return 'exito';
    }

    if (resultado === 'no_viable' || resultado === 'negativa') {
        return 'peligro';
    }

    return resultado === null ? 'alerta' : 'info';
}

type Psicometrica = CandidatoFicha['psicometricas'][number];
type Socioeconomico = CandidatoFicha['socioeconomicos'][number];
type Referencia = CandidatoFicha['referencias'][number];

/**
 * Campos que el backend ya manda (CandidatoPresenter) y aún no están en el
 * tipo compartido: se leen aquí, en un solo lugar.
 */
function puestoDeQuienValido(item: Referencia): string | null {
    return (
        (item as Referencia & { validada_por_puesto?: string | null })
            .validada_por_puesto ?? null
    );
}

/** Quién revisó (y escribió las observaciones de la revisión). */
function revisor(item: Psicometrica | Socioeconomico): string | null {
    const extra = item as {
        revisada_por?: string | null;
        revisado_por?: string | null;
    };

    return extra.revisada_por ?? extra.revisado_por ?? null;
}

/** «Sí»/«No» (o un «1»/«0» de estudios anteriores) → booleano; otro valor → null. */
function siNo(valor: string): boolean | null {
    const limpio = valor.trim().toLowerCase();

    if (['sí', 'si', '1', 'true'].includes(limpio)) {
        return true;
    }

    return ['no', '0', 'false'].includes(limpio) ? false : null;
}

/** Sin revisión aún: «esperando resultados» si no ha llegado nada todavía. */
function etiquetaRevisionPsicometrica(item: Psicometrica): string {
    if (item.revision_resultado === null) {
        const hayResultados =
            item.resultados_en !== null ||
            !!item.resumen_resultados ||
            item.evidencias.length > 0;

        return hayResultados ? 'Pendiente de revisión' : 'Esperando resultados';
    }

    return item.revision_resultado === 'viable'
        ? 'En perfil'
        : 'Fuera de perfil';
}
</script>

<template>
    <SeccionFicha
        titulo="Evaluaciones registradas"
        :icono="ClipboardList"
        tono="info"
        :contador="totalEvaluaciones"
        :indice="indice"
        etiqueta-aria="Evaluaciones del reclutamiento"
    >
        <div
            v-if="pestanas.length > 2"
            class="flex flex-wrap gap-1.5"
            role="tablist"
            aria-label="Filtrar evaluaciones"
        >
            <button
                v-for="p in pestanas"
                :key="p.clave"
                type="button"
                role="tab"
                :aria-selected="pestana === p.clave"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm font-medium transicion-ui focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="
                    pestana === p.clave
                        ? 'border-primary/30 bg-salvia text-[var(--mrl-petroleo)] shadow-xs'
                        : 'border-transparent bg-[var(--mrl-fondo)] text-muted-foreground hover:bg-crema/60 hover:text-foreground'
                "
                @click="pestana = p.clave"
            >
                <component :is="p.icono" class="size-4" aria-hidden="true" />
                {{ p.etiqueta }}
                <span
                    class="rounded-full bg-[var(--mrl-superficie)] px-1.5 text-xs tabular-nums"
                    >{{ p.total }}</span
                >
            </button>
        </div>

        <CambioSuave>
            <div :key="pestana" class="grid items-start gap-3 xl:grid-cols-2">
                <!-- Entrevistas -->
                <template v-if="ve('entrevistas')">
                    <article
                        v-for="item in candidato.entrevistas"
                        :key="`e${item.id}`"
                        class="flex min-w-0 flex-col gap-2.5 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] p-3.5 text-sm transicion-ui hover:-translate-y-0.5 hover:shadow-sm"
                    >
                        <header class="flex flex-wrap items-center gap-2.5">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                                aria-hidden="true"
                            >
                                <MessagesSquare class="size-4" />
                            </span>
                            <p class="min-w-0 flex-1 font-semibold">
                                Entrevista
                            </p>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="TONO_SUAVE[tonoViable(item.resultado)]"
                                >{{ item.resultado_etiqueta }}</span
                            >
                        </header>
                        <ul
                            class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                        >
                            <li class="inline-flex items-center gap-1">
                                <CalendarDays
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ formatearFechaHora(item.realizada_en) }}
                            </li>
                            <li class="inline-flex items-center gap-1">
                                <UserRound
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ item.entrevistador ?? 'Sin entrevistador' }}
                            </li>
                        </ul>
                        <TextoLargo
                            v-if="item.observaciones"
                            etiqueta="Observaciones de la entrevista"
                            :autor="item.entrevistador"
                            :texto="item.observaciones"
                        />
                    </article>
                </template>

                <!-- Psicométricas -->
                <template v-if="ve('psicometricas')">
                    <article
                        v-for="item in candidato.psicometricas"
                        :key="`p${item.id}`"
                        class="flex min-w-0 flex-col gap-2.5 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] p-3.5 text-sm transicion-ui hover:-translate-y-0.5 hover:shadow-sm"
                    >
                        <header class="flex flex-wrap items-center gap-2.5">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-info-soft text-info"
                                aria-hidden="true"
                            >
                                <Brain class="size-4" />
                            </span>
                            <p class="min-w-0 flex-1 font-semibold">
                                Psicométricas
                            </p>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="
                                    TONO_SUAVE[
                                        tonoViable(item.revision_resultado)
                                    ]
                                "
                                >{{ etiquetaRevisionPsicometrica(item) }}</span
                            >
                        </header>
                        <ul
                            class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                        >
                            <li
                                v-if="item.link"
                                class="inline-flex items-center gap-1"
                            >
                                <Link2 class="size-3.5" aria-hidden="true" />
                                Link enviado
                                {{ formatearFechaHora(item.enviada_en) }}
                            </li>
                            <li
                                v-if="item.resultados_en"
                                class="inline-flex items-center gap-1"
                            >
                                <CalendarDays
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Resultados
                                {{ formatearFechaHora(item.resultados_en) }}
                            </li>
                            <li
                                v-if="item.revisada_en"
                                class="inline-flex items-center gap-1"
                            >
                                <ShieldCheck
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Revisada
                                {{ formatearFechaHora(item.revisada_en) }}
                            </li>
                        </ul>
                        <TextoLargo
                            v-if="item.resumen_resultados"
                            etiqueta="Resumen de resultados"
                            :texto="item.resumen_resultados"
                        />
                        <TextoLargo
                            v-if="item.revision_observaciones"
                            etiqueta="Observaciones de la revisión"
                            :autor="revisor(item)"
                            :texto="item.revision_observaciones"
                        />
                        <div
                            v-if="item.evidencias.length"
                            class="flex flex-wrap gap-2"
                        >
                            <button
                                v-for="ev in item.evidencias"
                                :key="ev.id"
                                type="button"
                                class="inline-flex max-w-full tarjeta-interactiva items-center gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 py-1.5 text-left text-xs font-medium"
                                :aria-label="`Ver ${ev.nombre}`"
                                @click="emit('ver', ev)"
                            >
                                <Brain
                                    class="size-4 shrink-0 text-info"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 wrap-anywhere">{{
                                    ev.nombre
                                }}</span>
                                <Eye
                                    class="size-3.5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </article>
                </template>

                <!-- Socioeconómico -->
                <template v-if="ve('socioeconomicos')">
                    <article
                        v-for="item in candidato.socioeconomicos"
                        :key="`s${item.id}`"
                        class="flex min-w-0 flex-col gap-2.5 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] p-3.5 text-sm transicion-ui hover:-translate-y-0.5 hover:shadow-sm"
                    >
                        <header class="flex flex-wrap items-center gap-2.5">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-crema text-bronce"
                                aria-hidden="true"
                            >
                                <Home class="size-4" />
                            </span>
                            <p class="min-w-0 flex-1 font-semibold">
                                Estudio socioeconómico
                            </p>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="TONO_SUAVE[tonoViable(item.resultado)]"
                                >{{ item.resultado_etiqueta }}</span
                            >
                        </header>
                        <ul
                            class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                        >
                            <li class="inline-flex items-center gap-1">
                                <CalendarDays
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Visita {{ formatearFecha(item.fecha_visita) }}
                            </li>
                            <li class="inline-flex items-center gap-1">
                                <UserRound
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ item.visitador ?? 'Sin visitador' }}
                            </li>
                            <li
                                v-if="item.revisado_en"
                                class="inline-flex items-center gap-1"
                            >
                                <ShieldCheck
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Revisado
                                {{ formatearFechaHora(item.revisado_en) }}
                            </li>
                        </ul>
                        <p
                            v-if="item.direccion"
                            class="flex items-start gap-1.5 text-xs break-words"
                        >
                            <MapPin
                                class="mt-0.5 size-3.5 shrink-0 text-bronce"
                                aria-hidden="true"
                            />
                            <span class="min-w-0">{{ item.direccion }}</span>
                        </p>
                        <ul
                            v-if="item.criterios.length"
                            class="flex flex-wrap gap-1.5"
                            aria-label="Criterios de la visita"
                        >
                            <li
                                v-for="criterio in item.criterios"
                                :key="criterio.clave"
                                class="inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-1 text-xs"
                                :class="
                                    siNo(criterio.valor) === null
                                        ? 'bg-[var(--mrl-superficie)]'
                                        : siNo(criterio.valor)
                                          ? 'bg-success-soft'
                                          : 'bg-danger-soft'
                                "
                            >
                                <component
                                    :is="
                                        siNo(criterio.valor) === null
                                            ? Hourglass
                                            : siNo(criterio.valor)
                                              ? CircleCheck
                                              : CircleX
                                    "
                                    class="size-3.5 shrink-0"
                                    :class="
                                        siNo(criterio.valor) === null
                                            ? 'text-bronce'
                                            : siNo(criterio.valor)
                                              ? 'text-success'
                                              : 'text-destructive'
                                    "
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 break-words">{{
                                    criterio.etiqueta
                                }}</span>
                                <strong class="shrink-0">{{
                                    siNo(criterio.valor) === null
                                        ? criterio.valor
                                        : siNo(criterio.valor)
                                          ? 'Sí'
                                          : 'No'
                                }}</strong>
                            </li>
                        </ul>
                        <div
                            v-if="item.riesgos"
                            class="flex items-start gap-2 rounded-xl bg-warning-soft px-3 py-2 text-sm"
                        >
                            <TriangleAlert
                                class="mt-0.5 size-4 shrink-0 text-warning"
                                aria-hidden="true"
                            />
                            <p class="min-w-0 break-words whitespace-pre-line">
                                <strong>Riesgos anotados:</strong> «{{
                                    item.riesgos
                                }}»
                            </p>
                        </div>
                        <TextoLargo
                            v-if="item.observaciones"
                            etiqueta="Observaciones de la visita"
                            :autor="item.visitador"
                            :texto="item.observaciones"
                        />
                        <TextoLargo
                            v-if="item.revision_observaciones"
                            etiqueta="Observaciones de la revisión"
                            :autor="revisor(item)"
                            :texto="item.revision_observaciones"
                        />
                        <div
                            v-if="item.evidencias.length"
                            class="flex flex-wrap gap-2"
                        >
                            <button
                                v-for="ev in item.evidencias"
                                :key="ev.id"
                                type="button"
                                class="inline-flex max-w-full tarjeta-interactiva items-center gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 py-1.5 text-left text-xs font-medium"
                                :aria-label="`Ver ${ev.nombre}`"
                                @click="emit('ver', ev)"
                            >
                                <component
                                    :is="ev.tipo === 'video' ? Video : Home"
                                    class="size-4 shrink-0 text-bronce"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 wrap-anywhere">{{
                                    ev.nombre
                                }}</span>
                                <Eye
                                    class="size-3.5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </article>
                </template>

                <!-- Referencias -->
                <template v-if="ve('referencias')">
                    <article
                        v-for="item in candidato.referencias"
                        :key="`r${item.id}`"
                        class="flex min-w-0 flex-col gap-2.5 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] p-3.5 text-sm transicion-ui hover:-translate-y-0.5 hover:shadow-sm"
                    >
                        <header class="flex flex-wrap items-center gap-2.5">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-success-soft text-success"
                                aria-hidden="true"
                            >
                                <UserCheck class="size-4" />
                            </span>
                            <p class="min-w-0 flex-1 font-semibold break-words">
                                Referencia · {{ item.empresa }}
                            </p>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="TONO_SUAVE[tonoViable(item.resultado)]"
                                >{{ item.resultado_etiqueta }}</span
                            >
                        </header>
                        <dl class="grid gap-1.5 text-xs sm:grid-cols-2">
                            <div
                                class="flex min-w-0 items-start gap-2 rounded-lg bg-[var(--mrl-superficie)] px-2.5 py-1.5"
                            >
                                <UserRound
                                    class="mt-0.5 size-3.5 shrink-0 text-bronce"
                                    aria-hidden="true"
                                />
                                <div class="min-w-0">
                                    <dt class="text-muted-foreground">
                                        Dio la referencia
                                    </dt>
                                    <dd class="font-medium break-words">
                                        {{ item.contacto
                                        }}<template v-if="item.relacion_puesto">
                                            ·
                                            {{ item.relacion_puesto }}</template
                                        >
                                    </dd>
                                    <dd
                                        class="inline-flex items-center gap-1 text-muted-foreground"
                                    >
                                        <Phone
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                        {{ item.telefono ?? 'Sin teléfono' }}
                                    </dd>
                                </div>
                            </div>
                            <div
                                class="flex min-w-0 items-start gap-2 rounded-lg bg-[var(--mrl-superficie)] px-2.5 py-1.5"
                            >
                                <Building2
                                    class="mt-0.5 size-3.5 shrink-0 text-bronce"
                                    aria-hidden="true"
                                />
                                <div class="min-w-0">
                                    <dt class="text-muted-foreground">
                                        Validó en PEOPLE
                                    </dt>
                                    <dd class="font-medium break-words">
                                        {{ item.validada_por ?? '—'
                                        }}<template
                                            v-if="puestoDeQuienValido(item)"
                                        >
                                            ·
                                            {{
                                                puestoDeQuienValido(item)
                                            }}</template
                                        >
                                    </dd>
                                    <dd
                                        class="inline-flex items-center gap-1 text-muted-foreground"
                                    >
                                        <CalendarDays
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                        {{
                                            formatearFecha(
                                                item.fecha_validacion,
                                            )
                                        }}
                                    </dd>
                                </div>
                            </div>
                        </dl>
                        <TextoLargo
                            v-if="item.observaciones"
                            etiqueta="Observaciones"
                            :autor="item.validada_por"
                            :texto="item.observaciones"
                        />
                        <div
                            v-if="item.evidencias.length"
                            class="flex flex-wrap gap-2"
                        >
                            <button
                                v-for="ev in item.evidencias"
                                :key="ev.id"
                                type="button"
                                class="inline-flex max-w-full tarjeta-interactiva items-center gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 py-1.5 text-left text-xs font-medium"
                                :aria-label="`Ver ${ev.nombre}`"
                                @click="emit('ver', ev)"
                            >
                                <Paperclip
                                    class="size-4 shrink-0 text-success"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 wrap-anywhere">{{
                                    ev.nombre
                                }}</span>
                                <Eye
                                    class="size-3.5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </article>
                </template>
            </div>
        </CambioSuave>
    </SeccionFicha>
</template>
