<script setup lang="ts">
import { Paperclip, UserRound, Users } from '@lucide/vue';
import { computed } from 'vue';
import { formatearFecha } from '@/lib/fechas';
import type { ActaDetalle, CatalogosActa } from '@/types';

/** Resumen del acta circunstanciada tal como está guardada (revisión final y consulta). */
const props = defineProps<{
    acta: ActaDetalle;
    catalogos: CatalogosActa;
}>();

const c = computed(() => props.acta.circunstanciada);

function etiqueta(
    lista: keyof CatalogosActa,
    valor: string | null | undefined,
): string {
    return props.catalogos[lista].find((o) => o.value === valor)?.label ?? '—';
}

const tipo = computed(() => {
    const i = c.value?.incidencia;

    if (!i?.tipo) {
        return 'Sin capturar';
    }

    return i.tipo === 'otra'
        ? i.otro || 'Otra'
        : etiqueta('incidencias', i.tipo);
});

const manifestacion = computed(() => {
    const t = c.value?.trabajador;

    if (t?.manifesto === false) {
        return 'Manifestó que no deseaba formular manifestación.';
    }

    return t?.manifestacion || 'Sin capturar';
});
</script>

<template>
    <div v-if="c" class="flex flex-col gap-4 text-sm">
        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 xl:grid-cols-3">
            <div>
                <dt class="text-xs text-muted-foreground">Colaborador</dt>
                <dd class="font-medium">
                    {{ acta.colaborador.nombre }}
                    <span
                        v-if="acta.colaborador.numero_empleado"
                        class="text-xs font-normal text-muted-foreground"
                        >· {{ acta.colaborador.numero_empleado }}</span
                    >
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Levantamiento</dt>
                <dd>
                    {{ formatearFecha(acta.fecha)
                    }}<template v-if="acta.hora">
                        · {{ acta.hora.slice(0, 5) }} h</template
                    >
                    <span class="block text-xs text-muted-foreground">{{
                        acta.lugar || 'Ciudad de su sucursal'
                    }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">
                    Tipo de incidencia
                </dt>
                <dd>{{ tipo }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">
                    Fecha de los hechos
                </dt>
                <dd>
                    {{ c.incidencia.fecha_hechos || 'Sin capturar'
                    }}<template v-if="c.incidencia.hora_hechos">
                        · {{ c.incidencia.hora_hechos }}</template
                    >
                    <span
                        v-if="c.incidencia.lugar_hechos"
                        class="block text-xs text-muted-foreground"
                        >{{ c.incidencia.lugar_hechos }}</span
                    >
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">
                    Firma por la empresa
                </dt>
                <dd>
                    Representante legal
                    <span class="block text-xs text-muted-foreground"
                        >Nombre en blanco, a pluma. Capturó:
                        {{ c.responsable.nombre || '—' }} (no se imprime)</span
                    >
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Jefe inmediato</dt>
                <dd>{{ c.jefe.participa ? c.jefe.nombre : 'No participa' }}</dd>
            </div>
        </dl>

        <div class="grid gap-3 md:grid-cols-2">
            <div class="rounded-xl bg-crema/40 p-3">
                <p
                    class="mb-1.5 flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
                >
                    <Users class="size-3.5" /> Testigos de hechos
                </p>
                <ul v-if="c.testigos_hechos.length" class="flex flex-col gap-1">
                    <li v-for="(t, i) in c.testigos_hechos" :key="i">
                        {{ t.nombre }}
                        <span class="text-xs text-muted-foreground"
                            >·
                            {{
                                t.relacion === 'otra'
                                    ? t.relacion_otro || 'Otra'
                                    : etiqueta('relaciones', t.relacion)
                            }}</span
                        >
                    </li>
                </ul>
                <p v-else class="text-muted-foreground italic">
                    No se registraron testigos de hechos.
                </p>
            </div>
            <div class="rounded-xl bg-crema/40 p-3">
                <p
                    class="mb-1.5 flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
                >
                    <Users class="size-3.5" /> Testigos de asistencia
                </p>
                <ul
                    v-if="c.testigos_asistencia.length"
                    class="flex flex-col gap-1"
                >
                    <li v-for="(t, i) in c.testigos_asistencia" :key="i">
                        {{ t.nombre
                        }}<span
                            v-if="t.puesto"
                            class="text-xs text-muted-foreground"
                        >
                            · {{ t.puesto }}</span
                        >
                    </li>
                </ul>
                <p v-else class="text-muted-foreground italic">
                    Sin testigos de asistencia.
                </p>
            </div>
        </div>

        <div>
            <p class="mb-1 text-xs text-muted-foreground">Hechos</p>
            <p class="line-clamp-6 whitespace-pre-line">
                {{ acta.hechos || 'Sin capturar' }}
            </p>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <p
                    class="mb-1 flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <UserRound class="size-3.5" /> Manifestación del trabajador
                </p>
                <p class="line-clamp-4 whitespace-pre-line">
                    {{ manifestacion }}
                </p>
            </div>
            <div>
                <p
                    class="mb-1 flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <Paperclip class="size-3.5" /> Anexos
                </p>
                <p v-if="acta.anexos.length">
                    {{ acta.anexos.length }}
                    {{
                        acta.anexos.length === 1
                            ? 'anexo relacionado'
                            : 'anexos relacionados'
                    }}
                </p>
                <p v-else class="text-muted-foreground italic">
                    No se incorporaron anexos.
                </p>
            </div>
        </div>
    </div>
</template>
