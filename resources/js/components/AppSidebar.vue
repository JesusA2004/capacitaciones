<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useMainNavItems } from '@/composables/useMainNavItems';
import { dashboard } from '@/routes';
import { index as indexPortal } from '@/routes/portal';

// El Portal RH es la experiencia principal (ver docs/PORTAL_RH.md).
// Capacitación se conserva por completo detrás del feature flag
// `capacitacion` (config/features.php, docs/CAPACITACION_PROXIMAMENTE.md):
// con la bandera apagada no se muestra ningún acceso a NADIE, ni siquiera
// super_admin — nada de "botones falsos" ni badges "Fase futura" en el
// menú mientras el módulo no esté terminado.
//
// La lista de accesos (modo colaborador/operativo + Administración) vive en
// useMainNavItems() — compartida con MobileBottomNav.vue, ver
// docs/ROLES_Y_NAVEGACION.md. Nunca dupliques esa lógica aquí.
const { navGroups, tieneAmbosModos, esColaborador, cambiarModo } =
    useMainNavItems();

const page = usePage();
const { state } = useSidebar();

type Modo = 'colaborador' | 'operativo';

const MODOS: { clave: Modo; texto: string }[] = [
    { clave: 'colaborador', texto: 'Mi espacio' },
    { clave: 'operativo', texto: 'Operación RH' },
];

// Selector de modo: la pastilla se desliza al instante (optimista) y se
// vuelve a alinear con el modo real cuando responde el servidor.
const modoVisual = ref<Modo>(esColaborador.value ? 'colaborador' : 'operativo');

function alinearModoVisual(): void {
    modoVisual.value = esColaborador.value ? 'colaborador' : 'operativo';
}

watch(esColaborador, alinearModoVisual);

function elegirModo(modo: Modo): void {
    modoVisual.value = modo;
    cambiarModo(modo);
}

/*
 * Acento oro del acceso activo: UN solo indicador para todo el menú, que se
 * desliza (translateY) de un acceso a otro al navegar, en vez de apagarse en
 * uno y prenderse en otro. Se mide contra el acceso con `data-active`; si el
 * grupo de ese acceso está plegado (o el menú va en modo iconos), se oculta.
 */
const zonaMenu = ref<HTMLElement | null>(null);
const indicador = reactive({ y: 0, alto: 0, visible: false, animar: false });
let observador: ResizeObserver | null = null;

function medirIndicador(animar: boolean): void {
    const zona = zonaMenu.value;

    if (!zona) {
        return;
    }

    const activo = zona.querySelector<HTMLElement>(
        '[data-sidebar="menu-button"][data-active="true"]',
    );
    const contenido = activo?.closest<HTMLElement>(
        '[data-slot="collapsible-content"]',
    );

    if (
        !activo ||
        state.value === 'collapsed' ||
        contenido?.dataset.state === 'closed'
    ) {
        indicador.visible = false;

        return;
    }

    // Mientras el grupo se despliega, el acceso aún puede estar recortado.
    if (contenido) {
        const fondoAcceso =
            activo.getBoundingClientRect().bottom -
            contenido.getBoundingClientRect().top;

        if (fondoAcceso > contenido.clientHeight + 8) {
            indicador.visible = false;

            return;
        }
    }

    // offsetTop acumulado hasta la zona: no lo alteran las animaciones de
    // entrada (transform) de los accesos.
    let y = 0;
    let nodo: HTMLElement | null = activo;

    while (nodo && nodo !== zona) {
        y += nodo.offsetTop;
        nodo = nodo.offsetParent as HTMLElement | null;
    }

    indicador.animar = animar && indicador.visible;
    indicador.y = y + 8;
    indicador.alto = Math.max(activo.offsetHeight - 16, 8);
    indicador.visible = true;
}

// Al navegar: se desliza. Al plegar/desplegar grupos o cambiar el ancho del
// menú: acompaña el movimiento sin transición propia.
watch(
    () => page.url,
    () => {
        alinearModoVisual();
        void nextTick(() => medirIndicador(true));
    },
);

watch(state, () => void nextTick(() => medirIndicador(false)));

onMounted(() => {
    void nextTick(() => medirIndicador(false));

    if (zonaMenu.value && typeof ResizeObserver !== 'undefined') {
        observador = new ResizeObserver(() => medirIndicador(false));
        observador.observe(zonaMenu.value);
    }
});

onBeforeUnmount(() => {
    observador?.disconnect();
    observador = null;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="h-auto rounded-2xl py-1.5 group-data-[collapsible=icon]:h-8 group-data-[collapsible=icon]:py-0 hover:bg-crema/60"
                    >
                        <Link
                            :href="esColaborador ? indexPortal() : dashboard()"
                            aria-label="Ir al inicio"
                        >
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>

            <!-- Selector "Mi espacio" / "Operación RH": solo para quien tiene
                 ambos modos disponibles (ver App\Services\Navigation\NavigationService).
                 Si el usuario solo tiene uno, no se muestra selector alguno. -->
            <div
                v-if="tieneAmbosModos"
                data-tour="selector-modo"
                role="group"
                aria-label="Modo de navegación"
                class="relative mt-1 grid grid-cols-2 rounded-lg bg-sidebar-accent/40 p-1 group-data-[collapsible=icon]:hidden"
            >
                <!-- Pastilla que se desliza entre los dos modos. -->
                <span
                    aria-hidden="true"
                    class="pastilla-modo pointer-events-none absolute inset-y-1 left-1 w-[calc(50%-0.25rem)] rounded-md bg-primary shadow-sm"
                    :class="{ 'translate-x-full': modoVisual === 'operativo' }"
                />
                <button
                    v-for="modo in MODOS"
                    :key="modo.clave"
                    type="button"
                    :aria-pressed="modoVisual === modo.clave"
                    class="relative z-10 presionable h-8 rounded-md px-2 text-xs font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="
                        modoVisual === modo.clave
                            ? 'text-primary-foreground'
                            : 'text-sidebar-foreground hover:text-primary'
                    "
                    @click="elegirModo(modo.clave)"
                >
                    {{ modo.texto }}
                </button>
            </div>
        </SidebarHeader>

        <SidebarContent>
            <!-- Un grupo por etapa del ciclo laboral + bloques transversales
                 (useMainNavItems): Inicio · 1 Reclutamiento · 2 Contratación ·
                 3 Onboarding · 4 Cierre del periodo de prueba · 5 Permanencia ·
                 6 Desarrollo · 7 Cierres laborales · Organización · Reportes ·
                 Configuración. «Inicio» (tablero, pendientes y Solicitudes) va
                 siempre desplegado: `grupo.fijo`. -->
            <div ref="zonaMenu" class="relative flex flex-col gap-2">
                <span
                    aria-hidden="true"
                    class="indicador-activo pointer-events-none absolute top-0 left-2 z-10 w-[3px] rounded-full bg-oro group-data-[collapsible=icon]:hidden"
                    :class="{
                        'indicador-activo--visible': indicador.visible,
                        'indicador-activo--desliza': indicador.animar,
                    }"
                    :style="{
                        height: `${indicador.alto}px`,
                        transform: `translateY(${indicador.y}px)`,
                    }"
                />
                <NavMain
                    v-for="grupo in navGroups"
                    :key="grupo.id"
                    :id="grupo.id"
                    :items="grupo.items"
                    :titulo="grupo.titulo"
                    :numero="grupo.numero"
                    :icono="grupo.icono"
                    :fijo="grupo.fijo || navGroups.length === 1"
                />
            </div>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

<style scoped>
/* Indicador único del acceso activo: aparece con un fundido y, al navegar,
   se desliza hasta el acceso nuevo (solo transform y opacity). */
.indicador-activo {
    opacity: 0;
    transition: opacity var(--mov-base) var(--curva-salida);
}

.indicador-activo--visible {
    opacity: 1;
}

.indicador-activo--desliza {
    transition:
        opacity var(--mov-base) var(--curva-salida),
        transform var(--mov-lento) var(--curva-salida);
}

.pastilla-modo {
    transition: transform var(--mov-lento) var(--curva-salida);
}

@media (prefers-reduced-motion: reduce) {
    .indicador-activo,
    .indicador-activo--desliza,
    .pastilla-modo {
        transition: none;
    }
}

.sin-animaciones .indicador-activo,
.sin-animaciones .pastilla-modo {
    transition: none;
}
</style>
