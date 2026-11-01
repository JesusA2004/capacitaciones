<script setup lang="ts">
import { X } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/** La lista llegó acotada a una persona (`?colaborador=`) desde su proceso. */
defineProps<{ activo: boolean }>();

defineEmits<{ quitar: [] }>();
</script>

<template>
    <p
        v-if="activo"
        class="flex flex-wrap items-center gap-2 rounded-xl bg-salvia px-3 py-1.5 text-sm"
    >
        Solo se muestra una persona.
        <Button
            type="button"
            size="sm"
            variant="ghost"
            class="h-7 px-2"
            @click="$emit('quitar')"
        >
            <X class="size-3.5" /> Ver a todas
        </Button>
    </p>
</template>
