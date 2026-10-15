<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CircleSlash, MonitorSmartphone } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useGuia } from '@/composables/useGuia';
import { articulo, rol as buscarRol, ROLES_GUIA, rolVisible } from '@/lib/guia';
import { iconoGuia } from '@/lib/guia/iconos';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ayuda', href: '/ayuda' }],
    },
});

const props = defineProps<{ rol: string }>();

const { rolGuia, alcance } = useGuia();
// Solo la guía de los roles que le corresponden (super_admin: todas).
const datos = computed(() => {
    const encontrado = buscarRol(props.rol);

    return encontrado && rolVisible(encontrado.id, alcance.value)
        ? encontrado
        : undefined;
});
const otrosRoles = computed(() =>
    ROLES_GUIA.filter(
        (x) => x.id !== datos.value?.id && rolVisible(x.id, alcance.value),
    ),
);
</script>

<template>
    <Head :title="datos ? `Guía · ${datos.nombre}` : 'Guía'" />

    <div class="pagina-media flex flex-col gap-8">
        <template v-if="datos">
            <nav
                class="flex items-center gap-1.5 text-xs text-[var(--mrl-texto-suave)]"
                aria-label="Ubicación en la guía"
            >
                <Link href="/ayuda" class="hover:underline">Guía</Link>
                <span>›</span>
                <span>¿Qué hago según mi rol?</span>
                <span>›</span>
                <span class="text-[var(--mrl-texto)]">{{ datos.nombre }}</span>
            </nav>

            <header
                class="flex flex-col gap-5 rounded-[2rem] border border-[var(--mrl-borde)] bg-gradient-to-br from-salvia via-[var(--mrl-superficie)] to-crema/70 p-6 sm:flex-row sm:items-center sm:p-8"
            >
                <span
                    class="flex size-16 shrink-0 items-center justify-center rounded-3xl bg-[var(--mrl-superficie)] text-[var(--mrl-petroleo)] shadow-sm"
                >
                    <component :is="iconoGuia(datos.icono)" class="size-8" />
                </span>
                <div class="min-w-0 space-y-2">
                    <p
                        class="flex items-center gap-2 text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
                    >
                        ¿Qué hago según mi rol?
                        <Badge
                            v-if="datos.id === rolGuia"
                            class="bg-oro text-[10px] text-white"
                            >Tu rol</Badge
                        >
                    </p>
                    <h1 class="text-3xl font-semibold tracking-tight">
                        {{ datos.nombre }}
                    </h1>
                    <p
                        class="max-w-3xl text-pretty text-[var(--mrl-texto-suave)]"
                    >
                        {{ datos.resumen }}
                    </p>
                    <p class="flex items-center gap-1.5 text-sm">
                        <MonitorSmartphone class="size-4 text-oro" />
                        {{ datos.dondeTrabaja }}
                    </p>
                </div>
            </header>

            <section>
                <h2
                    class="mb-3 text-xs font-semibold tracking-[0.16em] text-bronce uppercase"
                >
                    Tu checklist
                </h2>
                <ul class="grid gap-3 sm:grid-cols-2">
                    <li v-for="(item, i) in datos.checklist" :key="item.texto">
                        <Link
                            :href="`/ayuda/articulo/${item.articulo}`"
                            class="flex tarjeta-interactiva items-center gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                        >
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-crema text-xs font-semibold text-bronce tabular-nums"
                                >{{ i + 1 }}</span
                            >
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium">{{
                                    item.texto
                                }}</span>
                                <span
                                    class="block truncate text-xs text-[var(--mrl-texto-suave)]"
                                    >{{ articulo(item.articulo)?.titulo }}</span
                                >
                            </span>
                            <ArrowRight
                                class="size-4 shrink-0 text-[var(--mrl-petroleo)]"
                            />
                        </Link>
                    </li>
                </ul>
            </section>

            <section
                v-if="datos.noLeToca.length"
                class="rounded-2xl border border-oro/40 bg-[var(--pastel-warning)]/40 p-5"
            >
                <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold">
                    <CircleSlash class="size-4" /> Lo que NO le toca
                </h2>
                <ul class="list-disc space-y-1 pl-5 text-sm">
                    <li v-for="n in datos.noLeToca" :key="n">{{ n }}</li>
                </ul>
            </section>

            <section v-if="otrosRoles.length > 0">
                <h2
                    class="mb-3 text-xs font-semibold tracking-[0.16em] text-bronce uppercase"
                >
                    Otros roles
                </h2>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="r in otrosRoles"
                        :key="r.id"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <Link :href="`/ayuda/rol/${r.id}`">{{ r.nombre }}</Link>
                    </Button>
                </div>
            </section>
        </template>

        <div v-else class="py-20 text-center">
            <p class="mb-4">Esta guía no corresponde a tu rol.</p>
            <Button as-child variant="outline"
                ><Link href="/ayuda">Volver a la guía</Link></Button
            >
        </div>
    </div>
</template>
