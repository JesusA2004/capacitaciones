<script setup lang="ts">
import {
    Briefcase,
    ChevronDown,
    ChevronRight,
    Pencil,
    Route,
    UserPlus,
    UserRoundSearch,
    Unlink,
} from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import OrganigramaPersona from '@/components/Administracion/OrganigramaPersona.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    CLAVE_BUSQUEDA_ORGANIGRAMA,
    coincideBusqueda,
    ESTILO_SIN_TIPO,
    ESTILO_TIPO_PUESTO,
} from '@/lib/organigrama';
import type { PuestoJerarquiaItem } from '@/types';

const props = defineProps<{
    puesto: PuestoJerarquiaItem;
    /** true dentro del árbol de escritorio, donde el ancho es fijo. */
    compacto?: boolean;
    tieneHijos?: boolean;
    colapsado?: boolean;
}>();

const emit = defineEmits<{
    seleccionar: [];
    editar: [];
    agregarSubordinado: [];
    quitarRelacion: [];
    alternarColapso: [];
}>();

/** Filas con nombre visibles; el resto se muestra como pila de caras. */
const MAXIMO_FILAS = 3;

const busqueda = inject(CLAVE_BUSQUEDA_ORGANIGRAMA, ref(''));

const estilo = computed(
    () =>
        (props.puesto.tipo_puesto
            ? ESTILO_TIPO_PUESTO[props.puesto.tipo_puesto]
            : undefined) ?? ESTILO_SIN_TIPO,
);

const ocupantes = computed(() => props.puesto.ocupantes ?? []);
const filas = computed(() => ocupantes.value.slice(0, MAXIMO_FILAS));
const pila = computed(() => ocupantes.value.slice(MAXIMO_FILAS));
const restantes = computed(() =>
    Math.max(
        0,
        props.puesto.colaboradores_count - MAXIMO_FILAS - pila.value.length,
    ),
);

const hayBusqueda = computed(() => busqueda.value.trim() !== '');
const coincide = computed(() => coincideBusqueda(props.puesto, busqueda.value));
const sinCobertura = computed(
    () => props.puesto.activo && props.puesto.colaboradores_count === 0,
);
</script>

<template>
    <div
        class="group relative flex flex-col overflow-hidden rounded-2xl border bg-card text-left shadow-sm transition-all duration-300 ease-out hover:-translate-y-0.5 hover:shadow-xl"
        :class="[
            compacto ? 'w-64' : 'w-full',
            hayBusqueda && coincide
                ? 'border-primary shadow-lg ring-4 ring-primary/20'
                : 'border-border/60',
            hayBusqueda && !coincide ? 'opacity-35 saturate-50' : '',
            !puesto.activo ? 'opacity-70' : '',
        ]"
    >
        <!-- Franja de color por tipo de puesto -->
        <div class="h-1.5 bg-gradient-to-r" :class="estilo.franja" />

        <button
            type="button"
            class="flex flex-1 flex-col gap-3 p-4 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
            @click="emit('seleccionar')"
        >
            <div class="flex items-start gap-3 pr-16">
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl"
                    :class="estilo.chip"
                >
                    <Briefcase class="size-4" />
                </span>
                <div class="min-w-0">
                    <p
                        class="text-base leading-tight font-semibold break-words"
                    >
                        {{ puesto.nombre }}
                    </p>
                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                        {{ puesto.departamento?.nombre ?? 'Sin departamento' }}
                        <template v-if="puesto.nivel_jerarquico">
                            · Nivel {{ puesto.nivel_jerarquico }}
                        </template>
                    </p>
                </div>
            </div>

            <!-- Quién está en el puesto -->
            <div
                v-if="ocupantes.length"
                class="flex flex-col gap-1 rounded-xl bg-muted/40 p-1.5"
            >
                <OrganigramaPersona
                    v-for="persona in filas"
                    :key="persona.id"
                    :persona="persona"
                    :destacado="ocupantes.length === 1"
                />
                <div
                    v-if="pila.length || restantes > 0"
                    class="flex items-center gap-2 px-1 pt-1"
                >
                    <div class="flex -space-x-2">
                        <OrganigramaPersona
                            v-for="persona in pila"
                            :key="persona.id"
                            :persona="persona"
                            :con-nombre="false"
                        />
                    </div>
                    <span class="text-xs font-medium text-muted-foreground">
                        +{{ pila.length + restantes }} más
                    </span>
                </div>
            </div>
            <div
                v-else-if="sinCobertura"
                class="flex items-center gap-2.5 rounded-xl border border-dashed border-orange-300 bg-orange-50/70 p-3 text-sm text-orange-700 dark:border-orange-500/40 dark:bg-orange-500/10 dark:text-orange-300"
            >
                <UserRoundSearch class="size-5 shrink-0" />
                <span class="font-medium">Puesto sin ocupar</span>
            </div>
            <p
                v-else
                class="rounded-xl bg-muted/40 p-3 text-sm text-muted-foreground"
            >
                {{ puesto.colaboradores_count }}
                {{
                    puesto.colaboradores_count === 1
                        ? 'persona no visible'
                        : 'personas no visibles'
                }}
                con tus filtros o alcance
            </p>

            <div class="flex flex-wrap items-center gap-1.5">
                <span
                    v-if="estilo.etiqueta"
                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                    :class="estilo.chip"
                >
                    {{ estilo.etiqueta }}
                </span>
                <span
                    v-if="puesto.vacantes_abiertas_count > 0"
                    class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/20 dark:text-amber-300"
                >
                    {{ puesto.vacantes_abiertas_count }} vacante{{
                        puesto.vacantes_abiertas_count === 1 ? '' : 's'
                    }}
                </span>
                <span
                    v-if="puesto.candidatos_count > 0"
                    class="rounded-full bg-indigo-100 px-2 py-0.5 text-[11px] font-medium text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300"
                >
                    {{ puesto.candidatos_count }} candidato{{
                        puesto.candidatos_count === 1 ? '' : 's'
                    }}
                </span>
                <span
                    v-if="puesto.requiere_ruta"
                    class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground"
                >
                    <Route class="size-3" /> Ruta
                </span>
                <span
                    v-if="!puesto.activo"
                    class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground"
                >
                    Inactivo
                </span>
            </div>
        </button>

        <div
            class="absolute top-4 right-3 flex items-center gap-0.5 rounded-lg bg-card/90 opacity-100 backdrop-blur transition-all duration-200 md:opacity-0 md:group-hover:opacity-100"
        >
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="flex size-7 items-center justify-center rounded-lg text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                        @click.stop="emit('agregarSubordinado')"
                    >
                        <UserPlus class="size-4" />
                    </button>
                </TooltipTrigger>
                <TooltipContent>Agregar puesto debajo</TooltipContent>
            </Tooltip>

            <Tooltip v-if="puesto.puesto_superior_id">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="flex size-7 items-center justify-center rounded-lg text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                        @click.stop="emit('quitarRelacion')"
                    >
                        <Unlink class="size-4" />
                    </button>
                </TooltipTrigger>
                <TooltipContent>Quitar relación con su superior</TooltipContent>
            </Tooltip>

            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="flex size-7 items-center justify-center rounded-lg text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                        @click.stop="emit('editar')"
                    >
                        <Pencil class="size-4" />
                    </button>
                </TooltipTrigger>
                <TooltipContent>Editar jerarquía</TooltipContent>
            </Tooltip>
        </div>

        <button
            v-if="tieneHijos"
            type="button"
            class="absolute -bottom-3.5 left-1/2 z-10 flex h-7 -translate-x-1/2 items-center gap-1 rounded-full border border-border/60 bg-card px-2.5 text-xs font-medium text-muted-foreground shadow-md transition-colors hover:border-primary/40 hover:text-primary"
            :title="colapsado ? 'Expandir rama' : 'Contraer rama'"
            @click.stop="emit('alternarColapso')"
        >
            <ChevronRight v-if="colapsado" class="size-3.5" />
            <ChevronDown v-else class="size-3.5" />
        </button>
    </div>
</template>
