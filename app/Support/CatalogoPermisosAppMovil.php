<?php

namespace App\Support;

use App\Enums\TipoSolicitudInterna;

/**
 * Permisos por botón de la app móvil (grupo «App móvil» de Roles y
 * permisos, config/permisos_modulos.php → modulos.app_movil_*): una acción
 * de autoservicio que antes bastaba con tener sesión ahora tiene su propio
 * permiso `app.*`, que la API exige con PermisosAppMovilService.
 *
 * - De inicio TODOS los roles los tienen (migración
 *   2026_11_18_600100_otorga_permisos_app_movil + herencia de
 *   `mobile.bootstrap.ver` en el seeder): nadie pierde ni gana nada al
 *   desplegar; «Roles y permisos» quita los que el negocio decida.
 * - Lo que ya tenía permiso web exacto lo reutiliza (directorio.ver,
 *   evaluaciones.*, agenda.*, candidatos.*, rh.*…): no se duplica aquí.
 * - Los pasos obligatorios del alta (registro, checklist y carga de
 *   documentos del expediente, documentos pendientes, datos faltantes) NO
 *   tienen permiso propio: quitarlos dejaría a una persona nueva sin poder
 *   entrar nunca.
 *
 * Clase pura (sin base de datos): la usa el archivo de configuración.
 */
final class CatalogoPermisosAppMovil
{
    /**
     * Módulo de config => [etiqueta, descripción, acciones (permiso => etiqueta)].
     *
     * @var array<string, array{etiqueta: string, descripcion: string, acciones: array<string, string>}>
     */
    private const MODULOS = [
        'app_movil_solicitudes' => [
            'etiqueta' => 'App móvil: mis solicitudes',
            'descripcion' => 'Botones de «Solicitudes» y «Nueva solicitud» en la app (solo sobre lo propio).',
            'acciones' => [
                'app.solicitudes.ver' => 'Ver sus solicitudes, su detalle y sus correcciones',
                'app.solicitudes.crear_permiso' => 'Pedir permisos (con o sin goce, por horas, llegada tarde, salida temprano y especiales)',
                'app.solicitudes.crear_incapacidad' => 'Reportar una incapacidad',
                'app.solicitudes.crear_constancia' => 'Pedir constancia laboral',
                'app.solicitudes.crear_actualizacion' => 'Pedir actualización de datos, datos bancarios, domicilio o reposición de documentos (con el expediente bloqueado por datos faltantes la actualización de datos siempre se permite)',
                'app.solicitudes.crear_prestamo' => 'Pedir un préstamo',
                'app.solicitudes.crear_baja' => 'Solicitar su baja (renuncia)',
                'app.solicitudes.crear_general' => 'Hacer una solicitud general',
                'app.solicitudes.adjuntar' => 'Adjuntar archivos a su solicitud',
                'app.solicitudes.cancelar' => 'Cancelar su solicitud',
                'app.solicitudes.responder_correccion' => 'Responder las correcciones que pide RH',
                'app.solicitudes.descargar_formato' => 'Descargar el formato de permiso autorizado (PDF)',
            ],
        ],
        'app_movil_vacaciones' => [
            'etiqueta' => 'App móvil: mis vacaciones',
            'descripcion' => 'Pestaña «Vacaciones» de la app. Su historial sale de la lista de solicitudes: requiere también «Ver sus solicitudes».',
            'acciones' => [
                'app.vacaciones.ver' => 'Ver su saldo y calendario de vacaciones',
                'app.vacaciones.solicitar' => 'Solicitar vacaciones',
            ],
        ],
        'app_movil_expediente' => [
            'etiqueta' => 'App móvil: mi expediente y documentos',
            'descripcion' => 'Expediente, documentos laborales, recibos, préstamos y carta compromiso propios. Subir los documentos del expediente nunca se bloquea (es paso obligatorio del alta).',
            'acciones' => [
                'app.expediente.ver' => 'Ver su expediente y sus datos de alta',
                'app.perfil.cambiar_foto' => 'Cambiar su foto de perfil',
                'app.documentos_laborales.ver' => 'Ver sus documentos laborales y contratos',
                'app.documentos_laborales.descargar' => 'Descargar sus documentos laborales',
                'app.documentos_laborales.firmar' => 'Firmar documentos laborales en la app',
                'app.recibos.ver' => 'Ver y descargar sus recibos de nómina (si la empresa los publica)',
                'app.prestamos.ver' => 'Ver sus préstamos',
                'app.carta_compromiso.ver' => 'Ver y descargar su carta compromiso',
                'app.carta_compromiso.solicitar' => 'Pedir una carta compromiso por documentos pendientes',
                'app.descriptivo_puesto.acusar' => 'Dar «Enterado» a su descriptivo de puesto',
            ],
        ],
        'app_movil_induccion' => [
            'etiqueta' => 'App móvil: inducción',
            'descripcion' => 'Inducción Mr. Lana en la app. Ojo: sin «Contestar el cuestionario» una persona nueva no puede pasar a Laborando.',
            'acciones' => [
                'app.induccion.ver' => 'Ver el material de la inducción (capítulos, video, audio y PDF)',
                'app.induccion.contestar' => 'Contestar el cuestionario de la inducción',
                'app.induccion.presentacion' => 'Seguir la presentación de inducción en vivo con RH',
            ],
        ],
        'app_movil_comunidad' => [
            'etiqueta' => 'App móvil: avisos, celebraciones y jerarquía',
            'descripcion' => 'El Directorio usa el permiso «Directorio» de siempre.',
            'acciones' => [
                'app.avisos.ver' => 'Ver los avisos de RH y sus archivos',
                'app.celebraciones.ver' => 'Ver cumpleaños, aniversarios y muros de felicitación',
                'app.celebraciones.felicitar' => 'Escribir, editar y borrar sus felicitaciones',
                'app.jerarquia.ver' => 'Ver su jerarquía (jefe directo y compañeros)',
            ],
        ],
        'app_movil_equipo' => [
            'etiqueta' => 'App móvil: mi equipo (jefe directo)',
            'descripcion' => 'Lo que el jefe directo hace desde «Mi espacio». Solo aplica a quien el organigrama nombra jefe o aprobador.',
            'acciones' => [
                'app.equipo.ver' => 'Ver su equipo directo y sus pendientes',
                'app.equipo.visto_bueno' => 'Dar visto bueno a las solicitudes de su equipo',
            ],
        ],
    ];

    /**
     * Definición del módulo para config/permisos_modulos.php.
     *
     * @return array{etiqueta: string, grupo: string, etapa: string, descripcion: string, acceso: null, alcances: array<string, string>, acciones: array<string, string>}
     */
    public static function modulo(string $clave): array
    {
        $modulo = self::MODULOS[$clave];

        return [
            'etiqueta' => $modulo['etiqueta'],
            'grupo' => 'App móvil',
            'etapa' => 'mi_espacio',
            'descripcion' => $modulo['descripcion'],
            'acceso' => null,
            'alcances' => [],
            'acciones' => $modulo['acciones'],
        ];
    }

    /**
     * @return array<string, string> permiso => etiqueta
     */
    public static function acciones(): array
    {
        $acciones = [];

        foreach (self::MODULOS as $modulo) {
            $acciones = [...$acciones, ...$modulo['acciones']];
        }

        return $acciones;
    }

    /**
     * @return list<string>
     */
    public static function permisos(): array
    {
        return array_keys(self::acciones());
    }

    public static function etiqueta(string $permiso): string
    {
        return self::acciones()[$permiso] ?? $permiso;
    }

    /**
     * Permiso de la app para CREAR una solicitud de este tipo.
     */
    public static function permisoParaCrear(TipoSolicitudInterna $tipo): string
    {
        return match ($tipo) {
            TipoSolicitudInterna::Vacaciones => 'app.vacaciones.solicitar',
            TipoSolicitudInterna::Permiso,
            TipoSolicitudInterna::PermisoConGoce,
            TipoSolicitudInterna::PermisoSinGoce,
            TipoSolicitudInterna::PermisoTiempo,
            TipoSolicitudInterna::SalidaTemprano,
            TipoSolicitudInterna::LlegadaTarde,
            TipoSolicitudInterna::PermisoEspecialCumpleanos,
            TipoSolicitudInterna::PermisoEspecialPaternidad,
            TipoSolicitudInterna::PermisoEspecialFallecimiento => 'app.solicitudes.crear_permiso',
            TipoSolicitudInterna::Incapacidad => 'app.solicitudes.crear_incapacidad',
            TipoSolicitudInterna::ConstanciaLaboral => 'app.solicitudes.crear_constancia',
            TipoSolicitudInterna::ActualizacionDatos,
            TipoSolicitudInterna::ActualizacionBancaria,
            TipoSolicitudInterna::ReposicionDocumental => 'app.solicitudes.crear_actualizacion',
            TipoSolicitudInterna::PrestamoInterno => 'app.solicitudes.crear_prestamo',
            TipoSolicitudInterna::BajaColaborador => 'app.solicitudes.crear_baja',
            TipoSolicitudInterna::General => 'app.solicitudes.crear_general',
        };
    }
}
