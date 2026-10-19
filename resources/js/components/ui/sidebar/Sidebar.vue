<script setup lang="ts">
import type { SidebarProps } from "."
import { useMediaQuery } from "@vueuse/core"
import { computed } from "vue"
import { cn } from "@/lib/utils"
import { Sheet, SheetContent } from '@/components/ui/sheet'
import SheetDescription from '@/components/ui/sheet/SheetDescription.vue'
import SheetHeader from '@/components/ui/sheet/SheetHeader.vue'
import SheetTitle from '@/components/ui/sheet/SheetTitle.vue'
import { SIDEBAR_WIDTH_MOBILE, useSidebar } from "./utils"

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(defineProps<SidebarProps>(), {
  side: "left",
  variant: "sidebar",
  collapsible: "offcanvas",
})

const { isMobile, state, open, setHovered, openMobile, setOpenMobile } = useSidebar()

// Solo escritorio con puntero fino expande al pasar el mouse; en touch no.
const canHover = useMediaQuery("(hover: hover) and (pointer: fine)")

// El contenido lo empuja el ancho del estado FIJADO por el usuario (`open`),
// no el estado visible: así la expansión temporal al pasar el mouse solo
// superpone la barra y la app no se mueve.
const pinnedCollapsed = computed(() => !open.value)
const gapWidth = computed(() => {
  if (!pinnedCollapsed.value)
    return "var(--sidebar-width)"
  if (props.collapsible === "offcanvas")
    return "0px"
  if (props.variant === "floating" || props.variant === "inset")
    return "calc(var(--sidebar-width-icon) + 1rem)"
  return "var(--sidebar-width-icon)"
})

function alEntrarMouse() {
  if (canHover.value && pinnedCollapsed.value)
    setHovered(true)
}
function alSalirMouse() {
  setHovered(false)
}
</script>

<template>
  <div
    v-if="collapsible === 'none'"
    data-slot="sidebar"
    :class="cn('bg-sidebar text-sidebar-foreground flex h-full w-(--sidebar-width) flex-col', props.class)"
    v-bind="$attrs"
  >
    <slot />
  </div>

  <Sheet v-else-if="isMobile" :open="openMobile" v-bind="$attrs" @update:open="setOpenMobile">
    <SheetContent
      data-sidebar="sidebar"
      data-slot="sidebar"
      data-mobile="true"
      :side="side"
      class="bg-sidebar text-sidebar-foreground w-(--sidebar-width) p-0 [&>button]:hidden"
      :style="{
        '--sidebar-width': SIDEBAR_WIDTH_MOBILE,
      }"
    >
      <SheetHeader class="sr-only">
        <SheetTitle>Sidebar</SheetTitle>
        <SheetDescription>Displays the mobile sidebar.</SheetDescription>
      </SheetHeader>
      <div class="flex h-full w-full flex-col">
        <slot />
      </div>
    </SheetContent>
  </Sheet>

  <div
    v-else
    class="group peer text-sidebar-foreground hidden md:block"
    data-slot="sidebar"
    :data-state="state"
    :data-pinned-state="open ? 'expanded' : 'collapsed'"
    :data-pinned-collapsible="pinnedCollapsed ? collapsible : ''"
    :data-collapsible="state === 'collapsed' ? collapsible : ''"
    :data-variant="variant"
    :data-side="side"
  >
    <!-- Hueco que reserva el espacio del sidebar en escritorio. Su ancho
         sigue el estado FIJADO (gapWidth), no el visible: por eso al pasar
         el mouse la barra se expande SUPERPUESTA y el contenido no se mueve. -->
    <div
      :class="cn(
        'relative bg-transparent transition-[width] duration-200 ease-linear',
        'group-data-[side=right]:rotate-180',
      )"
      :style="{ width: gapWidth }"
    />
    <div
      :class="cn(
        'fixed inset-y-0 z-30 hidden h-svh w-(--sidebar-width) transition-[left,right,width] duration-200 ease-linear md:flex',
        side === 'left'
          ? 'left-0 group-data-[collapsible=offcanvas]:left-[calc(var(--sidebar-width)*-1)]'
          : 'right-0 group-data-[collapsible=offcanvas]:right-[calc(var(--sidebar-width)*-1)]',
        // Adjust the padding for floating and inset variants.
        variant === 'floating' || variant === 'inset'
          ? 'p-2 group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4))+2px)]'
          : 'group-data-[collapsible=icon]:w-(--sidebar-width-icon) group-data-[side=left]:border-r group-data-[side=right]:border-l',
        props.class,
      )"
      v-bind="$attrs"
      @mouseenter="alEntrarMouse"
      @mouseleave="alSalirMouse"
    >
      <div
        data-sidebar="sidebar"
        class="bg-sidebar group-data-[variant=floating]:border-sidebar-border flex h-full w-full flex-col group-data-[variant=floating]:rounded-lg group-data-[variant=floating]:border group-data-[variant=floating]:shadow-sm"
      >
        <slot />
      </div>
    </div>
  </div>
</template>
