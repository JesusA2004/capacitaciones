<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    ArrowUpToLine,
    ChevronRight,
    FileUser,
    MapPin,
    MoveRight,
    Shuffle,
    UserCheck,
    UserRoundX,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import SelectorLadoRegion from '@/components/Organigrama/SelectorLadoRegion.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatearFecha } from '@/lib/fechas';
import {
    CLASE_POR_CLASIFICAR,
    claseChipRegion,
    codigoRegion,
    contarRama,
    jefeVisible,
    lugarDeNodo,
    regionDeNodo,
} from '@/lib/organigramaEditable';
import type {
    AccionMovimientoOrganigrama,
    NodoOrganigramaPersona,
    RegionOrganigrama,
} from '@/types';

/**
 * Panel lateral del nodo elegido: quién es, a quién reporta, quiénes le
 * reportan y las acciones GRANDES (solo RH: el backend lo vuelve a validar).
 */
const props = defineProps<{
    nodo: NodoOrganigramaPersona | null;
    porClave: Map<string, NodoOrganigramaPersona>;
    hijos: Map<string, NodoOrganigramaPersona[]>;
    regiones: RegionOrganigrama[];
    puedeEditar: boolean;
    puedeCubrir?: boolean;
    puedeMover?: boolean;
    ocupado?: boolean;
}>();

const abierto = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    cubrir: [nodo: NodoOrganigramaPersona];
    quitarCobertura: [nodo: NodoOrganigramaPersona];
    mover: [nodo: NodoOrganigramaPersona, accion: AccionMovimientoOrganigrama];
    clasificar: [nodo: NodoOrganigramaPersona, regionId: number | null];
    seleccionar: [clave: string];
}>();

const esPersona = computed(() => props.nodo?.tipo === 'persona');
const esCobertura = computed(() => props.nodo?.tipo === 'cobertura');
const esVacante = computed(() => props.nodo?.tipo === 'vacante');

const jefe = computed(() =>
    props.nodo ? jefeVisible(props.nodo, props.porClave) : null,
);
const directos = computed(() =>
    props.nodo ? (props.hijos.get(props.nodo.clave) ?? []) : [],
);
const totalRama = computed(() =>
    props.nodo ? contarRama(props.nodo.clave, props.hijos) : 0,
);
const lugar = computed(() => (props.nodo ? lugarDeNodo(props.nodo) : null));
const codigo = computed(() =>
    props.nodo ? codigoRegion(regionDeNodo(props.nodo), props.regiones) : null,
);

type AccionGrande = {
    clave: string;
    etiqueta: string;
    detalle: string;
    icono: Component;
    alPulsar: () => void;
    peligro?: boolean;
};

const cubrirPermitido = computed(() => props.puedeCubrir ?? props.puedeEditar);
const moverPermitido = computed(() => props.puedeMover ?? props.puedeEditar);

const acciones = computed<AccionGrande[]>(() => {
    const nodo = props.nodo;

    if (!nodo || !props.puedeEditar) {
        return [];
    }

    if (esVacante.value) {
        if (!cubrirPermitido.value) {
            return [];
        }

        return [
            {
                clave: 'cubrir',
                etiqueta: 'Cubre temporalmente este puesto',
                detalle:
                    'Elige a alguien que la cubra sin dejar su puesto. Sin fechas.',
                icono: Shuffle,
                alPulsar: () => emit('cubrir', nodo),
            },
        ];
    }

    if (esCobertura.value) {
        if (!cubrirPermitido.value) {
            return [];
        }

        return [
            {
                clave: 'quitar',
                etiqueta: 'Quitar cobertura',
                detalle: 'Un clic: la plaza vuelve a verse vacante.',
                icono: UserRoundX,
                alPulsar: () => emit('quitarCobertura', nodo),
                peligro: true,
            },
        ];
    }

    if (!moverPermitido.value) {
        return [];
    }

    return [
        {
            clave: 'subir',
            etiqueta: 'Subir de puesto',
            detalle: 'Al puesto superior (promoción).',
            icono: ArrowUpToLine,
            alPulsar: () => emit('mover', nodo, 'subir'),
        },
        {
            clave: 'bajar',
            etiqueta: 'Bajar de puesto',
            detalle: 'A un puesto que cuelga del suyo.',
            icono: ArrowDownToLine,
            alPulsar: () => emit('mover', nodo, 'bajar'),
        },
        {
            clave: 'mover',
            etiqueta: 'Mover bajo…',
            detalle: 'Mismo puesto, bajo otro jefe o plaza.',
            icono: MoveRight,
            alPulsar: () => emit('mover', nodo, 'mover'),
        },
    ];
});

function clasificarNodo(regionId: number | null): void {
    if (props.nodo) {
        emit('clasificar', props.nodo, regionId);
    }
}

function fecha(iso: string | null | undefined): string {
    return iso ? formatearFecha(iso) : '';
}
</script>

<template>
    <Sheet v-model:open="abierto">
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-y-auto bg-background p-0 sm:max-w-md"
        >
            <template v-if="nodo">
                <SheetHeader
                    class="relative gap-3 border-b border-border bg-crema/60 px-5 pt-6 pb-5"
                >
                    <div class="flex items-center gap-4">
                        <ColaboradorAvatar
                            v-if="nodo.persona && esPersona"
                            :nombre="nodo.persona.nombre"
                            :foto-url="nodo.persona.foto_url"
                            tamano="xl"
                            class="ring-4"
                        />
                        <span
                            v-else
                            class="flex size-20 shrink-0 items-center justify-center rounded-full border-2 border-dashed border-input bg-card text-muted-foreground"
                        >
                            <UserRoundX class="size-8" />
                        </span>
                        <div class="min-w-0">
                            <SheetTitle class="text-lg leading-tight">
                                {{
                                    esPersona
                                        ? nodo.persona?.nombre
                                        : 'Plaza vacante'
                                }}
                            </SheetTitle>
                            <SheetDescription class="text-sm">
                                {{ nodo.puesto.nombre }}
                            </SheetDescription>
                            <p
                                v-if="lugar"
                                class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                            >
                                <MapPin class="size-3.5" /> {{ lugar }}
                                <span
                                    v-if="codigo && !nodo.clasificacion"
                                    class="ml-1 rounded-full border px-1.5 text-[10px] font-bold"
                                    :class="claseChipRegion(codigo)"
                                    >{{ codigo }}</span
                                >
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="esCobertura && nodo.persona"
                        class="flex items-start gap-3 rounded-2xl border border-dashed border-esmeralda/60 bg-menta p-3"
                    >
                        <ColaboradorAvatar
                            :nombre="nodo.persona.nombre"
                            :foto-url="nodo.persona.foto_url"
                            tamano="md"
                        />
                        <div class="min-w-0 text-sm">
                            <p class="font-semibold text-primary">
                                Cubre temporalmente: {{ nodo.persona.nombre }}
                            </p>
                            <p
                                v-if="nodo.cobertura?.titular_de"
                                class="text-xs text-muted-foreground"
                            >
                                Conserva su puesto:
                                {{ nodo.cobertura.titular_de }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Marcada
                                <template v-if="nodo.cobertura?.registrada_por">
                                    por
                                    {{
                                        nodo.cobertura.registrada_por
                                    }}</template
                                >
                                el
                                {{
                                    fecha(
                                        nodo.cobertura?.registrada_en ??
                                            nodo.cobertura?.desde,
                                    )
                                }}. Le reportan los subordinados de esta plaza.
                            </p>
                        </div>
                    </div>
                </SheetHeader>

                <div class="flex flex-col gap-5 px-5 py-5">
                    <!-- Responsable de aprobación explícito (≠ a quién reporta) -->
                    <section
                        v-if="nodo.aprobador"
                        class="flex items-start gap-3 rounded-2xl border border-dashed border-[var(--mrl-primary)]/40 bg-[var(--mrl-primary)]/5 p-3 text-sm"
                    >
                        <UserCheck
                            class="mt-0.5 size-4 shrink-0 text-primary"
                        />
                        <div class="min-w-0">
                            <p>
                                Responsable de aprobaciones:
                                <strong>{{ nodo.aprobador.nombre }}</strong>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    nodo.aprobador.origen === 'puesto'
                                        ? 'Asignado por RH a todo su puesto.'
                                        : 'Asignado por RH a esta persona.'
                                }}
                                No cambia a quién reporta en el organigrama.
                            </p>
                        </div>
                    </section>

                    <!-- Q1 / Q3 -->
                    <section
                        v-if="nodo.clasificacion"
                        class="flex flex-col gap-2.5 rounded-2xl border p-4"
                        :class="
                            nodo.clasificacion.estado === 'por_clasificar'
                                ? CLASE_POR_CLASIFICAR
                                : 'border-border bg-card'
                        "
                    >
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-foreground">
                                Lado del organigrama
                            </h3>
                            <span class="text-xs text-muted-foreground">
                                {{
                                    nodo.clasificacion.estado ===
                                    'por_clasificar'
                                        ? 'Por clasificar'
                                        : `Clasificado${nodo.clasificacion.clasificada_por ? ` por ${nodo.clasificacion.clasificada_por}` : ''}${nodo.clasificacion.clasificada_en ? ` · ${fecha(nodo.clasificacion.clasificada_en)}` : ''}`
                                }}
                            </span>
                        </div>
                        <SelectorLadoRegion
                            v-if="moverPermitido"
                            :regiones="regiones"
                            :actual-id="nodo.clasificacion.region?.id ?? null"
                            :sugerida-id="
                                nodo.clasificacion.sugerida?.id ?? null
                            "
                            :deshabilitado="ocupado"
                            grande
                            @elegir="clasificarNodo"
                        />
                        <p class="text-xs text-foreground/70">
                            <template
                                v-if="
                                    nodo.clasificacion.estado ===
                                    'por_clasificar'
                                "
                            >
                                Mientras no se clasifique, su jefe sale de la
                                región de su sucursal en la matriz
                                comercial<template
                                    v-if="nodo.clasificacion.sugerida"
                                >
                                    ({{
                                        nodo.clasificacion.sugerida.nombre
                                    }})</template
                                >.
                            </template>
                            <template v-else>
                                Reporta al gerente regional de
                                {{ nodo.clasificacion.region?.nombre }} (o a
                                quien lo cubra temporalmente).
                            </template>
                        </p>
                    </section>

                    <!-- Acciones grandes -->
                    <section v-if="acciones.length" class="flex flex-col gap-2">
                        <h3
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Acciones
                        </h3>
                        <button
                            v-for="a in acciones"
                            :key="a.clave"
                            type="button"
                            :disabled="ocupado"
                            class="flex tarjeta-interactiva items-center gap-3 rounded-2xl border p-4 text-left transition-colors disabled:opacity-50"
                            :class="
                                a.peligro
                                    ? 'border-danger/30 bg-danger-soft/50 hover:bg-danger-soft'
                                    : 'border-border bg-card hover:border-primary/40 hover:bg-menta'
                            "
                            @click="a.alPulsar"
                        >
                            <span
                                class="flex size-11 shrink-0 items-center justify-center rounded-xl"
                                :class="
                                    a.peligro
                                        ? 'bg-danger-soft text-danger'
                                        : 'bg-salvia text-primary'
                                "
                            >
                                <component :is="a.icono" class="size-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold">{{
                                    a.etiqueta
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ a.detalle }}</span
                                >
                            </span>
                            <ChevronRight
                                class="size-4 text-muted-foreground"
                            />
                        </button>
                    </section>

                    <!-- A quién reporta -->
                    <section class="flex flex-col gap-2">
                        <h3
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Reporta a
                        </h3>
                        <button
                            v-if="jefe?.persona"
                            type="button"
                            class="flex items-center gap-3 rounded-2xl border border-border bg-card p-3 text-left hover:border-primary/40"
                            @click="emit('seleccionar', jefe.clave)"
                        >
                            <ColaboradorAvatar
                                :nombre="jefe.persona.nombre"
                                :foto-url="jefe.persona.foto_url"
                                tamano="md"
                            />
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium"
                                    >{{ jefe.persona.nombre }}</span
                                >
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{
                                        jefe.tipo === 'cobertura'
                                            ? `Cubre temporalmente ${jefe.puesto.nombre}`
                                            : jefe.puesto.nombre
                                    }}
                                </span>
                            </span>
                        </button>
                        <p v-else class="text-sm text-muted-foreground">
                            Encabeza la estructura.
                        </p>
                    </section>

                    <!-- Le reportan -->
                    <section v-if="directos.length" class="flex flex-col gap-2">
                        <h3
                            class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            <Users class="size-3.5" />
                            Le reportan ({{ directos.length }} directos ·
                            {{ totalRama }} en su rama)
                        </h3>
                        <ul class="flex flex-col gap-1">
                            <li
                                v-for="d in directos.slice(0, 12)"
                                :key="d.clave"
                            >
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2.5 rounded-xl px-2 py-1.5 text-left text-sm transition-colors hover:bg-muted"
                                    @click="emit('seleccionar', d.clave)"
                                >
                                    <ColaboradorAvatar
                                        v-if="d.persona && d.tipo === 'persona'"
                                        :nombre="d.persona.nombre"
                                        :foto-url="d.persona.foto_url"
                                        tamano="sm"
                                    />
                                    <span
                                        v-else
                                        class="size-8 shrink-0 rounded-full border-2 border-dashed border-input"
                                    />
                                    <span class="min-w-0 flex-1 truncate">
                                        {{
                                            d.tipo === 'persona'
                                                ? d.persona?.nombre
                                                : 'Vacante'
                                        }}
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >· {{ d.puesto.nombre }}</span
                                        >
                                    </span>
                                </button>
                            </li>
                            <li
                                v-if="directos.length > 12"
                                class="px-2 text-xs text-muted-foreground"
                            >
                                y {{ directos.length - 12 }} más…
                            </li>
                        </ul>
                    </section>

                    <Link
                        v-if="nodo.persona"
                        :href="nodo.persona.expediente_url"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl border border-border bg-card px-4 py-3 text-sm font-medium hover:border-primary/40 hover:bg-menta"
                    >
                        <FileUser class="size-4" />
                        Ver expediente{{ esCobertura ? ' de quien cubre' : '' }}
                    </Link>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
