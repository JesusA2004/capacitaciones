<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { PencilLine } from '@lucide/vue';
import { computed, ref } from 'vue';
import CorregirDatoDialog from '@/components/correcciones/CorregirDatoDialog.vue';
import { Button } from '@/components/ui/button';
import type { DominioCorreccion, RegistroCorreccion } from '@/types';

/**
 * Abre la corrección de un registro. `permiso` solo oculta el botón (UX); quien
 * autoriza de verdad es CorreccionService.
 */
const props = withDefaults(
    defineProps<{
        dominio: DominioCorreccion;
        sujetoId: number;
        campo?: string;
        etiqueta?: string;
        permiso?: string;
        variante?: 'ghost' | 'outline' | 'secondary';
        /** Abre el diálogo al montar (llegó desde «Siguiente paso»). */
        abrirAlInicio?: boolean;
    }>(),
    { etiqueta: 'Corregir', variante: 'outline' },
);

const emit = defineEmits<{ corregido: [registro: RegistroCorreccion] }>();

const pagina = usePage<{ auth: { user: { permissions?: string[] } | null } }>();
const abierto = ref(props.abrirAlInicio === true);

const visible = computed(
    () =>
        props.permiso === undefined ||
        (pagina.props.auth.user?.permissions ?? []).includes(props.permiso),
);

function alCorregir(registro: RegistroCorreccion): void {
    emit('corregido', registro);
    router.reload();
}
</script>

<template>
    <template v-if="visible">
        <Button
            type="button"
            size="sm"
            :variant="variante"
            :aria-label="`${etiqueta}: abrir corrección con motivo`"
            data-boton-corregir
            @click="abierto = true"
        >
            <PencilLine class="size-3.5" />
            {{ etiqueta }}
        </Button>
        <CorregirDatoDialog
            v-if="abierto"
            v-model:open="abierto"
            :dominio="dominio"
            :sujeto-id="sujetoId"
            :campo-inicial="campo"
            @corregido="alCorregir"
        />
    </template>
</template>
