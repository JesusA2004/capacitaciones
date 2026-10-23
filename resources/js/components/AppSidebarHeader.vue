<script setup lang="ts">
import {
    nextTick,
    onBeforeUnmount,
    onMounted,
    useTemplateRef,
    watch,
} from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import AyudaContextual from '@/components/sistema/AyudaContextual.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useEspacioAccionesPagina } from '@/composables/useEspacioAccionesPagina';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

/** Lo mínimo que se le reserva al breadcrumb antes de prestar espacio a las acciones. */
const MINIMO_BREADCRUMB = 260;
/** Separación entre los elementos de la barra (gap-2). */
const SEPARACION = 8;

const fila = useTemplateRef<HTMLElement>('fila');
const migas = useTemplateRef<HTMLElement>('migas');
const acciones = useTemplateRef<HTMLElement>('acciones');
const { espacioAcciones } = useEspacioAccionesPagina();

/** Ancho que el breadcrumb ocuparía sin recortar ningún nivel. */
function anchoNaturalMigas(): number {
    const lista = migas.value?.querySelector('ol');

    if (!lista) {
        return 0;
    }

    let recortado = 0;

    lista.querySelectorAll<HTMLElement>('[data-recortable]').forEach((el) => {
        recortado += Math.max(0, el.scrollWidth - el.clientWidth);
    });

    return lista.scrollWidth + recortado;
}

function medir(): void {
    if (!fila.value) {
        return;
    }

    // Todo lo que NO son las acciones ni el breadcrumb (botón del menú,
    // ayuda, campana) más las separaciones entre ellos.
    let fijo = 0;
    let elementos = 0;

    for (const hijo of Array.from(fila.value.children) as HTMLElement[]) {
        if (hijo === acciones.value) {
            continue;
        }

        elementos++;

        if (hijo.contains(migas.value)) {
            for (const nieto of Array.from(hijo.children) as HTMLElement[]) {
                if (nieto !== migas.value) {
                    fijo += nieto.offsetWidth + SEPARACION;
                }
            }

            continue;
        }

        fijo += hijo.offsetWidth;
    }

    fijo += elementos * SEPARACION;

    const reservaMigas = Math.min(anchoNaturalMigas(), MINIMO_BREADCRUMB);

    espacioAcciones.value = Math.max(
        0,
        fila.value.clientWidth - fijo - reservaMigas,
    );
}

// Se observa la barra, cada elemento de ella (la ayuda contextual aparece
// después, el contador de la campana cambia) y cada nivel del breadcrumb
// (cambia de ancho al cargar la fuente): cualquiera altera el espacio.
const observador =
    typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(medir);

function observarBarra(): void {
    if (!observador || !fila.value) {
        return;
    }

    observador.disconnect();
    observador.observe(fila.value);

    for (const hijo of Array.from(fila.value.children)) {
        if (hijo !== acciones.value) {
            observador.observe(hijo);
        }
    }

    migas.value
        ?.querySelectorAll('[data-recortable]')
        .forEach((el) => observador.observe(el));

    medir();
}

onMounted(() => {
    observarBarra();
    void document.fonts?.ready.then(medir);
});
onBeforeUnmount(() => observador?.disconnect());
watch(
    () => props.breadcrumbs,
    () => nextTick(observarBarra),
    { deep: true },
);
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 rounded-t-[inherit] border-b border-border/70 bg-card/85 px-3 backdrop-blur-md transition-[width,height] ease-linear group-has-data-[pinned-collapsible=icon]/sidebar-wrapper:h-12 sm:px-4"
    >
        <div
            ref="fila"
            class="flex min-w-0 flex-1 items-center justify-between gap-2"
        >
            <div class="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger class="-ml-1 shrink-0" />
                <div
                    v-if="breadcrumbs && breadcrumbs.length > 0"
                    ref="migas"
                    class="flex min-w-0 flex-1"
                >
                    <Breadcrumbs :breadcrumbs="breadcrumbs" />
                </div>
            </div>
            <!-- Destino de las acciones de módulo (CrudPageHeader) en md+,
                 SOLO si caben junto al breadcrumb (useEspacioAccionesPagina):
                 así un botón como «Nueva solicitud» no ocupa una fila entera
                 arriba del contenido, pero cinco botones largos nunca
                 desbordan la barra (se quedan en la página). -->
            <div
                id="acciones-pagina"
                ref="acciones"
                class="ml-auto hidden shrink-0 items-center justify-end gap-2 md:flex"
            />
            <AyudaContextual />
            <NotificationBell />
        </div>
    </header>
</template>
