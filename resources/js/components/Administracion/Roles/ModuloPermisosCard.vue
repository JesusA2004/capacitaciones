<script setup lang="ts">
import { CheckCheck, Eraser, Info, KeyRound, Lock } from '@lucide/vue';
import { computed } from 'vue';
import PermisoSwitch from '@/components/Administracion/Roles/PermisoSwitch.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    accionesVisibles,
    ETIQUETA_NIVEL,
    moduloActivo,
    nivelDe,
} from '@/lib/rolesPermisos';
import type { ModuloCatalogo, NivelAlcance } from '@/types/roles';

/**
 * Un módulo de la matriz de «Roles y permisos»: Acceso, Alcance de datos
 * (Propios…Todos) y cada acción como interruptor con su descripción.
 * Presentacional: los cambios los aplica la página (emits).
 */
const props = defineProps<{
    modulo: ModuloCatalogo;
    permisos: Set<string>;
    /** Permisos del rol con el que se compara (null = sin comparar). */
    comparacion: Set<string> | null;
    nombreComparacion: string | null;
    soloLectura: boolean;
    filtro: string;
}>();

const emit = defineEmits<{
    acceso: [valor: boolean];
    nivel: [valor: NivelAlcance | 'propios'];
    accion: [permiso: string, valor: boolean];
    todo: [valor: boolean];
}>();

const activo = computed(() => moduloActivo(props.modulo, props.permisos));
const tieneAcceso = computed(() =>
    props.modulo.acceso
        ? props.permisos.has(props.modulo.acceso.permiso)
        : true,
);
const nivel = computed(() => nivelDe(props.modulo, props.permisos));
const nivelComparado = computed(() =>
    props.comparacion ? nivelDe(props.modulo, props.comparacion) : null,
);

const niveles = computed(() =>
    (['propios', 'equipo', 'sucursal', 'region', 'todos'] as const).filter(
        (n) => n === 'propios' || props.modulo.alcances[n] !== undefined,
    ),
);

const acciones = computed(() => accionesVisibles(props.modulo, props.filtro));
const marcadas = computed(
    () =>
        props.modulo.acciones.filter((a) => props.permisos.has(a.permiso))
            .length,
);
const completo = computed(
    () =>
        tieneAcceso.value &&
        props.modulo.acciones.every((a) => props.permisos.has(a.permiso)),
);

function difiere(permiso: string): boolean {
    return (
        props.comparacion !== null &&
        props.comparacion.has(permiso) !== props.permisos.has(permiso)
    );
}

const accesoDifiere = computed(() =>
    props.modulo.acceso ? difiere(props.modulo.acceso.permiso) : false,
);
</script>

<template>
    <article
        class="@container flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm transition-colors"
        :class="activo ? 'border-primary/30' : 'border-border/60 bg-card/70'"
        :data-modulo="modulo.clave"
    >
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <h4 class="flex flex-wrap items-center gap-2 font-medium">
                    {{ modulo.etiqueta }}
                    <Badge v-if="activo" variant="success">Con acceso</Badge>
                    <Badge v-else variant="secondary">Sin acceso</Badge>
                </h4>
                <p
                    v-if="modulo.descripcion"
                    class="text-xs text-muted-foreground"
                >
                    {{ modulo.descripcion }}
                </p>
            </div>

            <div
                v-if="modulo.acceso"
                class="flex items-center gap-2 rounded-xl px-2 py-1"
                :class="accesoDifiere ? 'bg-warning-soft' : ''"
            >
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span
                            class="inline-flex items-center gap-1 text-sm font-medium"
                        >
                            <KeyRound class="size-3.5 text-muted-foreground" />
                            Acceso
                        </span>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">
                        {{
                            modulo.acceso.descripcion ??
                            'Entrar al módulo y ver sus registros.'
                        }}
                        <span
                            class="mt-1 block font-mono text-[10px] opacity-80"
                            >{{ modulo.acceso.permiso }}</span
                        >
                    </TooltipContent>
                </Tooltip>
                <PermisoSwitch
                    :model-value="tieneAcceso"
                    :disabled="soloLectura"
                    :etiqueta="`Acceso a ${modulo.etiqueta}`"
                    @update:model-value="emit('acceso', $event)"
                />
            </div>
        </header>

        <div
            v-if="niveles.length > 1"
            class="flex flex-wrap items-center gap-2 rounded-xl bg-crema/40 px-3 py-2"
        >
            <span class="text-sm text-muted-foreground">Alcance de datos</span>
            <NativeSelect
                :model-value="nivel"
                :disabled="soloLectura || !tieneAcceso"
                class="w-40"
                :aria-label="`Alcance de datos en ${modulo.etiqueta}`"
                @update:model-value="
                    emit('nivel', $event as NivelAlcance | 'propios')
                "
            >
                <NativeSelectOption v-for="n in niveles" :key="n" :value="n">{{
                    ETIQUETA_NIVEL[n]
                }}</NativeSelectOption>
            </NativeSelect>
            <span class="text-xs text-muted-foreground"
                >Qué registros ve. Ver todo no permite aprobar todo.</span
            >
            <Badge
                v-if="nivelComparado !== null && nivelComparado !== nivel"
                variant="warning"
                >{{ nombreComparacion }}:
                {{ ETIQUETA_NIVEL[nivelComparado] }}</Badge
            >
        </div>

        <ul
            v-if="acciones.length > 0"
            class="grid gap-1.5 @md:grid-cols-2 @4xl:grid-cols-3"
        >
            <li
                v-for="accion in acciones"
                :key="accion.permiso"
                class="flex items-center gap-2.5 rounded-xl border px-3 py-2"
                :class="[
                    difiere(accion.permiso)
                        ? 'border-warning/50 bg-warning-soft/60'
                        : 'border-transparent bg-muted/40',
                    !tieneAcceso && !permisos.has(accion.permiso)
                        ? 'opacity-60'
                        : '',
                ]"
            >
                <PermisoSwitch
                    tamano="sm"
                    :model-value="permisos.has(accion.permiso)"
                    :disabled="soloLectura"
                    :etiqueta="accion.etiqueta"
                    @update:model-value="emit('accion', accion.permiso, $event)"
                />
                <span class="min-w-0 flex-1 text-sm leading-tight">
                    {{ accion.etiqueta }}
                    <span
                        v-if="difiere(accion.permiso)"
                        class="block text-[11px] text-warning"
                        >{{ nombreComparacion }}:
                        {{
                            comparacion?.has(accion.permiso) ? 'sí' : 'no'
                        }}</span
                    >
                </span>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="shrink-0 rounded-full p-0.5 text-muted-foreground hover:text-foreground"
                            :aria-label="`Qué permite: ${accion.etiqueta}`"
                        >
                            <Info class="size-3.5" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">
                        {{ accion.descripcion ?? accion.etiqueta }}
                        <span
                            class="mt-1 block font-mono text-[10px] opacity-80"
                            >{{ accion.permiso }}</span
                        >
                    </TooltipContent>
                </Tooltip>
            </li>
        </ul>
        <p
            v-else-if="modulo.acciones.length === 0"
            class="text-xs text-muted-foreground"
        >
            Este módulo solo tiene acceso y alcance de datos.
        </p>

        <footer
            v-if="modulo.acciones.length > 0"
            class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
        >
            <span>{{ marcadas }} de {{ modulo.acciones.length }} acciones</span>
            <span v-if="soloLectura" class="inline-flex items-center gap-1"
                ><Lock class="size-3" /> Solo lectura</span
            >
            <div v-else class="flex gap-1">
                <Button
                    v-if="!completo"
                    variant="ghost"
                    size="sm"
                    class="h-7"
                    @click="emit('todo', true)"
                >
                    <CheckCheck class="size-3.5" />
                    Todo el módulo
                </Button>
                <Button
                    v-if="activo || marcadas > 0"
                    variant="ghost"
                    size="sm"
                    class="h-7"
                    @click="emit('todo', false)"
                >
                    <Eraser class="size-3.5" />
                    Quitar todo
                </Button>
            </div>
        </footer>
    </article>
</template>
