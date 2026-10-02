<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { RotateCcw, Search } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import AprobacionesResumen from '@/components/ciclo/AprobacionesResumen.vue';
import Casilla from '@/components/Common/Casilla.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { ciclo as cicloColaborador } from '@/routes/rh/colaboradores';
import { decidir, index, store } from '@/routes/rh/reingresos';
import type { ResumenAprobaciones } from '@/types';

type Resultado = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    curp: string | null;
    rfc: string | null;
    puesto: string | null;
    sucursal: string | null;
    dado_de_baja: boolean;
    fecha_baja: string | null;
    causa_salida: string | null;
    reingreso_abierto: boolean;
};

type ReingresoFila = {
    id: number;
    colaborador: { id: number; nombre: string; numero_empleado: string | null };
    estado: string;
    estado_etiqueta: string;
    motivo: string;
    puesto: string | null;
    sucursal: string | null;
    documentos_requeridos: { id: number; nombre: string | null }[];
    comentario_decision: string | null;
    solicitado_por: string | null;
    decidido_por: string | null;
    creado_en: string | null;
    aprobaciones: ResumenAprobaciones;
    acciones_permitidas: { clave: string; etiqueta: string; tipo: string }[];
};

const props = defineProps<{
    busqueda: string;
    resultados: Resultado[];
    historial: Record<string, unknown> | null;
    reingresos: { data: ReingresoFila[] };
    opciones: {
        puestos: { id: number; nombre: string }[];
        sucursales: { id: number; nombre: string }[];
        tiposDocumento: { id: number; nombre: string }[];
        tiposContratacion: { value: string; etiqueta: string }[];
    };
    puedeSolicitar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Reingresos', href: '' },
        ],
    },
});

const termino = ref(props.busqueda);

function buscar() {
    router.get(
        index.url(),
        { busqueda: termino.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

// Sin escribir nada ya se ven las bajas recientes; la lista se actualiza
// conforme se escribe (sin mínimo de letras ni botón).
watch(termino, useDebounceFn(buscar, 300));

const seleccionado = ref<Resultado | null>(null);
const solicitud = useForm({
    colaborador_id: 0,
    motivo: '',
    puesto_id: null as number | null,
    sucursal_id: null as number | null,
    tipo_contratacion: 'periodo_prueba',
    sueldo_mensual: '',
    fecha_reingreso: new Date().toISOString().slice(0, 10),
    documentos_adicionales: [] as number[],
});

function elegir(r: Resultado) {
    seleccionado.value = r;
    solicitud.colaborador_id = r.id;
}

function solicitar() {
    solicitud.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => (seleccionado.value = null),
    });
}

const decision = useForm({ viable: true, comentario: '' });
const decidiendo = ref<number | null>(null);

function enviarDecision(reingreso: ReingresoFila) {
    decision.post(decidir.url(reingreso.id), {
        preserveScroll: true,
        onSuccess: () => (decidiendo.value = null),
    });
}
</script>

<template>
    <Head title="Reingresos" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Reingresos"
            descripcion="Busca a la persona, revisa su historial y decide. Nunca se crea otro colaborador."
            :icono="RotateCcw"
        />

        <form
            data-tour="reingresos-busqueda"
            class="flex gap-2"
            @submit.prevent="buscar"
        >
            <Input
                v-model="termino"
                placeholder="Número de empleado, CURP, RFC, NSS o nombre"
                class="flex-1"
            />
            <Button type="submit"><Search class="size-4" /> Buscar</Button>
        </form>

        <ul v-if="resultados.length" class="flex flex-col gap-2">
            <li
                v-for="r in resultados"
                :key="r.id"
                class="flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-medium">
                        {{ r.nombre }}
                        <span class="text-xs text-[var(--mrl-texto-suave)]">{{
                            r.numero_empleado
                        }}</span>
                    </p>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        {{ r.puesto ?? '—' }} · {{ r.sucursal ?? '—' }} · CURP
                        {{ r.curp ?? '—' }}
                    </p>
                    <p v-if="r.dado_de_baja" class="text-xs">
                        Baja {{ r.fecha_baja ?? '' }} · causa:
                        {{ r.causa_salida ?? 'sin registro' }}
                    </p>
                    <p v-else class="text-xs text-[var(--mrl-verde)]">
                        Activo actualmente
                    </p>
                </div>
                <Link
                    :href="cicloColaborador.url(r.id)"
                    class="text-xs text-[var(--mrl-petroleo)] hover:underline"
                    >Ver historial</Link
                >
                <Button
                    v-if="
                        puedeSolicitar && r.dado_de_baja && !r.reingreso_abierto
                    "
                    size="sm"
                    @click="elegir(r)"
                    >Solicitar reingreso</Button
                >
                <span
                    v-else-if="r.reingreso_abierto"
                    class="text-xs text-[var(--mrl-dorado-oscuro)]"
                    >Reingreso en curso</span
                >
            </li>
        </ul>
        <p v-else-if="busqueda" class="text-sm text-[var(--mrl-texto-suave)]">
            Sin coincidencias para «{{ busqueda }}».
        </p>

        <form
            v-if="seleccionado"
            class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-petroleo)]/30 bg-[var(--mrl-superficie)] p-5"
            @submit.prevent="solicitar"
        >
            <h2 class="text-sm font-semibold">
                Solicitar reingreso de {{ seleccionado.nombre }}
            </h2>
            <div class="grid gap-1.5">
                <Label>Motivo</Label
                ><Textarea v-model="solicitud.motivo" rows="2" /><InputError
                    :message="solicitud.errors.motivo"
                />
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label>Puesto</Label>
                    <SelectSimple
                        v-model="solicitud.puesto_id"
                        numerico
                        :opciones="
                            opciones.puestos.map((p) => ({
                                value: p.id,
                                label: p.nombre,
                            }))
                        "
                        opcion-vacia="El mismo"
                        placeholder="El mismo"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Sucursal</Label>
                    <SelectSimple
                        v-model="solicitud.sucursal_id"
                        numerico
                        :opciones="
                            opciones.sucursales.map((s) => ({
                                value: s.id,
                                label: s.nombre,
                            }))
                        "
                        opcion-vacia="La misma"
                        placeholder="La misma"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Fecha de reingreso</Label
                    ><DatePicker v-model="solicitud.fecha_reingreso" />
                </div>
                <div class="grid gap-1.5">
                    <Label>Sueldo mensual</Label
                    ><Input
                        v-model="solicitud.sueldo_mensual"
                        type="number"
                        min="0"
                        step="0.01"
                    />
                </div>
            </div>
            <fieldset class="grid gap-1 text-sm">
                <legend class="mb-1 text-sm font-medium">
                    Pedir de nuevo expresamente (además de vencidos y faltantes)
                </legend>
                <label
                    v-for="t in opciones.tiposDocumento"
                    :key="t.id"
                    class="flex items-center gap-2"
                    ><Casilla
                        v-model="solicitud.documentos_adicionales"
                        :value="t.id"
                    />
                    {{ t.nombre }}</label
                >
            </fieldset>
            <InputError
                :message="
                    (solicitud.errors as Record<string, string>).colaborador
                "
            />
            <div class="flex gap-2">
                <Button type="submit" :disabled="solicitud.processing"
                    >Enviar a RH</Button
                ><Button
                    type="button"
                    variant="ghost"
                    @click="seleccionado = null"
                    >Cancelar</Button
                >
            </div>
        </form>

        <section data-tour="reingresos-lista" class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold">Solicitudes de reingreso</h2>
            <p
                v-if="!reingresos.data.length"
                class="text-sm text-[var(--mrl-texto-suave)]"
            >
                Sin solicitudes.
            </p>
            <article
                v-for="r in reingresos.data"
                :key="r.id"
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="cicloColaborador.url(r.colaborador.id)"
                        class="font-medium hover:underline"
                        >{{ r.colaborador.nombre }}</Link
                    >
                    <span
                        class="rounded-full bg-[var(--mrl-fondo)] px-2 py-0.5 text-xs"
                        >{{ r.estado_etiqueta }}</span
                    >
                    <span class="ml-auto text-xs text-[var(--mrl-texto-suave)]"
                        >Solicitó {{ r.solicitado_por ?? '—' }}</span
                    >
                </div>
                <p class="mt-1">{{ r.motivo }}</p>
                <p v-if="r.documentos_requeridos.length" class="mt-1 text-xs">
                    Documentos a renovar:
                    {{
                        r.documentos_requeridos.map((d) => d.nombre).join(', ')
                    }}
                </p>
                <p v-if="r.comentario_decision" class="mt-1 text-xs">
                    Decisión: {{ r.comentario_decision }} ({{ r.decidido_por }})
                </p>

                <div v-if="r.acciones_permitidas.length" class="mt-3">
                    <Button
                        v-if="decidiendo !== r.id"
                        size="sm"
                        @click="decidiendo = r.id"
                        >Decidir reingreso</Button
                    >
                    <form
                        v-else
                        class="flex flex-col gap-2"
                        @submit.prevent="enviarDecision(r)"
                    >
                        <div class="flex gap-2">
                            <Button
                                type="button"
                                :variant="
                                    decision.viable ? 'default' : 'outline'
                                "
                                @click="decision.viable = true"
                                >Viable</Button
                            >
                            <Button
                                type="button"
                                :variant="
                                    !decision.viable ? 'destructive' : 'outline'
                                "
                                @click="decision.viable = false"
                                >No viable</Button
                            >
                        </div>
                        <Textarea
                            v-model="decision.comentario"
                            rows="2"
                            :placeholder="
                                decision.viable
                                    ? 'Comentario (opcional)'
                                    : 'Motivo (obligatorio)'
                            "
                        />
                        <InputError
                            :message="
                                decision.errors.comentario ??
                                (decision.errors as Record<string, string>)
                                    .aprobacion
                            "
                        />
                        <Button
                            type="submit"
                            size="sm"
                            class="w-fit"
                            :disabled="decision.processing"
                            >Confirmar</Button
                        >
                    </form>
                </div>
                <AprobacionesResumen
                    v-if="r.aprobaciones.ronda"
                    class="mt-3"
                    :aprobaciones="r.aprobaciones"
                />
            </article>
        </section>
    </div>
</template>
