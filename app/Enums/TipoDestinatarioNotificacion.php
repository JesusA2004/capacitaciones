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
            self::UsuariosConPermiso => 'Quienes pueden hacer cierta acción',
            self::UsuarioEspecifico => 'Usuarios específicos',
            self::Creador => 'Quien registró el movimiento',
            self::Evaluador => 'El evaluador asignado',
            self::Aprobador => 'El aprobador asignado',
        };
    }

    /**
     * Explicación para el botón "?" de Configuración → Notificaciones.
     */
    public function ayuda(): string
    {
        return match ($this) {
            self::Solicitante => 'La persona que levantó la solicitud o el trámite. Sirve para avisarle cómo va.',
            self::ColaboradorAfectado => 'La persona sobre la que trata el trámite (por ejemplo, a quien se le da de baja), aunque no lo haya pedido ella.',
            self::JefeDirecto => 'El jefe directo según el organigrama (puesto superior ocupado). Si ese puesto está vacante, se sube al siguiente de la cadena.',
            self::Gerente => 'La gerencia que le corresponde a la persona según el organigrama.',
            self::SuperiorDelJefe => 'El jefe del jefe directo: un nivel más arriba en el organigrama.',
            self::GerenciaSucursal => 'Quien ocupa la gerencia de la sucursal donde trabaja la persona.',
            self::Regional => 'El gerente regional de la región a la que pertenece la sucursal de la persona.',
            self::GerenciaRh => 'Quien ocupa la Gerencia de Recursos Humanos.',
            self::Rh => 'Personal de RH que tiene a esta persona dentro de lo que puede ver. RH siempre da la autorización final.',
            self::UsuariosConPermiso => 'Todas las personas que pueden hacer la acción que elijas (por ejemplo, «Autorizar préstamos») y que además tienen a esa persona dentro de su sucursal o región.',
            self::UsuarioEspecifico => 'Personas que eliges por nombre. Les llega el aviso siempre, sin importar su puesto.',
            self::Creador => 'Quien capturó el movimiento en el sistema.',
            self::Evaluador => 'La persona asignada para evaluar (por ejemplo, el periodo de prueba).',
            self::Aprobador => 'La persona a quien le toca autorizar en ese momento.',
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
            self::UsuariosConPermiso => 'puedes atender este trámite y la persona está dentro de tu alcance',
            self::UsuarioEspecifico => 'la regla te incluye por nombre',
            self::Creador => 'registraste el movimiento',
            self::Evaluador => 'eres el evaluador asignado',
            self::Aprobador => 'eres el aprobador asignado',
        };
    }
}
