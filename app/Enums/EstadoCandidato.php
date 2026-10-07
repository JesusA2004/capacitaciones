<?php

namespace App\Enums;

/**
 * Pipeline real del reclutamiento (Etapa 1) consolidado en el cierre del
 * ciclo laboral (ver docs/AUDITORIA_CICLO_LABORAL_FINAL.md §5 para el mapeo
 * desde los estados anteriores del tablero).
 *
 * Los avances SOLO ocurren por acciones del workflow
 * (App\Services\Reclutamiento\CandidatoWorkflowService): cada paso exige
 * el registro estructurado correspondiente (entrevista, psicométricas,
 * socioeconómico, referencias) y la selección exige preautorización del
 * gerente y autorización final de RH. Arrastrar en el tablero solo permite
 * cerrar el proceso (estados de salida, con motivo).
 */
enum EstadoCandidato: string
{
    case Recibidos = 'recibidos';
    case EntrevistaPendiente = 'entrevista_pendiente';
    case PsicometricasPendientes = 'psicometricas_pendientes';
    case RevisionPsicometricas = 'revision_psicometricas';
    case SocioeconomicoPendiente = 'socioeconomico_pendiente';
    case ReferenciasPendientes = 'referencias_pendientes';
    case PreseleccionGerente = 'preseleccion_gerente';
    case AutorizacionRhPendiente = 'autorizacion_rh_pendiente';
    case AutorizadoRh = 'autorizado_rh';
    case EnContratacion = 'en_contratacion';
    case Contratado = 'contratado';

    case NoViable = 'no_viable';
    case NoSeleccionado = 'no_seleccionado';
    case RechazadoRh = 'rechazado_rh';
    case NoRespondio = 'no_respondio';
    case Desistio = 'desistio';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Recibidos => 'Interesado · revisión de perfil',
            self::EntrevistaPendiente => 'Entrevista con gerente',
            self::PsicometricasPendientes => 'Psicométricas pendientes',
            self::RevisionPsicometricas => 'Revisión de psicométricas',
            self::SocioeconomicoPendiente => 'Estudio socioeconómico',
            self::ReferenciasPendientes => 'Validación de referencias',
            self::PreseleccionGerente => 'Preautorización del gerente',
            self::AutorizacionRhPendiente => 'Autorización final de RH',
            self::AutorizadoRh => 'Autorizado por RH',
            self::EnContratacion => 'En contratación',
            self::Contratado => 'Contratado',
            self::NoViable => 'No viable',
            self::NoSeleccionado => 'No seleccionado',
            self::RechazadoRh => 'Rechazado por RH',
            self::NoRespondio => 'No respondió',
            self::Desistio => 'Desistió',
        };
    }

    /**
     * Posición en el pipeline (hito). Las salidas no tienen posición propia:
     * el hito máximo alcanzado se conserva en candidatos.etapa_maxima.
     */
    public function orden(): int
    {
        return match ($this) {
            self::Recibidos => 1,
            self::EntrevistaPendiente => 2,
            self::PsicometricasPendientes => 3,
            self::RevisionPsicometricas => 4,
            self::SocioeconomicoPendiente => 5,
            self::ReferenciasPendientes => 6,
            self::PreseleccionGerente => 7,
            self::AutorizacionRhPendiente => 8,
            self::AutorizadoRh => 9,
            self::EnContratacion => 10,
            self::Contratado => 11,
            self::NoViable, self::NoSeleccionado, self::RechazadoRh, self::NoRespondio, self::Desistio => 0,
        };
    }

    /**
     * Paso del stepper de la ficha del candidato al que corresponde el estado.
     */
    public function paso(): string
    {
        return match ($this) {
            self::Recibidos => 'perfil',
            self::EntrevistaPendiente => 'entrevista',
            self::PsicometricasPendientes, self::RevisionPsicometricas => 'psicometricas',
            self::SocioeconomicoPendiente => 'socioeconomico',
            self::ReferenciasPendientes => 'referencias',
            self::PreseleccionGerente, self::AutorizacionRhPendiente, self::AutorizadoRh => 'autorizacion',
            self::EnContratacion, self::Contratado => 'contratacion',
            default => 'cerrado',
        };
    }

    /**
     * Fase canónica para el tablero (CLAUDE.md §4): agrupa los sub-estados
     * técnicos del workflow en las columnas que RH realmente necesita ver.
     * "Autorizado por RH" y "en contratación" nunca son columnas propias —
     * son hitos dentro de "Contratación"; las 4 salidas técnicas son un
     * único "Rechazado" con el motivo capturado en motivo_salida.
     */
    public function faseCanonica(): string
    {
        return match ($this) {
            self::Recibidos => 'filtro_rh',
            self::EntrevistaPendiente => 'entrevista',
            self::PsicometricasPendientes, self::RevisionPsicometricas => 'psicometricos',
            self::SocioeconomicoPendiente => 'socioeconomico',
            self::ReferenciasPendientes, self::PreseleccionGerente, self::AutorizacionRhPendiente, self::AutorizadoRh, self::EnContratacion => 'contratacion',
            self::Contratado => 'contratado',
            self::NoViable, self::NoSeleccionado, self::RechazadoRh, self::NoRespondio => 'rechazado',
            self::Desistio => 'desistido',
        };
    }

    /**
     * Columnas del tablero, en orden (clave => etiqueta). Única fuente para
     * web y app: nunca una fase "inventada" en el frontend.
     *
     * @return array<string, string>
     */
    public static function fases(): array
    {
        return [
            'filtro_rh' => 'Filtro RH',
            'entrevista' => 'Entrevista',
            'psicometricos' => 'Psicométricos',
            'socioeconomico' => 'Socioeconómico',
            'contratacion' => 'Contratación',
            'contratado' => 'Contratado',
            'rechazado' => 'Rechazado',
            'desistido' => 'Desistió',
        ];
    }

    public function esSalida(): bool
    {
        return $this->orden() === 0;
    }

    public function esTerminal(): bool
    {
        return $this === self::Contratado || $this->esSalida();
    }

    /**
     * Un candidato puede cerrarse desde reclutamiento mientras no haya
     * entrado a contratación (a partir de ahí ya existe una persona en
     * Etapa 2 y su baja es un proceso distinto).
     */
    public function permiteDescartar(): bool
    {
        return ! $this->esTerminal() && $this->orden() < self::EnContratacion->orden();
    }

    /**
     * Único avance válido desde cada estado (el workflow nunca salta pasos).
     */
    public function puedeAvanzarA(self $destino): bool
    {
        if ($this->esTerminal()) {
            return false;
        }

        if ($destino->esSalida()) {
            return $this->permiteDescartar() || ($this === self::AutorizacionRhPendiente && $destino === self::RechazadoRh);
        }

        return $destino->orden() === $this->orden() + 1;
    }

    /**
     * Compatibilidad con el tablero kanban: desde el tablero solo se
     * permite cerrar el proceso (salidas con motivo); los avances son
     * acciones del workflow.
     */
    public function puedeTransicionarA(self $destino): bool
    {
        return $destino->esSalida() && $destino !== self::RechazadoRh && $this->permiteDescartar();
    }

    /**
     * @return list<self>
     */
    public static function salidas(): array
    {
        return [self::NoViable, self::NoSeleccionado, self::RechazadoRh, self::NoRespondio, self::Desistio];
    }

    /**
     * @return list<self>
     */
    public static function abiertos(): array
    {
        return array_values(array_filter(self::cases(), fn (self $e) => ! $e->esTerminal()));
    }
}
