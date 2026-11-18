import { usePage } from '@inertiajs/vue3';
import {
    Activity,
    Link2,
    Award,
    BarChart3,
    Send,
    Presentation,
    ListChecks,
    Clapperboard,
    CalendarDays,
    BookOpenCheck,
    FileChartColumn,
    BellRing,
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
    TrendingDown,
    FolderOpen,
    GitBranch,
    GraduationCap,
    Grid3x3,
    Inbox,
    Landmark,
    LayoutGrid,
    ListTree,
    Megaphone,
    MonitorCheck,
    MonitorX,
    Network,
    Package,
    PackageCheck,
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
    UserPlus,
    UserRound,
    UserSearch,
    UserCheck,
    Users,
    HeartPulse,
    BadgeDollarSign,
    FolderTree,
    KeyRound,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import { useNavegacion } from '@/composables/useNavegacion';
import { usePermisos } from '@/composables/usePermisos';
import { ayuda, calendario } from '@/routes';
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
import { index as indexAgenda } from '@/routes/agenda';
import { index as indexAsignaciones } from '@/routes/asignaciones';
import { index as indexBancosPreguntas } from '@/routes/bancos-preguntas';
import { index as indexCalificacionesActividades } from '@/routes/calificaciones/actividades';
import { index as indexCalificacionesCuestionarios } from '@/routes/calificaciones/cuestionarios';
import {
    certificacion as rutaCertificacion,
    index as indexCapacitacion,
} from '@/routes/capacitacion';
import { index as indexMuroFelicitaciones } from '@/routes/celebraciones';
import { index as indexCursos } from '@/routes/cursos';
import { index as indexDirectorio } from '@/routes/directorio';
import {
    configuracion as configuracionEvaluacionesTecnicas,
    index as indexEvaluacionesTecnicas,
} from '@/routes/evaluaciones-tecnicas';
import { presentacion as presentacionInduccion } from '@/routes/induccion';
import { index as indexMiCapacitacion } from '@/routes/mi-capacitacion';
import { index as indexMultimedia } from '@/routes/multimedia';
import {
    contratos as misContratos,
    evaluacionesTecnicas as misEvaluacionesTecnicas,
    index as indexPortal,
    notificaciones as indexNotificacionesPortal,
    recibos as misRecibosNomina,
} from '@/routes/portal';
import { index as misDocumentosLaborales } from '@/routes/portal/documentos-laborales';
import { ver as verInduccion } from '@/routes/portal/induccion';
import { index as indexReportes } from '@/routes/reportes';
import { index as indexCumplimiento } from '@/routes/reportes/cumplimiento';
import { index as indexRotacion } from '@/routes/reportes/rotacion';
import { index as indexTableroRotacion } from '@/routes/reportes/rotacion/tablero';
import { index as indexActas } from '@/routes/rh/actas';
import { index as indexActivosResponsivas } from '@/routes/rh/activos-responsivas';
import { index as indexAniversarios } from '@/routes/rh/aniversarios';
import { index as indexAvisos } from '@/routes/rh/avisos';
import { causales as causalesBajas, index as indexBajas } from '@/routes/rh/bajas';
import { index as indexFiniquitosBaja } from '@/routes/rh/bajas/finiquitos';
import { index as indexCampanas } from '@/routes/rh/campanas';
import { index as indexCandidatos } from '@/routes/rh/candidatos';
import { index as indexCartasCompromiso } from '@/routes/rh/cartas-compromiso';
import { index as indexCierres } from '@/routes/rh/cierres';
import { index as indexContratacion } from '@/routes/rh/contratacion';
import { index as indexContratos } from '@/routes/rh/contratos';
import { index as indexContratosIngreso } from '@/routes/rh/contratos-ingreso';
import { index as indexCumpleanos } from '@/routes/rh/cumpleanos';
import { index as indexDocumentosMaestros } from '@/routes/rh/documentos-maestros';
import { index as indexEvaluaciones } from '@/routes/rh/evaluaciones';
import { index as indexExpedientes } from '@/routes/rh/expedientes';
import { index as indexFechasLaborales } from '@/routes/rh/fechas-laborales';
import { index as indexFormulariosEvaluacion } from '@/routes/rh/formularios-evaluacion';
import { index as indexIncapacidades } from '@/routes/rh/incapacidades';
import { index as indexIncorporacionInvitaciones } from '@/routes/rh/incorporacion/invitaciones';
import { index as indexInduccionPuesto } from '@/routes/rh/induccion-puesto';
import { index as indexLotesNomina } from '@/routes/rh/nomina/lotes';
import {
    calificaciones as calificacionesInduccion,
    configuracion as configuracionOnboarding,
} from '@/routes/rh/onboarding';
import { index as indexPendientes } from '@/routes/rh/pendientes';
import { index as indexPerfilesPuesto } from '@/routes/rh/perfiles-puesto';
import { index as indexRecepcionDocumental } from '@/routes/rh/recepcion-documental';
import { index as indexReingresos } from '@/routes/rh/reingresos';
import { index as indexMisReportes } from '@/routes/rh/reportes/mis-reportes';
import { index as indexRhSolicitudes } from '@/routes/rh/solicitudes';
import { index as indexVacantes } from '@/routes/rh/vacantes';
import { index as indexValidacionDocumentos } from '@/routes/rh/validacion-documentos';
import { index as indexNineBox } from '@/routes/seguimiento/nine-box';
import { index as indexSeguimientoVinculos } from '@/routes/seguimiento/vinculos';
import { index as indexPendientesAltaSistemas } from '@/routes/sistemas/pendientes-alta';
import { index as indexPendientesBajaSistemas } from '@/routes/sistemas/pendientes-baja';
import { index as indexSolicitudes } from '@/routes/solicitudes';
import type { NavItem } from '@/types';

/**
 * Grupo del sidebar = una etapa del ciclo de vida laboral (o un bloque
 * transversal). `numero` es la etapa (1–7) que se pinta de forma discreta;
 * los bloques transversales (Inicio, Organización, Seguimiento, Reportes,
 * Configuración) no llevan número. Esta es la ÚNICA fuente declarativa de la navegación —
 * la consumen AppSidebar.vue y MobileBottomNav.vue. Ver
 * docs/ROLES_Y_NAVEGACION.md. Nunca dupliques esta estructura.
 */
export type NavGroup = {
    id: string;
    numero?: number;
    titulo: string;
    icono?: LucideIcon;
    /**
     * Grupo que arranca desplegado mientras la persona no lo haya plegado
     * a mano (su preferencia se recuerda). Hoy solo «Inicio».
     */
    abiertoPorDefecto?: boolean;
    items: NavItem[];
};

/**
 * Navegación de MR. LANA PEOPLE. Dos experiencias que NUNCA se mezclan
 * (ver App\Services\Navigation\NavigationService):
 *
 *   • Modo colaborador («Mi espacio»): portal personal.
 *   • Modo operativo («Operación RH»): el ciclo de vida laboral ordenado por
 *     etapas — Inicio · 1 Reclutamiento · 2 Contratación · 3 Onboarding ·
 *     4 Cierre del periodo de prueba · 5 Permanencia · 6 Capacitación
 *     (etapa Desarrollo) · 7 Cierres laborales — más los bloques
 *     transversales Organización, Seguimiento, Reportes y Configuración.
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
    // Personas por dar de alta en los demás sistemas (solo quien las
    // atiende: el backend manda 0 a los demás).
    const altasSistemasPendientes = computed(() => {
        const total = Number(page.props.altasSistemasPendientes ?? 0);

        return total > 99 ? '99+' : total > 0 ? String(total) : '';
    });

    const tieneColaboradorVinculado = computed(
        () => (page.props.auth?.user?.colaborador_id ?? null) !== null,
    );

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
            // Documentos laborales y contratos propios: el colaborador recibe
            // su copia física firmada, así que no hay acceso salvo que el
            // backend lo encienda (config('features.documentos_laborales_colaborador')).
            features.value.documentos_laborales_colaborador &&
                tieneColaboradorVinculado.value && {
                    title: 'Mis documentos laborales',
                    href: misDocumentosLaborales(),
                    icon: FileSignature,
                },
            features.value.documentos_laborales_colaborador &&
                tieneColaboradorVinculado.value && {
                    title: 'Mis contratos',
                    href: misContratos(),
                    icon: ScrollText,
                },
            // Autoservicio propio: cualquiera con colaborador vinculado en Mi
            // espacio, sin importar su rol operativo (SolicitudInternaPolicy::create).
            (tieneColaboradorVinculado.value ||
                algunPermiso(
                    'portal.solicitudes.ver',
                    'solicitudes.crear',
                )) && {
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
            // Capacitación (Fase 3): sus aulas con progreso, lo pendiente y
            // sus constancias/certificación. Solo opera sobre lo propio
            // (MiCapacitacionService usa el usuario autenticado).
            capacitacionActiva.value && {
                title: 'Mi capacitación',
                href: indexMiCapacitacion(),
                icon: BookOpenCheck,
            },
            // «Seguimiento 15 / 30 / 60…» que contesto (y las de mi equipo si
            // soy jefe directo). Siempre la cuenta en sesión.
            tieneColaboradorVinculado.value && {
                title: 'Mis seguimientos técnicos',
                href: misEvaluacionesTecnicas(),
                icon: ListChecks,
            },
            {
                title: 'Celebraciones',
                href: indexMuroFelicitaciones(),
                icon: PartyPopper,
            },
            features.value.directorio && {
                title: 'Directorio',
                href: indexDirectorio(),
                icon: Contact,
            },
            // Subgerente: consulta las citas de su sucursal sin pasar a modo operativo.
            tienePermiso('agenda.ver') &&
                !tieneAmbosModos.value && {
                    title: 'Mi agenda',
                    href: indexAgenda(),
                    icon: CalendarClock,
                },
            { title: 'Ayuda', href: ayuda.url(), icon: Compass },
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
            // «Inicio» arranca desplegado (lo de todos los días: tablero,
            // pendientes y solicitudes) pero se puede plegar como los demás.
            grupo(
                {
                    id: 'inicio',
                    titulo: 'Inicio',
                    icono: LayoutGrid,
                    abiertoPorDefecto: true,
                },
                [
                    // Sin tablero (Jurídico, Contraloría) el inicio es su bandeja de bajas.
                    algunPermiso(
                        'dashboard.global.ver',
                        'dashboard.sucursal.ver',
                    )
                        ? {
                              title: 'Tablero de RH',
                              href: dashboard(),
                              icon: LayoutGrid,
                          }
                        : {
                              title: 'Mi bandeja de bajas',
                              href: dashboard(),
                              icon: LayoutGrid,
                          },
                    features.value.directorio && {
                        title: 'Directorio',
                        href: indexDirectorio(),
                        icon: Contact,
                    },
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
                        'candidatos.intervencion_decidir',
                        'candidatos.aprobar_entrevista',
                    ) && {
                        title: 'Pendientes',
                        href: indexPendientes(),
                        icon: Inbox,
                        // Mismo conteo único que la app: solo lo que puedes
                        // aprobar ahora (nunca lo bloqueado por un paso anterior).
                        badge: aprobacionesPendientes.value || undefined,
                    },
                    // Bandeja de solicitudes de RH/gerencia (≠ «Mis solicitudes»
                    // de Mi espacio, que son las propias). Vivía dentro de
                    // «5 Permanencia», plegado por defecto, y no se encontraba:
                    // ahora está a la vista. Consulta incluida (Sistemas,
                    // Auditoría, Dirección Comercial): la misma regla de
                    // SolicitudInternaPolicy::viewAny.
                    algunPermiso(
                        'solicitudes.revisar',
                        'solicitudes.aprobar',
                        'rh.solicitudes.ver',
                    ) && {
                        title: 'Solicitudes',
                        href: indexRhSolicitudes(),
                        icon: ClipboardList,
                    },
                ],
            ),

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
                    tienePermiso('agenda.ver') && {
                        title: 'Agenda de entrevistas',
                        href: indexAgenda(),
                        icon: CalendarClock,
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
                    // Solo aplica a quien ya está en contratación
                    // (IncorporacionInvitacionController): va en la etapa 2.
                    tienePermiso('rh.incorporacion.invitaciones.ver') && {
                        title: 'Invitaciones QR',
                        href: indexIncorporacionInvitaciones(),
                        icon: QrCode,
                    },
                    // Sistemas da usuarios en las demás plataformas a quien
                    // entró a contratación (RH consulta).
                    tienePermiso('sistemas.altas.ver') && {
                        title: 'Pendientes de alta en sistemas',
                        href: indexPendientesAltaSistemas(),
                        icon: MonitorCheck,
                        badge: altasSistemasPendientes.value || undefined,
                    },
                    // Bajas: Sistemas retira los usuarios en las demás
                    // plataformas (aviso al bloquear accesos y al entregar finiquito).
                    tienePermiso('sistemas.altas.ver') && {
                        title: 'Pendientes de baja en sistemas',
                        href: indexPendientesBajaSistemas(),
                        icon: MonitorX,
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
                        title: 'Contratos de la plantilla',
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
                    // Cada entrada pide lo mismo que su controlador: el
                    // permiso general + la acción (Roles y permisos).
                    tienePermiso('onboarding.gestionar') &&
                        tienePermiso('onboarding.presentar_induccion') && {
                        title: 'Inducción institucional',
                        href: presentacionInduccion(),
                        icon: GraduationCap,
                    },
                    tienePermiso('onboarding.gestionar') &&
                        tienePermiso('onboarding.induccion_puesto') && {
                        title: 'Inducción al puesto',
                        href: indexInduccionPuesto(),
                        icon: Compass,
                    },
                    tienePermiso('onboarding.gestionar') &&
                        tienePermiso('onboarding.configurar') && {
                        title: 'Configuración de onboarding',
                        href: configuracionOnboarding(),
                        icon: Sparkles,
                    },
                    // Calificaciones de la INDUCCIÓN (FO-RH-EI + lecciones),
                    // retroalimentación y reevaluación. Las evaluaciones de
                    // PERIODO DE PRUEBA viven en el grupo «4».
                    tienePermiso('onboarding.gestionar') &&
                        tienePermiso('onboarding.calificar') && {
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
                    // Captura masiva de fechas laborales (ingreso, inducción,
                    // capacitación inicial, periodo específico): docs/FECHAS_LABORALES.md.
                    tienePermiso('expedientes.editar_fechas') && {
                        title: 'Fechas laborales',
                        href: indexFechasLaborales(),
                        icon: CalendarClock,
                    },
                    // «Solicitudes» (bandeja de RH) está en «Inicio», siempre
                    // a la vista: una sola entrada, no se repite aquí.
                    tienePermiso('incapacidades.ver') && {
                        title: 'Incapacidades',
                        href: indexIncapacidades(),
                        icon: HeartPulse,
                    },
                    tienePermiso('actas.ver') && {
                        title: 'Actas administrativas',
                        href: indexActas(),
                        icon: FilePen,
                    },
                    tienePermiso('nomina.recibos.ver') && {
                        title: 'Recibos de nómina',
                        href: indexLotesNomina(),
                        icon: ReceiptText,
                    },
                    // «Importar salarios y vacaciones» y «Contactos de
                    // emergencia» (cargas por Excel, solo super_admin) viven
                    // en Configuración → Importar datos: no se repiten aquí.
                    // Historial con avisos.ver; enviar exige avisos.enviar
                    // (el botón lo valida la pantalla y el backend).
                    algunPermiso('avisos.ver', 'avisos.enviar') && {
                        title: 'Avisos',
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

            // Etapa 6 · Desarrollo = Capacitación (Fase 3): aulas tipo
            // Classroom, asignaciones, calificaciones y Certificación Mr. Lana
            // (config/permisos_modulos.php → etapas.desarrollo = 6). Cada
            // acceso exige el permiso real de su controlador.
            grupo(
                {
                    id: 'capacitacion',
                    numero: 6,
                    titulo: 'Capacitación',
                    icono: GraduationCap,
                },
                [
                    // Portada: indicadores, aulas y próximas sesiones
                    // (CursoPolicy::portada).
                    capacitacionActiva.value &&
                        algunPermiso(
                            'cursos.ver',
                            'asignaciones.ver',
                            'respuestas.ver',
                            'reportes.globales',
                            'reportes.sucursal',
                        ) && {
                            title: 'Portada de capacitación',
                            href: indexCapacitacion(),
                            icon: LayoutGrid,
                        },
                    capacitacionActiva.value &&
                        tienePermiso('cursos.ver') && {
                            title: 'Cursos y aulas',
                            href: indexCursos(),
                            icon: Presentation,
                        },
                    capacitacionActiva.value &&
                        tienePermiso('asignaciones.ver') && {
                            title: 'Asignaciones',
                            href: indexAsignaciones(),
                            icon: Send,
                        },
                    capacitacionActiva.value &&
                        tienePermiso('respuestas.ver') && {
                            title: 'Calificar cuestionarios',
                            href: indexCalificacionesCuestionarios(),
                            icon: ClipboardCheck,
                        },
                    capacitacionActiva.value &&
                        tienePermiso('respuestas.ver') && {
                            title: 'Calificar actividades',
                            href: indexCalificacionesActividades(),
                            icon: FileCheck,
                        },
                    capacitacionActiva.value &&
                        tienePermiso('cuestionarios.administrar') && {
                            title: 'Banco de preguntas',
                            href: indexBancosPreguntas(),
                            icon: ListChecks,
                        },
                    capacitacionActiva.value &&
                        tienePermiso('multimedia.administrar') && {
                            title: 'Biblioteca multimedia',
                            href: indexMultimedia(),
                            icon: Clapperboard,
                        },
                    capacitacionActiva.value &&
                        tienePermiso('sesiones.administrar') && {
                            title: 'Calendario de sesiones',
                            href: calendario(),
                            icon: CalendarDays,
                        },
                    capacitacionActiva.value &&
                        algunPermiso(
                            'reportes.globales',
                            'reportes.sucursal',
                        ) && {
                            title: 'Reporte de cumplimiento',
                            href: indexCumplimiento(),
                            icon: BarChart3,
                        },
                    // Base mínima: niveles por constancias emitidas
                    // (CursoPolicy::certificacion + feature:certificacion).
                    features.value.certificacion &&
                        capacitacionActiva.value &&
                        algunPermiso(
                            'cursos.ver',
                            'asignaciones.ver',
                            'respuestas.ver',
                            'certificacion.gestionar',
                        ) && {
                            title: 'Certificación Mr. Lana',
                            href: rutaCertificacion(),
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
                    // Un solo apartado «Bajas» con pestañas Bajas · Finiquitos ·
                    // Rotación · Catálogo de causales (PDF Etapa 1). El enlace
                    // abre la primera pestaña que el backend le permite.
                    algunPermiso(
                        'bajas.ver',
                        'bajas.revisar_juridico',
                        'bajas.consulta_contraloria',
                        'finiquitos.ver',
                        'bajas.tablero.ver',
                        'rotacion.ver',
                        'cierres.ver',
                    ) && {
                        title: 'Bajas',
                        href: algunPermiso(
                            'bajas.ver',
                            'bajas.revisar_juridico',
                            'bajas.consulta_contraloria',
                        )
                            ? indexBajas()
                            : tienePermiso('finiquitos.ver')
                              ? indexFiniquitosBaja()
                              : tienePermiso('bajas.tablero.ver')
                                ? indexTableroRotacion()
                                : tienePermiso('cierres.ver')
                                  ? indexCierres()
                                  : indexRotacion(),
                        icon: ClipboardList,
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
                    tienePermiso('organigrama.ver') &&
                        algunPermiso(
                            'responsables_aprobacion.ver',
                            'responsables_aprobacion.administrar',
                        ) && {
                        title: 'Responsables de aprobación',
                        href: indexResponsablesAprobacion(),
                        icon: UserCheck,
                    },
                    // Un solo módulo: catálogo de puestos (puestos.*) y su
                    // tabulador de sueldos (salarios.*, importes solo con permiso).
                    (tienePermiso('puestos.administrar') ||
                        tienePermiso('salarios.ver')) && {
                        title: 'Puestos y salarios',
                        href: indexPuestos(),
                        icon: BadgeDollarSign,
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
                        icon: FolderTree,
                    },
                    tienePermiso('empresas.ver') && {
                        title: 'Empresas',
                        href: indexEmpresas(),
                        icon: Landmark,
                    },
                ],
            ),

            // Fase 2 «Seguimiento» (docs/SEGUIMIENTO.md, docs/NINE_BOX.md): los
            // datos duros de Reportes alimentan la Nine Box; «Por sucursal» y
            // «Por gestor» quedan sin entrada (consulta secundaria). Solo modo operativo.
            grupo(
                {
                    id: 'seguimiento',
                    titulo: 'Seguimiento',
                    icono: Activity,
                },
                [
                    // Desempeño (Reportes) × potencial (Seguimientos técnicos +
                    // calibración): docs/NINE_BOX.md.
                    features.value.seguimiento &&
                        features.value.nine_box &&
                        tienePermiso('nine_box.ver') && {
                            title: 'Nine Box',
                            href: indexNineBox(),
                            icon: Grid3x3,
                        },
                    // Evaluaciones técnicas por puesto «Seguimiento 15 / 30 / 60…»
                    // (docs/EVALUACIONES_TECNICAS.md): no dependen de Reportes.
                    tienePermiso('evaluaciones_tecnicas.ver') && {
                        title: 'Seguimientos técnicos',
                        href: indexEvaluacionesTecnicas(),
                        icon: ListChecks,
                    },
                    tienePermiso('evaluaciones_tecnicas.configurar') && {
                        title: 'Calendario de seguimientos',
                        href: configuracionEvaluacionesTecnicas(),
                        icon: Settings2,
                    },
                    features.value.seguimiento &&
                        tienePermiso('seguimiento.vincular') && {
                            title: 'Vínculos con Reportes',
                            href: indexSeguimientoVinculos(),
                            icon: Link2,
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
                    // Módulo propio: índice, tendencias, causales, sucursales
                    // e histórico de bajas (docs/ROTACION.md).
                    tienePermiso('bajas.tablero.ver') && {
                        title: 'Rotación de personal',
                        href: indexTableroRotacion(),
                        icon: TrendingDown,
                    },
                    tienePermiso('reportes_rh.ver') && {
                        title: 'Reportes de RH',
                        href: indexReportes(),
                        icon: BarChart3,
                    },
                    tienePermiso('reportes_rh.mis_reportes') && {
                        title: 'Mis reportes',
                        href: indexMisReportes(),
                        icon: FileChartColumn,
                    },
                    // Originales firmados recibidos por paquetería (control
                    // mensual; docs/RECEPCION_DOCUMENTAL_RH.md).
                    tienePermiso('recepcion_documental.ver') && {
                        title: 'Recepción documental',
                        href: indexRecepcionDocumental(),
                        icon: PackageCheck,
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
                    // Misma pantalla que la pestaña «Catálogo de causales» de Bajas (RN-15).
                    tienePermiso('finiquitos.causales_rotacion') &&
                        tienePermiso('finiquitos.ver') && {
                            title: 'Catálogo de causales',
                            href: causalesBajas(),
                            icon: ListTree,
                        },
                    // UserPolicy::viewAny exige usuarios.ver (quien solo
                    // administra roles entra por «Roles y permisos»).
                    tienePermiso('usuarios.ver') && {
                        title: 'Usuarios y permisos',
                        href: indexUsuarios(),
                        icon: ShieldCheck,
                    },
                    algunPermiso(
                        'roles.administrar',
                        'notificaciones_rol.editar',
                    ) && {
                        title: 'Roles y permisos',
                        href: indexRoles(),
                        icon: KeyRound,
                    },
                    // Importaciones/cargas masivas: solo super_admin
                    // (2026-10-20); mismos permisos que exige
                    // ImportarDatosController.
                    algunPermiso(
                        'expedientes.migrar',
                        'headcount.importar',
                        'reportes_rh.importar_historico',
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
                    // ConfiguracionController exige configuracion.ver.
                    tienePermiso('configuracion.ver') && {
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
