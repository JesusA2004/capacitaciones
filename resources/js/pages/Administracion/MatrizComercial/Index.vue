<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CheckCircle2,
    ChevronDown,
    HelpCircle,
    MapPin,
    Users2,
    X,
    XCircle,
} from '@lucide/vue';
import { ref } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { index as indexJerarquiaPuestos } from '@/routes/administracion/jerarquia-puestos';
import {
    index,
    responsable as actualizarResponsable,
} from '@/routes/administracion/matriz-comercial';
import apoyo from '@/routes/administracion/matriz-comercial/apoyo';
import { index as indexExpedientes } from '@/routes/rh/expedientes';
import { index as indexVacantes } from '@/routes/rh/vacantes';
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

/** Solo las rutas de cobro se asignan a un gestor. */
function rutasDe(zona: NodoComercialArbol): NodoComercialArbol[] {
    return zona.hijos.filter((h) => !h.es_posicion);
}

function posicionesDe(zona: NodoComercialArbol): NodoComercialArbol[] {
    return zona.hijos.filter((h) => h.es_posicion);
}

function nombreGestor(gestor: GestorDisponible): string {
    return `${gestor.name} ${gestor.apellidos ?? ''}`.trim();
}

function estaAbierto(id: number): boolean {
    return abiertos.value.has(id);
}

function asignarGestor(nodo: NodoComercialArbol, valor: string) {
    router.put(
        actualizarResponsable.url(nodo.id),
        { responsable_colaborador_id: valor === '__ninguno__' ? null : valor },
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Gestor actualizado.'),
        },
    );
}

const seleccionApoyo = ref<Record<number, string>>({});

function agregarApoyoOVolante(
    nodo: NodoComercialArbol,
    tipo: 'apoyo' | 'volante',
) {
    const colaboradorId = seleccionApoyo.value[nodo.id];

    if (!colaboradorId) {
        return;
    }

    router.post(
        apoyo.agregar.url(nodo.id),
        { colaborador_id: colaboradorId, tipo },
        {
            preserveScroll: true,
            onSuccess: () => {
                mostrarExito('Colaborador agregado a la ruta.');
                seleccionApoyo.value[nodo.id] = '';
            },
        },
    );
}

function quitarApoyoOVolante(
    nodo: NodoComercialArbol,
    colaboradorId: number,
    tipo: 'apoyo' | 'volante',
) {
    router.delete(apoyo.quitar.url(nodo.id), {
        data: { colaborador_id: colaboradorId, tipo },
        preserveScroll: true,
        onSuccess: () => mostrarExito('Colaborador quitado de la ruta.'),
    });
}
</script>

<template>
    <Head title="Matriz comercial" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Matriz comercial"
            descripcion="Estructura territorial MATRIZ → Región → Zona → Ruta, y quién cubre cada ruta hoy. Las vacantes disponibles se ven en Vacantes, no aquí."
            :icono="MapPin"
        >
            <Tooltip :delay-duration="0">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="flex size-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                        aria-label="Qué significa cada botón y color de esta pantalla"
                    >
                        <HelpCircle class="size-5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom" class="max-w-72 text-left">
                    <ul class="flex flex-col gap-1.5">
                        <li>
                            <span class="font-semibold text-success"
                                >Cubierta</span
                            >
                            — la ruta ya tiene un gestor asignado.
                        </li>
                        <li>
                            <span class="font-semibold text-warning"
                                >Sin cubrir</span
                            >
                            — nadie está a cargo todavía.
                        </li>
                        <li>
                            <span class="font-semibold text-destructive"
                                >Vencidos / Castigo</span
                            >
                            — situación operativa que requiere atención.
                        </li>
                        <li>
                            <span class="font-semibold"
                                >Selector "Sin gestor"</span
                            >
                            — asigna o cambia quién cobra la ruta.
                        </li>
                        <li>
                            <Users2 class="mb-0.5 inline size-3.5" />
                            <span class="font-semibold"
                                >Agregar apoyo/volante</span
                            >
                            — suma un colaborador extra a la ruta sin quitar al
                            gestor (elige a la persona y luego presiona el
                            botón).
                        </li>
                    </ul>
                </TooltipContent>
            </Tooltip>
        </CrudPageHeader>

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

        <div
            v-if="!arbol"
            class="rounded-2xl border border-dashed border-border/60 p-8 text-center text-sm text-muted-foreground"
        >
            Todavía no hay matriz comercial cargada. Corre
            <code class="rounded bg-muted px-1.5 py-0.5"
                >php artisan db:seed --class=MatrizComercialSeeder</code
            >.
        </div>

        <div v-else class="flex flex-col gap-4">
            <div
                v-for="region in arbol.hijos"
                :key="region.id"
                class="rounded-2xl border border-border/60 bg-card"
            >
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-2 rounded-t-2xl p-4 text-left transition-colors hover:bg-accent/50"
                    @click="alternar(region.id)"
                >
                    <span class="font-semibold">{{ region.nombre }}</span>
                    <span
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        {{ region.hijos.length }} zona(s)
                        <ChevronDown
                            class="size-4 transition-transform"
                            :class="{ 'rotate-180': estaAbierto(region.id) }"
                        />
                    </span>
                </button>

                <div
                    v-if="estaAbierto(region.id)"
                    class="flex flex-col gap-3 border-t border-border/60 p-4"
                >
                    <p
                        v-if="!region.hijos.length"
                        class="text-sm text-muted-foreground"
                    >
                        Región pendiente de configurar: todavía no tiene zonas
                        cargadas.
                    </p>

                    <Collapsible
                        v-for="zona in region.hijos"
                        :key="zona.id"
                        class="rounded-xl border border-border/60"
                    >
                        <CollapsibleTrigger
                            class="flex w-full items-center justify-between gap-2 rounded-t-xl p-3 text-left text-sm font-medium transition-colors hover:bg-accent/50"
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
                                {{ rutasDe(zona).length }} ruta(s)
                            </span>
                        </CollapsibleTrigger>
                        <CollapsibleContent
                            class="grid grid-cols-1 gap-2 border-t border-border/60 p-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
                        >
                            <!-- Posiciones de la sucursal que dirección listó junto con las
                                 rutas (GTE, SUBGERENCIA, VOLANTE): no son carteras asignables;
                                 se ocupan desde el Organigrama. -->
                            <div
                                v-if="posicionesDe(zona).length"
                                class="col-span-full flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <span
                                    >Posiciones de la sucursal (ver
                                    Organigrama):</span
                                >
                                <Badge
                                    v-for="posicion in posicionesDe(zona)"
                                    :key="posicion.id"
                                    variant="outline"
                                    :title="posicion.tipo_etiqueta"
                                    >{{ posicion.nombre }}</Badge
                                >
                            </div>
                            <div
                                v-for="ruta in rutasDe(zona)"
                                :key="ruta.id"
                                class="flex flex-col gap-2 rounded-lg border border-border/60 p-3"
                            >
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span class="text-sm font-medium">{{
                                        ruta.nombre
                                    }}</span>
                                    <Tooltip
                                        v-if="ruta.cobertura !== 'inactiva'"
                                        :delay-duration="0"
                                    >
                                        <TooltipTrigger as-child>
                                            <component
                                                :is="
                                                    ruta.cobertura ===
                                                    'cubierta'
                                                        ? CheckCircle2
                                                        : XCircle
                                                "
                                                class="size-4 shrink-0"
                                                :class="
                                                    ruta.cobertura ===
                                                    'cubierta'
                                                        ? 'text-success'
                                                        : 'text-warning'
                                                "
                                            />
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            {{
                                                ruta.cobertura === 'cubierta'
                                                    ? 'Ruta cubierta: ya tiene gestor asignado.'
                                                    : 'Ruta sin cubrir: todavía nadie está a cargo.'
                                            }}
                                        </TooltipContent>
                                    </Tooltip>
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    <Badge
                                        variant="outline"
                                        :class="COBERTURA_CLASE[ruta.cobertura]"
                                    >
                                        {{ COBERTURA_ETIQUETA[ruta.cobertura] }}
                                    </Badge>
                                    <Badge
                                        v-if="
                                            ruta.estado_operativo === 'vencidos'
                                        "
                                        variant="outline"
                                        class="border-destructive/30 bg-destructive/10 text-destructive"
                                        >Vencidos</Badge
                                    >
                                    <Badge
                                        v-if="
                                            ruta.estado_operativo === 'castigo'
                                        "
                                        variant="outline"
                                        class="border-destructive/30 bg-destructive/10 text-destructive"
                                        >Castigo</Badge
                                    >
                                </div>

                                <div
                                    v-if="ruta.activa"
                                    title="Gestor responsable: quien cobra esta ruta hoy."
                                    class="[&>div]:w-full"
                                >
                                    <SelectSimple
                                        class="h-8 text-xs"
                                        :model-value="
                                            ruta.responsable
                                                ? String(ruta.responsable.id)
                                                : '__ninguno__'
                                        "
                                        @update:model-value="
                                            (v) =>
                                                asignarGestor(ruta, String(v))
                                        "
                                        :opciones="[
                                            {
                                                value: '__ninguno__',
                                                label: 'Sin gestor',
                                            },
                                            ...gestoresDisponibles.map(
                                                (gestor) => ({
                                                    value: String(gestor.id),
                                                    label: nombreGestor(gestor),
                                                }),
                                            ),
                                        ]"
                                    />
                                </div>

                                <div
                                    v-if="
                                        ruta.apoyos.length ||
                                        ruta.volantes.length
                                    "
                                    class="flex flex-wrap gap-1"
                                >
                                    <Badge
                                        v-for="apoyoItem in ruta.apoyos"
                                        :key="`apoyo-${apoyoItem.id}`"
                                        variant="outline"
                                        class="gap-1 pr-1"
                                    >
                                        Apoyo: {{ apoyoItem.nombre }}
                                        <button
                                            type="button"
                                            title="Quitar este apoyo de la ruta"
                                            class="text-muted-foreground hover:text-destructive"
                                            @click="
                                                quitarApoyoOVolante(
                                                    ruta,
                                                    apoyoItem.id,
                                                    'apoyo',
                                                )
                                            "
                                        >
                                            <X class="size-3" />
                                        </button>
                                    </Badge>
                                    <Badge
                                        v-for="volanteItem in ruta.volantes"
                                        :key="`volante-${volanteItem.id}`"
                                        variant="outline"
                                        class="gap-1 pr-1"
                                    >
                                        Volante: {{ volanteItem.nombre }}
                                        <button
                                            type="button"
                                            title="Quitar este volante de la ruta"
                                            class="text-muted-foreground hover:text-destructive"
                                            @click="
                                                quitarApoyoOVolante(
                                                    ruta,
                                                    volanteItem.id,
                                                    'volante',
                                                )
                                            "
                                        >
                                            <X class="size-3" />
                                        </button>
                                    </Badge>
                                </div>

                                <div
                                    v-if="ruta.activa"
                                    class="flex items-center gap-1"
                                >
                                    <SelectSimple
                                        v-model="seleccionApoyo[ruta.id]"
                                        class="h-8 flex-1 text-xs"
                                        :opciones="
                                            gestoresDisponibles.map(
                                                (gestor) => ({
                                                    value: String(gestor.id),
                                                    label: nombreGestor(gestor),
                                                }),
                                            )
                                        "
                                        opcion-vacia="Agregar apoyo/volante..."
                                    />
                                    <Button
                                        size="icon"
                                        variant="outline"
                                        class="size-8 shrink-0"
                                        title="Agregar como apoyo"
                                        @click="
                                            agregarApoyoOVolante(ruta, 'apoyo')
                                        "
                                    >
                                        <Users2 class="size-3.5" />
                                    </Button>
                                </div>

                                <div class="flex gap-2 text-xs">
                                    <Link
                                        v-if="ruta.sucursal"
                                        class="text-muted-foreground underline-offset-2 hover:underline"
                                        :href="`${indexExpedientes.url()}?sucursal_id=${ruta.sucursal.id}`"
                                        >Ver colaboradores</Link
                                    >
                                    <Link
                                        v-if="ruta.sucursal"
                                        class="text-muted-foreground underline-offset-2 hover:underline"
                                        :href="`${indexVacantes.url()}?sucursal_id=${ruta.sucursal.id}`"
                                        >Ver vacantes</Link
                                    >
                                </div>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </div>
            </div>

            <!-- Zonas sin región (p. ej. Aguascalientes inactiva). -->
            <div
                v-for="zonaSuelta in arbol.hijos.filter(
                    (h) => h.tipo === 'zona',
                )"
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
