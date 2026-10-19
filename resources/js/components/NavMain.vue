<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

const props = withDefaults(
    defineProps<{
        items: NavItem[];
        titulo?: string;
        /** Clave estable para persistir el plegado (preferible al título). */
        id?: string;
        /** Etapa del ciclo (1–7); los bloques transversales no la llevan. */
        numero?: number;
        /** Icono de la etapa/bloque. */
        icono?: LucideIcon;
    }>(),
    {
        titulo: 'Navegación',
        id: undefined,
        numero: undefined,
        icono: undefined,
    },
);

const page = usePage();
const { currentUrl } = useCurrentUrl();
const { state } = useSidebar();

// Cuando el sidebar está contraído a iconos, los grupos se muestran siempre
// desplegados (solo iconos): el encabezado colapsable se oculta y no tiene
// sentido esconder accesos. El plegado por grupo solo aplica expandido.
const sidebarContraido = computed(() => state.value === 'collapsed');

// Estado (filtro ?estado=) de la navegación actual. Distingue las vistas que
// comparten la misma pantalla —p. ej. las tres vistas del motor de evaluación
// de capacitación inicial (pendiente / capturada / autorizada)— sin duplicar
// rutas.
const estadoActual = computed<string | null>(() => {
    const query = page.url.split('?')[1] ?? '';

    return new URLSearchParams(query).get('estado');
});

function partesHref(href: NavItem['href']): {
    ruta: string;
    estado: string | null;
} {
    const [ruta, query = ''] = toUrl(href).split('?');

    return { ruta, estado: new URLSearchParams(query).get('estado') };
}

// ¿Este acceso corresponde a la navegación actual? Coincide la ruta y, SOLO si
// el acceso declara un filtro `estado`, también ese estado (así las vistas que
// comparten pantalla resaltan por separado, sin afectar a los accesos normales
// aunque la pantalla tenga su propio filtro de estado abierto).
function accesoActivo(href: NavItem['href']): boolean {
    const { ruta, estado } = partesHref(href);

    if (ruta !== currentUrl.value) {
        return false;
    }

    return estado === null ? true : estado === estadoActual.value;
}

// ¿Algún acceso del grupo corresponde a la ruta actual? Coincidencia exacta
// (incluido el foco) o de ruta padre (/rh/expedientes/123 ⇒ grupo activo). Se
// ignora «/» para que el inicio no marque como activo a todos los grupos.
const grupoActivo = computed(() =>
    props.items.some((item) => {
        if (accesoActivo(item.href)) {
            return true;
        }

        const { ruta } = partesHref(item.href);

        return ruta.length > 1 && currentUrl.value.startsWith(`${ruta}/`);
    }),
);

const claveAlmacen = computed(
    () => `mrl.sidebar.grupo.${props.id ?? props.titulo}`,
);

function leerPreferencia(): boolean | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const valor = window.localStorage.getItem(claveAlmacen.value);

        return valor === null ? null : valor === '1';
    } catch {
        return null;
    }
}

function guardarPreferencia(abierto: boolean): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(claveAlmacen.value, abierto ? '1' : '0');
    } catch {
        // localStorage puede no estar disponible (modo privado): el plegado
        // sigue funcionando en memoria, solo no se persiste.
    }
}

// Estado persistido del grupo. Por defecto cerrado; el grupo de la ruta
// activa arranca abierto aunque no haya preferencia guardada.
const abiertoGuardado = ref<boolean>(leerPreferencia() ?? grupoActivo.value);

// Al navegar hacia un acceso de este grupo, se abre automáticamente (sin
// perder la posibilidad de cerrarlo manualmente después).
watch(grupoActivo, (activo) => {
    if (activo) {
        abiertoGuardado.value = true;
    }
});

const abierto = computed<boolean>(() =>
    sidebarContraido.value ? true : abiertoGuardado.value,
);

function alCambiar(valor: boolean): void {
    // En modo iconos el grupo se fuerza abierto: no se persiste nada.
    if (sidebarContraido.value) {
        return;
    }

    abiertoGuardado.value = valor;
    guardarPreferencia(valor);
}
</script>

<template>
    <Collapsible
        as-child
        :open="abierto"
        class="group/colapsable"
        @update:open="alCambiar"
    >
        <SidebarGroup class="px-2 py-1">
            <!-- Encabezado del grupo = disparador del plegado. Se oculta en
                 modo iconos (SidebarGroupLabel) y deja de ser clicable. -->
            <CollapsibleTrigger as-child :disabled="sidebarContraido">
                <SidebarGroupLabel
                    as="button"
                    type="button"
                    class="group/etiqueta flex h-7 w-full cursor-pointer items-center gap-2 rounded-md px-2 text-[11px] font-semibold tracking-[0.1em] text-bronce uppercase transition-colors hover:bg-salvia/60 hover:text-primary focus-visible:ring-2"
                >
                    <!-- Número de etapa, discreto (1–7); los bloques
                         transversales no lo llevan. -->
                    <span
                        v-if="numero"
                        class="flex size-4 shrink-0 items-center justify-center rounded-[5px] bg-oro/15 text-[10px] font-bold text-bronce tabular-nums"
                        aria-hidden="true"
                    >
                        {{ numero }}
                    </span>
                    <component
                        :is="icono"
                        v-else-if="icono"
                        class="size-3.5 shrink-0 text-bronce/70"
                        aria-hidden="true"
                    />
                    <span class="truncate">{{ titulo }}</span>
                    <ChevronRight
                        class="ml-auto size-3.5 shrink-0 text-bronce/70 transition-transform duration-200 group-data-[state=open]/colapsable:rotate-90"
                        aria-hidden="true"
                    />
                </SidebarGroupLabel>
            </CollapsibleTrigger>

            <CollapsibleContent
                class="overflow-hidden data-[state=closed]:animate-colapsable-cerrar data-[state=open]:animate-colapsable-abrir"
            >
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in items" :key="item.title">
                        <SidebarMenuButton
                            as-child
                            :is-active="accesoActivo(item.href)"
                            :tooltip="item.title"
                            class="relative h-10 rounded-xl text-sidebar-foreground transition-colors before:absolute before:inset-y-2 before:left-0 before:w-[3px] before:rounded-full before:bg-transparent hover:bg-salvia/70 hover:text-primary data-[active=true]:bg-verde-suave/80 data-[active=true]:font-semibold data-[active=true]:text-primary data-[active=true]:before:bg-oro"
                        >
                            <Link :href="item.href">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                                <Badge
                                    v-if="item.badge"
                                    variant="secondary"
                                    class="ml-auto text-[10px]"
                                >
                                    {{ item.badge }}
                                </Badge>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </CollapsibleContent>
        </SidebarGroup>
    </Collapsible>
</template>
