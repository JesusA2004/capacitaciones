<script setup lang="ts">
import { Smile } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * Paleta curada de emojis de celebración para insertar en texto libre (ej.
 * frases de felicitación de cumpleaños). El panel se queda abierto tras
 * elegir uno (son botones simples, no DropdownMenuItem) para poder agregar
 * varios seguidos sin reabrir.
 */
defineEmits<{
    select: [emoji: string];
}>();

const EMOJIS = [
    '🎂', '🎉', '🎈', '🥳', '🎁', '✨', '🌟', '💫',
    '🙌', '👏', '😄', '😊', '🤗', '😍', '🥰', '😁',
    '💚', '💙', '💛', '🧡', '💜', '❤️', '🎊', '🍰',
    '🧁', '🍾', '🥂', '🎶', '🎵', '🌈', '☀️', '🌻',
    '🌸', '🍀', '🏆', '🏅', '👑', '💐', '🎀', '🪅',
];
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                type="button"
                variant="outline"
                size="icon"
                class="shrink-0"
                aria-label="Insertar emoji"
            >
                <Smile class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="grid w-64 grid-cols-8 gap-0.5 p-2">
            <button
                v-for="emoji in EMOJIS"
                :key="emoji"
                type="button"
                class="flex size-7 items-center justify-center rounded text-lg transition-colors hover:bg-accent"
                @click="$emit('select', emoji)"
            >
                {{ emoji }}
            </button>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
