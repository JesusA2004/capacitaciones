<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BellRing } from '@lucide/vue';
import { ref } from 'vue';
import AdjuntoAviso from '@/components/avisos/AdjuntoAviso.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { formatearFecha } from '@/lib/fechas';
import { leerCookie } from '@/lib/http';
import { dashboard } from '@/routes';
import { archivo as archivoUrl, index, leido } from '@/routes/avisos';
import { index as indexPortal } from '@/routes/portal';
import type { AvisoItem, RespuestaPaginada } from '@/types';

const props = defineProps<{
    avisos: RespuestaPaginada<AvisoItem>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi portal', href: indexPortal() },
            { title: 'Avisos', href: index.url() },
        ],
    },
});

const lista = ref<AvisoItem[]>([...props.avisos.data]);

function marcarLeido(aviso: AvisoItem) {
    if (aviso.leido) {
        return;
    }

    aviso.leido = true;

    void fetch(leido.url(aviso.id), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '' },
    }).catch(() => {
        aviso.leido = false;
    });
}
</script>

<template>
    <Head title="Avisos" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Avisos"
            descripcion="Mensajes de RH para toda la empresa o para ti."
            :icono="BellRing"
        />

        <EmptyState
            v-if="!lista.length"
            :icono="BellRing"
            titulo="No tienes avisos todavía"
            descripcion="Cuando RH envíe un aviso, aparecerá aquí."
        />

        <div class="flex flex-col gap-3">
            <Card
                v-for="aviso in lista"
                :key="aviso.id"
                class="animate-in transition-colors duration-200 fade-in slide-in-from-bottom-1 motion-reduce:animate-none"
                :class="!aviso.leido && 'border-primary/40 bg-primary/5'"
                @click="marcarLeido(aviso)"
                @focusin="marcarLeido(aviso)"
            >
                <CardContent class="flex flex-col gap-3 pt-6">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-semibold">{{ aviso.titulo }}</p>
                        <Badge v-if="!aviso.leido" class="h-5">Nuevo</Badge>
                    </div>
                    <p
                        class="text-sm whitespace-pre-line text-muted-foreground"
                    >
                        {{ aviso.mensaje }}
                    </p>
                    <AdjuntoAviso
                        v-if="aviso.archivo"
                        :archivo="aviso.archivo"
                        :url="archivoUrl.url(aviso.id)"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            aviso.enviado_en
                                ? formatearFecha(aviso.enviado_en)
                                : ''
                        }}
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
