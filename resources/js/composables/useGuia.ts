import { computed } from 'vue';
import { useNavegacion } from '@/composables/useNavegacion';
import { usePermisos } from '@/composables/usePermisos';
import { accesosVisibles, alcanceGuia, rolDelUsuario } from '@/lib/guia';
import type { AccesoGuia, CanalGuia } from '@/lib/guia/tipos';
import type { ContextoGuia } from '@/lib/tours/tipos';

/**
 * Contexto de la Guía para la persona que la lee: su modo (operativo /
 * colaborador), el rol de la guía que le corresponde y qué accesos reales
 * puede abrir. Así un artículo nunca ofrece un botón que respondería 403.
 */
export function useGuia() {
    const { tienePermiso, roles } = usePermisos();
    const { esColaborador } = useNavegacion();

    const modo = computed<'operativo' | 'colaborador'>(() =>
        esColaborador.value ? 'colaborador' : 'operativo',
    );

    const contextoTour = computed<ContextoGuia>(() => ({
        tienePermiso,
        modo: modo.value,
    }));

    const rolGuia = computed(() =>
        rolDelUsuario(roles.value, esColaborador.value),
    );

    /** Qué parte de la guía le corresponde (rol + modo); ver alcanceGuia. */
    const alcance = computed(() =>
        alcanceGuia(roles.value, esColaborador.value),
    );

    function accesos(lista: AccesoGuia[]): AccesoGuia[] {
        return accesosVisibles(lista, tienePermiso, modo.value);
    }

    return { modo, contextoTour, rolGuia, alcance, accesos, esColaborador };
}

export const ETIQUETA_CANAL: Record<CanalGuia, string> = {
    web: 'Web',
    app: 'App',
    web_app: 'Web y app',
    automatico: 'Automático',
};
