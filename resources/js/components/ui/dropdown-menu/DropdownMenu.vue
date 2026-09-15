<script setup lang="ts">
import type { DropdownMenuRootEmits, DropdownMenuRootProps } from "reka-ui"
import { DropdownMenuRoot, useForwardPropsEmits } from "reka-ui"

/**
 * `modal` de reka-ui es `true` por defecto: bloquea
 * `document.body.style.pointerEvents` mientras el menú está abierto (ver
 * node_modules/reka-ui/dist/DropdownMenu/DropdownMenuRoot.js). Ninguno de
 * nuestros dropdowns (campana, usuario, acciones de fila) necesita ese
 * aislamiento de accesibilidad tipo modal, así que se desactiva aquí una
 * sola vez para toda la app en vez de bloquear body en cada uno.
 */
const props = withDefaults(defineProps<DropdownMenuRootProps>(), {
    modal: false,
})
const emits = defineEmits<DropdownMenuRootEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <DropdownMenuRoot
    v-slot="slotProps"
    data-slot="dropdown-menu"
    v-bind="forwarded"
  >
    <slot v-bind="slotProps" />
  </DropdownMenuRoot>
</template>
