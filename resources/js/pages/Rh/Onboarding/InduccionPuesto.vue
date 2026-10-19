<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ClipboardList, Send } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { ciclo as cicloColaborador } from '@/routes/rh/colaboradores';
import { enviar as enviarDescriptivo } from '@/routes/rh/colaboradores/descriptivo-puesto';

type Acuse = {
    id: number;
    estado: string;
    estado_etiqueta: string;
    enviado_por: string;
    enviado_en: string;
    leido_en: string | null;
    acusado_en: string | null;
    enterado: boolean;
    comentario: string | null;
    descriptivo: { id: number; nombre_puesto: string; version: number };
};

type Fila = {
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        puesto: string | null;
        sucursal: string | null;
    };
    onboarding_estado: string;
    onboarding_estado_etiqueta: string;
    acuse: Acuse | null;
    puede_enviar: boolean;
    motivo: string | null;
};

type Paginador = {
    data: Fila[];
    current_page: number;
    last_page: number;
    per_page: number;
    from?: number | null;
    to?: number | null;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

const props = defineProps<{
    colaboradores: Paginador;
    filtros: { busqueda: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Inducción al puesto', href: '' },
        ],
    },
});

// Estados derivados del acuse (App\Enums\EstadoAcuseDescriptivoPuesto).
const variantes: Record<string, 'warning' | 'info' | 'success'> = {
    enviado: 'warning',
    leido: 'info',
    enterado: 'success',
};

const termino = ref(props.filtros.busqueda);
const enviandoId = ref<number | null>(null);

function filtrar() {
    router.get(
        window.location.pathname,
        { busqueda: termino.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(termino, useDebounceFn(filtrar, 300));

function enviar(fila: Fila) {
    enviandoId.value = fila.colaborador.id;
    router.post(
        enviarDescriptivo.url(fila.colaborador.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => (enviandoId.value = null),
        },
    );
}
</script>

<template>
    <Head title="Inducción al puesto" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Inducción al puesto"
            descripcion="Envía a cada colaborador en onboarding el descriptivo oficial de su puesto y da seguimiento a su acuse (enviado · visto · aceptado). Se habilita cuando aprueba su evaluación de inducción."
            :icono="ClipboardList"
        />

        <div>
            <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]">Buscar colaborador</Label>
            <Input v-model="termino" placeholder="Nombre o número de empleado" class="max-w-md" />
        </div>

        <p
            v-if="!colaboradores.data.length"
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-4 py-8 text-center text-sm text-[var(--mrl-texto-suave)]"
        >
            No hay colaboradores en onboarding dentro de tu alcance.
        </p>

        <template v-else>
            <!-- Tabla (pantallas anchas) -->
            <div class="tabla-scroll hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] md:block">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--mrl-borde)] text-xs text-[var(--mrl-texto-suave)]">
                        <tr>
                            <th class="px-4 py-3 font-medium">Colaborador</th>
                            <th class="px-4 py-3 font-medium">Puesto / Sucursal</th>
                            <th class="px-4 py-3 font-medium">Etapa de onboarding</th>
                            <th class="px-4 py-3 font-medium">Descriptivo de puesto</th>
                            <th class="px-4 py-3 text-right font-medium">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="f in colaboradores.data" :key="f.colaborador.id" class="border-b border-[var(--mrl-borde)] last:border-0">
                            <td class="px-4 py-3">
                                <Link :href="cicloColaborador.url(f.colaborador.id)" class="font-medium hover:underline">{{ f.colaborador.nombre }}</Link>
                                <p class="text-xs text-[var(--mrl-texto-suave)]">{{ f.colaborador.numero_empleado ?? '—' }}</p>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ f.colaborador.puesto ?? '—' }}<br />
                                <span class="text-[var(--mrl-texto-suave)]">{{ f.colaborador.sucursal ?? '—' }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">{{ f.onboarding_estado_etiqueta }}</td>
                            <td class="px-4 py-3 text-xs">
                                <template v-if="f.acuse">
                                    <Badge :variant="variantes[f.acuse.estado] ?? 'info'">{{ f.acuse.estado_etiqueta }}</Badge>
                                    <p class="mt-1 text-[var(--mrl-texto-suave)]">
                                        Enviado {{ formatearFecha(f.acuse.enviado_en) }}
                                        <template v-if="f.acuse.acusado_en"> · aceptado {{ formatearFecha(f.acuse.acusado_en) }}</template>
                                        <template v-else-if="f.acuse.leido_en"> · visto {{ formatearFecha(f.acuse.leido_en) }}</template>
                                    </p>
                                    <p v-if="f.acuse.comentario" class="mt-0.5 italic text-[var(--mrl-texto-suave)]">«{{ f.acuse.comentario }}»</p>
                                </template>
                                <span v-else class="text-[var(--mrl-texto-suave)]">
                                    {{ f.motivo ?? 'Sin enviar' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button
                                    v-if="f.puede_enviar"
                                    size="sm"
                                    :disabled="enviandoId === f.colaborador.id"
                                    @click="enviar(f)"
                                >
                                    <Send class="size-3.5" /> {{ f.acuse ? 'Reenviar' : 'Enviar descriptivo' }}
                                </Button>
                                <span v-else-if="f.acuse?.acusado_en" class="text-xs text-[var(--mrl-verde)]">Aceptado</span>
                                <span v-else class="text-xs text-[var(--mrl-texto-suave)]">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Tarjetas (pantallas estrechas): una columna -->
            <ul class="flex flex-col gap-3 md:hidden">
                <li v-for="f in colaboradores.data" :key="f.colaborador.id" class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <Link :href="cicloColaborador.url(f.colaborador.id)" class="font-medium hover:underline">{{ f.colaborador.nombre }}</Link>
                        <Badge v-if="f.acuse" :variant="variantes[f.acuse.estado] ?? 'info'">{{ f.acuse.estado_etiqueta }}</Badge>
                    </div>
                    <p class="mt-0.5 text-xs text-[var(--mrl-texto-suave)]">
                        {{ f.colaborador.puesto ?? '—' }} · {{ f.colaborador.sucursal ?? '—' }}
                    </p>
                    <p class="mt-1 text-xs">{{ f.onboarding_estado_etiqueta }}</p>
                    <p v-if="f.acuse" class="mt-1 text-xs text-[var(--mrl-texto-suave)]">
                        Enviado {{ formatearFecha(f.acuse.enviado_en) }}
                        <template v-if="f.acuse.acusado_en"> · aceptado {{ formatearFecha(f.acuse.acusado_en) }}</template>
                        <template v-else-if="f.acuse.leido_en"> · visto {{ formatearFecha(f.acuse.leido_en) }}</template>
                    </p>
                    <p v-else class="mt-1 text-xs text-[var(--mrl-texto-suave)]">{{ f.motivo ?? 'Sin enviar' }}</p>
                    <Button
                        v-if="f.puede_enviar"
                        size="sm"
                        class="mt-3"
                        :disabled="enviandoId === f.colaborador.id"
                        @click="enviar(f)"
                    >
                        <Send class="size-3.5" /> {{ f.acuse ? 'Reenviar' : 'Enviar descriptivo' }}
                    </Button>
                </li>
            </ul>

            <PaginacionTabla :datos="colaboradores" />
        </template>
    </div>
</template>
