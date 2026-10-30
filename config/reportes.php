<?php

/*
|--------------------------------------------------------------------------
| Reportes de RH: diseño y layout de Altas para NOI
|--------------------------------------------------------------------------
|
| colores: paleta oficial de la propuesta de Etapa 1, disponible como color de
| acento de los reportes en PDF y Excel.
|
| noi.campos: lista cerrada de datos que puede llevar el layout de Altas para
| NOI. 'sensible' exige reportes_rh.datos_sensibles y 'salario' exige
| salarios.ver. 'sin_fuente' marca un dato que People todavía no captura: se
| reporta como faltante, nunca se inventa.
|
| El layout oficial de Aspel NOI no se ha entregado. noi.layout_inicial es un
| reporte intermedio completo; RH reordena y renombra columnas desde pantalla.
| Hasta homologarlo con la versión real de NOI, el archivo se identifica como
| «pendiente de homologar», nunca como una importación validada.
|
*/

return [

    'colores' => [
        'petroleo' => ['etiqueta' => 'Petróleo', 'hex' => '#0D3E43'],
        'esmeralda' => ['etiqueta' => 'Esmeralda', 'hex' => '#225C54'],
        'bosque' => ['etiqueta' => 'Bosque', 'hex' => '#2F5937'],
        'bronce' => ['etiqueta' => 'Bronce', 'hex' => '#754711'],
        'dorado' => ['etiqueta' => 'Dorado', 'hex' => '#AF8B51'],
    ],

    // Secuencia categórica de las gráficas; el primer color lo pone el acento.
    'grafica' => ['#225C54', '#AF8B51', '#2F5937', '#754711', '#0D3E43', '#7FA79B', '#D0B483', '#A9B8A0', '#B9956A', '#5E8780'],

    'noi' => [
        'campos' => [
            'nombre' => ['etiqueta' => 'Nombre(s)'],
            'apellidos' => ['etiqueta' => 'Apellidos'],
            'rfc' => ['etiqueta' => 'RFC', 'sensible' => true],
            'curp' => ['etiqueta' => 'CURP', 'sensible' => true],
            'nss' => ['etiqueta' => 'NSS', 'sensible' => true],
            'fecha_nacimiento' => ['etiqueta' => 'Fecha de nacimiento', 'sensible' => true],
            'sexo' => ['etiqueta' => 'Sexo'],
            'domicilio' => ['etiqueta' => 'Domicilio', 'sensible' => true],
            'codigo_postal' => ['etiqueta' => 'Código postal', 'sensible' => true],
            'fecha_ingreso' => ['etiqueta' => 'Fecha de ingreso'],
            'fecha_alta_imss' => ['etiqueta' => 'Fecha de alta IMSS'],
            'puesto' => ['etiqueta' => 'Puesto'],
            'departamento' => ['etiqueta' => 'Departamento'],
            'sucursal' => ['etiqueta' => 'Sucursal'],
            'registro_patronal' => ['etiqueta' => 'Registro patronal'],
            'salario_diario' => ['etiqueta' => 'Salario diario', 'salario' => true],
            'salario_diario_integrado' => ['etiqueta' => 'Salario diario integrado', 'salario' => true],
            'tipo_contrato' => ['etiqueta' => 'Tipo de contrato'],
            'jornada' => ['etiqueta' => 'Jornada', 'sin_fuente' => true],
            'periodicidad_pago' => ['etiqueta' => 'Periodicidad de pago', 'sin_fuente' => true],
            'banco' => ['etiqueta' => 'Banco', 'sensible' => true, 'sin_fuente' => true],
            'cuenta_clabe' => ['etiqueta' => 'Cuenta o CLABE', 'sensible' => true, 'sin_fuente' => true],
            'correo' => ['etiqueta' => 'Correo', 'opcional' => true],
        ],
        'layout_inicial' => [
            'formato' => 'xlsx',
            'columnas' => [
                'nombre', 'apellidos', 'rfc', 'curp', 'nss', 'fecha_nacimiento', 'sexo', 'domicilio', 'codigo_postal',
                'fecha_ingreso', 'fecha_alta_imss', 'puesto', 'departamento', 'sucursal', 'registro_patronal',
                'salario_diario', 'salario_diario_integrado', 'tipo_contrato', 'jornada', 'periodicidad_pago', 'banco',
                'cuenta_clabe', 'correo',
            ],
        ],
    ],

];
