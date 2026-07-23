<script setup lang="ts">
import type { TabsListProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'
import { reactiveOmit } from '@vueuse/core'
import { TabsList, useForwardProps } from 'reka-ui'
import { cn } from '@/lib/utils'

const props = defineProps<TabsListProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = reactiveOmit(props, 'class')
const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <TabsList
    data-slot="tabs-list"
    v-bind="forwardedProps"
    :class="cn('inline-flex h-9 w-fit items-center justify-start gap-1 overflow-x-auto rounded-xl bg-muted p-1 text-muted-foreground', props.class)"
  >
    <slot />
  </TabsList>
</template>
