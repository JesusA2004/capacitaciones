<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    Building2,
    ChevronDown,
    ChevronRight,
    IdCard,
    Map as IconoRegion,
    MapPinned,
    UserRoundSearch,
} from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getInitials } from '@/composables/useInitials';
import {
    CLAVE_ACCIONES_ORGANIGRAMA,
    CLAVE_BUSQUEDA_ORGANIGRAMA,
    CLAVE_DETALLE_ORGANIGRAMA,
    coincidePersona,
    ESTILO_SIN_TIPO,
    ESTILO_TIPO_PUESTO,
    etiquetaRuta,
} from '@/lib/organigrama';
import type { DetalleOrganigrama } from '@/lib/organigrama';
import type { NodoOrganigramaPersona } from '@/types';

/**
 * Tarjeta de UNA persona en el organigrama (o del puesto "sin ocupar" en
 * su lugar de la cadena). Clic abre su expediente; pasar el mouse sobre la
 * foto la muestra en grande.
 *
 * Según el zoom del árbol (CLAVE_DETALLE_ORGANIGRAMA) muestra todo, solo
 * foto + nombre + puesto, o foto y nombre en grande — así al alejar se
 * sigue leyendo en vez de volverse diminuto.
 */
const props = defineProps<{
    nodo: NodoOrganigramaPersona;
    tieneHijos?: boolean;
    colapsado?: boolean;
    totalDebajo?: number;
}>();

const emit = defineEmits<{ alternarColapso: [] }>();

/** Rutas visibles en la tarjeta; el resto como "+N". */
const MAXIMO_RUTAS = 1;

const busqueda = inject(CLAVE_BUSQUEDA_ORGANIGRAMA, ref(''));
const detalle = inject(
    CLAVE_DETALLE_ORGANIGRAMA,
    ref<DetalleOrganigrama>('completo'),
);

const hayBusqueda = computed(() => busqueda.value.trim() !== '');
const coincide = computed(() => coincidePersona(props.nodo, busqueda.value));

const estilo = computed(
    () =>
        (props.nodo.puesto.tipo_puesto
            ? ESTILO_TIPO_PUESTO[props.nodo.puesto.tipo_puesto]
            : undefined) ?? ESTILO_SIN_TIPO,
);

const acciones = inject(CLAVE_ACCIONES_ORGANIGRAMA, null);

const esVacante = computed(() => props.nodo.tipo === 'vacante');
const esCobertura = computed(() => props.nodo.tipo === 'cobertura');

const desde = computed(() =>
    props.nodo.cobertura
        ? new Date(`${props.nodo.cobertura.desde}T00:00:00`).toLocaleDateString(
              'es-MX',
              { day: 'numeric', month: 'short' },
          )
        : '',
);

/** En modo mínimo basta nombre + primer apellido. */
const nombreCorto = computed(() =>
    (props.nodo.persona?.nombre ?? '').split(/\s+/).slice(0, 2).join(' '),
);

const TAMANO = {
    completo: {
        tarjeta: 'w-52',
        avatar: 'size-16 text-xl',
        nombre: 'text-sm',
        puesto: 'text-xs',
        vacanteIcono: 'size-12',
    },
    compacto: {
        tarjeta: 'w-44',
        avatar: 'size-20 text-2xl',
        nombre: 'text-base',
        puesto: 'text-sm',
        vacanteIcono: 'size-14',
    },
    minimo: {
        tarjeta: 'w-48',
        avatar: 'size-28 text-4xl',
        nombre: 'text-2xl',
        puesto: 'text-lg',
        vacanteIcono: 'size-24',
    },
} as const;

const tamano = computed(() => TAMANO[detalle.value]);

function abrirExpediente(): void {
    if (props.nodo.persona) {
        router.visit(props.nodo.persona.expediente_url);
    }
}
</script>

<template>
    <div
        class="group relative overflow-visible rounded-2xl text-center transition-all duration-300 ease-out"
        :class="[
            tamano.tarjeta,
            esVacante
                ? 'border-2 border-dashed border-orange-300/80 bg-orange-50/60 dark:border-orange-500/40 dark:bg-orange-500/5'
                : esCobertura
                  ? 'border-2 border-amber-400 bg-amber-50/70 shadow-sm hover:-translate-y-0.5 hover:shadow-lg dark:border-amber-500/60 dark:bg-amber-500/10'
                  : 'border border-border/60 bg-card shadow-sm hover:-translate-y-0.5 hover:shadow-lg',
            hayBusqueda && coincide ? 'shadow-lg ring-4 ring-primary/25' : '',
            hayBusqueda && !coincide ? 'opacity-30 saturate-50' : '',
        ]"
    >
        <div
            v-if="esCobertura && nodo.cobertura"
            class="flex items-center justify-center gap-1 rounded-t-xl bg-amber-400 px-2 py-1 text-[11px] font-semibold text-amber-950"
            :title="nodo.cobertura.nota ?? undefined"
        >
            <ArrowLeftRight class="size-3" />
            Cubriendo · {{ nodo.cobertura.motivo_etiqueta }}
        </div>
        <div
            v-else-if="!esVacante"
            class="h-1 rounded-t-2xl bg-gradient-to-r"
            :class="estilo.franja"
        />

        <!-- Persona -->
        <button
            v-if="nodo.persona"
            type="button"
            class="flex w-full flex-col items-center gap-1.5 p-3 focus-visible:outline-none"
            :title="`Abrir expediente de ${nodo.persona.nombre}`"
            @click="abrirExpediente"
        >
            <Tooltip :delay-duration="200">
                <TooltipTrigger as-child>
                    <span class="rounded-2xl">
                        <ColaboradorAvatar
                            :nombre="nodo.persona.nombre"
                            :foto-url="nodo.persona.foto_url"
                            tamano="xl"
                            class="rounded-2xl shadow-sm"
                            :class="tamano.avatar"
                        />
                    </span>
                </TooltipTrigger>
                <TooltipContent
                    side="right"
                    :side-offset="12"
                    class="w-64 overflow-hidden rounded-2xl border bg-popover p-0 text-popover-foreground shadow-2xl"
                >
                    <div class="aspect-square w-full bg-muted">
                        <img
                            v-if="nodo.persona.foto_url"
                            :src="nodo.persona.foto_url"
                            :alt="nodo.persona.nombre"
                            class="size-full object-cover"
                        />
                        <div
                            v-else
                            class="flex size-full items-center justify-center bg-gradient-to-br from-primary/15 to-primary/5 text-6xl font-semibold text-primary"
                        >
                            {{ getInitials(nodo.persona.nombre) }}
                        </div>
                    </div>
                    <div class="space-y-1 p-3 text-left">
                        <p class="text-sm leading-tight font-semibold">
                            {{ nodo.persona.nombre }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ nodo.puesto.nombre }}
                            <template v-if="nodo.sucursal">
                                · {{ nodo.sucursal.nombre }}
                            </template>
                        </p>
                        <p
                            v-if="nodo.persona.numero_empleado"
                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <IdCard class="size-3.5" /> No.
                            {{ nodo.persona.numero_empleado }}
                        </p>
                        <p
                            v-for="ruta in nodo.rutas"
                            :key="`${ruta.tipo}-${ruta.nombre}`"
                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <MapPinned class="size-3.5" />
                            {{ etiquetaRuta(ruta.tipo) }}: {{ ruta.nombre }}
                        </p>
                        <p
                            v-if="!nodo.persona.foto_url"
                            class="pt-1 text-[11px] text-muted-foreground italic"
                        >
                            Aún no tiene foto en su expediente.
                        </p>
                    </div>
                </TooltipContent>
            </Tooltip>

            <div class="w-full min-w-0">
                <p
                    class="leading-tight font-semibold text-balance"
                    :class="tamano.nombre"
                >
                    {{
                        detalle === 'minimo' ? nombreCorto : nodo.persona.nombre
                    }}
                </p>
                <p
                    v-if="detalle !== 'minimo'"
                    class="mt-0.5 truncate text-muted-foreground"
                    :class="tamano.puesto"
                >
                    {{ nodo.puesto.nombre }}
                </p>
                <p
                    v-if="esCobertura && detalle !== 'minimo'"
                    class="mt-0.5 text-[11px] text-amber-800 dark:text-amber-300"
                >
                    Titular en {{ nodo.persona.sucursal ?? 'otra sucursal' }} ·
                    desde {{ desde }}
                </p>
            </div>

            <span
                v-if="nodo.region && detalle !== 'minimo'"
                class="inline-flex items-center gap-1 rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-semibold text-sky-800 dark:bg-sky-500/20 dark:text-sky-300"
            >
                <IconoRegion class="size-3" />
                {{ nodo.region.nombre }}
            </span>

            <div
                v-if="detalle === 'completo' && nodo.rutas.length"
                class="flex w-full flex-wrap items-center justify-center gap-1"
            >
                <span
                    v-for="ruta in nodo.rutas.slice(0, MAXIMO_RUTAS)"
                    :key="`${ruta.tipo}-${ruta.nombre}`"
                    class="inline-flex max-w-full items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                    :class="estilo.chip"
                    :title="`${etiquetaRuta(ruta.tipo)}: ${ruta.nombre}`"
                >
                    <MapPinned class="size-3 shrink-0" />
                    <span class="truncate">{{ ruta.nombre }}</span>
                </span>
                <span
                    v-if="nodo.rutas.length > MAXIMO_RUTAS"
                    class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground"
                >
                    +{{ nodo.rutas.length - MAXIMO_RUTAS }}
                </span>
            </div>
        </button>

        <!-- Puesto sin ocupar -->
        <div
            v-else
            class="flex flex-col items-center gap-1.5 p-3 text-orange-700 dark:text-orange-300"
        >
            <span
                class="flex items-center justify-center rounded-2xl bg-orange-100 dark:bg-orange-500/15"
                :class="tamano.vacanteIcono"
            >
                <UserRoundSearch class="size-1/2" />
            </span>
            <p
                class="leading-tight font-semibold text-balance"
                :class="tamano.nombre"
            >
                {{ nodo.puesto.nombre }}
            </p>
            <p :class="tamano.puesto">Sin ocupar</p>
            <span
                v-if="(nodo.sucursal || nodo.region) && detalle === 'completo'"
                class="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2 py-0.5 text-[11px] font-medium dark:bg-orange-500/15"
            >
                <Building2 v-if="nodo.sucursal" class="size-3" />
                <IconoRegion v-else class="size-3" />
                {{ nodo.sucursal?.nombre ?? nodo.region?.nombre }}
            </span>
            <button
                v-if="acciones?.puedeEditar && detalle === 'completo'"
                type="button"
                class="mt-1 inline-flex items-center gap-1 rounded-lg border border-orange-300 bg-card px-2 py-1 text-[11px] font-semibold text-orange-700 transition-colors hover:bg-orange-100 dark:border-orange-500/40 dark:text-orange-300 dark:hover:bg-orange-500/15"
                @click.stop="acciones.asignarCobertura(nodo)"
            >
                <ArrowLeftRight class="size-3" />
                Asignar quién cubre
            </button>
        </div>

        <div
            v-if="
                esCobertura && acciones?.puedeEditar && detalle === 'completo'
            "
            class="border-t border-amber-300/60 px-3 py-1.5"
        >
            <button
                type="button"
                class="text-[11px] font-medium text-amber-800 underline-offset-2 hover:underline dark:text-amber-300"
                @click.stop="acciones.terminarCobertura(nodo)"
            >
                Terminar cobertura
            </button>
        </div>

        <button
            v-if="tieneHijos"
            type="button"
            class="absolute -bottom-3 left-1/2 z-10 flex h-6 -translate-x-1/2 items-center gap-1 rounded-full border border-border/60 bg-card px-2 text-xs font-medium text-muted-foreground shadow-md transition-colors hover:border-primary/40 hover:text-primary"
            :title="colapsado ? 'Expandir rama' : 'Contraer rama'"
            @click.stop="emit('alternarColapso')"
        >
            <ChevronRight v-if="colapsado" class="size-3.5" />
            <ChevronDown v-else class="size-3.5" />
            <span v-if="colapsado && totalDebajo" class="tabular-nums">
                {{ totalDebajo }}
            </span>
        </button>
    </div>
</template>
