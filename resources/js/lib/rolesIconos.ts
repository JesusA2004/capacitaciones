import {
    Activity,
    BarChart3,
    Building2,
    CalendarClock,
    DoorOpen,
    FileSignature,
    Globe,
    GraduationCap,
    LayoutGrid,
    Layers,
    Map,
    Network,
    Search,
    Settings2,
    Smartphone,
    Sparkles,
    Store,
    User,
    UserRound,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import type { NivelAlcance } from '@/types/roles';

/**
 * Apoyos visuales de «Roles y permisos»: el icono de cada etapa (los mismos
 * del sidebar) y un «mini mapa» por alcance de datos.
 */
export const ICONO_ETAPA: Record<string, Component> = {
    inicio: LayoutGrid,
    reclutamiento: Search,
    contratacion: FileSignature,
    onboarding: Sparkles,
    periodo_prueba: CalendarClock,
    permanencia: Users,
    desarrollo: GraduationCap,
    cierres: DoorOpen,
    organizacion: Network,
    reportes: BarChart3,
    configuracion: Settings2,
    mi_espacio: UserRound,
    app_movil: Smartphone,
    otros: Layers,
};

export function iconoEtapa(clave: string): Component {
    return ICONO_ETAPA[clave] ?? Activity;
}

export const ICONO_NIVEL: Record<NivelAlcance | 'propios', Component> = {
    propios: User,
    equipo: Users,
    sucursal: Store,
    sucursal_corporativo: Building2,
    region: Map,
    todos: Globe,
};

/** Cuántas «manchas» del mini mapa cubre cada alcance (de 5). */
export const COBERTURA_NIVEL: Record<NivelAlcance | 'propios', number> = {
    propios: 0,
    equipo: 1,
    sucursal: 1,
    sucursal_corporativo: 2,
    region: 4,
    todos: 5,
};
