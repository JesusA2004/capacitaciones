<?php

namespace App\Enums;

enum EstadoCandidato: string
{
    case Recibidos = 'recibidos';
    case Preseleccion = 'preseleccion';
    case Entrevista = 'entrevista';
    case Psicometricos = 'psicometricos';
    case EstudioSocioeconomico = 'estudio_socioeconomico';
    case Pruebas = 'pruebas';
    case ValidacionDocumental = 'validacion_documental';
    case OfertaAprobacion = 'oferta_aprobacion';
    case ListoParaContratacion = 'listo_para_contratacion';
    case Contratado = 'contratado';

    case NoSeleccionado = 'no_seleccionado';
    case NoViable = 'no_viable';
    case NoRespondio = 'no_respondio';
    case Desistio = 'desistio';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Recibidos => 'Recibidos',
            self::Preseleccion => 'Preselección',
            self::Entrevista => 'Entrevista',
            self::Psicometricos => 'Psicométricos',
            self::EstudioSocioeconomico => 'Estudio socioeconómico',
            self::Pruebas => 'Pruebas / evaluación',
            self::ValidacionDocumental => 'Validación documental',
            self::OfertaAprobacion => 'Oferta / aprobación',
            self::ListoParaContratacion => 'Listo para contratación',
            self::Contratado => 'Contratado',
            self::NoSeleccionado => 'No seleccionado',
            self::NoViable => 'No viable',
            self::NoRespondio => 'No respondió',
            self::Desistio => 'Desistió',
        };
    }

    /**
     * Los 4 estados terminales de salida (descarte/abandono) no forman parte
     * del pipeline lineal: pueden alcanzarse desde cualquier fase abierta,
     * así que no llevan un número de orden propio.
     */
    private const ESTADOS_SALIDA = [self::NoSeleccionado, self::NoViable, self::NoRespondio, self::Desistio];

    /**
     * Posición en el pipeline sucesivo de un candidato. Usado por
     * puedeTransicionarA() para prohibir retrocesos (ver docs del método).
     */
    public function orden(): int
    {
        return match ($this) {
            self::Recibidos => 1,
            self::Preseleccion => 2,
            self::Entrevista => 3,
            self::Psicometricos => 4,
            self::EstudioSocioeconomico => 5,
            self::Pruebas => 6,
            self::ValidacionDocumental => 7,
            self::OfertaAprobacion => 8,
            self::ListoParaContratacion => 9,
            self::Contratado => 10,
            self::NoSeleccionado, self::NoViable, self::NoRespondio, self::Desistio => 0,
        };
    }

    public function esTerminal(): bool
    {
        return $this === self::Contratado || in_array($this, self::ESTADOS_SALIDA, true);
    }

    /**
     * Mapa de transiciones válidas para App\Http\Controllers\Rh\CandidatoController::actualizarEstado()
     * (drag and drop del tablero de candidatos). Las fases de un candidato son
     * sucesivas: nunca se permite retroceder ni saltar hacia atrás en el
     * pipeline. Un estado terminal (Contratado o cualquiera de los 4 estados
     * de salida) es definitivo: no admite ningún cambio posterior.
     */
    public function puedeTransicionarA(self $destino): bool
    {
        if ($this->esTerminal()) {
            return false;
        }

        if (in_array($destino, self::ESTADOS_SALIDA, true)) {
            return true;
        }

        return $destino->orden() > $this->orden();
    }
}
