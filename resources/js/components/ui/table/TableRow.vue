<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <tr data-slot="table-row" :class="cn('hover:bg-menta/50 data-[state=selected]:bg-salvia/60 border-b border-border/70 transition-colors', props.class)">
        <slot />
    </tr>
</template>
