<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    ClipboardCheck,
    Eye,
    GraduationCap,
    Presentation,
    UserPlus,
    Video as VideoIcon,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import MaterialInduccionTarjetaRh from '@/components/ciclo/MaterialInduccionTarjetaRh.vue';
import PresentacionInduccionRh from '@/components/ciclo/PresentacionInduccionRh.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import VisorPdf from '@/components/documentos/VisorPdf.vue';
import AsignacionesInduccionRh from '@/components/onboarding/AsignacionesInduccionRh.vue';
import AsignarInduccionDialog from '@/components/onboarding/AsignarInduccionDialog.vue';
import MiInduccionTarjeta from '@/components/onboarding/MiInduccionTarjeta.vue';
import VisorInduccionColaborador from '@/components/onboarding/VisorInduccionColaborador.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { miProceso } from '@/routes/portal';
import {
    vistaColaborador,
    vistaPrevia as vistaPreviaEvaluacion,
} from '@/routes/rh/onboarding/induccion';
import type {
    AccesoInduccionColaborador,
    EstadoMaterialInduccionRh,
    FilaAsignacionInduccion,
    LimitesMaterialInduccion,
    MaterialInduccion,
    MiInduccion,
    SesionInduccionPantalla,
} from '@/types';

/**
 * Inducción Mr. Lana = el PDF OFICIAL (docs/onboarding), dibujado página por
 * página con pdf.js, más un material de explicación (audio XOR video):
 *  - «Inducción institucional» (RH, modoPresentacion): a quién está asignada
 *    y en qué paso va cada persona, «Asignar inducción» (todos / personas /
 *    sucursal), el contenido (presentación, material, evaluación) y «Ver
 *    como colaborador». (La sesión en vivo quedó tras un flag legacy.)
 *  - «Mi espacio» (colaborador): la ve por su cuenta mientras la tiene
 *    asignada (VisorInduccionColaborador). Si el material es VIDEO, su
 *    evaluación se desbloquea al verlo completo (anti-adelanto del
 *    servidor); si es AUDIO, es narración que no bloquea nada.
 */
const props = defineProps<{
    colaboradorBuscado?: string | null;
    /** null cuando el colaborador no puede verla todavía / ya no. */
    pdf: { url: string; titulo: string; total_paginas: number } | null;
    modoPresentacion?: boolean;
    /** RH (legacy): sesión de presentación en vivo. */
    sesionRh?: SesionInduccionPantalla | null;
    /** RH: material de explicación vigente. */
    materialRh?: EstadoMaterialInduccionRh | null;
    /** RH: endpoints para cargar/retirar material. */
    rutasMaterial?: { store: string; destroy: string } | null;
    /** RH: límites reales del backend para la zona de carga. */
    limitesMaterial?: LimitesMaterialInduccion;
    /** RH: si puede subir/retirar material (onboarding.configurar). */
    puedeConfigurarMaterial?: boolean;
    /** RH: personas con la inducción asignada y su paso. */
    asignaciones?: FilaAsignacionInduccion[];
    /** Colaborador: cuándo puede ver la inducción. */
    acceso?: AccesoInduccionColaborador | null;
    /** Colaborador: pasos y lo que RH le asignó. */
    miInduccion?: MiInduccion | null;
    /** Colaborador: avance institucional titular. */
    avanceId?: number | null;
    /** Colaborador: material de explicación activo. */
    material?: MaterialInduccion | null;
    /** Colaborador: si el servidor ya tiene el video por completo. */
    videoCompletado?: boolean;
    /** Colaborador: endpoints ya resueltos. */
    rutas?: {
        pagina: string;
        terminar: string;
        video_iniciar: string;
        video_heartbeat: string;
        audio_progreso: string;
    } | null;
}>();

// `layout` como función: defineOptions() se compila fuera de setup() y no
// puede leer `props` (ver Rh/Importaciones/Show.vue).
defineOptions({
    layout: (pageProps: { modoPresentacion?: boolean }) => ({
        breadcrumbs: pageProps.modoPresentacion
            ? [
                  { title: 'Inicio', href: dashboard() },
                  { title: 'Inducción institucional', href: '' },
              ]
            : [
                  { title: 'Inicio', href: dashboard() },
                  { title: 'Mis pendientes', href: miProceso() },
                  { title: 'Mi inducción', href: '' },
              ],
    }),
});

const puedeVer = computed(
    () =>
        !props.modoPresentacion &&
        props.pdf !== null &&
        (props.acceso?.puede_ver_contenido ?? false) &&
        props.miInduccion,
);

const tipoMaterialRh = computed<'audio' | 'video' | null>(() =>
    props.materialRh?.tipo === 'audio' || props.materialRh?.tipo === 'video'
        ? props.materialRh.tipo
        : null,
);

// --- RH: asignar ---
const dialogo = ref(false);
const contenidoInicial = ref<'ambos' | 'presentacion' | 'material'>('ambos');

function abrirAsignar(contenido: 'ambos' | 'presentacion' | 'material') {
    contenidoInicial.value = contenido;
    dialogo.value = true;
}

const repasando = ref(false);
</script>

<template>
    <Head
        :title="modoPresentacion ? 'Inducción institucional' : 'Mi inducción'"
    />

    <!-- ===================== RH: Inducción institucional ===================== -->
    <div v-if="modoPresentacion" class="pagina-ancha flex flex-col gap-6 pb-10">
        <CrudPageHeader titulo="Inducción institucional" :icono="GraduationCap">
            <Button as-child variant="outline" class="w-full sm:w-auto">
                <Link :href="vistaColaborador.url()"
                    ><Eye class="size-4" /> Ver como colaborador</Link
                >
            </Button>
            <Button class="w-full sm:w-auto" @click="abrirAsignar('ambos')">
                <UserPlus class="size-4" /> Asignar inducción
            </Button>
        </CrudPageHeader>

        <!-- Explicación en lenguaje llano -->
        <section
            class="relative overflow-hidden rounded-3xl border border-border/60 bg-gradient-to-br from-crema via-card to-salvia p-5 shadow-sm sm:p-6"
        >
            <h1 class="text-xl font-semibold">Inducción Mr. Lana</h1>
            <p class="mt-1 max-w-3xl text-sm text-pretty text-muted-foreground">
                Es lo primero que ve cada persona al entrar a Mr. Lana. Se le
                asigna sola al firmar sus contratos y también puedes asignarla
                tú (por persona, por sucursal o a todos). La persona la ve en
                «Mi inducción» —en Mi espacio o en la app—, y al terminar
                contesta su evaluación FO-RH-EI. Al aprobarla empieza a laborar.
            </p>
            <ol class="mt-4 grid gap-2 text-sm sm:grid-cols-4">
                <li
                    class="flex items-center gap-2 rounded-2xl bg-card/80 px-3 py-2"
                >
                    <Presentation class="size-4 text-primary" /> 1 · Ve la
                    presentación
                </li>
                <li
                    class="flex items-center gap-2 rounded-2xl bg-card/80 px-3 py-2"
                >
                    <VideoIcon class="size-4 text-primary" /> 2 · Ve el video
                </li>
                <li
                    class="flex items-center gap-2 rounded-2xl bg-card/80 px-3 py-2"
                >
                    <ClipboardCheck class="size-4 text-primary" /> 3 · Contesta
                    su evaluación
                </li>
                <li
                    class="flex items-center gap-2 rounded-2xl bg-card/80 px-3 py-2"
                >
                    <GraduationCap class="size-4 text-primary" /> 4 · RH
                    califica y aprueba
                </li>
            </ol>
        </section>

        <!-- Legacy: sesión en vivo -->
        <PresentacionInduccionRh
            v-if="sesionRh"
            v-model:repasando="repasando"
            :datos="sesionRh"
        />

        <AsignacionesInduccionRh
            :filas="asignaciones ?? []"
            :material="tipoMaterialRh"
            :buscado="colaboradorBuscado"
        />

        <!-- Contenido -->
        <section
            class="flex flex-col gap-4"
            aria-label="Contenido de la inducción"
        >
            <div>
                <h2 class="text-lg font-semibold">Contenido de la inducción</h2>
                <p class="text-sm text-muted-foreground">
                    Lo que verá cada persona, en este orden. Puedes asignar cada
                    parte por separado.
                </p>
            </div>

            <div
                class="grid grid-cols-1 gap-4 lg:grid-cols-3 [&>article]:min-w-0"
            >
                <article
                    class="flex flex-col gap-3 rounded-3xl border border-border/60 bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-10 items-center justify-center rounded-2xl bg-salvia text-primary"
                        >
                            <Presentation class="size-5" />
                        </span>
                        <div>
                            <h3 class="font-semibold">1 · Presentación</h3>
                            <p class="text-xs text-muted-foreground">
                                PDF oficial ·
                                {{ pdf?.total_paginas ?? 0 }} hojas
                            </p>
                        </div>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        La persona avanza hoja por hoja y se guarda cada hoja
                        vista. También puedes usarla para presentarla por Meet.
                    </p>
                    <div class="mt-auto flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="repasando = !repasando"
                        >
                            <Eye class="size-4" />
                            {{ repasando ? 'Ocultar' : 'Ver / presentar' }}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="abrirAsignar('presentacion')"
                        >
                            <UserPlus class="size-4" /> Asignar solo la
                            presentación
                        </Button>
                    </div>
                </article>

                <MaterialInduccionTarjetaRh
                    :material="materialRh ?? null"
                    :puede-configurar="puedeConfigurarMaterial ?? false"
                    :rutas="rutasMaterial ?? null"
                    :limites="
                        limitesMaterial ?? {
                            video_max_mb: 2048,
                            audio_max_mb: 200,
                        }
                    "
                    @asignar="abrirAsignar('material')"
                />

                <article
                    class="flex flex-col gap-3 rounded-3xl border border-border/60 bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-10 items-center justify-center rounded-2xl bg-info-soft text-info"
                        >
                            <ClipboardCheck class="size-5" />
                        </span>
                        <div>
                            <h3 class="font-semibold">
                                3 · Evaluación FO-RH-EI
                            </h3>
                            <p class="text-xs text-muted-foreground">
                                Se desbloquea al terminar 1 y 2
                            </p>
                        </div>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Las secciones A, B y C se califican solas; los casos
                        prácticos (D) los calificas en «Calificaciones y
                        refuerzo».
                    </p>
                    <div class="mt-auto flex flex-wrap gap-2">
                        <Button as-child variant="outline" size="sm">
                            <Link :href="vistaPreviaEvaluacion()"
                                ><Eye class="size-4" /> Ver la evaluación</Link
                            >
                        </Button>
                    </div>
                </article>
            </div>

            <div
                v-if="repasando && pdf"
                class="flex h-[78vh] min-h-[28rem] flex-col rounded-3xl border border-border/60 bg-card p-3 shadow-sm"
            >
                <p class="mb-2 px-1 text-xs text-muted-foreground">
                    Repaso de RH: no se registra avance de nadie.
                </p>
                <VisorPdf :url="pdf.url" :titulo="pdf.titulo" />
            </div>
        </section>

        <AsignarInduccionDialog
            v-model:open="dialogo"
            :material="tipoMaterialRh"
            :contenido-inicial="contenidoInicial"
        />
    </div>

    <!-- ===================== Colaborador: Mi inducción ===================== -->
    <div
        v-else-if="puedeVer && pdf && miInduccion"
        class="pagina-ancha flex flex-col gap-4 pb-10"
    >
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                {{
                    miInduccion.es_reinduccion
                        ? 'Mi reinducción'
                        : 'Mi inducción'
                }}
                Mr. Lana
            </h1>
            <p class="text-sm text-muted-foreground">
                Tómate tu tiempo: tu avance se guarda solo y puedes continuar
                después.
            </p>
        </header>
        <VisorInduccionColaborador
            :pdf="pdf"
            :induccion="miInduccion"
            :material="material ?? null"
            :video-completado="videoCompletado ?? false"
            :rutas="rutas ?? null"
        />
    </div>

    <!-- Colaborador sin contenido visible (sin asignar / evaluación / completada). -->
    <div v-else class="pagina-ancha flex flex-col gap-4 pb-10">
        <MiInduccionTarjeta v-if="miInduccion" :induccion="miInduccion" />
        <p v-else class="text-sm text-muted-foreground">
            {{
                acceso?.mensaje ??
                'Aún no tienes asignada la Inducción Mr. Lana.'
            }}
        </p>
        <Link
            :href="miProceso()"
            class="inline-flex w-fit items-center gap-2 rounded-xl border border-border/60 px-4 py-2.5 text-sm font-medium transition-colors hover:bg-muted/50"
        >
            Ir a Mis pendientes <ArrowRight class="size-4" />
        </Link>
    </div>
</template>
