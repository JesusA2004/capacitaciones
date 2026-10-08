<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import simboloColor from '@/assets/brand/people/MrLanaPeople_simbolo_color.svg';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
    alt?: string;
};

withDefaults(defineProps<Props>(), {
    alt: 'MR. LANA PEOPLE',
});
</script>

<template>
    <img :src="simboloColor" :alt="alt" :class="className" v-bind="$attrs" />
</template>
