<?php

namespace App\Enums;

/**
 * Destinatarios DINÁMICOS de una regla de notificación
 * (App\Services\Configuracion\WorkflowRoutingService). Se resuelven contra
 * el organigrama real de personas al momento del evento — nunca por el
 * nombre de un rol — y solo deciden quién recibe AVISO, jamás quién puede
 * autorizar (eso es Policy + permiso + alcance + AprobacionService).
 */
enum TipoDestinatarioNotificacion: string
{
    case Solicitante = 'solicitante';
    case ColaboradorAfectado = 'colaborador';
    case JefeDirecto = 'jefe_directo';
    case Gerente = 'gerente';
    case SuperiorDelJefe = 'superior_del_jefe';
    case GerenciaSucursal = 'responsable_sucursal';
    case Regional = 'regional';
    case GerenciaRh = 'gerencia_rh';
    case Rh = 'rh';
    case UsuariosConPermiso = 'usuarios_con_permiso';
    case UsuarioEspecifico = 'usuario_especifico';
    case Creador = 'creador';
    case Evaluador = 'evaluador';
    case Aprobador = 'aprobador';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Solicitante => 'Quien hizo la solicitud',
            self::ColaboradorAfectado => 'La persona afectada',
            self::JefeDirecto => 'Su jefe directo',
            self::Gerente => 'Su gerente',
            self::SuperiorDelJefe => 'El jefe de su jefe',
            self::GerenciaSucursal => 'Gerencia de su sucursal',
            self::Regional => 'Gerente regional de su región',
            self::GerenciaRh => 'Gerencia de Recursos Humanos',
            self::Rh => 'RH con alcance (autorización final)',
            self::UsuariosConPermiso => 'Usuarios con un permiso (dentro de su alcance)',
            self::UsuarioEspecifico => 'Usuarios específicos',
            self::Creador => 'Quien registró el movimiento',
            self::Evaluador => 'El evaluador asignado',
            self::Aprobador => 'El aprobador asignado',
        };
    }

    /**
     * Motivo legible que queda en la notificación ("¿por qué me llegó?").
     */
    public function motivo(): string
    {
        return match ($this) {
            self::Solicitante => 'eres quien hizo la solicitud',
            self::ColaboradorAfectado => 'eres la persona afectada',
            self::JefeDirecto => 'eres su jefe directo',
            self::Gerente => 'eres su gerente',
            self::SuperiorDelJefe => 'eres el jefe de su jefe',
            self::GerenciaSucursal => 'eres la gerencia de su sucursal',
            self::Regional => 'eres el gerente regional de su región',
            self::GerenciaRh => 'eres la Gerencia de Recursos Humanos',
            self::Rh => 'eres RH con alcance sobre la persona',
            self::UsuariosConPermiso => 'tienes el permiso requerido y alcance sobre la persona',
            self::UsuarioEspecifico => 'la regla te incluye por nombre',
            self::Creador => 'registraste el movimiento',
            self::Evaluador => 'eres el evaluador asignado',
            self::Aprobador => 'eres el aprobador asignado',
        };
    }
}
