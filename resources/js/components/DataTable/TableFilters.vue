<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

defineProps<{
    modelValue: string;
    placeholder?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [valor: string];
    limpiar: [];
}>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div class="relative w-full max-w-sm">
            <Search
                class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
            />
            <Input
                :model-value="modelValue"
                @update:model-value="
                    (valor) => emit('update:modelValue', String(valor))
                "
                :placeholder="placeholder ?? 'Buscar...'"
                class="pl-8"
            />
        </div>

        <slot />

        <Button variant="ghost" size="sm" @click="emit('limpiar')">
            <X class="size-4" />
            Limpiar filtros
        </Button>
    </div>
</template>
