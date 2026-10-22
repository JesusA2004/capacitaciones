<?php

namespace App\Support;

/**
 * Columnas que referencian a `colaboradores.id`, qué hace la base al borrar
 * la persona (cascade / set_null / restrict, tal como lo declaran las
 * migraciones) y cómo las trata la PURGA INTEGRAL de una persona
 * (App\Services\Administracion\PurgaPersonaService):
 *
 *  - `eliminar`    → la fila ES de la persona (su solicitud, su contrato, su
 *                    responsiva, su expediente…): se borra físicamente.
 *  - `desvincular` → la columna solo registra a la persona como ACTOR o
 *                    relación sobre un registro de TERCEROS (jefe/gerente de
 *                    otro, evaluador, autor del mensaje en el muro ajeno,
 *                    objetivo de un aviso, responsable de un nodo…): el
 *                    registro se conserva y la columna se pone en NULL.
 *  - `cuenta`      → `users.colaborador_id`: es el enlace de la cuenta de
 *                    acceso; se resuelve al borrar la propia cuenta, no aquí.
 *
 * Igual que App\Support\ReferenciasUsuario, la lista es explícita para que la
 * purga respete la misma integridad en cualquier motor (MyISAM no expone ni
 * aplica llaves foráneas). tests/Feature/Administracion/PurgaPersonaTest.php
 * verifica que coincida con las llaves foráneas reales de las migraciones.
 */
final class ReferenciasColaborador
{
    /** @var list<array{0: string, 1: string, 2: 'cascade'|'set_null'|'restrict', 3: 'eliminar'|'desvincular'|'cuenta'}> */
    public const COLUMNAS = [
        // Expediente y ciclo laboral de la persona (se eliminan).
        ['actas_administrativas', 'colaborador_id', 'cascade', 'eliminar'],
        ['acuses_descriptivo_puesto', 'colaborador_id', 'restrict', 'eliminar'],
        ['ajustes_historicos_vacaciones', 'colaborador_id', 'cascade', 'eliminar'],
        ['altas_digitales', 'colaborador_id', 'set_null', 'eliminar'],
        ['avisos_datos_faltantes', 'colaborador_id', 'cascade', 'eliminar'],
        ['bajas', 'colaborador_id', 'restrict', 'eliminar'],
        ['birthday_greetings', 'colaborador_id', 'cascade', 'eliminar'],
        ['cambios_foto_perfil', 'colaborador_id', 'cascade', 'eliminar'],
        ['cartas_compromiso', 'colaborador_id', 'restrict', 'eliminar'],
        ['cierres_laborales', 'colaborador_id', 'cascade', 'eliminar'],
        ['clasificaciones_region', 'colaborador_id', 'cascade', 'eliminar'],
        ['coberturas_puesto', 'colaborador_id', 'cascade', 'eliminar'],
        ['colaborador_datos_medicos', 'colaborador_id', 'cascade', 'eliminar'],
        ['contratos_laborales', 'colaborador_id', 'cascade', 'eliminar'],
        ['divisiones_expediente_historico', 'colaborador_id', 'restrict', 'eliminar'],
        ['document_extractions', 'colaborador_id', 'set_null', 'eliminar'],
        ['employee_documents', 'colaborador_id', 'cascade', 'eliminar'],
        ['entregas_activo', 'colaborador_id', 'cascade', 'eliminar'],
        ['evaluaciones_induccion', 'colaborador_id', 'cascade', 'eliminar'],
        ['evaluaciones_periodo_prueba', 'colaborador_id', 'cascade', 'eliminar'],
        ['expedientes_historicos', 'colaborador_id', 'set_null', 'eliminar'],
        ['finiquito_calculos', 'colaborador_id', 'cascade', 'eliminar'],
        ['generated_documents', 'colaborador_id', 'set_null', 'eliminar'],
        ['historial_salarios', 'colaborador_id', 'cascade', 'eliminar'],
        ['incorporacion_invitaciones', 'colaborador_id', 'set_null', 'eliminar'],
        ['induccion_asignaciones', 'colaborador_id', 'cascade', 'eliminar'],
        ['movimientos_laborales', 'colaborador_id', 'cascade', 'eliminar'],
        ['official_format_generations', 'colaborador_id', 'set_null', 'eliminar'],
        ['onboarding_procesos', 'colaborador_id', 'cascade', 'eliminar'],
        ['prestamos', 'colaborador_id', 'cascade', 'eliminar'],
        ['recibos_nomina', 'colaborador_id', 'cascade', 'eliminar'],
        ['reingresos', 'colaborador_id', 'cascade', 'eliminar'],
        ['responsivas', 'colaborador_id', 'restrict', 'eliminar'],
        // Regla de responsable de aprobación (configuración, no historial: el
        // antes/después vive en activity_log). Sin la persona —como sujeto o
        // como responsable— la regla no tiene sentido; la FK es cascade y
        // `responsable_colaborador_id` no admite NULL, así que se elimina.
        ['responsables_aprobacion', 'colaborador_id', 'cascade', 'eliminar'],
        ['responsables_aprobacion', 'responsable_colaborador_id', 'cascade', 'eliminar'],
        ['sesion_induccion_colaboradores', 'colaborador_id', 'restrict', 'eliminar'],
        ['solicitud_vacaciones_dias', 'colaborador_id', 'set_null', 'eliminar'],
        ['solicitudes_internas', 'colaborador_id', 'set_null', 'eliminar'],
        ['solicitudes_vacaciones', 'colaborador_id', 'set_null', 'eliminar'],
        ['sucursal_colaborador', 'colaborador_id', 'cascade', 'eliminar'],
        ['user_nodo_comercial', 'colaborador_id', 'cascade', 'eliminar'],

        // Autoría / relación sobre registros de TERCEROS (se conservan; NULL).
        ['aprobaciones', 'aprobador_colaborador_id', 'set_null', 'desvincular'],
        ['aprobaciones', 'colaborador_id', 'set_null', 'desvincular'],
        ['avisos', 'colaborador_objetivo_id', 'set_null', 'desvincular'],
        ['birthday_wall_messages', 'autor_colaborador_id', 'set_null', 'desvincular'],
        ['candidatos', 'colaborador_id', 'set_null', 'desvincular'],
        ['colaboradores', 'gerente_id', 'set_null', 'desvincular'],
        ['colaboradores', 'jefe_id', 'set_null', 'desvincular'],
        ['evaluaciones_periodo_prueba', 'evaluador_colaborador_id', 'set_null', 'desvincular'],
        ['movimientos_laborales', 'jefe_anterior_colaborador_id', 'set_null', 'desvincular'],
        ['movimientos_laborales', 'jefe_nuevo_colaborador_id', 'set_null', 'desvincular'],
        ['nodos_comerciales', 'responsable_colaborador_id', 'set_null', 'desvincular'],
        ['reingresos', 'jefe_id', 'set_null', 'desvincular'],
        ['solicitudes_internas', 'objetivo_colaborador_id', 'set_null', 'desvincular'],
        ['tareas_rh', 'colaborador_id', 'set_null', 'desvincular'],
        ['vacantes', 'colaborador_contratado_id', 'set_null', 'desvincular'],

        // Enlace de la cuenta: se resuelve al borrar la propia cuenta.
        ['users', 'colaborador_id', 'set_null', 'cuenta'],
    ];
}
