<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Check,
    Clock,
    ShieldCheck,
    ThumbsDown,
    ThumbsUp,
    UserRound,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { vistoBueno } from '@/routes/rh/solicitudes';

export type NivelVistoBueno = {
    nivel: string;
    etiqueta: string;
    estado: 'pendiente' | 'aprobado' | 'rechazado' | string;
    aprobadores: string[];
    decidio: string | null;
    comentario: string | null;
    fecha: string | null;
};

const props = defineProps<{
    solicitudId: number;
    estado: string;
    niveles: NivelVistoBueno[];
    puedeDarVistoBueno: boolean;
}>();

/**
 * Recibida → Gerente → Regional → RH autoriza. Muestra en qué paso va la
 * solicitud y, a quien le toca, los botones de visto bueno.
 */
const autorizada = computed(() => props.estado === 'aprobada');
const rechazada = computed(() => props.estado === 'rechazada');
const vistosCompletos = computed(() =>
    props.niveles.every((n) => n.estado === 'aprobado'),
);

type Paso = {
    clave: string;
    titulo: string;
    detalle: string;
    estado: 'hecho' | 'actual' | 'pendiente' | 'rechazado';
};

const pasos = computed<Paso[]>(() => {
    const lista: Paso[] = [
        {
            clave: 'recibida',
            titulo: 'Recibida',
            detalle: 'El colaborador la envió',
            estado: 'hecho',
        },
    ];
    let actualAsignado = false;

    for (const n of props.niveles) {
        let estado: Paso['estado'] = 'pendiente';

        if (n.estado === 'aprobado') {
            estado = 'hecho';
        } else if (n.estado === 'rechazado') {
            estado = 'rechazado';
            actualAsignado = true;
        } else if (!actualAsignado && !rechazada.value) {
            estado = 'actual';
            actualAsignado = true;
        }

        lista.push({
            clave: n.nivel,
            titulo: `Visto bueno: ${n.etiqueta}`,
            detalle:
                n.decidio ??
                (n.aprobadores.join(', ') || 'Sin persona asignada'),
            estado,
        });
    }

    lista.push({
        clave: 'rh',
        titulo: 'Autoriza RH',
        detalle: autorizada.value
            ? 'Autorizada'
            : rechazada.value
              ? 'No aprobada'
              : vistosCompletos.value
                ? 'Pendiente de autorizar'
                : 'Espera los vistos buenos',
        estado: autorizada.value
            ? 'hecho'
            : rechazada.value
              ? actualAsignado
                  ? 'pendiente'
                  : 'rechazado'
              : !actualAsignado && vistosCompletos.value
                ? 'actual'
                : 'pendiente',
    });

    return lista;
});

const estilo: Record<
    Paso['estado'],
    { circulo: string; linea: string; texto: string }
> = {
    hecho: {
        circulo: 'bg-[var(--mrl-success)] text-white',
        linea: 'bg-[var(--mrl-success)]',
        texto: 'text-[var(--mrl-texto)]',
    },
    actual: {
        circulo:
            'bg-[var(--mrl-primary)] text-white ring-4 ring-[var(--mrl-primary)]/20 animate-pulse',
        linea: 'bg-[var(--mrl-borde)]',
        texto: 'text-[var(--mrl-primary)] font-semibold',
    },
    pendiente: {
        circulo:
            'bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)] border border-[var(--mrl-borde)]',
        linea: 'bg-[var(--mrl-borde)]',
        texto: 'text-[var(--mrl-texto-suave)]',
    },
    rechazado: {
        circulo: 'bg-[var(--mrl-danger)] text-white',
        linea: 'bg-[var(--mrl-borde)]',
        texto: 'text-[var(--mrl-danger)]',
    },
};

const decidiendo = ref<boolean | null>(null);
const form = useForm<{ aprobado: boolean; comentario: string }>({
    aprobado: true,
    comentario: '',
});

function enviar(aprobado: boolean) {
    form.aprobado = aprobado;
    form.post(vistoBueno.url(props.solicitudId), {
        preserveScroll: true,
        onSuccess: () => (decidiendo.value = null),
    });
}
</script>

<template>
    <section
        class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
        aria-label="Cadena de autorización"
    >
        <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold">
            <ShieldCheck class="size-4 text-[var(--mrl-primary)]" /> Cadena de
            autorización
        </h2>

        <ol class="flex flex-col gap-0 sm:flex-row sm:items-start">
            <li
                v-for="(p, i) in pasos"
                :key="p.clave"
                class="flex flex-1 gap-3 sm:flex-col sm:items-center sm:text-center"
            >
                <div class="flex flex-col items-center sm:w-full sm:flex-row">
                    <span
                        v-if="i > 0"
                        class="hidden h-0.5 flex-1 sm:block"
                        :class="
                            estilo[
                                pasos[i - 1].estado === 'hecho'
                                    ? 'hecho'
                                    : 'pendiente'
                            ].linea
                        "
                    />
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-full transition-all"
                        :class="estilo[p.estado].circulo"
                    >
                        <Check v-if="p.estado === 'hecho'" class="size-4" />
                        <X
                            v-else-if="p.estado === 'rechazado'"
                            class="size-4"
                        />
                        <Clock
                            v-else-if="p.estado === 'actual'"
                            class="size-4"
                        />
                        <UserRound v-else class="size-4" />
                    </span>
                    <span
                        v-if="i < pasos.length - 1"
                        class="hidden h-0.5 flex-1 sm:block"
                        :class="estilo[p.estado].linea"
                    />
                    <span
                        v-if="i < pasos.length - 1"
                        class="my-1 h-6 w-0.5 sm:hidden"
                        :class="estilo[p.estado].linea"
                    />
                </div>
                <div class="pb-3 sm:mt-2 sm:px-2">
                    <p class="text-sm" :class="estilo[p.estado].texto">
                        {{ p.titulo }}
                    </p>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        {{ p.detalle }}
                    </p>
                </div>
            </li>
        </ol>

        <ul
            v-if="niveles.some((n) => n.comentario)"
            class="mt-3 flex flex-col gap-1.5"
        >
            <li
                v-for="n in niveles.filter((x) => x.comentario)"
                :key="n.nivel"
                class="rounded-xl bg-[var(--mrl-fondo)] px-3 py-2 text-xs"
            >
                <span class="font-medium">{{ n.etiqueta }}:</span>
                {{ n.comentario }}
            </li>
        </ul>

        <div
            v-if="puedeDarVistoBueno"
            class="mt-4 flex flex-col gap-3 rounded-xl border border-[var(--mrl-primary)]/30 bg-[var(--mrl-primary)]/5 p-4"
        >
            <p class="text-sm font-medium">Te toca dar el visto bueno.</p>
            <div v-if="decidiendo === false" class="grid gap-1.5">
                <Textarea
                    v-model="form.comentario"
                    rows="2"
                    placeholder="¿Por qué no das el visto bueno? (obligatorio)"
                />
                <InputError
                    :message="
                        form.errors.comentario ??
                        (form.errors as Record<string, string>).solicitud
                    "
                />
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="decidiendo !== false"
                    :disabled="form.processing"
                    class="gap-2 transition-all hover:gap-3"
                    @click="enviar(true)"
                >
                    <ThumbsUp class="size-4" /> Dar visto bueno
                </Button>
                <Button
                    v-if="decidiendo !== false"
                    variant="outline"
                    :disabled="form.processing"
                    class="gap-2"
                    @click="decidiendo = false"
                >
                    <ThumbsDown class="size-4" /> No dar visto bueno
                </Button>
                <template v-else>
                    <Button
                        variant="destructive"
                        :disabled="form.processing || !form.comentario.trim()"
                        @click="enviar(false)"
                        >Rechazar con este motivo</Button
                    >
                    <Button variant="ghost" @click="decidiendo = null"
                        >Cancelar</Button
                    >
                </template>
            </div>
        </div>
    </section>
</template>
