<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import logoLetras from '@/assets/brand/logoLetras.png';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
    alt?: string;
};

withDefaults(defineProps<Props>(), {
    alt: 'Logo',
});
</script>

<template>
    <img
        :src="logoLetras"
        :alt="alt"
        :class="className"
        v-bind="$attrs"
    />
</template>
