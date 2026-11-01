<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import type { EnlaceFlujo } from '@/types';

/** «Volver a…»: lo resuelve el backend con lista blanca, nunca una URL recibida. */
defineProps<{ regreso?: EnlaceFlujo | null }>();
</script>

<template>
    <Link
        v-if="regreso"
        :href="regreso.href"
        class="inline-flex w-fit items-center gap-1.5 text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
    >
        <ArrowLeft class="size-4" aria-hidden="true" />
        {{ regreso.etiqueta }}
    </Link>
</template>
