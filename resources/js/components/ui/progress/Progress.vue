<script setup lang="ts">
import type { ProgressRootEmits, ProgressRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { ProgressIndicator, ProgressRoot, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<ProgressRootProps & { class?: HTMLAttributes["class"], indicatorClass?: HTMLAttributes["class"] }>()
const emits = defineEmits<ProgressRootEmits>()

const delegatedProps = reactiveOmit(props, "class", "indicatorClass")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <ProgressRoot
    data-slot="progress"
    v-bind="forwarded"
    :class="cn('bg-primary/15 relative h-2 w-full overflow-hidden rounded-full', props.class)"
  >
    <ProgressIndicator
      data-slot="progress-indicator"
      :class="cn('bg-primary h-full w-full flex-1 transition-transform duration-500 ease-out', props.indicatorClass)"
      :style="`transform: translateX(-${100 - (props.modelValue ?? 0)}%)`"
    />
  </ProgressRoot>
</template>
