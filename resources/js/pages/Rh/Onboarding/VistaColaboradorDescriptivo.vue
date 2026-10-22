<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Eye } from '@lucide/vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DescriptivoPuestoColaborador from '@/components/onboarding/DescriptivoPuestoColaborador.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as induccionPuesto } from '@/routes/rh/induccion-puesto';
import type {
    ColaboradorBusqueda,
    DescriptivoPuestoColaborador as Acuse,
} from '@/types';

/**
 * «Vista previa como colaborador» del descriptivo de puesto (RH): el mismo
 * componente que la persona ve en Mi espacio, con su acuse real o el que
 * recibiría. Solo lectura: no marca leído ni envía nada
 * (AcuseDescriptivoPuestoService::vistaPreviaColaborador).
 */
defineProps<{
    colaborador: ColaboradorBusqueda;
    acuse: Acuse | null;
    motivo: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Inducción al puesto', href: induccionPuesto() },
            { title: 'Vista previa como colaborador', href: '' },
        ],
    },
});
</script>

<template>
    <Head title="Vista previa del descriptivo" />

    <div class="pagina-ancha flex flex-col gap-5 pb-10">
        <CrudPageHeader titulo="Vista previa como colaborador" :icono="Eye">
            <Button as-child variant="ghost" class="w-full sm:w-auto">
                <Link :href="induccionPuesto()"
                    ><ArrowLeft class="size-4" /> Volver</Link
                >
            </Button>
        </CrudPageHeader>

        <div
            class="flex items-start gap-3 rounded-2xl border-2 border-dashed border-oro/60 bg-warning-soft/60 p-4"
            role="note"
        >
            <Eye class="size-6 shrink-0 text-warning" />
            <div>
                <p class="font-semibold">
                    Vista previa — así lo ve el colaborador
                </p>
                <p class="text-sm text-muted-foreground">
                    Descriptivo de puesto de
                    <strong>{{ colaborador.nombre }}</strong>
                    <template v-if="colaborador.puesto">
                        · {{ colaborador.puesto }}</template
                    >.
                    {{
                        acuse?.id
                            ? 'Es el que ya le enviaste.'
                            : 'Es el que recibiría al enviárselo.'
                    }}
                    Aquí no se registra nada.
                </p>
            </div>
        </div>

        <DescriptivoPuestoColaborador
            v-if="acuse"
            :acuse="acuse"
            vista-previa
        />
        <p
            v-else
            class="rounded-2xl border border-dashed border-border/60 p-5 text-sm text-muted-foreground"
        >
            {{ motivo ?? 'No hay descriptivo de puesto para mostrar.' }}
        </p>
    </div>
</template>
