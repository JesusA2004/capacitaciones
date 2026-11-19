<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="dialog-header"
    :class="cn('flex flex-col gap-2 px-8 text-center sm:px-0 sm:pr-8 sm:text-left', props.class)"
  >
    <slot />
  </div>
</template>
