<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { AlertTriangle, BellRing, PencilLine } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { avisarDatosFaltantes } from '@/routes/rh/expedientes';
import { index as indexSolicitudes } from '@/routes/solicitudes';

/**
 * ⚠ Faltan datos contractuales. RH: «Avisar al colaborador» (notificación
 * + push, máximo uno cada 24 h). El propio colaborador: «Completar mis
 * datos» abre la solicitud de actualización (nunca edita directo).
 */
export type DatosFaltantesResumen = {
    completo: boolean;
    personales: { campo: string; etiqueta: string }[];
    laborales: { campo: string; etiqueta: string }[];
    solicitud_en_revision: boolean;
    ultimo_aviso: { fecha: string | null; enviado_por: string | null } | null;
    puede_avisar_de_nuevo: boolean;
};

const props = defineProps<{
    datos: DatosFaltantesResumen;
    colaboradorId: number;
    esPropio: boolean;
    puedeAvisar: boolean;
}>();

const enviando = ref(false);
const personales = computed(() => props.datos.personales.map((d) => d.etiqueta));
const laborales = computed(() => props.datos.laborales.map((d) => d.etiqueta));

function avisar() {
    enviando.value = true;
    router.post(avisarDatosFaltantes.url(props.colaboradorId), {}, {
        preserveScroll: true,
        onFinish: () => (enviando.value = false),
    });
}
</script>

<template>
    <section
        v-if="!datos.completo && (esPropio ? personales.length > 0 : true)"
        class="flex flex-col gap-3 rounded-2xl border border-[#af8b51]/40 bg-[#e9d6b0]/20 p-4 sm:flex-row sm:items-center sm:justify-between dark:bg-[#c9a876]/10"
    >
        <div class="flex items-start gap-3">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-[#af8b51]/20 text-[#754711] dark:text-[#e9d6b0]">
                <AlertTriangle class="size-4" />
            </span>
            <div class="grid gap-1 text-sm">
                <p class="font-semibold">{{ esPropio ? 'Completa tu información' : 'Faltan datos' }}</p>
                <p v-if="personales.length" class="text-muted-foreground">
                    <span class="font-medium text-foreground">{{ esPropio ? 'Te falta' : 'Del colaborador' }}:</span>
                    {{ personales.join(', ') }}
                </p>
                <p v-if="!esPropio && laborales.length" class="text-muted-foreground">
                    <span class="font-medium text-foreground">Los captura RH:</span> {{ laborales.join(', ') }}
                </p>
                <p v-if="datos.solicitud_en_revision" class="text-xs text-[#754711] dark:text-[#e9d6b0]">
                    Hay una solicitud de actualización de datos en revisión.
                </p>
                <p v-if="!esPropio && datos.ultimo_aviso" class="text-xs text-muted-foreground">
                    Último aviso: {{ datos.ultimo_aviso.fecha ? new Date(datos.ultimo_aviso.fecha).toLocaleString('es-MX') : '' }}
                    <template v-if="datos.ultimo_aviso.enviado_por"> por {{ datos.ultimo_aviso.enviado_por }}</template>
                </p>
            </div>
        </div>

        <Button
            v-if="!esPropio && puedeAvisar && personales.length"
            variant="outline"
            class="shrink-0"
            :disabled="enviando || !datos.puede_avisar_de_nuevo"
            :title="datos.puede_avisar_de_nuevo ? '' : 'Ya se avisó en las últimas 24 horas'"
            @click="avisar"
        >
            <BellRing class="size-4" />
            Avisar al colaborador
        </Button>
        <Button v-else-if="esPropio && !datos.solicitud_en_revision" as-child class="shrink-0">
            <Link :href="indexSolicitudes({ query: { nueva: 'actualizacion_datos' } })">
                <PencilLine class="size-4" />
                Completar mis datos
            </Link>
        </Button>
    </section>
</template>
