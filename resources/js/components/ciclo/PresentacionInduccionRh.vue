<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    BookOpen,
    CircleCheck,
    ClipboardCheck,
    Presentation,
    Send,
    UserRound,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EnvioDescriptivoPuestoRh from '@/components/ciclo/EnvioDescriptivoPuestoRh.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
    cancelar,
    colaboradores,
    enviar,
    store,
} from '@/routes/rh/induccion/sesiones';
import { mostrar as mostrarIntento } from '@/routes/rh/onboarding/induccion';
import type {
    ColaboradorBusqueda,
    EtapaSeguimientoInduccion,
    SesionInduccionPantalla,
} from '@/types';

/**
 * Lado de RH de «Presentar inducción»: ANTES de presentar se elige a quién
 * (buscador dentro del alcance, nunca texto libre); durante la sesión se
 * ve a quién se le está presentando; al terminar, «Enviar evaluación» la
 * habilita y avisa a cada asistente en la app. Debajo, el seguimiento:
 * pendiente de presentar → por contestar → por calificar → resultado.
 * Toda la regla vive en SesionInduccionService.
 */
const props = defineProps<{ datos: SesionInduccionPantalla }>();
const repasando = defineModel<boolean>('repasando', { default: false });

const elegido = ref<ColaboradorBusqueda | null>(null);
const asistentes = ref<ColaboradorBusqueda[]>([]);
const notas = ref('');
const procesando = ref(false);
const errores = ref<Record<string, string>>({});

const sesion = computed(() => props.datos.sesion_en_curso);

function agregar(c: ColaboradorBusqueda | null) {
    if (c && !asistentes.value.some((a) => a.id === c.id)) {
        asistentes.value = [...asistentes.value, c];
    }

    elegido.value = null;
}

function quitar(id: number) {
    asistentes.value = asistentes.value.filter((a) => a.id !== id);
}

function agregarPendientes() {
    for (const fila of props.datos.seguimiento) {
        if (fila.etapa === 'pendiente_presentar') {
            agregar({
                id: fila.colaborador.id,
                nombre: fila.colaborador.nombre,
                numero_empleado: fila.colaborador.numero_empleado,
                puesto: fila.colaborador.puesto,
                sucursal: fila.colaborador.sucursal,
            });
        }
    }
}

const pendientesPorPresentar = computed(
    () =>
        props.datos.seguimiento.filter((f) => f.etapa === 'pendiente_presentar')
            .length,
);

function iniciar() {
    procesando.value = true;
    router.post(
        store.url(),
        {
            colaborador_ids: asistentes.value.map((a) => a.id),
            notas: notas.value || null,
        },
        {
            preserveScroll: true,
            onError: (e) => (errores.value = e),
            onSuccess: () => {
                asistentes.value = [];
                notas.value = '';
                errores.value = {};
            },
            onFinish: () => (procesando.value = false),
        },
    );
}

function enviarEvaluacion() {
    if (
        !sesion.value ||
        !window.confirm(
            `¿Terminaste de presentar? Se enviará la evaluación a ${sesion.value.asistentes.length} colaborador(es) y les llegará el aviso en la app.`,
        )
    ) {
        return;
    }

    procesando.value = true;
    router.post(
        enviar.url(sesion.value.id),
        {},
        { preserveScroll: true, onFinish: () => (procesando.value = false) },
    );
}

function cancelarSesion() {
    if (
        !sesion.value ||
        !window.confirm(
            '¿Cancelar la sesión? No se enviará ninguna evaluación.',
        )
    ) {
        return;
    }

    router.post(cancelar.url(sesion.value.id), {}, { preserveScroll: true });
}

const tonos: Record<EtapaSeguimientoInduccion, string> = {
    pendiente_presentar: 'bg-crema',
    por_contestar: 'bg-salvia',
    reevaluacion_por_contestar: 'bg-salvia',
    por_calificar: 'bg-warning-soft',
    reforzamiento: 'bg-danger-soft',
    aprobada: 'bg-success-soft',
};

function fecha(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('es-MX') : '';
}
</script>

<template>
    <!-- Sesión en curso: a quién se le está presentando + Enviar evaluación -->
    <section
        v-if="sesion"
        class="flex flex-col gap-3 rounded-2xl border bg-salvia/50 px-4 py-3 text-sm lg:flex-row lg:items-center"
    >
        <div class="flex flex-1 flex-wrap items-center gap-2">
            <Presentation class="size-4 text-primary" />
            <span class="font-medium">Presentando a:</span>
            <span
                v-for="a in sesion.asistentes"
                :key="a.id"
                class="rounded-full border bg-card px-2.5 py-0.5"
                >{{ a.nombre }}</span
            >
        </div>
        <div class="flex gap-2">
            <Button variant="outline" size="sm" @click="cancelarSesion">
                Cancelar sesión
            </Button>
            <Button size="sm" :disabled="procesando" @click="enviarEvaluacion">
                <Send class="size-4" /> Enviar evaluación
            </Button>
        </div>
    </section>

    <template v-else-if="!repasando">
        <section
            class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]"
        >
            <!-- 1. Antes de presentar: a quién -->
            <div class="flex flex-col gap-4 rounded-3xl border bg-card p-5">
                <div>
                    <h2 class="flex items-center gap-2 text-lg font-semibold">
                        <Users class="size-5 text-primary" /> ¿A quién le
                        presentas la inducción?
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Elige a uno o varios colaboradores (p. ej. un grupo por
                        Meet). Al terminar de presentar, presiona «Enviar
                        evaluación»: antes de eso no se les envía nada.
                    </p>
                </div>

                <BuscadorColaborador
                    :model-value="elegido"
                    :buscar-url="
                        (texto: string) =>
                            colaboradores.url({ query: { q: texto } })
                    "
                    @update:model-value="agregar"
                />

                <button
                    v-if="pendientesPorPresentar > 0"
                    type="button"
                    class="w-fit tarjeta-interactiva rounded-full border bg-crema px-3 py-1 text-xs"
                    @click="agregarPendientes"
                >
                    Agregar a los {{ pendientesPorPresentar }} pendientes de
                    presentar
                </button>

                <ul v-if="asistentes.length" class="flex flex-col gap-2">
                    <li
                        v-for="a in asistentes"
                        :key="a.id"
                        class="flex items-center gap-3 rounded-xl border px-3 py-2"
                    >
                        <UserRound class="size-4 text-primary" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ a.nombre }}
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{
                                    [a.numero_empleado, a.puesto, a.sucursal]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="rounded-md p-1 text-muted-foreground hover:bg-muted"
                            :aria-label="`Quitar a ${a.nombre}`"
                            @click="quitar(a.id)"
                        >
                            <X class="size-4" />
                        </button>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    Aún no eliges a nadie.
                </p>

                <Textarea
                    v-model="notas"
                    rows="2"
                    placeholder="Notas de la sesión (opcional): modalidad, enlace de Meet…"
                />
                <p
                    v-if="errores.colaborador_ids"
                    class="text-sm text-destructive"
                >
                    {{ errores.colaborador_ids }}
                </p>

                <div class="flex flex-wrap gap-2">
                    <Button
                        :disabled="asistentes.length === 0 || procesando"
                        @click="iniciar"
                    >
                        <Presentation class="size-4" /> Iniciar presentación
                    </Button>
                    <Button variant="ghost" @click="repasando = true">
                        <BookOpen class="size-4" /> Solo repasar, sin sesión
                    </Button>
                </div>

                <div v-if="datos.recientes.length" class="border-t pt-3">
                    <p class="mb-2 text-xs font-semibold text-muted-foreground">
                        Tus últimas sesiones
                    </p>
                    <p
                        v-for="s in datos.recientes"
                        :key="s.id"
                        class="text-xs text-muted-foreground"
                    >
                        {{ fecha(s.presentada_en) }} ·
                        {{ s.asistentes.map((a) => a.nombre).join(', ') }}
                    </p>
                </div>
            </div>

            <!-- Seguimiento -->
            <div class="flex flex-col gap-3 rounded-3xl border bg-card p-5">
                <h2 class="flex items-center gap-2 text-lg font-semibold">
                    <ClipboardCheck class="size-5 text-primary" /> Seguimiento
                    de la evaluación de inducción
                </h2>
                <p
                    v-if="datos.seguimiento.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No hay personas en proceso de ingreso dentro de tu alcance.
                </p>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="fila in datos.seguimiento"
                        :key="fila.colaborador.id"
                        class="flex tarjeta-interactiva flex-col gap-2 rounded-2xl border p-3 sm:flex-row sm:flex-wrap sm:items-center"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ fila.colaborador.nombre }}
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{
                                    [
                                        fila.colaborador.puesto,
                                        fila.colaborador.sucursal,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                                <template v-if="fila.presentada_por_rh_en">
                                    · presentada
                                    {{
                                        fecha(fila.presentada_por_rh_en)
                                    }}</template
                                >
                            </p>
                        </div>
                        <span
                            class="w-fit rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="tonos[fila.etapa]"
                        >
                            {{ fila.etapa_etiqueta }}
                            <template v-if="fila.calificacion !== null">
                                · {{ fila.calificacion }}/30</template
                            >
                        </span>
                        <span
                            v-if="fila.laborando"
                            class="flex w-fit items-center gap-1 rounded-full bg-success-soft px-2.5 py-0.5 text-xs font-medium"
                            ><CircleCheck class="size-3.5" /> Contrato
                            indeterminado</span
                        >
                        <Link
                            v-if="fila.intento_por_calificar_id"
                            :href="
                                mostrarIntento(fila.intento_por_calificar_id)
                            "
                            class="text-sm font-medium text-primary underline-offset-4 hover:underline"
                            >Revisar y calificar</Link
                        >
                        <Link
                            v-else-if="fila.ultimo_intento_id"
                            :href="mostrarIntento(fila.ultimo_intento_id)"
                            class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                            >Ver evaluación</Link
                        >
                        <!-- Aprobada: «Enviar descriptivo de puesto» y su acuse -->
                        <EnvioDescriptivoPuestoRh
                            v-if="fila.descriptivo_puesto"
                            class="sm:basis-full"
                            compacto
                            :colaborador-id="fila.colaborador.id"
                            :resumen="fila.descriptivo_puesto"
                        />
                    </li>
                </ul>
            </div>
        </section>
    </template>

    <div v-else class="flex items-center justify-between text-sm">
        <span class="text-muted-foreground"
            >Repaso sin sesión: no se registra ni se envía nada.</span
        >
        <Button variant="ghost" size="sm" @click="repasando = false"
            >Volver a sesiones</Button
        >
    </div>
</template>
