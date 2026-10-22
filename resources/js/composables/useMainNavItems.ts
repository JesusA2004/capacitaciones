import { usePage } from '@inertiajs/vue3';
import {
    Award,
    BarChart3,
    BellRing,
    Boxes,
    Briefcase,
    Building2,
    CalendarClock,
    ClipboardCheck,
    ClipboardList,
    Compass,
    Contact,
    DoorOpen,
    FileCheck,
    FilePen,
    FileSignature,
    FileSpreadsheet,
    FileStack,
    FolderOpen,
    GitBranch,
    GraduationCap,
    Inbox,
    Landmark,
    LayoutGrid,
    ListTree,
    Megaphone,
    Network,
    Package,
    PartyPopper,
    QrCode,
    ReceiptText,
    RotateCcw,
    ScrollText,
    Search,
    Settings2,
    Smartphone,
    ShieldCheck,
    Sparkles,
    Target,
    TrendingDown,
    TrendingUp,
    UserPlus,
    UserRound,
    UserSearch,
    UserCheck,
    Users,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import { useNavegacion } from '@/composables/useNavegacion';
import { usePermisos } from '@/composables/usePermisos';
import { dashboard, miExpediente } from '@/routes';
import { index as indexAppReleases } from '@/routes/administracion/app-releases';
import { index as indexConfiguracion } from '@/routes/administracion/configuracion';
import { index as indexDepartamentos } from '@/routes/administracion/departamentos';
import { index as indexEmpresas } from '@/routes/administracion/empresas';
import { index as indexImportarDatos } from '@/routes/administracion/importar-datos';
import { index as indexJerarquiaPuestos } from '@/routes/administracion/jerarquia-puestos';
import { index as indexNotificacionesSistema } from '@/routes/administracion/notificaciones-sistema';
import { index as indexPuestos } from '@/routes/administracion/puestos';
import { index as indexResponsablesAprobacion } from '@/routes/administracion/responsables-aprobacion';
import { index as indexRoles } from '@/routes/administracion/roles';
import { index as indexSucursales } from '@/routes/administracion/sucursales';
import { index as indexUsuarios } from '@/routes/administracion/usuarios';
import { proximamente as capacitacionProximamente } from '@/routes/capacitacion';
import { index as indexMuroFelicitaciones } from '@/routes/celebraciones';
import { index as indexCursos } from '@/routes/cursos';
import { index as indexDirectorio } from '@/routes/directorio';
import { presentacion as presentacionInduccion } from '@/routes/induccion';
import {
    index as indexPortal,
    notificaciones as indexNotificacionesPortal,
    recibos as misRecibosNomina,
} from '@/routes/portal';
import { ver as verInduccion } from '@/routes/portal/induccion';
import { index as indexReportes } from '@/routes/reportes';
import { index as indexRotacion } from '@/routes/reportes/rotacion';
import { index as indexTableroRotacion } from '@/routes/reportes/rotacion/tablero';
import { index as indexActas } from '@/routes/rh/actas';
import { index as indexActivosResponsivas } from '@/routes/rh/activos-responsivas';
import { index as indexAniversarios } from '@/routes/rh/aniversarios';
import { index as indexAvisos } from '@/routes/rh/avisos';
import { index as indexBajas } from '@/routes/rh/bajas';
import { index as indexCampanas } from '@/routes/rh/campanas';
import { index as indexCandidatos } from '@/routes/rh/candidatos';
import { index as indexCartasCompromiso } from '@/routes/rh/cartas-compromiso';
import { index as indexCierres } from '@/routes/rh/cierres';
import { index as indexContactosEmergencia } from '@/routes/rh/contactos-emergencia';
import { index as indexContratacion } from '@/routes/rh/contratacion';
import { index as indexContratos } from '@/routes/rh/contratos';
import { index as indexContratosIngreso } from '@/routes/rh/contratos-ingreso';
import { index as indexCumpleanos } from '@/routes/rh/cumpleanos';
import { index as indexDocumentosMaestros } from '@/routes/rh/documentos-maestros';
import { index as indexEvaluaciones } from '@/routes/rh/evaluaciones';
import { index as indexExpedientes } from '@/routes/rh/expedientes';
import { index as indexFormulariosEvaluacion } from '@/routes/rh/formularios-evaluacion';
import { index as indexImportaciones } from '@/routes/rh/importaciones';
import { index as indexIncorporacionInvitaciones } from '@/routes/rh/incorporacion/invitaciones';
import { index as indexInduccionPuesto } from '@/routes/rh/induccion-puesto';
import { index as indexLotesNomina } from '@/routes/rh/nomina/lotes';
import {
    calificaciones as calificacionesInduccion,
    configuracion as configuracionOnboarding,
} from '@/routes/rh/onboarding';
import { index as indexPendientes } from '@/routes/rh/pendientes';
import { index as indexPerfilesPuesto } from '@/routes/rh/perfiles-puesto';
import { index as indexReingresos } from '@/routes/rh/reingresos';
import { index as indexRhSolicitudes } from '@/routes/rh/solicitudes';
import { index as indexVacantes } from '@/routes/rh/vacantes';
import { index as indexValidacionDocumentos } from '@/routes/rh/validacion-documentos';
import { index as indexSolicitudes } from '@/routes/solicitudes';
import type { NavItem } from '@/types';

/**
 * Grupo del sidebar = una etapa del ciclo de vida laboral (o un bloque
 * transversal). `numero` es la etapa (1–7) que se pinta de forma discreta;
 * los bloques transversales (Inicio, Organización, Reportes, Configuración)
 * no llevan número. Esta es la ÚNICA fuente declarativa de la navegación —
 * la consumen AppSidebar.vue y MobileBottomNav.vue. Ver
 * docs/ROLES_Y_NAVEGACION.md. Nunca dupliques esta estructura.
 */
export type NavGroup = {
    id: string;
    numero?: number;
    titulo: string;
    icono?: LucideIcon;
    items: NavItem[];
};

/**
 * Navegación de MR. LANA PEOPLE. Dos experiencias que NUNCA se mezclan
 * (ver App\Services\Navigation\NavigationService):
 *
 *   • Modo colaborador («Mi espacio»): portal personal.
 *   • Modo operativo («Operación RH»): el ciclo de vida laboral ordenado por
 *     etapas — Inicio · 1 Reclutamiento · 2 Contratación · 3 Onboarding ·
 *     4 Cierre del periodo de prueba · 5 Permanencia · 6 Desarrollo ·
 *     7 Cierres laborales — más los bloques transversales Organización,
 *     Reportes y Configuración.
 *
 * El menú SOLO refleja lo que el backend permite (Policies + permisos de
 * Spatie + alcance). Cada acceso se muestra únicamente si el usuario tiene
 * el permiso real que exige su controlador: nada de «botón visible que
 * responde 403» ni «endpoint abierto sin botón».
 */
export function useMainNavItems() {
    const { tienePermiso } = usePermisos();
    const page = usePage();
    const { esColaborador, tieneAmbosModos, cambiarModo } = useNavegacion();

    const features = computed(() => page.props.features);
    const capacitacionActiva = computed(() => features.value.capacitacion);
    const aprobacionesPendientes = computed(() => {
        const total = Number(page.props.aprobacionesPendientes ?? 0);

        return total > 99 ? '99+' : total > 0 ? String(total) : '';
    });

    const algunPermiso = (...permisos: string[]): boolean =>
        permisos.some((p) => tienePermiso(p));

    // ───────────────────────── Modo colaborador ─────────────────────────
    const navItemsColaborador = computed<NavItem[]>(() => {
        const items: (NavItem | false)[] = [
            { title: 'Mi inicio', href: indexPortal(), icon: LayoutGrid },
            // «Mi expediente» exige expedientes.ver (la ruta lo valida: sin él
            // el acceso terminaba en 403).
            tienePermiso('expedientes.ver') && {
                title: 'Mi expediente',
                href: miExpediente(),
                icon: FolderOpen,
            },
            algunPermiso('portal.solicitudes.ver', 'solicitudes.crear') && {
                title: 'Mis solicitudes',
                href: indexSolicitudes(),
                icon: ClipboardList,
            },
            // El recibo se entrega impreso: sin autoservicio salvo que el
            // backend lo encienda (config('features.recibos_colaborador')).
            features.value.recibos_colaborador && {
                title: 'Mi recibo',
                href: misRecibosNomina(),
                icon: ReceiptText,
            },
            {
                title: 'Mis notificaciones',
                href: indexNotificacionesPortal(),
                icon: BellRing,
            },
            {
                title: 'Mi inducción',
                href: verInduccion(),
                icon: GraduationCap,
            },
            capacitacionActiva.value && {
                title: 'Mi capacitación',
                href: capacitacionProximamente(),
                icon: GraduationCap,
            },
            {
                title: 'Celebraciones',
                href: indexMuroFelicitaciones(),
                icon: PartyPopper,
            },
            { title: 'Directorio', href: indexDirectorio(), icon: Contact },
            { title: 'Ayuda', href: '/ayuda', icon: Compass },
        ];

        return items.filter((i): i is NavItem => i !== false);
    });

    // ───────────────────────── Modo operativo ──────────────────────────
    const gruposOperativo = computed<NavGroup[]>(() => {
        const grupo = (
            base: Omit<NavGroup, 'items'>,
            items: (NavItem | false)[],
        ): NavGroup => ({
            ...base,
            // Un acceso con href marcador '#' (módulo sin pantalla real) nunca
            // se pinta, aunque se encienda su bandera por error: nada de
            // enlaces falsos en el menú.
            items: items.filter(
                (i): i is NavItem => i !== false && i.href !== '#',
            ),
        });

        const grupos: NavGroup[] = [
            grupo({ id: 'inicio', titulo: 'Inicio', icono: LayoutGrid }, [
                { title: 'Tablero de RH', href: dashboard(), icon: LayoutGrid },
                { title: 'Directorio', href: indexDirectorio(), icon: Contact },
                algunPermiso(
                    'ciclo.preautorizar',
                    'ciclo.autorizar_rh',
                    'evaluaciones.capturar',
                    'evaluaciones.autorizar',
                    'onboarding.entregar_activos',
                    'cierres.solicitar',
                    'cierres.gestionar',
                    'expedientes.revisar',
                    'solicitudes.revisar',
                    'solicitudes.aprobar',
                    'nomina.recibos.crear',
                ) && {
                    title: 'Pendientes',
                    href: indexPendientes(),
                    icon: Inbox,
                    // Mismo conteo único que la app: solo lo que puedes
                    // aprobar ahora (nunca lo bloqueado por un paso anterior).
                    badge: aprobacionesPendientes.value || undefined,
                },
            ]),

            grupo(
                {
                    id: 'reclutamiento',
                    numero: 1,
                    titulo: 'Reclutamiento y selección',
                    icono: Search,
                },
                [
                    tienePermiso('vacantes.ver') && {
                        title: 'Vacantes',
                        href: indexVacantes(),
                        icon: Briefcase,
                    },
                    tienePermiso('reclutamiento.campanas.ver') && {
                        title: 'Campañas',
                        href: indexCampanas(),
                        icon: Megaphone,
                    },
                    tienePermiso('perfiles_puesto.ver') && {
                        title: 'Descriptivos y perfiles',
                        href: indexPerfilesPuesto(),
                        icon: UserSearch,
                    },
                    tienePermiso('candidatos.ver') && {
                        title: 'Candidatos',
                        href: indexCandidatos(),
                        icon: UserRound,
                    },
                    tienePermiso('rh.incorporacion.invitaciones.ver') && {
                        title: 'Invitaciones QR',
                        href: indexIncorporacionInvitaciones(),
                        icon: QrCode,
                    },
                ],
            ),

            grupo(
                {
                    id: 'contratacion',
                    numero: 2,
                    titulo: 'Contratación y expediente',
                    icono: FileSignature,
                },
                [
                    tienePermiso('contratos.ver') && {
                        title: 'Personas en contratación',
                        href: indexContratacion(),
                        icon: UserPlus,
                    },
                    tienePermiso('documentos.revisar') && {
                        title: 'Validación de documentos',
                        href: indexValidacionDocumentos(),
                        icon: FileCheck,
                    },
                    tienePermiso('contratos.ver') && {
                        title: 'Contratos de ingreso',
                        href: indexContratosIngreso(),
                        icon: ScrollText,
                    },
                    // Contratos que le corresponden a TODA la plantilla activa
                    // por fecha de ingreso (generar los que falten).
                    tienePermiso('contratos.ver') && {
                        title: 'Contratos',
                        href: indexContratos(),
                        icon: FileSignature,
                    },
                    // Documentos faltantes del nuevo ingreso: la genera RH o su
                    // gerente/regional y la firma quien está entrando.
                    tienePermiso('cartas_compromiso.ver') && {
                        title: 'Cartas compromiso',
                        href: indexCartasCompromiso(),
                        icon: FilePen,
                    },
                ],
            ),

            grupo(
                {
                    id: 'onboarding',
                    numero: 3,
                    titulo: 'Onboarding',
                    icono: Sparkles,
                },
                [
                    tienePermiso('onboarding.gestionar') && {
                        title: 'Inducción institucional',
                        href: presentacionInduccion(),
                        icon: GraduationCap,
                    },
                    tienePermiso('onboarding.gestionar') && {
                        title: 'Inducción al puesto',
                        href: indexInduccionPuesto(),
                        icon: Compass,
                    },
                    tienePermiso('onboarding.gestionar') && {
                        title: 'Configuración de onboarding',
                        href: configuracionOnboarding(),
                        icon: Sparkles,
                    },
                    // Calificaciones de la INDUCCIÓN (FO-RH-EI + lecciones),
                    // retroalimentación y reevaluación. Las evaluaciones de
                    // PERIODO DE PRUEBA viven en el grupo «4».
                    tienePermiso('onboarding.gestionar') && {
                        title: 'Calificaciones y refuerzo',
                        href: calificacionesInduccion(),
                        icon: ClipboardCheck,
                    },
                    tienePermiso('responsivas.ver') && {
                        title: 'Activos y responsivas',
                        href: indexActivosResponsivas(),
                        icon: Package,
                    },
                ],
            ),

            grupo(
                {
                    id: 'periodo-prueba',
                    numero: 4,
                    titulo: 'Cierre del periodo de prueba',
                    icono: CalendarClock,
                },
                [
                    algunPermiso(
                        'evaluaciones.capturar',
                        'evaluaciones.autorizar',
                        'evaluaciones.ver_todos',
                    ) && {
                        // UN solo acceso (2026-10-22): «capacitación inicial» y
                        // «periodo de prueba» son la misma evaluación
                        // (FO-RH-EPP-001). La pantalla junta los periodos por
                        // vencer (asignar), las evaluaciones en curso y las ya
                        // contestadas (respuestas + PDF para firma): nada de
                        // accesos que solo sean un filtro de otro.
                        title: 'Evaluaciones de periodo de prueba',
                        href: indexEvaluaciones(),
                        icon: ClipboardCheck,
                    },
                ],
            ),

            grupo(
                {
                    id: 'permanencia',
                    numero: 5,
                    titulo: 'Permanencia',
                    icono: Users,
                },
                [
                    // Directorio laboral completo = listado de Expedientes
                    // (una sola fuente de colaboradores, ver §18).
                    algunPermiso(
                        'expedientes.ver_todos',
                        'expedientes.ver_region',
                        'expedientes.ver_sucursal',
                        'expedientes.ver_equipo',
                    ) && {
                        title: 'Colaboradores',
                        href: indexExpedientes(),
                        icon: Users,
                    },
                    algunPermiso(
                        'solicitudes.revisar',
                        'solicitudes.aprobar',
                    ) && {
                        title: 'Solicitudes',
                        href: indexRhSolicitudes(),
                        icon: ClipboardList,
                    },
                    tienePermiso('actas.ver') && {
                        title: 'Actas administrativas',
                        href: indexActas(),
                        icon: FilePen,
                    },
                    tienePermiso('nomina.recibos.ver') && {
                        title: 'Recibo simple de pago',
                        href: indexLotesNomina(),
                        icon: ReceiptText,
                    },
                    tienePermiso(
                        'colaboradores.importar_salarios_vacaciones',
                    ) && {
                        title: 'Importar salarios y vacaciones',
                        href: indexImportaciones(),
                        icon: FileSpreadsheet,
                    },
                    // Pantalla SOLO de importación por Excel: como toda
                    // carga masiva, solo super_admin (2026-10-20).
                    tienePermiso('contactos_emergencia.importar') && {
                        title: 'Contactos de emergencia',
                        href: indexContactosEmergencia(),
                        icon: FileSpreadsheet,
                    },
                    // Historial con avisos.ver; enviar exige avisos.enviar
                    // (el botón lo valida la pantalla y el backend).
                    algunPermiso('avisos.ver', 'avisos.enviar') && {
                        title: 'Enviar notificación',
                        href: indexAvisos(),
                        icon: BellRing,
                    },
                    // Cumpleaños + aniversarios + muro unificados en
                    // «Celebraciones»: una sola entrada (§18).
                    algunPermiso('rh.cumpleanos.ver', 'celebraciones.ver') && {
                        title: 'Celebraciones',
                        href: tienePermiso('rh.cumpleanos.ver')
                            ? indexCumpleanos()
                            : indexAniversarios(),
                        icon: PartyPopper,
                    },
                ],
            ),

            grupo(
                {
                    id: 'desarrollo',
                    numero: 6,
                    titulo: 'Desarrollo',
                    icono: TrendingUp,
                },
                [
                    // Evaluación del desempeño, Matriz 9 box y Certificación
                    // Mr. Lana aún no tienen pantalla real: se quedan DETRÁS de
                    // su feature flag (apagado) para no mostrar accesos falsos
                    // (plan §9). Al construir cada módulo se cambia el href '#'
                    // por su ruta real y se enciende la bandera.
                    features.value.evaluacion_desempeno && {
                        title: 'Evaluación del desempeño',
                        href: '#',
                        icon: Target,
                    },
                    features.value.nine_box && {
                        title: 'Matriz 9 box y planes',
                        href: '#',
                        icon: Boxes,
                    },
                    capacitacionActiva.value && {
                        title: 'Capacitación continua',
                        href: indexCursos(),
                        icon: GraduationCap,
                    },
                    features.value.certificacion && {
                        title: 'Certificación Mr. Lana',
                        href: '#',
                        icon: Award,
                    },
                ],
            ),

            grupo(
                {
                    id: 'cierres',
                    numero: 7,
                    titulo: 'Cierres laborales',
                    icono: DoorOpen,
                },
                [
                    // «Bajas» (no es una solicitud): el gerente o RH la
                    // registra y queda aplicada. Solo gerentes, RH y super
                    // admin (+ Jurídico y Contraloría de lectura).
                    algunPermiso(
                        'bajas.ver',
                        'bajas.revisar_juridico',
                        'bajas.consulta_contraloria',
                    ) && {
                        title: 'Bajas',
                        href: indexBajas(),
                        icon: ClipboardList,
                    },
                    tienePermiso('cierres.ver') && {
                        title: 'Bajas y finiquitos',
                        href: indexCierres(),
                        icon: DoorOpen,
                    },
                    algunPermiso(
                        'reingresos.solicitar',
                        'reingresos.gestionar',
                    ) && {
                        title: 'Reingresos',
                        href: indexReingresos(),
                        icon: RotateCcw,
                    },
                ],
            ),

            grupo(
                {
                    id: 'organizacion',
                    titulo: 'Organización',
                    icono: Network,
                },
                [
                    tienePermiso('organigrama.ver') && {
                        title: 'Organigrama',
                        href: indexJerarquiaPuestos(),
                        icon: GitBranch,
                    },
                    // Quién aprueba las solicitudes de cada persona/puesto
                    // (≠ jefe organizacional). Editar: organigrama.editar.
                    tienePermiso('organigrama.ver') && {
                        title: 'Responsables de aprobación',
                        href: indexResponsablesAprobacion(),
                        icon: UserCheck,
                    },
                    tienePermiso('puestos.administrar') && {
                        title: 'Puestos y tabulador',
                        href: indexPuestos(),
                        icon: Briefcase,
                    },
                    // Solo sucursales: los departamentos (y mover personas
                    // entre ellos) viven en su propio módulo, abajo.
                    tienePermiso('sucursales.administrar') && {
                        title: 'Sucursales',
                        href: indexSucursales(),
                        icon: Building2,
                    },
                    tienePermiso('departamentos.administrar') && {
                        title: 'Departamentos',
                        href: indexDepartamentos(),
                        icon: Briefcase,
                    },
                    tienePermiso('empresas.ver') && {
                        title: 'Empresas',
                        href: indexEmpresas(),
                        icon: Landmark,
                    },
                ],
            ),

            grupo(
                {
                    id: 'reportes',
                    titulo: 'Reportes',
                    icono: BarChart3,
                },
                [
                    tienePermiso('reportes_rh.ver') && {
                        title: 'Reportes de RH',
                        href: indexReportes(),
                        icon: BarChart3,
                    },
                    // Un solo acceso: el tablero (Excel histórico + bajas del
                    // sistema); quien solo tiene rotacion.ver ve el histórico.
                    algunPermiso('bajas.tablero.ver', 'rotacion.ver') && {
                        title: 'Rotación de personal',
                        href: tienePermiso('bajas.tablero.ver')
                            ? indexTableroRotacion()
                            : indexRotacion(),
                        icon: TrendingDown,
                    },
                ],
            ),

            // Configuración · solo administrador. Cada acceso se muestra solo
            // con su permiso: un usuario sin permisos administrativos no ve el
            // grupo (queda vacío y se filtra abajo).
            grupo(
                {
                    id: 'configuracion',
                    titulo: 'Configuración',
                    icono: Settings2,
                },
                [
                    // Un solo módulo para configurar las DOS evaluaciones de
                    // ingreso (inducción FO-RH-EI y periodo de prueba
                    // FO-RH-EPP-001), con diseño, versiones y vista previa.
                    tienePermiso('formularios_evaluacion.administrar') && {
                        title: 'Formularios de evaluación',
                        href: indexFormulariosEvaluacion(),
                        icon: ClipboardList,
                    },
                    tienePermiso('plantillas_documentales.administrar') && {
                        title: 'Documentos maestros',
                        href: indexDocumentosMaestros(),
                        icon: FileStack,
                    },
                    // Misma pantalla que Bajas y finiquitos → «Causales» (RN-15).
                    tienePermiso('finiquitos.causales_rotacion') &&
                        tienePermiso('finiquitos.ver') && {
                            title: 'Catálogo de bajas',
                            href: indexCierres({
                                query: { seccion: 'causales' },
                            }),
                            icon: ListTree,
                        },
                    algunPermiso('usuarios.ver', 'roles.administrar') && {
                        title: 'Usuarios y permisos',
                        href: indexUsuarios(),
                        icon: ShieldCheck,
                    },
                    tienePermiso('roles.administrar') && {
                        title: 'Roles y permisos',
                        href: indexRoles(),
                        icon: ShieldCheck,
                    },
                    // Importaciones/cargas masivas: solo super_admin
                    // (2026-10-20); mismos permisos que exige
                    // ImportarDatosController.
                    algunPermiso(
                        'expedientes.migrar',
                        'headcount.importar',
                        'colaboradores.importar_salarios_vacaciones',
                        'rotacion.cargar',
                        'perfiles_puesto.importar',
                        'contactos_emergencia.importar',
                        'nomina.recibos.importar',
                    ) && {
                        title: 'Importar datos',
                        href: indexImportarDatos(),
                        icon: FileSpreadsheet,
                    },
                    algunPermiso(
                        'configuracion.ver',
                        'notificaciones.ver_todas',
                    ) && {
                        title: 'Ajustes y notificaciones',
                        href: indexConfiguracion(),
                        icon: Settings2,
                    },
                    tienePermiso('notificaciones.ver_todas') && {
                        title: 'Notificaciones del sistema',
                        href: indexNotificacionesSistema(),
                        icon: BellRing,
                    },
                    tienePermiso('app_releases.ver') && {
                        title: 'Versiones de app',
                        href: indexAppReleases(),
                        icon: Smartphone,
                    },
                ],
            ),
        ];

        return grupos.filter((g) => g.items.length > 0);
    });

    // Un solo grupo en modo colaborador; las etapas + transversales en
    // operativo. Es lo que pinta AppSidebar.vue.
    const navGroups = computed<NavGroup[]>(() =>
        esColaborador.value
            ? [
                  {
                      id: 'mi-espacio',
                      titulo: 'Mi espacio',
                      items: navItemsColaborador.value,
                  },
              ]
            : gruposOperativo.value,
    );

    // Lista plana del modo actual (la usa MobileBottomNav para los atajos
    // directos de la barra inferior).
    const mainNavItems = computed<NavItem[]>(() =>
        navGroups.value.flatMap((g) => g.items),
    );

    return {
        mainNavItems,
        navGroups,
        esColaborador,
        tieneAmbosModos,
        cambiarModo,
    };
}
