import type { ComputedRef, Ref } from "vue"
import { createContext } from "reka-ui"

export const SIDEBAR_COOKIE_NAME = "sidebar_state"
export const SIDEBAR_COOKIE_MAX_AGE = 60 * 60 * 24 * 7
// Ancho más compacto (antes 16rem): deja más espacio al contenido sin que
// las etiquetas del menú se corten. El estado contraído usa solo iconos.
export const SIDEBAR_WIDTH = "14rem"
export const SIDEBAR_WIDTH_MOBILE = "18rem"
export const SIDEBAR_WIDTH_ICON = "3rem"
export const SIDEBAR_KEYBOARD_SHORTCUT = "b"

export const [useSidebar, provideSidebarContext] = createContext<{
  // `state` es el estado VISIBLE (fijo por el usuario o expandido al pasar
  // el mouse sobre la barra contraída). `open` es el estado FIJADO manual
  // que se persiste en cookie — ver SidebarProvider.vue.
  state: ComputedRef<"expanded" | "collapsed">
  open: Ref<boolean>
  setOpen: (value: boolean) => void
  // Expansión temporal al pasar el mouse (solo escritorio con puntero fino).
  hovered: Ref<boolean>
  setHovered: (value: boolean) => void
  isMobile: Ref<boolean>
  openMobile: Ref<boolean>
  setOpenMobile: (value: boolean) => void
  toggleSidebar: () => void
}>("Sidebar")
