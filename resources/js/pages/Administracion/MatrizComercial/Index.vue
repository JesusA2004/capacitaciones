<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, ChevronDown, MapPin, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { index as indexJerarquiaPuestos } from '@/routes/administracion/jerarquia-puestos';
import { index, responsable as actualizarResponsable } from '@/routes/administracion/matriz-comercial';
import type {
    GestorDisponible,
    NodoComercialArbol,
    ResumenMatrizComercial,
} from '@/types';

defineProps<{
    arbol: NodoComercialArbol | null;
    resumen: ResumenMatrizComercial;
    gestoresDisponibles: GestorDisponible[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Organigrama', href: indexJerarquiaPuestos() },
            { title: 'Matriz comercial', href: index.url() },
        ],
    },
});

const { mostrarExito } = useAlertas();

const COBERTURA_ETIQUETA: Record<string, string> = {
    cubierta: 'Cubierta',
    sin_cubrir: 'Sin cubrir',
    inactiva: 'Inactiva',
    no_aplica: '',
};

const COBERTURA_CLASE: Record<string, string> = {
    cubierta: 'border-success/30 bg-success/10 text-success',
    sin_cubrir: 'border-warning/30 bg-warning/10 text-warning',
    inactiva: 'border-border bg-muted text-muted-foreground',
    no_aplica: '',
};

// Regiones/zonas abiertas por defecto: la primera región con datos, el
// resto colapsado (200 rutas no caben todas expandidas a la vez).
const abiertos = ref<Set<number>>(new Set());

function alternar(id: number) {
    if (abiertos.value.has(id)) {
        abiertos.value.delete(id);
    } else {
        abiertos.value.add(id);
    }

    // Forzar reactividad de Set.
    abiertos.value = new Set(abiertos.value);
}

function estaAbierto(id: number): boolean {
    return abiertos.value.has(id);
}

function asignarGestor(nodo: NodoComercialArbol, valor: string) {
    router.put(
        actualizarResponsable.url(nodo.id),
        { responsable_user_id: valor === '__ninguno__' ? null : valor },
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Gestor actualizado.'),
        },
    );
}
</script>

<template>
    <Head title="Matriz comercial" />

    <div class="flex flex-col gap-6 p-4 lg:p-6">
        <CrudPageHeader
            titulo="Matriz comercial"
            descripcion="Estructura territorial MATRIZ → Región → Zona → Ruta, y quién cubre cada ruta hoy. Las vacantes disponibles se ven en Vacantes, no aquí."
            :icono="MapPin"
        />

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="text-2xl font-semibold">{{ resumen.activas }}</p>
                <p class="text-xs text-muted-foreground">Rutas activas</p>
            </div>
            <div class="rounded-2xl border border-success/30 bg-success/5 p-4">
                <p class="text-2xl font-semibold text-success">
                    {{ resumen.cubiertas }}
                </p>
                <p class="text-xs text-muted-foreground">Cubiertas</p>
            </div>
            <div class="rounded-2xl border border-warning/30 bg-warning/5 p-4">
                <p class="text-2xl font-semibold text-warning">
                    {{ resumen.sin_cubrir }}
                </p>
                <p class="text-xs text-muted-foreground">Sin cubrir</p>
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="text-2xl font-semibold">
                    {{ resumen.porcentaje_cobertura }}%
                </p>
                <p class="text-xs text-muted-foreground">Cobertura</p>
            </div>
        </div>

        <div v-if="!arbol" class="rounded-2xl border border-dashed border-border/60 p-8 text-center text-sm text-muted-foreground">
            Todavía no hay matriz comercial cargada. Corre
            <code class="rounded bg-muted px-1.5 py-0.5">php artisan db:seed --class=MatrizComercialSeeder</code>.
        </div>

        <div v-else class="flex flex-col gap-4">
            <div
                v-for="region in arbol.hijos"
                :key="region.id"
                class="rounded-2xl border border-border/60 bg-card"
            >
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-2 p-4 text-left"
                    @click="alternar(region.id)"
                >
                    <span class="font-semibold">{{ region.nombre }}</span>
                    <span class="flex items-center gap-2 text-xs text-muted-foreground">
                        {{ region.hijos.length }} zona(s)
                        <ChevronDown
                            class="size-4 transition-transform"
                            :class="{ 'rotate-180': estaAbierto(region.id) }"
                        />
                    </span>
                </button>

                <div v-if="estaAbierto(region.id)" class="flex flex-col gap-3 border-t border-border/60 p-4">
                    <p
                        v-if="!region.hijos.length"
                        class="text-sm text-muted-foreground"
                    >
                        Región pendiente de configurar: todavía no tiene
                        zonas cargadas.
                    </p>

                    <Collapsible
                        v-for="zona in region.hijos"
                        :key="zona.id"
                        class="rounded-xl border border-border/60"
                    >
                        <CollapsibleTrigger
                            class="flex w-full items-center justify-between gap-2 p-3 text-left text-sm font-medium"
                        >
                            <span class="flex items-center gap-2">
                                {{ zona.nombre }}
                                <Badge v-if="!zona.activa" variant="outline"
                                    >Inactiva</Badge
                                >
                                <Badge v-if="!zona.sucursal" variant="outline"
                                    >Sin sucursal vinculada</Badge
                                >
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ zona.hijos.length }} ruta(s)
                            </span>
                        </CollapsibleTrigger>
                        <CollapsibleContent class="grid grid-cols-1 gap-2 border-t border-border/60 p-3 sm:grid-cols-2 lg:grid-cols-3">
                            <div
                                v-for="ruta in zona.hijos"
                                :key="ruta.id"
                                class="flex flex-col gap-2 rounded-lg border border-border/60 p-3"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm font-medium">{{
                                        ruta.nombre
                                    }}</span>
                                    <component
                                        :is="
                                            ruta.cobertura === 'cubierta'
                                                ? CheckCircle2
                                                : XCircle
                                        "
                                        v-if="ruta.cobertura !== 'inactiva'"
                                        class="size-4 shrink-0"
                                        :class="
                                            ruta.cobertura === 'cubierta'
                                                ? 'text-success'
                                                : 'text-warning'
                                        "
                                    />
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    <Badge
                                        variant="outline"
                                        :class="COBERTURA_CLASE[ruta.cobertura]"
                                    >
                                        {{ COBERTURA_ETIQUETA[ruta.cobertura] }}
                                    </Badge>
                                    <Badge
                                        v-if="ruta.estado_operativo === 'vencidos'"
                                        variant="outline"
                                        class="border-destructive/30 bg-destructive/10 text-destructive"
                                        >Vencidos</Badge
                                    >
                                    <Badge
                                        v-if="ruta.estado_operativo === 'castigo'"
                                        variant="outline"
                                        class="border-destructive/30 bg-destructive/10 text-destructive"
                                        >Castigo</Badge
                                    >
                                </div>

                                <Select
                                    v-if="ruta.activa"
                                    :model-value="
                                        ruta.responsable
                                            ? String(ruta.responsable.id)
                                            : '__ninguno__'
                                    "
                                    @update:model-value="
                                        (v) => asignarGestor(ruta, String(v))
                                    "
                                >
                                    <SelectTrigger class="h-8 text-xs">
                                        <SelectValue placeholder="Sin gestor" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__ninguno__"
                                            >Sin gestor</SelectItem
                                        >
                                        <SelectItem
                                            v-for="gestor in gestoresDisponibles"
                                            :key="gestor.id"
                                            :value="String(gestor.id)"
                                        >
                                            {{ gestor.name }}
                                            {{ gestor.apellidos ?? '' }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </div>
            </div>

            <!-- Zonas sin región (p. ej. Aguascalientes inactiva). -->
            <div
                v-for="zonaSuelta in arbol.hijos.filter((h) => h.tipo === 'zona')"
                :key="`suelta-${zonaSuelta.id}`"
                class="rounded-2xl border border-border/60 bg-card p-4"
            >
                <div class="flex items-center gap-2">
                    <span class="font-semibold">{{ zonaSuelta.nombre }}</span>
                    <Badge v-if="!zonaSuelta.activa" variant="outline"
                        >Inactiva</Badge
                    >
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ zonaSuelta.hijos.length }} ruta(s), sin operar.
                </p>
            </div>
        </div>
    </div>
</template>
