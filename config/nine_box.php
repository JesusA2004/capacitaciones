<?php

/*
|--------------------------------------------------------------------------
| Nine Box (Seguimiento): valores POR DEFECTO de la configuración
|--------------------------------------------------------------------------
|
| La configuración vigente vive en la tabla `nine_box_configuraciones`
| (versionada: cada cambio guarda una versión nueva con quién y por qué) y
| la edita quien tenga `nine_box.configurar`. Estos valores son el punto de
| partida y el respaldo si la tabla está vacía. Ver docs/NINE_BOX.md.
|
| Todos los puntajes van de 0 a 100.
|
*/

return [

    'valores' => [
        // Eje DESEMPEÑO: datos duros de Reportes por gestor.
        'desempeno' => [
            // Peso de cada indicador (se normalizan: no tienen que sumar 100).
            // Un indicador sin dato (p. ej. mora sin cartera) no cuenta y su
            // peso se reparte entre los demás.
            'pesos' => [
                'colocacion' => 40,
                'recuperacion' => 35,
                'mora' => 25,
            ],
            // Meta MENSUAL por gestor (pesos MXN). Con meta: puntaje =
            // cumplimiento (real / meta, tope 100). Sin meta (null): puntaje
            // = percentil del gestor contra TODOS los gestores de la empresa
            // en el mismo periodo (independiente del alcance de quien mira).
            // En trimestre la meta se multiplica por los meses del periodo.
            'meta_colocacion_mensual' => null,
            'meta_recuperacion_mensual' => null,
            // Mora (% vencida / cartera, corte del último mes del periodo):
            // hasta «mora_excelente» = 100 puntos; desde «mora_critica» = 0;
            // en medio, lineal.
            'mora_excelente' => 5,
            'mora_critica' => 25,
        ],

        // Eje POTENCIAL: evaluaciones técnicas («Seguimiento 15/30/60…») +
        // calibración del jefe directo o RH.
        'potencial' => [
            'pesos' => [
                'evaluaciones' => 60,
                'calibracion' => 40,
            ],
            // Meses hacia atrás (desde el fin del periodo) en que se buscan
            // evaluaciones técnicas contestadas.
            'ventana_evaluaciones_meses' => 6,
            // Puntaje de cada nivel de calibración.
            'valores_calibracion' => [
                'bajo' => 25,
                'medio' => 60,
                'alto' => 90,
            ],
        ],

        // Cortes de cada eje: menor que «medio» = Bajo; desde «alto» = Alto.
        'cortes' => [
            'desempeno' => ['medio' => 40, 'alto' => 70],
            'potencial' => ['medio' => 40, 'alto' => 70],
        ],
    ],

];
