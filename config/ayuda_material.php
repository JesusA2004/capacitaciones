<?php

/*
|--------------------------------------------------------------------------
| Material de apoyo del centro de Ayuda
|--------------------------------------------------------------------------
|
| Videos e infografías oficiales de MR. LANA PEOPLE que entrega el negocio
| en docs/ayuda (fuente única en el repositorio). `php artisan
| ayuda:publicar-material` los copia al disco privado (`local`, carpeta
| `ayuda/`) y la pantalla /ayuda/material solo muestra lo publicado. Ver
| docs/AYUDA_MATERIAL.md.
|
| Cada material: slug (URL), título, descripción, tipo (video|pdf), tema
| (agrupa el video con su documento: general, gestor, gerentes, manual,
| flujograma), audiencia (texto para quién es), archivo (nombre exacto en
| docs/ayuda) y orden.
|
*/

return [

    'origen' => base_path('docs/ayuda'),

    'disco' => 'local',

    'carpeta' => 'ayuda',

    'temas' => [
        'general' => 'Presentación general',
        'manual' => 'Manual de operaciones',
        'flujograma' => 'Flujograma de despliegue',
        'gerentes' => 'Infografía de gerentes',
        'gestor' => 'Infografía de gestor',
    ],

    'materiales' => [
        [
            'slug' => 'video-general',
            'titulo' => 'Mr. Lana People en general',
            'descripcion' => 'Qué es Mr. Lana People, para qué sirve la app y la plataforma web, y cómo acompaña la vida laboral de cada colaborador.',
            'tipo' => 'video',
            'tema' => 'general',
            'audiencia' => 'Todos',
            'archivo' => '1_Mr_Lana_People_General.mp4',
            'orden' => 1,
        ],
        [
            'slug' => 'video-infografia-gestor',
            'titulo' => 'Infografía de gestor',
            'descripcion' => 'Tu vida laboral en tu celular: registro con tu código QR, expediente, permisos y todos tus trámites sin papeles.',
            'tipo' => 'video',
            'tema' => 'gestor',
            'audiencia' => 'Gestores y colaboradores',
            'archivo' => '2_Infografia_Colaboradores.mp4',
            'orden' => 2,
        ],
        [
            'slug' => 'video-infografia-gerentes',
            'titulo' => 'Infografía de gerentes: antes y ahora',
            'descripcion' => 'Lo que el gerente de sucursal hacía a mano y ahora hace People: 13 tareas administrativas que dejan de ser trabajo suyo.',
            'tipo' => 'video',
            'tema' => 'gerentes',
            'audiencia' => 'Gerentes de sucursal',
            'archivo' => '3_Infografia_Gerentes_Antes_y_Ahora.mp4',
            'orden' => 3,
        ],
        [
            'slug' => 'video-manual-operacion',
            'titulo' => 'Manual de operaciones',
            'descripcion' => 'Recorrido del manual de operación: qué le toca a cada rol, paso a paso, en la app y en la plataforma web.',
            'tipo' => 'video',
            'tema' => 'manual',
            'audiencia' => 'Todos los roles',
            'archivo' => '4_Manual_de_Operacion.mp4',
            'orden' => 4,
        ],
        [
            'slug' => 'video-flujograma-despliegue',
            'titulo' => 'Flujograma de despliegue',
            'descripcion' => 'El ciclo de vida del colaborador de la postulación a la salida: las etapas, quién participa y qué resultado deja cada una.',
            'tipo' => 'video',
            'tema' => 'flujograma',
            'audiencia' => 'Todos los roles',
            'archivo' => '5_Diagrama_de_Flujo_Desplegado.mp4',
            'orden' => 5,
        ],
        [
            'slug' => 'manual-operacion',
            'titulo' => 'Manual de operaciones',
            'descripcion' => 'Manual de operación MO-RH-PPL-001: la operación completa de Mr. Lana People organizada por rol, con las pantallas reales.',
            'tipo' => 'pdf',
            'tema' => 'manual',
            'audiencia' => 'Todos los roles',
            'archivo' => 'Manual_de_Operacion_Mr_Lana_People_PDF.pdf',
            'orden' => 6,
        ],
        [
            'slug' => 'flujograma-despliegue',
            'titulo' => 'Flujograma de despliegue',
            'descripcion' => 'Diagrama de flujo desplegado del ciclo de vida del colaborador: etapas, participantes y resultado de cada una.',
            'tipo' => 'pdf',
            'tema' => 'flujograma',
            'audiencia' => 'Todos los roles',
            'archivo' => 'Flujograma_Desplegado_Mr_Lana_People.pdf',
            'orden' => 7,
        ],
        [
            'slug' => 'infografia-gerentes',
            'titulo' => 'Infografía de gerentes',
            'descripcion' => 'Antes y ahora: las tareas que el gerente de sucursal hacía a mano y que hoy resuelve People.',
            'tipo' => 'pdf',
            'tema' => 'gerentes',
            'audiencia' => 'Gerentes de sucursal',
            'archivo' => 'Infografia_Gerentes_Antes_y_Ahora.pdf',
            'orden' => 8,
        ],
        [
            'slug' => 'infografia-gestor',
            'titulo' => 'Infografía de gestor',
            'descripcion' => 'Tu vida laboral en tu celular: todo lo que harás en la app Mr. Lana People, paso a paso.',
            'tipo' => 'pdf',
            'tema' => 'gestor',
            'audiencia' => 'Gestores y colaboradores',
            'archivo' => 'Infografia_Colaboradores_Mr_Lana_People.pdf',
            'orden' => 9,
        ],
    ],

];
