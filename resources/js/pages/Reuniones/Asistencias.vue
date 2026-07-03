<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { marcar } from '@/routes/sesiones/asistencias';
import type { AsistenciaItem, SesionConAsistencias } from '@/types';

const props = defineProps<{
    sesion: SesionConAsistencias;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Sesión en vivo', href: '#' },
            { title: 'Asistencias', href: '#' },
        ],
    },
});

const { mostrarExito, mostrarError, confirmarCambioAsistencia } = useAlertas();

const etiquetaEstado: Record<string, string> = {
    pendiente: 'Pendiente',
    presente: 'Presente',
    asistencia_parcial: 'Asistencia parcial',
    ausente: 'Ausente',
    tarde: 'Tarde',
    pendiente_revision: 'Pendiente de revisión',
    corregida_manualmente: 'Corregida manualmente',
};

const formularioCorreccion = reactive<
    Record<number, { motivo: string; minutos: string; evidencia: File | null }>
>({});

function datosCorreccion(asistenciaId: number) {
    formularioCorreccion[asistenciaId] ??= {
        motivo: '',
        minutos: '',
        evidencia: null,
    };

    return formularioCorreccion[asistenciaId];
}

function seleccionarEvidencia(asistenciaId: number, evento: Event) {
    const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    datosCorreccion(asistenciaId).evidencia = archivo;
}

async function cambiarEstado(asistencia: AsistenciaItem, estado: string) {
    const yaEstabaMarcada = asistencia.estado !== 'pendiente';

    if (yaEstabaMarcada) {
        const confirmado = await confirmarCambioAsistencia();

        if (!confirmado) {
            return;
        }
    }

    const datos = datosCorreccion(asistencia.id);

    router.post(
        marcar.url({ sesion: props.sesion.id, asistencia: asistencia.id }),
        {
            estado,
            motivo: datos.motivo,
            minutos: datos.minutos || undefined,
            evidencia: datos.evidencia ?? undefined,
        },
        {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () =>
                mostrarExito('Asistencia actualizada correctamente.'),
            onError: () =>
                mostrarError('No fue posible actualizar la asistencia.'),
        },
    );
}
</script>

<template>
    <Head title="Asistencias" />

    <div class="flex flex-col gap-6 p-4">
        <Heading :title="sesion.titulo" description="Registro de asistencia" />

        <div class="flex flex-col gap-3">
            <div
                v-for="asistencia in sesion.asistencias"
                :key="asistencia.id"
                class="rounded-lg border p-4"
            >
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium">
                        {{ asistencia.usuario.name }}
                        {{ asistencia.usuario.apellidos ?? '' }}
                    </span>
                    <Badge variant="secondary">
                        {{
                            etiquetaEstado[asistencia.estado] ??
                            asistencia.estado
                        }}
                    </Badge>
                </div>

                <!-- Datos recuperados de la sincronización automática, si existen -->
                <div
                    v-if="asistencia.sincronizado_en"
                    class="mt-2 rounded-md bg-muted/50 p-2 text-xs text-muted-foreground"
                >
                    <p>
                        Sincronizado automáticamente:
                        {{ asistencia.minutos_totales ?? 0 }} min ({{
                            asistencia.porcentaje_sesion ?? 0
                        }}%), {{ asistencia.numero_reconexiones }}
                        reconexión(es).
                    </p>
                    <p v-if="asistencia.motivo_estado" class="mt-1">
                        {{ asistencia.motivo_estado }}
                    </p>
                    <ul
                        v-if="
                            asistencia.sesion_participante?.entradas_salidas
                                ?.length
                        "
                        class="mt-1 list-inside list-disc"
                    >
                        <li
                            v-for="tramo in asistencia.sesion_participante
                                .entradas_salidas"
                            :key="tramo.id"
                        >
                            {{ new Date(tramo.inicio).toLocaleTimeString() }} –
                            {{
                                tramo.fin
                                    ? new Date(tramo.fin).toLocaleTimeString()
                                    : 'en curso'
                            }}
                        </li>
                    </ul>
                </div>

                <!-- Rastro de la última corrección manual, si existe -->
                <div
                    v-if="asistencia.corregido_por"
                    class="mt-2 rounded-md border border-dashed p-2 text-xs text-muted-foreground"
                >
                    <p>
                        Corregida por {{ asistencia.corregido_por.name }}: de
                        «{{
                            etiquetaEstado[asistencia.estado_anterior ?? ''] ??
                            asistencia.estado_anterior
                        }}»
                        <template v-if="asistencia.minutos_anteriores !== null">
                            ({{ asistencia.minutos_anteriores }} min)
                        </template>
                        a «{{ etiquetaEstado[asistencia.estado] }}»
                        <template v-if="asistencia.minutos_totales !== null">
                            ({{ asistencia.minutos_totales }} min)
                        </template>
                        . Motivo: {{ asistencia.motivo_correccion }}
                    </p>
                </div>

                <div
                    v-if="asistencia.estado !== 'pendiente'"
                    class="mt-2 grid gap-2 sm:grid-cols-3"
                >
                    <Input
                        v-model="datosCorreccion(asistencia.id).motivo"
                        placeholder="Motivo de la corrección (obligatorio)"
                        class="sm:col-span-2"
                    />
                    <Input
                        v-model="datosCorreccion(asistencia.id).minutos"
                        type="number"
                        min="0"
                        placeholder="Minutos corregidos"
                    />
                    <input
                        type="file"
                        class="text-xs sm:col-span-3"
                        @change="
                            (evento) =>
                                seleccionarEvidencia(asistencia.id, evento)
                        "
                    />
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        @click="cambiarEstado(asistencia, 'presente')"
                        >Presente</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="cambiarEstado(asistencia, 'asistencia_parcial')"
                        >Asistencia parcial</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="cambiarEstado(asistencia, 'tarde')"
                        >Tarde</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="cambiarEstado(asistencia, 'ausente')"
                        >Ausente</Button
                    >
                </div>
            </div>
        </div>
    </div>
</template>
