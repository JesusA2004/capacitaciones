<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { MapPin } from '@lucide/vue';
import { computed, ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import SelectorLadoRegion from '@/components/Organigrama/SelectorLadoRegion.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { clasificar } from '@/routes/administracion/jerarquia-puestos/personas';
import type { NodoOrganigramaPersona, RegionOrganigrama } from '@/types';

/**
 * Clasificar Q1 / Q3 a todos los Gerentes de Sucursal en una sola vista:
 * un clic por persona. Los «Por clasificar» van primero.
 */
const props = defineProps<{
    nodos: NodoOrganigramaPersona[];
    regiones: RegionOrganigrama[];
}>();

const abierto = defineModel<boolean>('open', { required: true });

const soloPendientes = ref(true);
const guardando = ref<number | null>(null);

const gerentes = computed(() =>
    props.nodos
        .filter((n) => n.tipo === 'persona' && n.persona && n.clasificacion)
        .sort(
            (a, b) =>
                Number(b.clasificacion?.estado === 'por_clasificar') -
                    Number(a.clasificacion?.estado === 'por_clasificar') ||
                (a.sucursal?.nombre ?? '').localeCompare(
                    b.sucursal?.nombre ?? '',
                    'es',
                ),
        ),
);

const pendientes = computed(
    () =>
        gerentes.value.filter(
            (n) => n.clasificacion?.estado === 'por_clasificar',
        ).length,
);

const visibles = computed(() =>
    soloPendientes.value && pendientes.value > 0
        ? gerentes.value.filter(
              (n) => n.clasificacion?.estado === 'por_clasificar',
          )
        : gerentes.value,
);

function elegir(nodo: NodoOrganigramaPersona, regionId: number | null): void {
    if (!nodo.persona) {
        return;
    }

    guardando.value = nodo.persona.id;
    router.put(
        clasificar.url(nodo.persona.id),
        { region_id: regionId },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                guardando.value = null;
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="pr-6"
                    >Clasificar gerentes de sucursal en Q1 / Q3</DialogTitle
                >
                <DialogDescription>
                    Cada gerente pertenece a UN lado. Con un clic queda
                    clasificado y su jefe directo se recalcula. El punto dorado
                    solo sugiere la región de su sucursal en la matriz
                    comercial; no se aplica sola.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-wrap items-center gap-2 text-sm">
                <button
                    type="button"
                    class="rounded-full border px-3 py-1"
                    :class="
                        soloPendientes
                            ? 'border-warning/40 bg-warning-soft text-warning'
                            : 'border-border bg-card text-muted-foreground'
                    "
                    @click="soloPendientes = true"
                >
                    Por clasificar ({{ pendientes }})
                </button>
                <button
                    type="button"
                    class="rounded-full border px-3 py-1"
                    :class="
                        !soloPendientes
                            ? 'border-primary bg-salvia text-primary'
                            : 'border-border bg-card text-muted-foreground'
                    "
                    @click="soloPendientes = false"
                >
                    Todos ({{ gerentes.length }})
                </button>
            </div>

            <ul
                class="-mx-1 flex max-h-[60vh] flex-col gap-1.5 overflow-y-auto px-1"
            >
                <li
                    v-for="n in visibles"
                    :key="n.clave"
                    class="flex flex-col gap-2 rounded-2xl border border-border bg-card p-3 sm:flex-row sm:items-center"
                    :class="
                        n.clasificacion?.estado === 'por_clasificar'
                            ? 'border-dashed border-warning/40'
                            : ''
                    "
                >
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <ColaboradorAvatar
                            :nombre="n.persona?.nombre ?? ''"
                            :foto-url="n.persona?.foto_url"
                            tamano="sm"
                        />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">
                                {{ n.persona?.nombre }}
                            </p>
                            <p
                                class="flex items-center gap-1 truncate text-xs text-muted-foreground"
                            >
                                <MapPin class="size-3" />
                                {{ n.sucursal?.nombre ?? 'Sin sucursal' }}
                                <template v-if="n.clasificacion?.sugerida">
                                    · matriz:
                                    {{
                                        n.clasificacion.sugerida.nombre
                                    }}</template
                                >
                            </p>
                        </div>
                    </div>
                    <SelectorLadoRegion
                        :regiones="regiones"
                        :actual-id="n.clasificacion?.region?.id ?? null"
                        :sugerida-id="n.clasificacion?.sugerida?.id ?? null"
                        :deshabilitado="guardando !== null"
                        @elegir="(id) => elegir(n, id)"
                    />
                </li>
                <li
                    v-if="!visibles.length"
                    class="rounded-2xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
                >
                    No hay gerentes de sucursal activos.
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
