<?php

/*
|--------------------------------------------------------------------------
| Módulo de Bajas y Causales (especificación de RH v1.0, 20-oct-2026)
|--------------------------------------------------------------------------
|
| Catálogo de dos niveles: forma de salida (App\Enums\FormaSalida: RV / NR /
| RE) → causal (17, clave que inicia con la forma). Este archivo es el
| catálogo INICIAL: App\Services\Bajas\CatalogoBajasService::sembrar() lo
| carga sin pisar lo que RH ya editó o desactivó (RN-15: las causales se
| desactivan, nunca se borran). Textos confirmados por la Gerencia de RH.
|
| Ver docs/BAJAS_Y_CAUSALES.md.
|
*/

return [

    'folio_prefijo' => 'BAJ',

    'reglas' => [
        // RN-04: comentario obligatorio (mínimo) en RV-05 y en todas las RE.
        'comentario_minimo' => 20,
        'comentario_maximo' => 500,
        // RN-07: días entre que se conoció la falta y la fecha de baja.
        'dias_prescripcion_rescision' => 30,
        // RN-08: días hábiles máximos para entregar el aviso ante el Tribunal.
        'dias_habiles_aviso_tribunal' => 5,
        // RN-10: la fecha de baja no puede ser mayor a N días en el futuro.
        'dias_futuro_maximo' => 15,
        // RN-12: rotación temprana = antigüedad menor a N días.
        'dias_rotacion_temprana' => 90,
    ],

    // Archivos adjuntos a la baja (PDF o imagen), disco del NAS de expedientes.
    'documentos' => [
        'max_kb' => 15360,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'carpeta' => 'bajas',
    ],

    /*
    | Causales (clave => datos). `ayuda` = «Cuándo usarla» (se muestra debajo
    | del combo). `excluye_indemnizacion`: el finiquito no estima
    | indemnización para esa causal aunque la forma la contemple.
    */
    'causales' => [
        'RV-01' => ['forma' => 'RV', 'nombre' => 'Mejor oferta laboral o salarial', 'ayuda' => 'Se va a otro empleo con mejor sueldo, mejor puesto o mejor oportunidad.', 'requiere_juridico' => false, 'comentario_obligatorio' => false, 'alerta' => null],
        'RV-02' => ['forma' => 'RV', 'nombre' => 'Condiciones de trabajo', 'ayuda' => 'Inconformidad con prestaciones (IMSS), horario, distancia, carga de trabajo, esquema de comisiones, uso de moto o inseguridad en la ruta.', 'requiere_juridico' => false, 'comentario_obligatorio' => false, 'alerta' => null],
        'RV-03' => ['forma' => 'RV', 'nombre' => 'No se adaptó al puesto', 'ayuda' => 'No le gustó el trabajo de campo, la cobranza o el manejo de efectivo. Suele ocurrir en las primeras semanas.', 'requiere_juridico' => false, 'comentario_obligatorio' => false, 'alerta' => null],
        'RV-04' => ['forma' => 'RV', 'nombre' => 'Relación con el jefe o clima laboral', 'ayuda' => 'Conflicto con el jefe inmediato, malos tratos, acoso o mal ambiente. Genera alerta a RH.', 'requiere_juridico' => false, 'comentario_obligatorio' => false, 'alerta' => 'Alerta a RH: entrevista de salida obligatoria por RH y revisión conforme a NOM-035'],
        'RV-05' => ['forma' => 'RV', 'nombre' => 'Motivos personales o familiares', 'ayuda' => 'Familia, salud propia, cambio de residencia o motivo personal que no desea explicar. El detalle va en comentarios.', 'requiere_juridico' => false, 'comentario_obligatorio' => true, 'alerta' => null],
        'RV-06' => ['forma' => 'RV', 'nombre' => 'Estudios o proyecto propio', 'ayuda' => 'Se va a estudiar, emprender un negocio propio o familiar, o busca un crecimiento que aquí no tuvo.', 'requiere_juridico' => false, 'comentario_obligatorio' => false, 'alerta' => null],
        'NR-01' => ['forma' => 'NR', 'nombre' => 'Bajo desempeño', 'ayuda' => 'No cumplió metas de colocación, cobranza o productividad de forma reiterada, con seguimiento documentado.', 'requiere_juridico' => true, 'comentario_obligatorio' => false, 'alerta' => null, 'implica_indemnizacion' => true],
        'NR-02' => ['forma' => 'NR', 'nombre' => 'No aprobó periodo de prueba / perfil no idóneo', 'ayuda' => 'Durante el periodo de prueba se detecta que no cubre el perfil: malas referencias, documentación incompleta, no apto para el puesto.', 'requiere_juridico' => false, 'comentario_obligatorio' => false, 'alerta' => null],
        'NR-03' => ['forma' => 'NR', 'nombre' => 'Actitud o conflictos con el equipo', 'ayuda' => 'Conflictos recurrentes o mala actitud, sin una falta grave documentada.', 'requiere_juridico' => true, 'comentario_obligatorio' => false, 'alerta' => null, 'implica_indemnizacion' => true],
        'NR-04' => ['forma' => 'NR', 'nombre' => 'Término de contrato temporal o suplencia', 'ayuda' => 'Concluye el contrato por tiempo determinado o la cobertura de una incapacidad.', 'requiere_juridico' => false, 'comentario_obligatorio' => false, 'alerta' => null],
        'NR-05' => ['forma' => 'NR', 'nombre' => 'Reestructura o recorte de plantilla', 'ayuda' => 'La empresa elimina el puesto o reduce la plantilla del área.', 'requiere_juridico' => true, 'comentario_obligatorio' => false, 'alerta' => null, 'implica_indemnizacion' => true],
        'NR-06' => ['forma' => 'NR', 'nombre' => 'Cierre de sucursal o unidad de negocio', 'ayuda' => 'Cierre definitivo de la sucursal o de la empresa.', 'requiere_juridico' => true, 'comentario_obligatorio' => false, 'alerta' => null, 'implica_indemnizacion' => true],
        'RE-01' => ['forma' => 'RE', 'nombre' => 'Faltas injustificadas o abandono de empleo', 'ayuda' => 'Más de 3 faltas en 30 días sin permiso ni justificación, o deja de presentarse (art. 47, fr. X).', 'requiere_juridico' => true, 'comentario_obligatorio' => true, 'alerta' => null, 'excluye_indemnizacion' => true],
        'RE-02' => ['forma' => 'RE', 'nombre' => 'Faltantes en caja o cartera (desfaces)', 'ayuda' => 'Diferencias de efectivo o de cartera detectadas en arqueo o auditoría.', 'requiere_juridico' => true, 'comentario_obligatorio' => true, 'alerta' => 'Aviso a Contraloría'],
        'RE-03' => ['forma' => 'RE', 'nombre' => 'Falta de probidad, robo o fraude', 'ayuda' => 'Robo, fraude, simulación de asalto, alteración de documentos, préstamos o créditos indebidos a clientes (art. 47, fr. II).', 'requiere_juridico' => true, 'comentario_obligatorio' => true, 'alerta' => 'Aviso a Contraloría y Jurídico'],
        'RE-04' => ['forma' => 'RE', 'nombre' => 'Alcohol en horario laboral', 'ayuda' => 'Se presenta en estado de ebriedad o consume alcohol en el trabajo (art. 47, fr. XIII).', 'requiere_juridico' => true, 'comentario_obligatorio' => true, 'alerta' => null],
        'RE-05' => ['forma' => 'RE', 'nombre' => 'Incumplimiento de reglamento o políticas', 'ayuda' => 'Malas prácticas, violación de confidencialidad, filtración de información, conflicto de interés o incumplimiento de políticas de crédito.', 'requiere_juridico' => true, 'comentario_obligatorio' => true, 'alerta' => null],
        // OT — Otras terminaciones (causa ajena a las partes). Generan una
        // constancia según el caso (no carta/convenio/aviso) y NO se fuerzan a
        // liquidación; sus flujos específicos (beneficiario en fallecimiento,
        // pensión del IMSS en incapacidad permanente) los define RH/Jurídico.
        'OT-01' => ['forma' => 'OT', 'nombre' => 'Fallecimiento', 'ayuda' => 'El colaborador fallece. El finiquito se entrega a los beneficiarios conforme a la ley; adjunta el acta de defunción.', 'requiere_juridico' => false, 'comentario_obligatorio' => true, 'alerta' => null],
        'OT-02' => ['forma' => 'OT', 'nombre' => 'Incapacidad permanente total', 'ayuda' => 'El IMSS dictamina incapacidad permanente total que impide continuar laborando (ST-3). Adjunta el dictamen.', 'requiere_juridico' => false, 'comentario_obligatorio' => true, 'alerta' => null],
        'OT-03' => ['forma' => 'OT', 'nombre' => 'Jubilación o pensión', 'ayuda' => 'El colaborador se jubila o accede a una pensión. Adjunta la resolución de pensión del IMSS.', 'requiere_juridico' => false, 'comentario_obligatorio' => true, 'alerta' => null],
    ],

    // Alertas automáticas por causal (RN-12): a quién se avisa.
    'alertas' => [
        'RV-04' => ['rh_correo'],
        'RE-02' => ['contraloria'],
        'RE-03' => ['contraloria', 'juridico'],
    ],

    /*
    | Documentos requeridos para CERRAR (RN-05): los de la forma + los
    | adicionales de la causal. `sistema`: el espacio también se cumple con
    | lo que el sistema ya lleva en el cierre laboral (renuncia o aviso de
    | terminación generados y marcados como firmados físicamente, o el
    | finiquito entregado), sin volver a subir el archivo. El aviso de
    | rescisión siempre se adjunta (el sistema no lo genera).
    */
    'documentos_requeridos' => [
        'RV.carta_renuncia' => ['forma' => 'RV', 'nombre' => 'Carta de renuncia firmada', 'sistema' => 'carta_renuncia'],
        'RV.entrevista_salida' => ['forma' => 'RV', 'nombre' => 'Entrevista de salida'],
        'RV.finiquito' => ['forma' => 'RV', 'nombre' => 'Finiquito firmado', 'sistema' => 'finiquito'],
        'NR.convenio_o_aviso' => ['forma' => 'NR', 'nombre' => 'Convenio de terminación o aviso de término de contrato', 'sistema' => 'aviso_terminacion'],
        'NR.finiquito' => ['forma' => 'NR', 'nombre' => 'Finiquito o liquidación firmado', 'sistema' => 'finiquito'],
        'RE.acta_administrativa' => ['forma' => 'RE', 'nombre' => 'Acta administrativa con testigos'],
        'RE.aviso_rescision' => ['forma' => 'RE', 'nombre' => 'Aviso de rescisión entregado'],
        'RE.finiquito' => ['forma' => 'RE', 'nombre' => 'Finiquito firmado', 'sistema' => 'finiquito'],
        // OT — Otras terminaciones: constancia según el caso (se adjunta, el
        // sistema no la genera) + documento que acredita la causa + finiquito.
        'OT.constancia' => ['forma' => 'OT', 'nombre' => 'Constancia o documento que acredita la causa (acta de defunción, dictamen ST-3 o resolución de pensión)'],
        'OT.finiquito' => ['forma' => 'OT', 'nombre' => 'Finiquito firmado', 'sistema' => 'finiquito'],
        'NR-01.evidencia_desempeno' => ['causal' => 'NR-01', 'nombre' => 'Evidencia de seguimiento de desempeño (metas, evaluaciones o llamadas de atención)'],
        'NR-05.autorizacion_direccion' => ['causal' => 'NR-05', 'nombre' => 'Autorización de Dirección General'],
        'NR-06.autorizacion_direccion' => ['causal' => 'NR-06', 'nombre' => 'Autorización de Dirección General'],
        'RE-02.arqueo_auditoria' => ['causal' => 'RE-02', 'nombre' => 'Arqueo o reporte de auditoría'],
        'RE-03.evidencia_falta' => ['causal' => 'RE-03', 'nombre' => 'Evidencia de la falta (reporte, auditoría, denuncia)'],
    ],

    // Causal automática de la no renovación decidida en la evaluación del
    // periodo de prueba (EvaluacionPeriodoPruebaService → cierre laboral).
    'clave_no_renovacion' => 'NR-02',

    /*
    | Causales del subcatálogo anterior (claves del 20-oct-2026, antes de la
    | especificación) → clave nueva. `limpio` = la equivalencia es directa;
    | si no, la baja queda marcada «causal migrada, revisar» y la migración
    | lo registra en el log (nunca en silencio). `primaria`: la fila que se
    | actualiza en su lugar (conserva su id); el resto se marca como legado.
    */
    'causales_anteriores' => [
        'mejor_oferta' => ['clave' => 'RV-01', 'limpio' => true, 'primaria' => true],
        'inconformidad_condiciones' => ['clave' => 'RV-02', 'limpio' => true, 'primaria' => true],
        'motivos_personales' => ['clave' => 'RV-05', 'limpio' => true, 'primaria' => true],
        'cambio_residencia' => ['clave' => 'RV-05', 'limpio' => true],
        'motivos_salud' => ['clave' => 'RV-05', 'limpio' => true],
        'renuncia_voluntaria' => ['clave' => 'RV-05', 'limpio' => false],
        'mutuo_acuerdo' => ['clave' => 'RV-05', 'limpio' => false],
        'estudios' => ['clave' => 'RV-06', 'limpio' => true, 'primaria' => true],
        'bajo_desempeno' => ['clave' => 'NR-01', 'limpio' => true, 'primaria' => true],
        'no_acredita_periodo_prueba' => ['clave' => 'NR-02', 'limpio' => true, 'primaria' => true],
        'termino_contrato_determinado' => ['clave' => 'NR-04', 'limpio' => true, 'primaria' => true],
        'conclusion_obra' => ['clave' => 'NR-04', 'limpio' => true],
        'abandono_trabajo' => ['clave' => 'RE-01', 'limpio' => true, 'primaria' => true],
        'faltas_injustificadas' => ['clave' => 'RE-01', 'limpio' => true],
        'falta_probidad' => ['clave' => 'RE-03', 'limpio' => true, 'primaria' => true],
        'incumplimiento_reglamento' => ['clave' => 'RE-05', 'limpio' => true, 'primaria' => true],
        'desobediencia' => ['clave' => 'RE-05', 'limpio' => false],
        'otra_art_47' => ['clave' => 'RE-05', 'limpio' => false],
    ],

    // Valores anteriores de tipo_baja (antes de los tres tipos) → tipo vigente.
    'tipos_baja_anteriores' => [
        'mutuo_acuerdo' => 'renuncia',
        'despido' => 'rescision',
        'baja_inmediata' => 'rescision',
        'bajo_desempeno' => 'fin_contrato',
        'abandono' => 'rescision',
        'no_renovacion' => 'fin_contrato',
        'otro' => 'rescision',
    ],

    // Tablero de rotación: ventana de la tendencia.
    'tablero' => [
        'meses_tendencia' => 12,
        // Primer mes que se mide con las bajas del sistema; los anteriores
        // usan los agregados del Excel de rotación (rotacion_periodos).
        'mes_inicio_sistema' => env('BAJAS_MES_INICIO_SISTEMA', '2026-10'),
    ],

];
