<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Cake, Copy, Download, Gift, Send, Sparkles } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AccionesCelebracionHoy from '@/components/Celebraciones/AccionesCelebracionHoy.vue';
import TarjetaCelebracionPersona from '@/components/Celebraciones/TarjetaCelebracionPersona.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAlertas } from '@/composables/useAlertas';
import { useCelebracion } from '@/composables/useCelebracion';
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
    avisada_todos_at?: string | null;
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
const { celebrar } = useCelebracion();
const generando = ref(false);
const enviando = ref(false);
const raizCard = ref<HTMLElement | null>(null);

const detalle = computed(() => {
    const lugar = [props.colaborador.sucursal, props.colaborador.puesto].filter(Boolean).join(' · ') || 'Sin sucursal';
    const edad = props.colaborador.edad !== null ? ` · ${props.colaborador.edad} años` : '';

    return `${lugar} · día ${props.colaborador.dia}${edad}`;
});

onMounted(() => {
    if (props.esHoy) {
        const rect = raizCard.value?.getBoundingClientRect();
        celebrar(rect ? { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 } : undefined);
    }
});

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

/** Envío manual fuera del día del cumpleaños (flujo previo). */
function enviarManual() {
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
    <div ref="raizCard" class="min-w-0">
        <!-- Compacto (lista/calendario): solo iconos con tooltip, siempre con
             aria-label. -->
        <div
            v-if="compacto"
            class="group flex min-w-0 flex-col gap-2 rounded-xl border bg-muted/30 p-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex min-w-0 items-center gap-3">
                <Avatar class="size-10 shrink-0 ring-2 ring-background">
                    <AvatarImage v-if="colaborador.foto_url" :src="colaborador.foto_url" :alt="colaborador.nombre" />
                    <AvatarFallback>{{ getInitials(colaborador.nombre) }}</AvatarFallback>
                </Avatar>
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ colaborador.nombre }}</p>
                    <p class="truncate text-xs text-muted-foreground">{{ detalle }}</p>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-1 opacity-80 group-hover:opacity-100">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button as-child size="icon" variant="ghost" aria-label="Ver tarjeta">
                            <Link :href="felicitacion.url(colaborador.id)"><Gift class="size-4" /></Link>
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Ver tarjeta</TooltipContent>
                </Tooltip>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button size="icon" variant="ghost" aria-label="Generar tarjeta" :disabled="generando" @click="generar">
                            <Spinner v-if="generando" />
                            <Sparkles v-else class="size-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Generar tarjeta</TooltipContent>
                </Tooltip>
                <Tooltip v-if="puedeDescargar">
                    <TooltipTrigger as-child>
                        <Button as-child size="icon" variant="ghost" aria-label="Descargar imagen">
                            <a :href="descargarFelicitacion.url(colaborador.id)"><Download class="size-4" /></a>
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Descargar imagen</TooltipContent>
                </Tooltip>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button size="icon" variant="ghost" aria-label="Copiar mensaje" @click="emit('copiar', colaborador)">
                            <Copy class="size-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Copiar mensaje</TooltipContent>
                </Tooltip>
                <Tooltip v-if="puedeEnviar">
                    <TooltipTrigger as-child>
                        <Button size="icon" variant="ghost" aria-label="Enviar felicitación" :disabled="enviando" @click="enviarManual">
                            <Spinner v-if="enviando" />
                            <Send v-else class="size-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Enviar felicitación</TooltipContent>
                </Tooltip>
            </div>
        </div>

        <!-- Banner de hoy / diálogo del día: layout responsivo que nunca
             recorta botones (ver TarjetaCelebracionPersona). -->
        <TarjetaCelebracionPersona
            v-else
            :nombre="colaborador.nombre"
            :detalle="detalle"
            :foto-url="colaborador.foto_url"
            :destacado="esHoy"
        >
            <template #icono>
                <Cake v-if="esHoy" class="size-3.5 shrink-0 text-amber-500" />
            </template>
            <template #acciones>
                <Button as-child size="sm" variant="secondary">
                    <Link :href="felicitacion.url(colaborador.id)"><Gift class="size-4" />Ver tarjeta</Link>
                </Button>
                <Button size="sm" variant="outline" :disabled="generando" @click="generar">
                    <Spinner v-if="generando" />
                    <Sparkles v-else class="size-4" />
                    Generar
                </Button>
                <Button v-if="puedeDescargar" as-child size="sm" variant="outline">
                    <a :href="descargarFelicitacion.url(colaborador.id)"><Download class="size-4" />Descargar</a>
                </Button>
                <Button size="sm" variant="outline" @click="emit('copiar', colaborador)">
                    <Copy class="size-4" />
                    Copiar
                </Button>
                <AccionesCelebracionHoy
                    v-if="esHoy"
                    :colaborador-id="colaborador.id"
                    tipo="cumpleanos"
                    :nombre="colaborador.nombre"
                    :avisada-todos-at="colaborador.avisada_todos_at ?? null"
                />
                <Button v-else-if="puedeEnviar" size="sm" :disabled="enviando" @click="enviarManual">
                    <Spinner v-if="enviando" />
                    <Send v-else class="size-4" />
                    Enviar
                </Button>
            </template>
        </TarjetaCelebracionPersona>
    </div>
</template>
