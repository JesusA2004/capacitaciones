<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';

type Variante = NonNullable<BadgeVariants['variant']>;

/**
 * Capa semántica sobre `Badge`: convierte cualquier valor de estado del
 * backend (vencida, en_progreso, aprobada, presente...) en el mismo color
 * en toda la app, sin que cada pantalla tenga que decidir su propia
 * variante. Ver docs/AUDITORIA_CUMPLIMIENTO.md — fase de modernización
 * visual, sección "Estados y badges consistentes".
 */
const props = defineProps<{
    estado: string;
    etiqueta?: string;
}>();

const MAPA_ESTADOS: Record<string, { variante: Variante; etiqueta: string }> = {
    // Verde: completado / aprobado / presente / disponible.
    completada: { variante: 'success', etiqueta: 'Completada' },
    completado: { variante: 'success', etiqueta: 'Completado' },
    aprobada: { variante: 'success', etiqueta: 'Aprobada' },
    aprobado: { variante: 'success', etiqueta: 'Aprobado' },
    presente: { variante: 'success', etiqueta: 'Presente' },
    activo: { variante: 'success', etiqueta: 'Activo' },
    disponible: { variante: 'success', etiqueta: 'Disponible' },
    publicado: { variante: 'success', etiqueta: 'Publicado' },
    con_imss: { variante: 'success', etiqueta: 'Con IMSS' },

    // Amarillo/naranja: pendiente / parcial / procesando / en revisión.
    pendiente: { variante: 'warning', etiqueta: 'Pendiente' },
    asistencia_parcial: { variante: 'warning', etiqueta: 'Asistencia parcial' },
    parcial: { variante: 'warning', etiqueta: 'Parcial' },
    pendiente_revision: {
        variante: 'warning',
        etiqueta: 'Pendiente de revisión',
    },
    procesando: { variante: 'warning', etiqueta: 'Procesando' },
    entregada: { variante: 'warning', etiqueta: 'Entregada' },
    enviado: { variante: 'warning', etiqueta: 'Enviado' },
    en_revision: { variante: 'warning', etiqueta: 'En revisión' },
    tarde: { variante: 'warning', etiqueta: 'Tarde' },
    pendiente_imss: { variante: 'warning', etiqueta: 'Pendiente de IMSS' },

    // Rojo: vencido / ausente / error / rechazado.
    vencida: { variante: 'destructive', etiqueta: 'Vencida' },
    vencido: { variante: 'destructive', etiqueta: 'Vencido' },
    revocado: { variante: 'destructive', etiqueta: 'Revocado' },
    revocada: { variante: 'destructive', etiqueta: 'Revocada' },
    ausente: { variante: 'destructive', etiqueta: 'Ausente' },
    rechazada: { variante: 'destructive', etiqueta: 'Rechazada' },
    reprobado: { variante: 'destructive', etiqueta: 'Reprobado' },
    reprobada: { variante: 'destructive', etiqueta: 'Reprobada' },
    error: { variante: 'destructive', etiqueta: 'Error' },
    suspendido: { variante: 'destructive', etiqueta: 'Suspendido' },
    descartado: { variante: 'destructive', etiqueta: 'Descartado' },
    sin_imss: { variante: 'destructive', etiqueta: 'Sin IMSS' },

    // Azul/turquesa: en progreso / asignado / información.
    en_progreso: { variante: 'info', etiqueta: 'En progreso' },
    asignado: { variante: 'info', etiqueta: 'Asignado' },
    programada: { variante: 'info', etiqueta: 'Programada' },
    calificado: { variante: 'info', etiqueta: 'Calificado' },
    sincronizado: { variante: 'info', etiqueta: 'Sincronizado' },
    usado: { variante: 'info', etiqueta: 'Usado' },
    corregida_manualmente: {
        variante: 'info',
        etiqueta: 'Corregida manualmente',
    },
    enviada: { variante: 'info', etiqueta: 'Enviada' },

    // Gris: borrador / archivado / inactivo.
    borrador: { variante: 'secondary', etiqueta: 'Borrador' },
    archivado: { variante: 'secondary', etiqueta: 'Archivado' },
    inactivo: { variante: 'secondary', etiqueta: 'Inactivo' },
    cancelada: { variante: 'secondary', etiqueta: 'Cancelada' },
    creada: { variante: 'secondary', etiqueta: 'Creada' },
    cerrada: { variante: 'secondary', etiqueta: 'Cerrada' },
    requiere_correccion: {
        variante: 'warning',
        etiqueta: 'Requiere corrección',
    },
};

const info = computed(() => {
    const clave = props.estado?.toLowerCase() ?? '';

    return (
        MAPA_ESTADOS[clave] ?? {
            variante: 'outline' as Variante,
            etiqueta: clave.replace(/_/g, ' '),
        }
    );
});
</script>

<template>
    <Badge :variant="info.variante" class="capitalize">
        {{ etiqueta ?? info.etiqueta }}
    </Badge>
</template>
