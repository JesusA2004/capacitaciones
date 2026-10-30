<script setup lang="ts">
import {
    Briefcase,
    Calculator,
    Clipboard,
    ClipboardCheck,
    FileSearch,
    Folder,
    Gavel,
    MapPin,
    Monitor,
    Network,
    Search,
    SearchCheck,
    ShieldCheck,
    Star,
    Store,
    UserCheck,
    UserRound,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import OrganigramaPersonasLamina from '@/components/Administracion/OrganigramaPersonasLamina.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import type { CajaLamina, LaminasOrganigrama } from '@/types';

/**
 * Organigrama como las láminas del PDF oficial de Inducción (cap. 4):
 * hoja 17 · Corporativo, 18 · Dirección Comercial y 19 · Sucursal. Todo
 * cabe en el ancho de la pantalla (sin zoom) y los nombres se ven
 * completos; las personas son las reales del sistema.
 */
const props = defineProps<{
    seccion: 'corporativo' | 'comercial' | 'sucursal';
    laminas: LaminasOrganigrama;
}>();

const ICONOS: Record<string, Component> = {
    briefcase: Briefcase,
    users: Users,
    calculator: Calculator,
    gavel: Gavel,
    monitor: Monitor,
    'map-pin': MapPin,
    store: Store,
    'user-check': UserCheck,
    folder: Folder,
    clipboard: Clipboard,
    'clipboard-check': ClipboardCheck,
    search: Search,
    'search-check': SearchCheck,
    'file-search': FileSearch,
    'shield-check': ShieldCheck,
};

function icono(nombre: string | null): Component {
    return (nombre && ICONOS[nombre]) || UserRound;
}

function cuantas(caja: CajaLamina): string {
    const n = caja.personas.length;

    return n === 0 ? 'Vacante' : n === 1 ? '1 persona' : `${n} personas`;
}

const sucursalId = ref<number | null>(
    props.laminas.sucursal.sucursales[0]?.id ?? null,
);
const sucursal = computed(
    () =>
        props.laminas.sucursal.sucursales.find(
            (s) => s.id === sucursalId.value,
        ) ?? props.laminas.sucursal.sucursales[0],
);
const puestosSucursal = computed(() => props.laminas.sucursal.puestos);
</script>

<template>
    <div
        class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-crema)] p-6 lg:p-8"
    >
        <!-- ─────────── Hoja 17 · Organigrama corporativo ─────────── -->
        <section
            v-if="seccion === 'corporativo'"
            class="flex flex-col items-center gap-0"
        >
            <header class="mb-6 w-full">
                <h2 class="font-serif text-xl text-[var(--mrl-petroleo)]">
                    Organigrama corporativo
                </h2>
                <p
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    Dirección General y áreas institucionales
                </p>
            </header>

            <div
                class="flex min-w-[320px] items-center gap-4 rounded-xl bg-[var(--mrl-petroleo)] px-6 py-4 text-white shadow-md"
            >
                <ColaboradorAvatar
                    v-if="laminas.corporativo.direccion_general.personas[0]?.id"
                    :nombre="
                        laminas.corporativo.direccion_general.personas[0].nombre
                    "
                    :foto-url="
                        laminas.corporativo.direccion_general.personas[0]
                            .foto_url
                    "
                    tamano="lg"
                />
                <span
                    v-else
                    class="flex size-12 shrink-0 items-center justify-center rounded-full border border-[var(--mrl-dorado)]/70 bg-white/10"
                >
                    <UserRound class="size-6 text-[var(--mrl-dorado)]" />
                </span>
                <div>
                    <p
                        v-for="p in laminas.corporativo.direccion_general
                            .personas"
                        :key="p.nombre"
                        class="font-serif text-lg leading-tight"
                    >
                        {{ p.nombre }}
                    </p>
                    <p
                        v-if="
                            !laminas.corporativo.direccion_general.personas
                                .length
                        "
                        class="font-serif text-lg leading-tight"
                    >
                        Vacante
                    </p>
                    <p
                        class="mt-0.5 flex items-center gap-1 text-xs text-[var(--mrl-dorado)]"
                    >
                        <Star class="size-3" />
                        {{ laminas.corporativo.direccion_general.titulo }}
                    </p>
                </div>
            </div>

            <div class="h-8 w-px bg-[var(--mrl-dorado)]" />
            <div
                class="relative grid w-full grid-cols-2 gap-4 pt-8 md:grid-cols-5"
            >
                <span
                    class="absolute top-0 hidden h-px bg-[var(--mrl-dorado)] md:block"
                    style="left: calc(10% - 0.4rem); right: calc(10% - 0.4rem)"
                />
                <article
                    v-for="area in laminas.corporativo.areas"
                    :key="area.sigla"
                    class="relative flex flex-col items-center gap-1.5 rounded-xl border border-[var(--mrl-dorado)]/70 bg-[var(--mrl-superficie)] px-3 py-4 text-center"
                >
                    <span
                        class="absolute -top-8 left-1/2 hidden h-8 w-px bg-[var(--mrl-dorado)] md:block"
                    />
                    <span
                        class="flex size-11 items-center justify-center rounded-full bg-[var(--mrl-petroleo)] text-[var(--mrl-dorado)]"
                    >
                        <component :is="icono(area.icono)" class="size-5" />
                    </span>
                    <span
                        class="text-[10px] font-semibold tracking-widest text-[var(--mrl-dorado-oscuro)]"
                    >
                        {{ area.sigla }}
                    </span>
                    <p
                        class="font-serif text-base leading-tight text-[var(--mrl-texto)]"
                    >
                        {{ area.titulo }}
                    </p>
                    <p
                        v-if="area.personas.length"
                        class="mt-1 text-sm leading-snug font-semibold text-[var(--mrl-petroleo)]"
                    >
                        <OrganigramaPersonasLamina
                            :personas="area.personas"
                            centrado
                        />
                    </p>
                    <p v-else class="mt-1 text-xs text-muted-foreground italic">
                        Sin asignar
                    </p>
                </article>
            </div>

            <p
                v-if="laminas.corporativo.nota"
                class="mt-6 flex items-center gap-1.5 text-sm text-[var(--mrl-dorado-oscuro)]"
            >
                <Network class="size-4" /> {{ laminas.corporativo.nota }}
            </p>
        </section>

        <!-- ─────────── Hoja 18 · Dirección Comercial ─────────── -->
        <section
            v-else-if="seccion === 'comercial'"
            class="flex flex-col gap-0"
        >
            <header class="mb-6">
                <h2 class="font-serif text-xl text-[var(--mrl-petroleo)]">
                    Organigrama · Dirección Comercial
                </h2>
                <p
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    Regionales, mesa de control, auditoría y áreas de apoyo
                </p>
            </header>

            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3">
                <div class="flex items-center gap-3">
                    <div
                        v-if="laminas.comercial.apoyo[0]"
                        class="ml-auto max-w-xs rounded-lg border border-[var(--mrl-dorado-oscuro)] bg-[var(--mrl-superficie)] px-3 py-2 text-sm"
                    >
                        <p class="font-serif leading-tight">
                            {{ laminas.comercial.apoyo[0].titulo }}
                        </p>
                        <p class="text-xs text-[var(--mrl-petroleo)]">
                            <OrganigramaPersonasLamina
                                :personas="laminas.comercial.apoyo[0].personas"
                            />
                        </p>
                    </div>
                    <span
                        class="w-10 border-t-2 border-dashed border-[var(--mrl-dorado-oscuro)]"
                    />
                </div>
                <div
                    class="rounded-lg bg-[var(--mrl-petroleo)] px-6 py-3 text-center text-white shadow-md"
                >
                    <p
                        class="flex items-center justify-center gap-2 font-serif text-lg"
                    >
                        <Briefcase class="size-4 text-[var(--mrl-dorado)]" />
                        {{ laminas.comercial.director.titulo }}
                    </p>
                    <p class="text-sm text-[var(--mrl-dorado)]">
                        <OrganigramaPersonasLamina
                            :personas="laminas.comercial.director.personas"
                        />
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <span
                        class="w-10 border-t-2 border-dashed border-[var(--mrl-dorado-oscuro)]"
                    />
                    <div
                        v-if="laminas.comercial.apoyo[1]"
                        class="max-w-xs rounded-lg border border-[var(--mrl-dorado-oscuro)] bg-[var(--mrl-superficie)] px-3 py-2 text-sm"
                    >
                        <p class="font-serif leading-tight">
                            {{ laminas.comercial.apoyo[1].titulo }}
                        </p>
                        <p class="text-xs text-[var(--mrl-petroleo)]">
                            <OrganigramaPersonasLamina
                                :personas="laminas.comercial.apoyo[1].personas"
                            />
                        </p>
                    </div>
                </div>
            </div>

            <div class="mx-auto h-6 w-px bg-[var(--mrl-dorado)]" />
            <div class="relative grid grid-cols-2 gap-4 pt-6 md:grid-cols-4">
                <span
                    class="absolute top-0 hidden h-px bg-[var(--mrl-dorado)] md:block"
                    style="
                        left: calc(12.5% - 0.375rem);
                        right: calc(12.5% - 0.375rem);
                    "
                />
                <div
                    v-for="(col, indiceCol) in laminas.comercial.columnas"
                    :key="col.titulo"
                    class="relative flex flex-col items-center"
                >
                    <span
                        class="absolute -top-6 left-1/2 hidden h-6 w-px bg-[var(--mrl-dorado)] md:block"
                    />
                    <div
                        class="w-full rounded-lg bg-[var(--mrl-verde)] px-3 py-2.5 text-center text-white"
                    >
                        <p
                            class="flex items-center justify-center gap-1.5 font-serif text-base leading-tight"
                        >
                            <component
                                :is="icono(col.icono)"
                                class="size-4 shrink-0"
                            />
                            {{ col.titulo }}
                        </p>
                        <p
                            v-if="col.personas.length"
                            class="mt-1 text-xs leading-snug text-white/90"
                        >
                            <OrganigramaPersonasLamina
                                :personas="col.personas"
                                con-sede
                                centrado
                            />
                        </p>
                        <p v-else class="mt-1 text-xs text-white/70 italic">
                            Vacante
                        </p>
                    </div>

                    <template v-for="caja in col.cadena" :key="caja.titulo">
                        <span class="h-5 w-px bg-[var(--mrl-dorado)]" />
                        <div
                            class="w-[88%] rounded-lg border px-3 py-2 text-center"
                            :class="
                                caja.destacado
                                    ? 'border-transparent bg-[var(--mrl-petroleo)] text-white'
                                    : 'border-[var(--mrl-dorado)]/70 bg-[var(--mrl-superficie)]'
                            "
                        >
                            <p
                                class="flex items-center justify-center gap-1.5 font-serif text-sm leading-tight"
                            >
                                <component
                                    :is="icono(caja.icono)"
                                    class="size-3.5 shrink-0"
                                />
                                {{ caja.titulo }}
                            </p>
                            <p
                                class="text-xs"
                                :class="
                                    caja.destacado
                                        ? 'text-[var(--mrl-dorado)]'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ cuantas(caja) }}
                            </p>
                        </div>
                    </template>

                    <template v-if="col.hijos.length">
                        <span class="h-4 w-px bg-[var(--mrl-dorado)]" />
                        <div
                            class="grid w-full gap-2"
                            :class="
                                col.hijos.length > 1
                                    ? 'grid-cols-2'
                                    : 'grid-cols-1 px-[6%]'
                            "
                        >
                            <div
                                v-for="caja in col.hijos"
                                :key="caja.titulo"
                                class="rounded-lg border border-[var(--mrl-dorado)]/70 bg-[var(--mrl-superficie)] px-2 py-2 text-center"
                            >
                                <component
                                    :is="icono(caja.icono)"
                                    class="mx-auto size-3.5 text-[var(--mrl-petroleo)]"
                                />
                                <p class="font-serif text-xs leading-tight">
                                    {{ caja.titulo }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ cuantas(caja) }}
                                </p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="mt-8 flex flex-col gap-1 text-xs text-muted-foreground">
                <p
                    v-if="laminas.comercial.leyenda.directa"
                    class="flex items-center gap-2"
                >
                    <span class="w-8 border-t border-[var(--mrl-dorado)]" />
                    {{ laminas.comercial.leyenda.directa }}
                </p>
                <p
                    v-if="laminas.comercial.leyenda.apoyo"
                    class="flex items-center gap-2"
                >
                    <span
                        class="w-8 border-t-2 border-dashed border-[var(--mrl-dorado-oscuro)]"
                    />
                    {{ laminas.comercial.leyenda.apoyo }}
                </p>
            </div>
        </section>

        <!-- ─────────── Hoja 19 · Organigrama de sucursal ─────────── -->
        <section v-else class="flex flex-col gap-5">
            <header>
                <h2 class="font-serif text-xl text-[var(--mrl-petroleo)]">
                    Organigrama de sucursal
                </h2>
                <p
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    Funciones por puesto en la operación local
                </p>
            </header>

            <div
                class="flex flex-wrap gap-2"
                role="tablist"
                aria-label="Sucursal"
            >
                <button
                    v-for="s in laminas.sucursal.sucursales"
                    :key="s.id"
                    type="button"
                    role="tab"
                    :aria-selected="sucursal?.id === s.id"
                    class="min-h-9 rounded-full border px-3.5 text-sm transition-colors"
                    :class="
                        sucursal?.id === s.id
                            ? 'border-[var(--mrl-petroleo)] bg-[var(--mrl-petroleo)] text-white'
                            : 'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] text-muted-foreground hover:text-[var(--mrl-texto)]'
                    "
                    @click="sucursalId = s.id"
                >
                    {{ s.nombre }}
                </button>
            </div>

            <p
                v-if="!sucursal"
                class="py-10 text-center text-sm text-muted-foreground"
            >
                No hay sucursales para mostrar.
            </p>

            <template v-else>
                <div
                    class="mx-auto w-full max-w-3xl rounded-xl bg-[var(--mrl-petroleo)] px-5 py-4 text-white shadow-md"
                >
                    <p
                        class="flex items-center gap-2 font-serif text-lg text-[var(--mrl-dorado)]"
                    >
                        <Store class="size-5" />
                        {{ puestosSucursal.gerente.titulo }}
                    </p>
                    <p class="mt-0.5 text-sm font-semibold">
                        <OrganigramaPersonasLamina
                            :personas="sucursal.gerente"
                        />
                    </p>
                    <ul
                        class="mt-2 grid gap-x-6 gap-y-0.5 text-xs text-white/85 sm:grid-cols-2"
                    >
                        <li
                            v-for="f in puestosSucursal.gerente.funciones"
                            :key="f"
                            class="flex gap-1.5"
                        >
                            <span class="text-[var(--mrl-dorado)]">•</span
                            >{{ f }}
                        </li>
                    </ul>
                </div>

                <div class="relative grid gap-5 md:grid-cols-2">
                    <span
                        class="absolute -top-5 left-1/4 hidden h-5 w-px bg-[var(--mrl-dorado)] md:block"
                    />
                    <span
                        class="absolute -top-5 left-1/4 hidden h-px w-1/2 border-t-2 border-dashed border-[var(--mrl-dorado-oscuro)] md:block"
                    />
                    <span
                        class="absolute -top-5 left-3/4 hidden h-5 border-l-2 border-dashed border-[var(--mrl-dorado-oscuro)] md:block"
                    />

                    <div class="flex flex-col items-stretch">
                        <article
                            class="rounded-xl border border-[var(--mrl-dorado)]/70 bg-[var(--mrl-superficie)] px-5 py-4"
                        >
                            <p
                                class="flex items-center gap-2 font-serif text-base text-[var(--mrl-texto)]"
                            >
                                <UserCheck
                                    class="size-4 text-[var(--mrl-petroleo)]"
                                />
                                {{ puestosSucursal.subgerente.titulo }}
                            </p>
                            <p
                                class="mt-0.5 text-sm font-semibold text-[var(--mrl-petroleo)]"
                            >
                                <OrganigramaPersonasLamina
                                    :personas="sucursal.subgerente"
                                />
                            </p>
                            <ul
                                class="mt-2 grid gap-x-6 gap-y-0.5 text-xs text-muted-foreground sm:grid-cols-2"
                            >
                                <li
                                    v-for="f in puestosSucursal.subgerente
                                        .funciones"
                                    :key="f"
                                    class="flex gap-1.5"
                                >
                                    <span
                                        class="text-[var(--mrl-dorado-oscuro)]"
                                        >•</span
                                    >{{ f }}
                                </li>
                            </ul>
                        </article>
                        <span class="mx-auto h-5 w-px bg-[var(--mrl-dorado)]" />
                        <article
                            class="rounded-xl border border-[var(--mrl-dorado)]/70 bg-[var(--mrl-superficie)] px-5 py-4"
                        >
                            <p
                                class="flex items-center gap-2 font-serif text-base text-[var(--mrl-texto)]"
                            >
                                <Users
                                    class="size-4 text-[var(--mrl-petroleo)]"
                                />
                                {{ puestosSucursal.gestores.titulo }}
                                <span class="text-sm text-muted-foreground"
                                    >({{ sucursal.gestores.length }})</span
                                >
                            </p>
                            <ul
                                class="mt-2 grid gap-x-6 gap-y-0.5 text-xs text-muted-foreground sm:grid-cols-2"
                            >
                                <li
                                    v-for="f in puestosSucursal.gestores
                                        .funciones"
                                    :key="f"
                                    class="flex gap-1.5"
                                >
                                    <span
                                        class="text-[var(--mrl-dorado-oscuro)]"
                                        >•</span
                                    >{{ f }}
                                </li>
                            </ul>
                            <div
                                v-if="sucursal.gestores.length"
                                class="mt-3 flex flex-wrap gap-1.5"
                            >
                                <span
                                    v-for="p in sucursal.gestores"
                                    :key="p.id ?? p.nombre"
                                    class="rounded-full bg-[var(--mrl-fondo)] px-2.5 py-0.5 text-xs text-[var(--mrl-texto)]"
                                >
                                    <OrganigramaPersonasLamina
                                        :personas="[p]"
                                    />
                                </span>
                            </div>
                        </article>
                    </div>

                    <div class="flex flex-col gap-4">
                        <article
                            class="rounded-xl border border-[var(--mrl-dorado)]/70 bg-[var(--mrl-superficie)] px-5 py-4"
                        >
                            <p
                                class="flex items-center gap-2 font-serif text-base text-[var(--mrl-texto)]"
                            >
                                <Folder
                                    class="size-4 text-[var(--mrl-petroleo)]"
                                />
                                {{ puestosSucursal.coordinacion.titulo }}
                            </p>
                            <p
                                class="mt-0.5 text-sm font-semibold text-[var(--mrl-petroleo)]"
                            >
                                <OrganigramaPersonasLamina
                                    :personas="sucursal.coordinacion"
                                />
                            </p>
                            <ul
                                class="mt-2 grid gap-x-6 gap-y-0.5 text-xs text-muted-foreground sm:grid-cols-2"
                            >
                                <li
                                    v-for="f in puestosSucursal.coordinacion
                                        .funciones"
                                    :key="f"
                                    class="flex gap-1.5"
                                >
                                    <span
                                        class="text-[var(--mrl-dorado-oscuro)]"
                                        >•</span
                                    >{{ f }}
                                </li>
                            </ul>
                        </article>
                        <div
                            class="flex flex-col gap-1 text-xs text-muted-foreground"
                        >
                            <p
                                v-if="laminas.sucursal.leyenda.apoyo"
                                class="flex items-center gap-2"
                            >
                                <span
                                    class="w-8 shrink-0 border-t-2 border-dashed border-[var(--mrl-dorado-oscuro)]"
                                />
                                {{ laminas.sucursal.leyenda.apoyo }}
                            </p>
                            <p
                                v-if="laminas.sucursal.leyenda.directa"
                                class="flex items-center gap-2"
                            >
                                <span
                                    class="w-8 shrink-0 border-t border-[var(--mrl-dorado)]"
                                />
                                {{ laminas.sucursal.leyenda.directa }}
                            </p>
                        </div>
                    </div>
                </div>
            </template>
        </section>
    </div>
</template>
