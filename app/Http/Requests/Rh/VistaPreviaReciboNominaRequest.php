<?php

namespace App\Http\Requests\Rh;

/**
 * Vista previa del recibo de nómina antes de «Generar» (mismos campos y
 * misma autorización que GenerarReciboNominaRequest: solo RH con
 * nomina.recibos.crear, dentro de su alcance y nunca su propio recibo).
 */
class VistaPreviaReciboNominaRequest extends GenerarReciboNominaRequest {}
