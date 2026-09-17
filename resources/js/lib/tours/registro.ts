import { tourGenerico } from './generico';
import type { Tour } from './tipos';

type EntradaRegistro = { patron: RegExp; tours: Tour[] };

const REGISTRO: EntradaRegistro[] = [
    {
        patron: /^\/dashboard\/?$/,
        tours: [
            tourGenerico(
                'dashboard',
                'Inicio',
                '/dashboard',
                'Aquí ves el resumen operativo: colaboradores, vacantes, cumpleaños próximos y demás KPIs de un vistazo.',
            ),
        ],
    },
    {
        patron: /^\/rh\/expedientes\/?$/,
        tours: [
            tourGenerico(
                'expedientes',
                'Expedientes',
                '/rh/expedientes',
                'Es la pantalla maestra de personas: activos, inactivos y bajas. Desde el expediente de cada colaborador administras sus datos, documentos, cuenta de acceso y su historial laboral completo.',
            ),
        ],
    },
    {
        patron: /^\/rh\/vacantes\/?$/,
        tours: [
            tourGenerico(
                'vacantes',
                'Vacantes',
                '/rh/vacantes',
                'Tablero de cobertura de plantilla: arrastra una vacante entre columnas para avanzar su estado, o cúbrela con un colaborador interno o un candidato externo.',
            ),
        ],
    },
    {
        patron: /^\/rh\/candidatos\/?$/,
        tours: [
            tourGenerico(
                'candidatos',
                'Candidatos',
                '/rh/candidatos',
                'Tablero de reclutamiento: arrastra un candidato entre fases del proceso. Las fases son sucesivas — no se puede retroceder una vez avanzado.',
            ),
        ],
    },
    {
        patron: /^\/rh\/solicitudes\/?$/,
        tours: [
            tourGenerico(
                'solicitudes',
                'Solicitudes',
                '/rh/solicitudes',
                'Bandeja unificada de todo lo que un colaborador solicita: vacaciones, permisos, bajas y más, con su propio flujo de revisión/aprobación.',
            ),
        ],
    },
    {
        patron: /^\/reportes\/?$/,
        tours: [
            tourGenerico(
                'reportes',
                'Reportes',
                '/reportes',
                'Genera tablas cruzadas filtrables y expórtalas a Excel o PDF, con gráficas incluidas.',
            ),
        ],
    },
    {
        patron: /^\/rh\/cumpleanos\/?$/,
        tours: [
            tourGenerico(
                'cumpleanos',
                'Cumpleaños',
                '/rh/cumpleanos',
                'Calendario de cumpleaños del equipo: genera, personaliza y envía la tarjeta de felicitación de cada colaborador.',
            ),
        ],
    },
];

/**
 * Tours disponibles para la ruta actual. `pathname` viene de
 * `window.location.pathname` (sin dominio ni query string).
 */
export function toursDisponibles(pathname: string): Tour[] {
    return REGISTRO.filter((entrada) => entrada.patron.test(pathname)).flatMap(
        (entrada) => entrada.tours,
    );
}
