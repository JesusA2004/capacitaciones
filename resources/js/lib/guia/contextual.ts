/**
 * Ayuda contextual: qué artículo de la Guía maestra explica la pantalla en
 * la que está el usuario. Lo usa el botón «¿Cómo funciona?» de la barra
 * superior (AyudaContextual.vue) y el botón flotante de ayuda. La prueba
 * tests/js/guia.test.mjs verifica que cada artículo exista.
 *
 * El orden importa: gana el primer patrón que coincide (los de detalle van
 * antes que los de listado).
 */
export const AYUDA_POR_PANTALLA: { patron: RegExp; articulo: string }[] = [
    { patron: /^\/rh\/vacantes$/, articulo: 'plantilla-y-vacantes' },
    { patron: /^\/rh\/campanas$/, articulo: 'campanas' },
    { patron: /^\/rh\/candidatos\/\d+$/, articulo: 'etapas-del-candidato' },
    { patron: /^\/rh\/candidatos$/, articulo: 'registrar-candidato' },
    {
        patron: /^\/rh\/incorporacion\/invitaciones(\/\d+)?$/,
        articulo: 'qr-incorporacion',
    },
    { patron: /^\/rh\/expedientes\/\d+$/, articulo: 'completitud-expediente' },
    { patron: /^\/rh\/expedientes$/, articulo: 'expediente' },
    {
        patron: /^\/rh\/colaboradores\/\d+\/ciclo$/,
        articulo: 'candidato-a-colaborador',
    },
    { patron: /^\/rh\/solicitudes(\/\d+)?$/, articulo: 'revisar-solicitudes' },
    { patron: /^\/solicitudes(\/\d+)?$/, articulo: 'permisos' },
    { patron: /^\/rh\/nomina(\/lotes(\/\d+)?)?$/, articulo: 'nomina-lotes' },
    {
        patron: /^\/rh\/onboarding\/configuracion$/,
        articulo: 'configurar-onboarding',
    },
    { patron: /^\/mi-proceso$/, articulo: 'onboarding' },
    { patron: /^\/rh\/evaluaciones$/, articulo: 'evaluacion-capacitacion' },
    { patron: /^\/rh\/reingresos$/, articulo: 'reingreso' },
    { patron: /^\/rh\/pendientes$/, articulo: 'mis-pendientes' },
    { patron: /^\/(rh\/)?avisos$/, articulo: 'avisos' },
    { patron: /^\/mi-expediente$/, articulo: 'completar-datos' },
    { patron: /^\/mi-portal$/, articulo: 'app-colaborador' },
    {
        patron: /^\/(rh\/cumpleanos|rh\/aniversarios|celebraciones)(\/.*)?$/,
        articulo: 'celebraciones',
    },
    {
        patron: /^\/administracion\/sucursales(\/\d+)?$/,
        articulo: 'estructura-y-plantilla',
    },
    {
        patron: /^\/administracion\/(puestos|departamentos|empresas|jerarquia-puestos)$/,
        articulo: 'estructura-y-plantilla',
    },
    {
        patron: /^\/administracion\/configuracion\/parametros-rh$/,
        articulo: 'estructura-y-plantilla',
    },
    {
        patron: /^\/administracion\/configuracion\/notificaciones$/,
        articulo: 'configuracion-notificaciones',
    },
    {
        patron: /^\/administracion\/(usuarios|roles)$/,
        articulo: 'usuarios-y-roles',
    },
    {
        patron: /^\/rh\/documentos-maestros(\/.*)?$/,
        articulo: 'documentos-maestros',
    },
    {
        patron: /^\/administracion\/app-versiones(\/\d+)?$/,
        articulo: 'app-movil',
    },
];

/** Artículo de la pantalla actual (pathname sin dominio), o null. */
export function articuloDePantalla(pathname: string): string | null {
    const ruta = pathname.replace(/\/+$/, '') || '/';

    return (
        AYUDA_POR_PANTALLA.find((p) => p.patron.test(ruta))?.articulo ?? null
    );
}
