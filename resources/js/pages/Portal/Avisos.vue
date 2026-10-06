<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BellRing } from '@lucide/vue';
import { ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { formatearFecha } from '@/lib/fechas';
import { leerCookie } from '@/lib/http';
import { dashboard } from '@/routes';
import { imagen, index, leido } from '@/routes/avisos';
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

        <p
            v-if="!lista.length"
            class="rounded-xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground"
        >
            No tienes avisos todavía.
        </p>

        <div class="flex flex-col gap-3">
            <Card
                v-for="aviso in lista"
                :key="aviso.id"
                class="transition-colors"
                :class="!aviso.leido && 'border-primary/40 bg-primary/5'"
                @click="marcarLeido(aviso)"
            >
                <CardContent class="flex flex-col gap-3 pt-6 sm:flex-row">
                    <img
                        v-if="aviso.imagen_path"
                        :src="imagen.url(aviso.id)"
                        alt=""
                        class="h-40 w-full rounded-lg object-cover sm:h-24 sm:w-24 sm:shrink-0"
                    />
                    <Avatar
                        v-else
                        class="size-12 shrink-0 rounded-lg sm:size-24"
                    >
                        <AvatarFallback class="rounded-lg bg-primary/10 text-primary">
                            <BellRing class="size-6" />
                        </AvatarFallback>
                    </Avatar>

                    <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold">{{ aviso.titulo }}</p>
                            <Badge v-if="!aviso.leido" class="h-5">Nuevo</Badge>
                        </div>
                        <p class="text-sm whitespace-pre-line text-muted-foreground">
                            {{ aviso.mensaje }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                aviso.enviado_en
                                    ? formatearFecha(aviso.enviado_en)
                                    : ''
                            }}
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
