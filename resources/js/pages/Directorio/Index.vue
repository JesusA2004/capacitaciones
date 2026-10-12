<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Contact, Mail, Phone, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { index as indexDirectorio } from '@/routes/directorio';

type ColaboradorDirectorio = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    puesto: string | null;
    sucursal: string | null;
    departamento: string | null;
    correo_corporativo: string | null;
    telefono_corporativo: string | null;
};

const props = defineProps<{
    colaboradores: {
        data: ColaboradorDirectorio[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filtros: {
        busqueda?: string;
        puesto_id?: number | string;
        sucursal_id?: number | string;
        departamento_id?: number | string;
    };
    opciones: {
        puestos: { id: number; nombre: string }[];
        sucursales: { id: number; nombre: string }[];
        departamentos: { id: number; nombre: string }[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Directorio', href: '' },
        ],
    },
});

const busqueda = ref(props.filtros.busqueda ?? '');
const puestoId = ref<number | null>(
    props.filtros.puesto_id ? Number(props.filtros.puesto_id) : null,
);
const sucursalId = ref<number | null>(
    props.filtros.sucursal_id ? Number(props.filtros.sucursal_id) : null,
);

function aplicarFiltros() {
    router.get(
        indexDirectorio.url(),
        {
            busqueda: busqueda.value || undefined,
            puesto_id: puestoId.value ?? undefined,
            sucursal_id: sucursalId.value ?? undefined,
        },
        { preserveState: true, replace: true },
    );
}

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(aplicarFiltros, 350);
});
watch([puestoId, sucursalId], aplicarFiltros);
</script>

<template>
    <Head title="Directorio" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Directorio"
            descripcion="Correo y teléfono corporativo de tus compañeros. No todos los puestos tienen correo corporativo registrado."
            :icono="Contact"
        />

        <section
            class="flex flex-wrap gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <div class="relative min-w-[220px] flex-1">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input v-model="busqueda" placeholder="Buscar por nombre…" class="pl-9" />
            </div>
            <SelectSimple
                :model-value="puestoId"
                numerico
                placeholder="Todos los puestos"
                opcion-vacia="Todos los puestos"
                class="min-w-[200px]"
                :opciones="opciones.puestos.map((p) => ({ value: p.id, label: p.nombre }))"
                @update:model-value="(v) => (puestoId = v)"
            />
            <SelectSimple
                :model-value="sucursalId"
                numerico
                placeholder="Todas las sucursales"
                opcion-vacia="Todas las sucursales"
                class="min-w-[200px]"
                :opciones="opciones.sucursales.map((s) => ({ value: s.id, label: s.nombre }))"
                @update:model-value="(v) => (sucursalId = v)"
            />
        </section>

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <article
                v-for="c in colaboradores.data"
                :key="c.id"
                class="flex flex-col gap-2 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
            >
                <p class="text-sm font-semibold text-[var(--mrl-texto)]">
                    {{ c.nombre }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ c.puesto ?? 'Sin puesto' }}
                    <template v-if="c.sucursal"> · {{ c.sucursal }}</template>
                </p>

                <div class="mt-1 flex flex-col gap-1 text-sm">
                    <a
                        v-if="c.correo_corporativo"
                        :href="`mailto:${c.correo_corporativo}`"
                        class="flex items-center gap-1.5 text-[var(--mrl-primary)] hover:underline"
                    >
                        <Mail class="size-3.5" /> {{ c.correo_corporativo }}
                    </a>
                    <p v-else class="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Mail class="size-3.5" /> Sin correo corporativo
                    </p>

                    <a
                        v-if="c.telefono_corporativo"
                        :href="`tel:${c.telefono_corporativo}`"
                        class="flex items-center gap-1.5 text-[var(--mrl-texto)] hover:underline"
                    >
                        <Phone class="size-3.5" /> {{ c.telefono_corporativo }}
                    </a>
                </div>
            </article>

            <p
                v-if="colaboradores.data.length === 0"
                class="col-span-full py-10 text-center text-sm text-muted-foreground"
            >
                No se encontró a nadie con esos filtros.
            </p>
        </section>
    </div>
</template>
