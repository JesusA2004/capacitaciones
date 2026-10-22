<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BriefcaseBusiness,
    Check,
    CircleCheck,
    Eye,
    FileText,
    Hourglass,
    ListFilter,
    MailOpen,
    Search,
    Send,
    UserPlus,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import AsignarDialog from '@/components/onboarding/AsignarDialog.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { parametrosPorPaginaActuales } from '@/composables/usePaginacion';
import { formatearFecha } from '@/lib/fechas';
import type { Tono } from '@/lib/tonos';
import { dashboard } from '@/routes';
import { ciclo as cicloColaborador } from '@/routes/rh/colaboradores';
import { enviar as enviarDescriptivo } from '@/routes/rh/colaboradores/descriptivo-puesto';
import { vistaColaborador } from '@/routes/rh/onboarding/descriptivo-puesto';
import {
    previsualizar,
    store,
} from '@/routes/rh/onboarding/descriptivo-puesto/asignaciones';
import type { DescriptivoPuestoColaborador } from '@/types';

/**
 * «Inducción al puesto» (3 · Onboarding): el descriptivo OFICIAL del puesto
 * que RH envía a cada persona y su acuse (enviado → visto → enterado, con la
 * casilla «Enterado(a)…» y su «Estoy de acuerdo con…»). «Asignar
 * descriptivo» lo envía a todos / personas / sucursal; «Ver como
 * colaborador» muestra exactamente lo que la persona ve. Reglas en
 * AcuseDescriptivoPuestoService.
 */
type Fila = {
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        puesto: string | null;
        sucursal: string | null;
    };
    onboarding_estado: string;
    onboarding_estado_etiqueta: string;
    acuse: DescriptivoPuestoColaborador | null;
    puede_enviar: boolean;
    motivo: string | null;
    /** PDF oficial del descriptivo vigente («Ver lo que recibirá»). */
    vista_previa: { url: string; nombre: string } | null;
};

type Paginador = {
    data: Fila[];
    current_page: number;
    last_page: number;
    per_page: number;
    from?: number | null;
    to?: number | null;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

const props = defineProps<{
    colaboradores: Paginador;
    filtros: { busqueda: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Inducción al puesto', href: '' },
        ],
    },
});

type Filtro = 'todas' | 'sin_enviar' | 'enviado' | 'leido' | 'enterado';

const ESTADO: Record<
    Exclude<Filtro, 'todas'>,
    { texto: string; tono: Tono; icono: Component }
> = {
    sin_enviar: { texto: 'Sin enviar', tono: 'neutro', icono: Hourglass },
    enviado: { texto: 'Enviado', tono: 'oro', icono: Send },
    leido: { texto: 'Visto', tono: 'info', icono: MailOpen },
    enterado: { texto: 'Enterado', tono: 'exito', icono: CircleCheck },
};

function estadoDe(f: Fila): Exclude<Filtro, 'todas'> {
    return f.acuse ? f.acuse.estado : 'sin_enviar';
}

const filtro = ref<Filtro>('todas');
const visibles = computed(() =>
    filtro.value === 'todas'
        ? props.colaboradores.data
        : props.colaboradores.data.filter((f) => estadoDe(f) === filtro.value),
);

function conteo(valor: Filtro): number {
    return valor === 'todas'
        ? props.colaboradores.data.length
        : props.colaboradores.data.filter((f) => estadoDe(f) === valor).length;
}

const termino = ref(props.filtros.busqueda);
const enviandoId = ref<number | null>(null);

function filtrar() {
    router.get(
        window.location.pathname,
        {
            ...parametrosPorPaginaActuales(),
            busqueda: termino.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(termino, useDebounceFn(filtrar, 300));

// «Ver lo que recibirá»: el primer envío pasa SIEMPRE por la vista previa
// del PDF oficial y al cerrarla se confirma (RH ve tal cual lo que recibe).
const filaVista = ref<Fila | null>(null);
const verVistaPrevia = ref(false);
const porConfirmar = ref<Fila | null>(null);

function verPdf(fila: Fila) {
    filaVista.value = fila;
    verVistaPrevia.value = true;
}

watch(verVistaPrevia, (abierto) => {
    if (abierto) {
        return;
    }

    const fila = porConfirmar.value;
    porConfirmar.value = null;

    if (
        fila &&
        window.confirm(
            `¿Enviar «${fila.vista_previa?.nombre ?? 'el descriptivo'}» a ${fila.colaborador.nombre}? Es exactamente lo que acabas de revisar.`,
        )
    ) {
        enviarDirecto(fila);
    }
});

function enviar(fila: Fila) {
    if (!fila.acuse && fila.vista_previa) {
        verPdf(fila);
        porConfirmar.value = fila;

        return;
    }

    enviarDirecto(fila);
}

function enviarDirecto(fila: Fila) {
    enviandoId.value = fila.colaborador.id;
    router.post(
        enviarDescriptivo.url(fila.colaborador.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => (enviandoId.value = null),
        },
    );
}

const dialogo = ref(false);

const pasos = ['Enviado', 'Visto', 'Enterado'] as const;

function pasoHecho(f: Fila, i: number): boolean {
    if (!f.acuse) {
        return false;
    }

    return (
        i === 0 ||
        (i === 1 &&
            (f.acuse.leido_en !== null || f.acuse.acusado_en !== null)) ||
        (i === 2 && f.acuse.acusado_en !== null)
    );
}
</script>

<template>
    <Head title="Inducción al puesto" />

    <div class="pagina-ancha flex flex-col gap-5 pb-10">
        <CrudPageHeader titulo="Inducción al puesto" :icono="BriefcaseBusiness">
            <Button class="w-full sm:w-auto" @click="dialogo = true">
                <UserPlus class="size-4" /> Asignar descriptivo de puesto
            </Button>
        </CrudPageHeader>

        <section
            class="rounded-3xl border border-border/60 bg-gradient-to-br from-crema via-card to-salvia p-5 shadow-sm"
        >
            <h1 class="text-xl font-semibold">Descriptivo de puesto</h1>
            <p class="mt-1 max-w-3xl text-sm text-pretty text-muted-foreground">
                Cada persona recibe el descriptivo oficial de SU puesto: lo lee
                en Mi espacio o en la app, marca «Enterado(a) de las actividades
                y responsabilidades de mi puesto» y escribe con qué está de
                acuerdo. A quien está en su ingreso se le envía cuando aprueba
                su evaluación de inducción; al personal que ya labora se le
                puede enviar en cualquier momento.
            </p>
        </section>

        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-5 sm:gap-3">
            <TarjetaMetrica
                etiqueta="Todas"
                :valor="conteo('todas')"
                :icono="ListFilter"
                tono="primario"
                clicable
                :activa="filtro === 'todas'"
                @click="filtro = 'todas'"
            />
            <TarjetaMetrica
                v-for="(e, clave) in ESTADO"
                :key="clave"
                :etiqueta="e.texto"
                :valor="conteo(clave)"
                :icono="e.icono"
                :tono="e.tono"
                clicable
                :activa="filtro === clave"
                @click="filtro = filtro === clave ? 'todas' : clave"
            />
        </div>

        <div class="relative sm:max-w-sm">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="termino"
                class="h-10 pl-9"
                placeholder="Nombre o número de empleado"
                aria-label="Buscar colaborador"
            />
        </div>

        <EmptyState
            v-if="visibles.length === 0"
            :icono="BriefcaseBusiness"
            titulo="Sin colaboradores"
            descripcion="No hay personas en onboarding con este filtro dentro de tu alcance. Para enviarlo a personal activo usa «Asignar descriptivo de puesto»."
        />

        <ul
            v-else
            class="rejilla-auto"
            style="--rejilla-min: 22rem"
            aria-label="Descriptivo de puesto por colaborador"
        >
            <li
                v-for="f in visibles"
                :key="f.colaborador.id"
                class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm"
            >
                <div class="flex items-start gap-3 p-4">
                    <ColaboradorAvatar :nombre="f.colaborador.nombre" />
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="cicloColaborador.url(f.colaborador.id)"
                            class="block truncate font-semibold hover:underline"
                            >{{ f.colaborador.nombre }}</Link
                        >
                        <p class="truncate text-sm text-muted-foreground">
                            {{
                                [
                                    f.colaborador.numero_empleado,
                                    f.colaborador.puesto,
                                    f.colaborador.sucursal,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <ChipEstado
                                :etiqueta="ESTADO[estadoDe(f)].texto"
                                :tono="ESTADO[estadoDe(f)].tono"
                                :icono="ESTADO[estadoDe(f)].icono"
                            />
                            <ChipEstado
                                :etiqueta="f.onboarding_estado_etiqueta"
                                tono="neutro"
                            />
                        </div>
                    </div>
                </div>

                <ol
                    class="grid grid-cols-3 gap-1 px-4 pb-3"
                    aria-label="Avance del acuse"
                >
                    <li
                        v-for="(p, i) in pasos"
                        :key="p"
                        class="flex flex-col gap-1"
                    >
                        <span
                            class="h-1.5 rounded-full"
                            :class="pasoHecho(f, i) ? 'bg-success' : 'bg-muted'"
                        />
                        <span class="text-[11px] text-muted-foreground">{{
                            p
                        }}</span>
                    </li>
                </ol>

                <div
                    v-if="f.acuse"
                    class="flex flex-col gap-1.5 px-4 pb-4 text-xs text-muted-foreground"
                >
                    <p>
                        Enviado {{ formatearFecha(f.acuse.enviado_en) }} por
                        {{ f.acuse.enviado_por }}
                        <template v-if="f.acuse.leido_en">
                            · visto
                            {{ formatearFecha(f.acuse.leido_en) }}</template
                        >
                    </p>
                    <template v-if="f.acuse.acusado_en">
                        <p class="flex items-start gap-1.5 text-foreground">
                            <Check
                                class="mt-0.5 size-3.5 shrink-0 text-success"
                            />
                            {{
                                f.acuse.enterado_texto ??
                                'Enterado(a) de su descriptivo de puesto'
                            }}
                            ·
                            {{
                                formatearFecha(
                                    f.acuse.enterado_en ?? f.acuse.acusado_en,
                                )
                            }}
                        </p>
                        <p
                            v-if="f.acuse.conformidad ?? f.acuse.comentario"
                            class="rounded-xl bg-crema/70 px-3 py-2 text-sm text-foreground"
                        >
                            <span class="font-medium"
                                >Estoy de acuerdo con:</span
                            >
                            «{{ f.acuse.conformidad ?? f.acuse.comentario }}»
                        </p>
                    </template>
                </div>
                <p v-else class="px-4 pb-4 text-xs text-muted-foreground">
                    {{ f.motivo ?? 'Sin enviar' }}
                </p>

                <footer
                    class="mt-auto flex flex-col gap-2 border-t border-border/50 bg-[var(--mrl-fondo)]/60 p-3 sm:flex-row sm:flex-wrap"
                >
                    <Button
                        v-if="f.puede_enviar"
                        class="h-11 flex-1 sm:h-9"
                        :disabled="enviandoId === f.colaborador.id"
                        @click="enviar(f)"
                    >
                        <Send class="size-4" />
                        {{ f.acuse ? 'Recordar' : 'Enviar descriptivo' }}
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        class="h-11 flex-1 sm:h-9"
                    >
                        <Link :href="vistaColaborador.url(f.colaborador.id)"
                            ><Eye class="size-4" /> Ver como colaborador</Link
                        >
                    </Button>
                    <Button
                        v-if="f.vista_previa"
                        variant="ghost"
                        class="h-11 flex-1 sm:h-9"
                        @click="verPdf(f)"
                    >
                        <FileText class="size-4" /> PDF oficial
                    </Button>
                </footer>
            </li>
        </ul>

        <PaginacionTabla :datos="colaboradores" />

        <DocumentPreviewDialog
            v-model:open="verVistaPrevia"
            :preview-url="filaVista?.vista_previa?.url ?? null"
            :nombre="filaVista?.vista_previa?.nombre ?? ''"
            tipo="pdf"
            mime="application/pdf"
            estado="Vista previa — así lo recibirá el colaborador"
        />

        <AsignarDialog
            v-model:open="dialogo"
            titulo="Asignar descriptivo de puesto"
            descripcion="Cada persona recibe el descriptivo oficial vigente de SU puesto en Mi espacio y la app, con un aviso. Quien ya lo tiene o no tiene descriptivo para su puesto se omite."
            :previsualizar-url="previsualizar.url()"
            :enviar-url="store.url()"
            texto-boton="Enviar descriptivo"
        />
    </div>
</template>
