<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Cake, Copy, Download, Gift, Send, Sparkles } from '@lucide/vue';
import { ref } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAlertas } from '@/composables/useAlertas';
import { useInitials } from '@/composables/useInitials';
import { felicitacion } from '@/routes/rh/cumpleanos';
import {
    descargar as descargarFelicitacion,
    enviar as enviarFelicitacion,
    generar as generarFelicitacion,
} from '@/routes/rh/cumpleanos/felicitacion';

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

const props = withDefaults(
    defineProps<{
        colaborador: Colaborador;
        puedeDescargar: boolean;
        puedeEnviar?: boolean;
        esHoy?: boolean;
        compacto?: boolean;
    }>(),
    { compacto: false, puedeEnviar: false, esHoy: false },
);

const emit = defineEmits<{
    copiar: [colaborador: Colaborador];
}>();

const { mostrarExito, mostrarError } = useAlertas();
const { getInitials } = useInitials();
const generando = ref(false);
const enviando = ref(false);

function generar() {
    generando.value = true;
    router.post(
        generarFelicitacion.url(props.colaborador.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => mostrarExito('Felicitación generada.'),
            onError: () => mostrarError('No se pudo generar la felicitación.'),
            onFinish: () => (generando.value = false),
        },
    );
}

function enviar() {
    enviando.value = true;
    router.post(
        enviarFelicitacion.url(props.colaborador.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => mostrarExito('Felicitación enviada.'),
            onError: () => mostrarError('No se pudo enviar la felicitación.'),
            onFinish: () => (enviando.value = false),
        },
    );
}
</script>

<template>
    <div
        class="group flex items-center justify-between gap-3 rounded-xl border p-3 transition-colors hover:border-primary/40 hover:bg-primary/[0.03]"
        :class="[
            compacto ? 'bg-muted/30' : 'bg-background',
            esHoy && 'border-[var(--success)]/40 bg-[var(--success)]/5',
        ]"
    >
        <div class="flex min-w-0 items-center gap-3">
            <Avatar class="size-10 shrink-0 ring-2 ring-background">
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
                <p class="flex items-center gap-1.5 truncate font-medium">
                    {{ colaborador.nombre }}
                    <Cake
                        v-if="esHoy"
                        class="size-3.5 shrink-0 text-[var(--success)]"
                    />
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

        <div
            class="flex shrink-0 items-center gap-1 opacity-80 group-hover:opacity-100"
        >
            <Tooltip>
                <TooltipTrigger as-child>
                    <Link :href="felicitacion.url(colaborador.id)">
                        <Button size="icon" variant="ghost">
                            <Gift class="size-4" />
                        </Button>
                    </Link>
                </TooltipTrigger>
                <TooltipContent>Ver felicitación</TooltipContent>
            </Tooltip>

            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        size="icon"
                        variant="ghost"
                        :disabled="generando"
                        @click="generar"
                    >
                        <Spinner v-if="generando" />
                        <Sparkles v-else class="size-4" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>Generar tarjeta</TooltipContent>
            </Tooltip>

            <Tooltip v-if="puedeDescargar">
                <TooltipTrigger as-child>
                    <Button as-child size="icon" variant="ghost">
                        <a :href="descargarFelicitacion.url(colaborador.id)">
                            <Download class="size-4" />
                        </a>
                    </Button>
                </TooltipTrigger>
                <TooltipContent>Descargar imagen</TooltipContent>
            </Tooltip>

            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        size="icon"
                        variant="ghost"
                        @click="emit('copiar', colaborador)"
                    >
                        <Copy class="size-4" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>Copiar mensaje</TooltipContent>
            </Tooltip>

            <Tooltip v-if="puedeEnviar">
                <TooltipTrigger as-child>
                    <Button
                        size="icon"
                        variant="ghost"
                        :disabled="enviando"
                        @click="enviar"
                    >
                        <Spinner v-if="enviando" />
                        <Send v-else class="size-4" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>Enviar felicitación</TooltipContent>
            </Tooltip>
        </div>
    </div>
</template>
