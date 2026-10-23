<script setup lang="ts">
import type { AcceptableValue } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { ChevronDownIcon } from "@lucide/vue"
import { reactiveOmit, useVModel } from "@vueuse/core"
import { computed } from "vue"
import { separarClasesAcomodo } from "@/lib/clasesAcomodo"
import { cn } from "@/lib/utils"

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<{ modelValue?: AcceptableValue | AcceptableValue[], class?: HTMLAttributes["class"] }>()

const emit = defineEmits<{
  "update:modelValue": [value: AcceptableValue]
}>()

const modelValue = useVModel(props, "modelValue", emit, {
  passive: true,
  defaultValue: "",
})

const delegatedProps = reactiveOmit(props, "class")

// `class` del padre: ancho/flex/márgenes/visibilidad van a la envoltura
// (por defecto w-fit) y el <select> la llena; lo visual va al <select>.
// Antes la envoltura era siempre w-fit y `class="w-full"` no hacía nada.
const clases = computed(() => separarClasesAcomodo(cn(props.class)))
</script>

<template>
  <div
    :class="cn('group/native-select relative w-fit has-[select:disabled]:opacity-50', clases.acomodo)"
    data-slot="native-select-wrapper"
  >
    <select
      v-bind="{ ...$attrs, ...delegatedProps }"
      v-model="modelValue"
      data-slot="native-select"
      :class="cn(
        'border-input placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground h-9 w-full min-w-0 appearance-none rounded-md border bg-transparent px-3 py-2 pr-9 text-sm shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed',
        'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-3',
        'aria-invalid:ring-destructive/20 aria-invalid:border-destructive',
        clases.resto,
      )"
    >
      <slot />
    </select>
    <ChevronDownIcon
      class="text-muted-foreground pointer-events-none absolute top-1/2 right-3.5 size-4 -translate-y-1/2 opacity-50 select-none"
      aria-hidden="true"
      data-slot="native-select-icon"
    />
  </div>
</template>
