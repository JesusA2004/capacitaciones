<?php

/*
 * Domicilios y teléfonos OFICIALES de las sucursales, tomados de
 * https://mr-lana.com/sucursales (consultado 2026-09-25), más el
 * Corporativo (dato de dirección: Subida del Club 114, Cuernavaca).
 *
 * Llave = `clave` de App\Models\Sucursal. Lo usan SucursalSeeder (instalación
 * nueva) y la migración 2026_09_25_190000_completar_domicilios_sucursales
 * (bases existentes; solo llena campos vacíos, nunca pisa lo capturado).
 *
 * San Juan del Río (SJR01) y Tenango del Valle (TEN01) NO aparecen en el
 * sitio: se dejan sin domicilio a propósito (auditable, no se inventa).
 *
 * El Corporativo es donde trabaja todo lo general y de servicio a todas las
 * sucursales (Sistemas, RH, Mesa de Control, Tesorería, Contabilidad…):
 * esos puestos pertenecen a CORP01, nunca a una sucursal operativa.
 */
return [
    'CORP01' => [
        'nombre' => 'Corporativo',
        'ciudad' => 'Cuernavaca',
        'estado' => 'Morelos',
        'direccion' => 'Subida del Club 114, Cuernavaca, Morelos, C.P. 62260',
        'telefono' => null,
    ],
    'CUE01' => ['direccion' => 'Privada Reforma #751-D, Col. Tlaltenango, Cuernavaca, Morelos, C.P. 62170', 'telefono' => '777 900 8852'],
    'MIA01' => ['direccion' => 'Av. Morelos S/N, Col. Centro, Miacatlán, Morelos, C.P. 62600', 'telefono' => '737 688 1772'],
    'ATL01' => ['direccion' => 'C. 9 Sur #507, Int. 7, Col. Centro, Atlixco, Puebla, C.P. 74200', 'telefono' => '222 944 6532'],
    'IXT01' => ['direccion' => 'Av. Gustavo Baz Prada y Av. de la Mujer #406, Int. 1, Col. San Pedro, Ixtlahuaca, Edo. Méx., C.P. 50740', 'telefono' => '712 688 1615'],
    'COR01' => ['direccion' => 'Av. 11 #1305 local 20, Col. Centro, Córdoba, Veracruz, C.P. 94500', 'telefono' => '271 344 2933'],
    'ORI01' => ['direccion' => 'Oriente 6, #851, Int. 10 y 11, Esq. Sur 17, Col. Centro, Orizaba, Veracruz, C.P. 94300', 'telefono' => '272 341 4129'],
    'HUA01' => ['direccion' => 'C. Morelos Oriente #311, Local 15, Col. Centro, Huamantla, Tlaxcala, C.P. 90500', 'telefono' => null],
    'TLX01' => ['direccion' => 'Carr. Ocotlán–Chiautempan #134, Col. La Joya Centro, Tlaxcala, C.P. 90114', 'telefono' => '246 312 8179'],
    'ATC01' => ['direccion' => 'Juan N. Reséndiz 5, Col. Centro, Atlacomulco, Edo. Méx., C.P. 50450', 'telefono' => '712 597 5776 / 712 688 2923'],
    'SLP01' => ['direccion' => 'Plaza Coronel, Prolongación Coronel Romero #110, Col. Alamitos, San Luis Potosí, C.P. 78280', 'telefono' => '444 459 7378'],
    'TUL01' => ['direccion' => 'Boulevard Tula Iturbe #100, Col. Villas del Salitre, Tula de Allende, Hidalgo', 'telefono' => '773 732 2376'],
];
