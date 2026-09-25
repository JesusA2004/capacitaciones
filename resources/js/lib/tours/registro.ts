import { MODULOS_GUIA } from './modulos';
import type { ContextoGuia, ModuloGuia, PasoTour, Tour } from './tipos';

export const ID_RECORRIDO_COMPLETO = 'recorrido-completo';

/**
 * Módulos que el usuario realmente tiene en su menú: mismo modo de
 * navegación y al menos uno de los permisos que exige el sidebar
 * (`AppSidebar.vue`). Así el recorrido nunca lo lleva a una pantalla que
 * le respondería 403.
 */
export function modulosDisponibles(contexto: ContextoGuia): ModuloGuia[] {
    return MODULOS_GUIA.filter(
        (modulo) =>
            modulo.modo === contexto.modo &&
            (modulo.permisos.length === 0 ||
                modulo.permisos.some((permiso) =>
                    contexto.tienePermiso(permiso),
                )),
    );
}

/**
 * Pasos del módulo con su ruta ya resuelta: todos viven en la pantalla del
 * módulo hasta el primer paso con `rutaDesde`; a partir de ahí heredan la
 * pantalla a la que ése llevó (p. ej. el detalle de un expediente).
 */
function pasosConRuta(modulo: ModuloGuia): PasoTour[] {
    let enPantallaDelModulo = true;

    return modulo.pasos.map((paso) => {
        if (paso.rutaDesde) {
            enPantallaDelModulo = false;
        }

        return {
            ...paso,
            ruta: enPantallaDelModulo ? modulo.ruta : paso.ruta,
            seccion: modulo.nombre,
        };
    });
}

export function tourDeModulo(modulo: ModuloGuia): Tour {
    return {
        id: modulo.id,
        titulo: `Cómo usar ${modulo.nombre}`,
        pasos: pasosConRuta(modulo),
    };
}

/**
 * Recorrido de punta a punta: bienvenida, elementos generales de la
 * interfaz y, módulo por módulo, la entrada del menú seguida de sus pasos
 * dentro de la pantalla. El tour navega solo entre pantallas.
 */
export function tourCompleto(contexto: ContextoGuia): Tour {
    const modulos = modulosDisponibles(contexto);
    const esColaborador = contexto.modo === 'colaborador';
    const general = 'Primeros pasos';

    const pasos: PasoTour[] = [
        {
            titulo: 'Bienvenido a MR. LANA PEOPLE',
            texto: `Te voy a llevar por ${modulos.length === 1 ? 'el módulo' : `los ${modulos.length} módulos`} que tienes disponibles, pantalla por pantalla, explicando para qué sirve cada parte. Yo navego por ti: solo presiona "Siguiente".`,
            consejo:
                'Usa las flechas ← → del teclado para avanzar o regresar, "Saltar módulo" si ya conoces uno, y Esc para salir cuando quieras.',
            seccion: general,
        },
        {
            selector: '[data-sidebar="sidebar"]',
            titulo: 'Menú principal',
            texto: 'Desde aquí llegas a cada módulo. Solo aparecen los que tu rol tiene permitidos, así que el menú de un compañero puede verse distinto al tuyo.',
            consejo:
                'En celular el menú se abre con el botón de las tres líneas, arriba a la izquierda.',
            seccion: general,
        },
        {
            selector: '[data-tour="selector-modo"]',
            titulo: 'Mi espacio / Operación RH',
            texto: esColaborador
                ? 'Tienes dos modos. "Mi espacio" es lo tuyo como colaborador (portal, solicitudes). "Operación RH" muestra las herramientas para administrar al personal, con su propio recorrido.'
                : 'Tienes dos modos. "Operación RH" son las herramientas para administrar al personal. "Mi espacio" es lo tuyo como colaborador (portal, tus solicitudes), con su propio recorrido.',
            opcional: true,
            seccion: general,
        },
        {
            selector: '[data-tour="notificaciones"]',
            titulo: 'Notificaciones',
            texto: 'La campana te avisa de lo que requiere tu atención: solicitudes nuevas, cambios de estado, avisos de RH. El número indica cuántas no has leído.',
            opcional: true,
            seccion: general,
        },
        {
            selector: '[data-test="sidebar-menu-button"]',
            titulo: 'Tu cuenta',
            texto: 'Aquí abres la Configuración de tu cuenta (perfil, contraseña, verificación en dos pasos, apariencia) y cierras sesión.',
            opcional: true,
            seccion: general,
        },
        {
            selector: '[data-tour="boton-ayuda"]',
            titulo: 'Ayuda siempre a la mano',
            texto: 'Este botón está en todas las pantallas. Ábrelo cuando tengas dudas: te ofrece el recorrido de la pantalla donde estás y la guía completa del sistema.',
            seccion: general,
        },
    ];

    for (const modulo of modulos) {
        pasos.push({
            ruta: modulo.ruta,
            selector: `[data-sidebar="sidebar"] a[href="${modulo.ruta}"]`,
            titulo: modulo.nombre,
            texto: `${modulo.descripcion} Lo encuentras en esta opción del menú.`,
            seccion: modulo.nombre,
        });
        pasos.push(...pasosConRuta(modulo));
    }

    pasos.push({
        titulo: '¡Listo, ya conoces el sistema!',
        texto: 'Recorriste todos tus módulos. Si algo se te olvida, el botón de ayuda de la esquina inferior derecha repite el recorrido de la pantalla en la que estés, y en Ayuda puedes volver a tomar este recorrido completo.',
        seccion: 'Final',
    });

    return {
        id: `${ID_RECORRIDO_COMPLETO}-${contexto.modo}`,
        titulo: 'Recorrido completo del sistema',
        pasos,
    };
}

/**
 * Tours que el botón flotante ofrece en la pantalla actual. `pathname`
 * viene de `window.location.pathname` (sin dominio ni query string).
 */
export function toursDisponibles(
    pathname: string,
    contexto: ContextoGuia,
): Tour[] {
    const ruta = pathname.replace(/\/+$/, '') || '/';

    return modulosDisponibles(contexto)
        .filter((modulo) => modulo.patron.test(ruta))
        .map(tourDeModulo);
}
