<?php

use App\Enums\ModoCorreccion;

/*
| Sistema universal de correcciones (docs/CORRECCIONES.md). Propuesta
| conservadora PENDIENTE DE VALIDACIÓN por RH y Jurídico: donde hay duda, el dato
| no se modifica. El modo aquí es el máximo permitido: cada dominio lo restringe
| según el estado del registro (firmado, emitido, cancelado…).
|
| api => false deja el dominio o campo fuera de la API móvil
| (routes/salarios.php: «sin API: dato sensible»).
*/

$texto = fn (string $etiqueta, int $max = 150): array => [
    'etiqueta' => $etiqueta, 'tipo' => 'texto', 'modo' => ModoCorreccion::Directo->value,
    'permiso' => 'expedientes.editar', 'reglas' => ['nullable', 'string', 'max:'.$max],
];

return [
    'motivo_minimo' => 5,
    'motivo_maximo' => 500,

    'dominios' => [
        'salario' => [
            'etiqueta' => 'Sueldo',
            'api' => false,
            'campos' => [
                'sueldo_mensual' => [
                    'etiqueta' => 'Sueldo mensual', 'tipo' => 'moneda', 'modo' => ModoCorreccion::ConVigencia->value,
                    'permiso' => 'salarios.editar', 'confidencial' => true,
                    'reglas' => ['required', 'numeric', 'gt:0', 'max:9999999.99'],
                ],
                'salario_diario' => [
                    'etiqueta' => 'Salario diario', 'tipo' => 'moneda', 'modo' => ModoCorreccion::NoCorregible->value,
                    'permiso' => 'salarios.editar', 'confidencial' => true,
                    'explicacion' => 'Se deriva siempre del sueldo mensual (mensual ÷ 30): corrige el mensual.',
                ],
                'salario_diario_integrado' => [
                    'etiqueta' => 'Salario diario integrado', 'tipo' => 'moneda', 'modo' => ModoCorreccion::NoCorregible->value,
                    'permiso' => 'salarios.editar', 'confidencial' => true,
                    'explicacion' => 'Su regla y su fuente están pendientes de validación por RH y Jurídico.',
                ],
            ],
        ],

        'laboral' => [
            'etiqueta' => 'Datos laborales',
            'campos' => [
                'fecha_ingreso' => [
                    'etiqueta' => 'Fecha de ingreso', 'tipo' => 'fecha', 'modo' => ModoCorreccion::Directo->value,
                    'permiso' => 'expedientes.editar',
                    'reglas' => ['required', 'date', 'after:1950-01-01', 'before_or_equal:+1 year'],
                ],
                'puesto_id' => [
                    'etiqueta' => 'Puesto', 'tipo' => 'seleccion', 'modo' => ModoCorreccion::Directo->value,
                    'permiso' => 'expedientes.editar', 'reglas' => ['required', 'integer', 'exists:puestos,id'],
                ],
                'sucursal_principal_id' => [
                    'etiqueta' => 'Sucursal', 'tipo' => 'seleccion', 'modo' => ModoCorreccion::Directo->value,
                    'permiso' => 'expedientes.editar', 'reglas' => ['required', 'integer', 'exists:sucursales,id'],
                ],
                'departamento_id' => [
                    'etiqueta' => 'Departamento', 'tipo' => 'seleccion', 'modo' => ModoCorreccion::Directo->value,
                    'permiso' => 'expedientes.editar', 'reglas' => ['required', 'integer', 'exists:departamentos,id'],
                ],
                'jefe_id' => [
                    'etiqueta' => 'Jefe directo', 'tipo' => 'texto', 'modo' => ModoCorreccion::NoCorregible->value,
                    'permiso' => 'expedientes.editar',
                    'explicacion' => 'Se deriva del organigrama según el puesto y la sucursal.',
                ],
                'tipo_contratacion' => [
                    'etiqueta' => 'Tipo de contratación', 'tipo' => 'texto', 'modo' => ModoCorreccion::NoCorregible->value,
                    'permiso' => 'expedientes.editar',
                    'explicacion' => 'Cambia con el ciclo del contrato, no con una corrección.',
                ],
                'fecha_baja' => [
                    'etiqueta' => 'Fecha de baja', 'tipo' => 'fecha', 'modo' => ModoCorreccion::NoCorregible->value,
                    'permiso' => 'expedientes.editar',
                    'explicacion' => 'Se corrige desde el expediente de la baja, que tiene su propia bitácora.',
                ],
            ],
        ],

        'personal' => [
            'etiqueta' => 'Datos personales',
            'campos' => [
                'name' => ['etiqueta' => 'Nombre(s)', 'tipo' => 'texto', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'expedientes.editar', 'reglas' => ['required', 'string', 'max:150']],
                'apellidos' => $texto('Apellidos'),
                'fecha_nacimiento' => ['etiqueta' => 'Fecha de nacimiento', 'tipo' => 'fecha', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'expedientes.editar', 'reglas' => ['nullable', 'date', 'before:today']],
                'genero' => ['etiqueta' => 'Sexo', 'tipo' => 'seleccion', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'expedientes.editar', 'reglas' => ['nullable', 'string']],
                'estado_civil' => ['etiqueta' => 'Estado civil', 'tipo' => 'seleccion', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'expedientes.editar', 'reglas' => ['nullable', 'string']],
                'curp' => ['etiqueta' => 'CURP', 'tipo' => 'texto', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'expedientes.editar', 'reglas' => ['nullable', 'string', 'regex:/^[A-Z]{4}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/']],
                'rfc' => ['etiqueta' => 'RFC', 'tipo' => 'texto', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'expedientes.editar', 'reglas' => ['nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u']],
                'nss' => ['etiqueta' => 'NSS', 'tipo' => 'texto', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'expedientes.editar', 'reglas' => ['nullable', 'string', 'regex:/^\d{11}$/']],
                'nacionalidad' => $texto('Nacionalidad', 80),
                'lugar_nacimiento' => $texto('Lugar de nacimiento'),
                'profesion' => $texto('Profesión u oficio'),
                'telefono' => $texto('Teléfono celular', 30),
                'telefono_corporativo' => $texto('Teléfono corporativo', 30),
                'correo_personal' => ['etiqueta' => 'Correo personal', 'tipo' => 'texto', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'expedientes.editar', 'reglas' => ['nullable', 'email', 'max:255']],
                'domicilio_calle' => $texto('Calle'),
                'domicilio_numero_exterior' => $texto('Número exterior', 20),
                'domicilio_numero_interior' => $texto('Número interior', 20),
                'domicilio_colonia' => $texto('Colonia'),
                'domicilio_cp' => ['etiqueta' => 'Código postal', 'tipo' => 'texto', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'expedientes.editar', 'reglas' => ['nullable', 'string', 'regex:/^\d{5}$/']],
                'domicilio_municipio' => $texto('Municipio o alcaldía'),
                'domicilio_estado' => $texto('Estado'),
                'contacto_emergencia_nombre' => $texto('Contacto de emergencia'),
                'contacto_emergencia_telefono' => $texto('Teléfono del contacto de emergencia', 30),
                'contacto_emergencia_parentesco' => $texto('Parentesco del contacto de emergencia', 80),
                'beneficiario_nombre' => $texto('Beneficiario'),
                'beneficiario_parentesco' => $texto('Parentesco del beneficiario', 80),
                'condicion_medica' => ['etiqueta' => 'Condición médica', 'tipo' => 'area', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'expedientes.datos_medicos.ver', 'confidencial' => true, 'reglas' => ['nullable', 'string', 'max:1000']],
                'alergias' => ['etiqueta' => 'Alergias', 'tipo' => 'area', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'expedientes.datos_medicos.ver', 'confidencial' => true, 'reglas' => ['nullable', 'string', 'max:1000']],
            ],
        ],

        'contratacion' => [
            'etiqueta' => 'Datos de contratación',
            'campos' => [
                'fecha_inicio' => ['etiqueta' => 'Inicio del contrato', 'tipo' => 'fecha', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'contratos.generar', 'reglas' => ['required', 'date']],
                'fecha_fin' => ['etiqueta' => 'Fin del contrato', 'tipo' => 'fecha', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'contratos.generar', 'reglas' => ['nullable', 'date']],
                'sueldo_mensual' => ['etiqueta' => 'Sueldo del contrato', 'tipo' => 'moneda', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'salarios.editar', 'confidencial' => true, 'api' => false, 'reglas' => ['required', 'numeric', 'gt:0', 'max:9999999.99']],
                'observaciones' => ['etiqueta' => 'Observaciones', 'tipo' => 'area', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'contratos.generar', 'reglas' => ['nullable', 'string', 'max:2000']],
                'tipo' => ['etiqueta' => 'Tipo de contrato', 'tipo' => 'texto', 'modo' => ModoCorreccion::NoCorregible->value, 'permiso' => 'contratos.generar', 'explicacion' => 'Cambia con el ciclo del contrato, no con una corrección.'],
            ],
        ],

        'incapacidad' => [
            'etiqueta' => 'Incapacidad',
            'campos' => [
                'serie_folio_imss' => ['etiqueta' => 'Serie y folio IMSS', 'tipo' => 'texto', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'incapacidades.corregir', 'reglas' => ['required', 'string', 'max:40']],
                'fecha_inicio' => ['etiqueta' => 'Fecha de inicio', 'tipo' => 'fecha', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'incapacidades.corregir', 'reglas' => ['required', 'date']],
                'dias' => ['etiqueta' => 'Días', 'tipo' => 'entero', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'incapacidades.corregir', 'reglas' => ['required', 'integer', 'min:1', 'max:365']],
                'observaciones' => ['etiqueta' => 'Observaciones', 'tipo' => 'area', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'incapacidades.corregir', 'reglas' => ['nullable', 'string', 'max:2000']],
                'tipo' => ['etiqueta' => 'Tipo de incapacidad', 'tipo' => 'texto', 'modo' => ModoCorreccion::NoCorregible->value, 'permiso' => 'incapacidades.corregir', 'explicacion' => 'El tipo no se corrige: cancela la captura y regístrala de nuevo.'],
            ],
        ],

        'recibo_nomina' => [
            'etiqueta' => 'Incidencias y conceptos del recibo',
            'api' => false,
            'campos' => [
                'dias_pagados' => ['etiqueta' => 'Días pagados', 'tipo' => 'numero', 'modo' => ModoCorreccion::SoloRectificacion->value, 'permiso' => 'nomina.recibos.crear', 'reglas' => ['required', 'numeric', 'min:0', 'max:31']],
                'dias_falta' => ['etiqueta' => 'Días de falta o sin goce', 'tipo' => 'numero', 'modo' => ModoCorreccion::SoloRectificacion->value, 'permiso' => 'nomina.recibos.crear', 'reglas' => ['required', 'numeric', 'min:0', 'max:31']],
                'dias_incapacidad' => ['etiqueta' => 'Días de incapacidad', 'tipo' => 'numero', 'modo' => ModoCorreccion::SoloRectificacion->value, 'permiso' => 'nomina.recibos.crear', 'reglas' => ['required', 'numeric', 'min:0', 'max:31']],
                'observaciones' => ['etiqueta' => 'Observaciones', 'tipo' => 'area', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'nomina.recibos.crear', 'reglas' => ['nullable', 'string', 'max:2000']],
            ],
        ],

        'finiquito' => [
            'etiqueta' => 'Conceptos de finiquito',
            'campos' => [
                'comentarios_ajuste' => ['etiqueta' => 'Comentarios del ajuste', 'tipo' => 'area', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'finiquitos.calcular', 'reglas' => ['nullable', 'string', 'max:2000']],
            ],
        ],

        'agenda' => [
            'etiqueta' => 'Cita de la agenda',
            'campos' => [
                'fecha_hora' => ['etiqueta' => 'Fecha y hora', 'tipo' => 'fecha_hora', 'modo' => ModoCorreccion::NuevaVersion->value, 'permiso' => 'agenda.agendar', 'reglas' => ['required', 'date']],
                'notas' => ['etiqueta' => 'Notas', 'tipo' => 'area', 'modo' => ModoCorreccion::Directo->value, 'permiso' => 'agenda.agendar', 'reglas' => ['nullable', 'string', 'max:2000']],
            ],
        ],
    ],
];
