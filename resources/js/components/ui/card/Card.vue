<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="card"
    :class="
      cn(
        'bg-card text-card-foreground flex flex-col gap-6 rounded-2xl border border-border/80 py-6 shadow-[0_1px_2px_rgb(48_58_56/0.04)]',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
