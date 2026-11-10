<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    CircleCheckBig,
    Hourglass,
    MailCheck,
    MailWarning,
    MailX,
    MonitorCheck,
    Send,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { Spinner } from '@/components/ui/spinner';
import { formatearFecha } from '@/lib/fechas';
import { reenviar } from '@/routes/rh/candidatos/aviso-sistemas';

export type ResumenAvisoSistemas = {
    estado:
        | 'pendiente'
        | 'enviado'
        | 'fallido'
        | 'sin_enviar'
        | 'esperando_datos'
        | null;
    estado_etiqueta: string | null;
    enviado_en: string | null;
    ultimo_error: string | null;
    puede_reenviar: boolean;
    // Opcionales (AvisoAltaSistemasService::resumen, bandeja de Sistemas):
    correo_activo?: boolean;
    atendido?: boolean;
    atendido_en?: string | null;
    atendido_en_texto?: string | null;
    atendido_por?: string | null;
    notas_atencion?: string | null;
    url_bandeja?: string | null;
};

/**
 * Alta en sistemas (fase 6 · Contratación): dos estados que no se mezclan —
 * el CORREO a Sistemas (en cola / enviado / no enviado) y si Sistemas YA dio
 * de alta a la persona en las demás plataformas (bandeja «Pendientes de
 * alta en sistemas»). Reenvío manual con AvisoAltaSistemasService.
 */
const props = defineProps<{
    candidatoId: number;
    aviso: ResumenAvisoSistemas;
}>();

const enviando = ref(false);

type Estado = {
    icono: Component;
    texto: string;
    detalle?: string | null;
    clases: string;
};

const correo = computed<Estado>(() => {
    switch (props.aviso.estado) {
        case 'enviado':
            return {
                icono: MailCheck,
                texto: 'Correo enviado',
                detalle: props.aviso.enviado_en
                    ? formatearFecha(props.aviso.enviado_en)
                    : null,
                clases: 'bg-success-soft text-success',
            };
        case 'fallido':
            return {
                icono: MailWarning,
                texto: 'No se pudo enviar',
                detalle: 'Reintenta el envío',
                clases: 'bg-danger-soft text-destructive',
            };
        case 'pendiente':
            return {
                icono: Hourglass,
                texto: 'Correo en cola',
                detalle: 'Sale en unos minutos',
                clases: 'bg-warning-soft text-warning',
            };
        case 'esperando_datos':
            return {
                icono: Hourglass,
                texto: 'En espera de sus datos',
                detalle: 'Sale cuando capture sus datos en la app',
                clases: 'bg-crema text-bronce',
            };
        case 'sin_enviar':
            return {
                icono: MailX,
                texto: 'Correo no enviado',
                detalle:
                    props.aviso.correo_activo === false
                        ? 'El correo está desactivado'
                        : null,
                clases: 'bg-crema text-bronce',
            };
        default:
            return {
                icono: MailX,
                texto: 'Sin aviso a Sistemas',
                clases: 'bg-crema text-bronce',
            };
    }
});

const sistemas = computed<Estado>(() =>
    props.aviso.atendido
        ? {
              icono: CircleCheckBig,
              texto: 'Dado de alta',
              detalle: [props.aviso.atendido_por, props.aviso.atendido_en_texto]
                  .filter(Boolean)
                  .join(' · '),
              clases: 'bg-success-soft text-success',
          }
        : {
              icono: MonitorCheck,
              texto: 'Alta en sistemas pendiente',
              detalle: 'Sistemas aún no lo marca',
              clases: 'bg-info-soft text-info',
          },
);

const mostrarSistemas = computed(
    () => props.aviso.estado !== null || props.aviso.atendido === true,
);

function reenviarAviso() {
    enviando.value = true;
    router.post(
        reenviar.url(props.candidatoId),
        {},
        { preserveScroll: true, onFinish: () => (enviando.value = false) },
    );
}
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-2 text-sm"
        aria-label="Alta en sistemas"
    >
        <CambioSuave>
            <span
                :key="`correo-${aviso.estado ?? 'ninguno'}`"
                class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 transicion-ui"
                :class="correo.clases"
                :title="aviso.ultimo_error ?? undefined"
            >
                <component
                    :is="correo.icono"
                    class="size-4 shrink-0"
                    aria-hidden="true"
                />
                <span class="flex flex-col leading-tight">
                    <span class="font-medium">{{ correo.texto }}</span>
                    <span v-if="correo.detalle" class="text-xs opacity-80">
                        {{ correo.detalle }}
                    </span>
                </span>
            </span>
        </CambioSuave>

        <CambioSuave>
            <span
                v-if="mostrarSistemas"
                :key="`sistemas-${aviso.atendido ? 'si' : 'no'}`"
                class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 transicion-ui"
                :class="sistemas.clases"
                :title="aviso.notas_atencion ?? undefined"
            >
                <component
                    :is="sistemas.icono"
                    class="size-4 shrink-0"
                    aria-hidden="true"
                />
                <span class="flex flex-col leading-tight">
                    <span class="font-medium">{{ sistemas.texto }}</span>
                    <span v-if="sistemas.detalle" class="text-xs opacity-80">
                        {{ sistemas.detalle }}
                    </span>
                </span>
            </span>
        </CambioSuave>

        <div class="ml-auto flex flex-wrap items-center gap-2">
            <Button v-if="aviso.url_bandeja" as-child size="sm" variant="ghost">
                <Link :href="aviso.url_bandeja">
                    <ArrowUpRight class="size-3.5" />
                    Ver bandeja
                </Link>
            </Button>
            <Button
                v-if="aviso.puede_reenviar"
                size="sm"
                variant="outline"
                :disabled="enviando"
                @click="reenviarAviso"
            >
                <Spinner v-if="enviando" />
                <Send v-else class="size-3.5" />
                {{
                    aviso.estado === 'sin_enviar' || aviso.estado === null
                        ? 'Enviar a Sistemas'
                        : 'Reenviar'
                }}
            </Button>
        </div>
    </div>
</template>
