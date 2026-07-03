<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

const motivos = ref<Record<number, string>>({});

const etiquetaEstado: Record<string, string> = {
    pendiente: 'Pendiente',
    presente: 'Presente',
    ausente: 'Ausente',
    tarde: 'Tarde',
};

async function cambiarEstado(asistencia: AsistenciaItem, estado: string) {
    const yaEstabaMarcada = asistencia.estado !== 'pendiente';

    if (yaEstabaMarcada) {
        const confirmado = await confirmarCambioAsistencia();

        if (!confirmado) {
            return;
        }
    }

    router.post(
        marcar.url({ sesion: props.sesion.id, asistencia: asistencia.id }),
        { estado, motivo: motivos.value[asistencia.id] },
        {
            preserveScroll: true,
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

                <div
                    v-if="asistencia.estado !== 'pendiente'"
                    class="mt-2 grid gap-2"
                >
                    <input
                        v-model="motivos[asistencia.id]"
                        placeholder="Motivo de la corrección (obligatorio para cambiar)"
                        class="w-full rounded-md border px-3 py-1.5 text-sm"
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
