<script setup lang="ts">
import {
    MapPin,
    ShieldQuestion,
    Shuffle,
    UserCheck,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import { ESTILO_SIN_TIPO, ESTILO_TIPO_PUESTO } from '@/lib/organigrama';
import {
    CLASE_POR_CLASIFICAR,
    claseChipRegion,
    codigoRegion,
    lugarDeNodo,
    regionDeNodo,
} from '@/lib/organigramaEditable';
import type { NodoOrganigramaPersona, RegionOrganigrama } from '@/types';

/**
 * Tarjeta de un nodo del organigrama editable: persona (foto/iniciales,
 * nombre, puesto, sucursal, chip Q1/Q3), plaza VACANTE (punteada) o plaza
 * vacante que alguien «Cubre temporalmente». Tamaño fijo: lo acomoda
 * lib/organigramaLayout (así todo queda alineado por nivel).
 */
const props = defineProps<{
    nodo: NodoOrganigramaPersona;
    regiones: RegionOrganigrama[];
    compacta?: boolean;
    seleccionada?: boolean;
    atenuada?: boolean;
    resaltada?: boolean;
    raiz?: boolean;
}>();

const emit = defineEmits<{ seleccionar: [] }>();

const estilo = computed(
    () =>
        (props.nodo.puesto.tipo_puesto
            ? ESTILO_TIPO_PUESTO[props.nodo.puesto.tipo_puesto]
            : undefined) ?? ESTILO_SIN_TIPO,
);

const esVacante = computed(() => props.nodo.tipo === 'vacante');
const esCobertura = computed(() => props.nodo.tipo === 'cobertura');
/** Cabeza del árbol con titular: tarjeta en petróleo. */
const esRaiz = computed(
    () => props.raiz && !esVacante.value && !esCobertura.value,
);
const porClasificar = computed(
    () => props.nodo.clasificacion?.estado === 'por_clasificar',
);
const codigo = computed(() =>
    codigoRegion(regionDeNodo(props.nodo), props.regiones),
);
const lugar = computed(() => lugarDeNodo(props.nodo));
const titulo = computed(() =>
    esVacante.value || esCobertura.value
        ? 'Vacante'
        : (props.nodo.persona?.nombre ?? 'Vacante'),
);
</script>

<template>
    <div
        role="button"
        tabindex="0"
        :aria-label="`${titulo} — ${nodo.puesto.nombre}${lugar ? ` · ${lugar}` : ''}`"
        :aria-pressed="seleccionada"
        class="group relative flex h-full w-full cursor-pointer flex-col overflow-hidden rounded-2xl border text-left transition-[box-shadow,opacity,transform] duration-200 outline-none select-none focus-visible:ring-2 focus-visible:ring-ring"
        :class="[
            esVacante || esCobertura
                ? esCobertura
                    ? 'border-2 border-dashed border-esmeralda/70 bg-menta'
                    : 'border-2 border-dashed border-input bg-muted/60'
                : raiz
                  ? 'border-transparent bg-primary text-primary-foreground shadow-md'
                  : 'border-border bg-card shadow-sm hover:shadow-md',
            seleccionada
                ? 'ring-2 ring-oro ring-offset-2 ring-offset-background'
                : '',
            resaltada ? 'ring-2 ring-primary' : '',
            atenuada ? 'opacity-35' : '',
        ]"
        @click="emit('seleccionar')"
        @keydown.enter.prevent="emit('seleccionar')"
        @keydown.space.prevent="emit('seleccionar')"
    >
        <span
            v-if="!esVacante && !esCobertura && !esRaiz"
            class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r"
            :class="estilo.franja"
        />

        <!-- Compacta: hoja apilada (gestores, coordinadoras…) -->
        <div v-if="compacta" class="flex h-full items-center gap-2.5 px-3 py-2">
            <ColaboradorAvatar
                v-if="nodo.persona && !esCobertura"
                :nombre="nodo.persona.nombre"
                :foto-url="nodo.persona.foto_url"
                tamano="sm"
            />
            <span
                v-else
                class="flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-dashed border-input text-muted-foreground"
            >
                <UserRound class="size-4" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-[13px] leading-tight font-semibold">
                    {{ titulo }}
                </p>
                <p class="truncate text-[11px] text-muted-foreground">
                    {{ nodo.puesto.nombre }}
                    <template v-if="nodo.rutas[0]">
                        · {{ nodo.rutas[0].nombre }}</template
                    >
                </p>
                <p
                    v-if="esCobertura && nodo.persona"
                    class="truncate text-[11px] font-medium text-primary"
                >
                    Cubre temporalmente: {{ nodo.persona.nombre }}
                </p>
            </div>
        </div>

        <!-- Completa -->
        <div v-else class="flex h-full flex-col gap-1.5 px-3 pt-3 pb-2.5">
            <div class="flex items-center gap-2.5">
                <ColaboradorAvatar
                    v-if="nodo.persona && !esCobertura"
                    :nombre="nodo.persona.nombre"
                    :foto-url="nodo.persona.foto_url"
                    tamano="md"
                    :class="esRaiz ? 'ring-2 ring-oro' : ''"
                />
                <span
                    v-else
                    class="flex size-10 shrink-0 items-center justify-center rounded-full border-2 border-dashed text-muted-foreground"
                    :class="
                        esCobertura
                            ? 'border-esmeralda/70 bg-card'
                            : 'border-input'
                    "
                >
                    <UserRound class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p
                        class="truncate text-sm leading-tight font-semibold"
                        :class="
                            esVacante || esCobertura
                                ? 'tracking-wide text-muted-foreground uppercase'
                                : ''
                        "
                    >
                        {{ titulo }}
                    </p>
                    <p
                        class="truncate text-xs"
                        :class="
                            esRaiz
                                ? 'text-primary-foreground/80'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ nodo.puesto.nombre }}
                    </p>
                </div>
            </div>

            <!-- Distintivo de cobertura temporal -->
            <div
                v-if="esCobertura && nodo.persona"
                class="flex min-w-0 items-center gap-1.5 rounded-lg bg-card/90 px-2 py-1 text-[11px] text-primary"
            >
                <Shuffle class="size-3.5 shrink-0" />
                <span class="truncate"
                    >Cubre temporalmente:
                    <strong class="font-semibold">{{
                        nodo.persona.nombre
                    }}</strong></span
                >
            </div>

            <div class="mt-auto flex min-w-0 items-center gap-1.5">
                <span
                    v-if="lugar"
                    class="inline-flex min-w-0 items-center gap-1 truncate text-[11px]"
                    :class="
                        esRaiz
                            ? 'text-primary-foreground/80'
                            : 'text-muted-foreground'
                    "
                >
                    <MapPin class="size-3 shrink-0" />
                    <span class="truncate">{{ lugar }}</span>
                </span>

                <span class="ml-auto flex shrink-0 items-center gap-1">
                    <!-- Responsable de aprobación explícito (≠ reporte
                         organizacional): «aprueba sus solicitudes». -->
                    <span
                        v-if="nodo.aprobador"
                        class="inline-flex max-w-24 items-center gap-0.5 rounded-full border border-dashed border-[var(--mrl-primary)]/50 bg-[var(--mrl-primary)]/5 px-1.5 py-0.5 text-[10px] font-semibold text-[var(--mrl-primary)]"
                        :title="`Responsable de aprobaciones: ${nodo.aprobador.nombre} (no cambia a quién reporta)`"
                    >
                        <UserCheck class="size-3 shrink-0" />
                        <span class="truncate">{{
                            nodo.aprobador.nombre.split(' ')[0]
                        }}</span>
                    </span>
                    <span
                        v-if="porClasificar"
                        class="inline-flex items-center gap-0.5 rounded-full border px-1.5 py-0.5 text-[10px] font-semibold"
                        :class="CLASE_POR_CLASIFICAR"
                        title="RH aún no clasifica si este gerente es de Q1 o de Q3"
                    >
                        <ShieldQuestion class="size-3" />
                        Por clasificar
                    </span>
                    <span
                        v-else-if="
                            codigo && (nodo.clasificacion || nodo.region)
                        "
                        class="rounded-full border px-1.5 py-0.5 text-[10px] font-bold tracking-wide"
                        :class="claseChipRegion(codigo)"
                    >
                        {{ codigo }}
                    </span>
                </span>
            </div>
        </div>
    </div>
</template>
