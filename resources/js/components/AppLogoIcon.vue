<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

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
        src="/images/logoLetras.png"
        :alt="alt"
        :class="className"
        v-bind="$attrs"
    />
</template>
