<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Copy, Download, Gift } from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import { felicitacion } from '@/routes/rh/cumpleanos';
import { descargar as descargarFelicitacion } from '@/routes/rh/cumpleanos/felicitacion';

type Colaborador = {
    id: number;
    nombre: string;
    sucursal: string | null;
    departamento: string | null;
    puesto: string | null;
    dia: number;
    mes: number;
    edad: number | null;
    foto_url: string | null;
};

withDefaults(
    defineProps<{
        colaborador: Colaborador;
        puedeDescargar: boolean;
        compacto?: boolean;
    }>(),
    { compacto: false },
);

const emit = defineEmits<{
    copiar: [colaborador: Colaborador];
}>();

const { getInitials } = useInitials();
</script>

<template>
    <div
        class="flex items-center justify-between gap-3 rounded-lg border p-3"
        :class="compacto ? 'bg-muted/30' : 'bg-background'"
    >
        <div class="flex min-w-0 items-center gap-3">
            <Avatar class="size-10 shrink-0">
                <AvatarImage
                    v-if="colaborador.foto_url"
                    :src="colaborador.foto_url"
                    :alt="colaborador.nombre"
                />
                <AvatarFallback>{{
                    getInitials(colaborador.nombre)
                }}</AvatarFallback>
            </Avatar>
            <div class="min-w-0">
                <p class="truncate text-sm font-medium">
                    {{ colaborador.nombre }}
                </p>
                <p class="truncate text-xs text-muted-foreground">
                    {{
                        [colaborador.sucursal, colaborador.puesto]
                            .filter(Boolean)
                            .join(' · ') || 'Sin sucursal'
                    }}
                    · día {{ colaborador.dia }}
                    <template v-if="colaborador.edad !== null">
                        · {{ colaborador.edad }} años
                    </template>
                </p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-1">
            <Link :href="felicitacion.url(colaborador.id)">
                <Button size="icon" variant="ghost" title="Ver felicitación">
                    <Gift class="size-4" />
                </Button>
            </Link>
            <Button
                v-if="puedeDescargar"
                as-child
                size="icon"
                variant="ghost"
            >
                <a
                    :href="descargarFelicitacion.url(colaborador.id)"
                    title="Descargar imagen"
                >
                    <Download class="size-4" />
                </a>
            </Button>
            <Button
                size="icon"
                variant="ghost"
                title="Copiar mensaje"
                @click="emit('copiar', colaborador)"
            >
                <Copy class="size-4" />
            </Button>
        </div>
    </div>
</template>
