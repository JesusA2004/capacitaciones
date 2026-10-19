<?php

/*
|--------------------------------------------------------------------------
| Identidad institucional de los PDF (MR. LANA PEOPLE)
|--------------------------------------------------------------------------
|
| FUENTE ÚNICA de color de TODOS los PDF administrativos y reportes. La
| paleta institucional PEOPLE vive aquí una sola vez (espejo de la paleta
| pastel/crema de resources/css/app.css: --pastel-*, bg-crema, bg-salvia…);
| ningún template ni servicio de PDF debe volver a escribir un hex de marca.
|
| Reglas de uso (ver CLAUDE.md «Solo tema claro» y la responsiva ya rehecha
| resources/views/pdf/responsivas/responsiva.blade.php como referencia):
|   - Encabezado principal de tabla  => 'petroleo' con texto 'blanco'.
|   - Subencabezados / acentos suaves => 'verde' / 'esmeralda'.
|   - 'dorado' SOLO como acento (filetes, bordes de énfasis), nunca de fondo
|     de toda una tabla. NO pintar todo verde.
|   - Estados (semáforo): tonos derivados con contraste suficiente también en
|     escala de grises (texto oscuro sobre fondo claro).
|   - NADA de azul genérico, cian, teal ni lima: esa familia queda retirada.
|
| Los blades leen estos tokens con config('pdf_identidad.<token>') y los
| imprimen como hex literal dentro de su <style>, de modo que el resultado
| es idéntico en Chrome/Browsershot y en DomPDF (DomPDF no soporta var()).
|
*/

return [

    // --- Identidad institucional ---
    'petroleo' => '#315B59',          // primario
    'petroleo_oscuro' => '#284B49',
    'verde' => '#3F7558',
    'esmeralda' => '#6E9B8F',
    'dorado' => '#C7A66B',            // acento
    'bronce' => '#A98252',

    // --- Superficies y neutros de marca ---
    'blanco' => '#FFFFFF',
    'crema' => '#FBF8F2',             // fondo de página / cajas
    'superficie' => '#FFFDF9',
    'salvia' => '#EEF3EF',            // chips / encabezados suaves
    'fondo_suave' => '#F3F7F3',       // bandas y bloques tenues
    'fondo_total' => '#F5EEDF',       // fila de totales (crema-dorado)
    'texto' => '#303A38',
    'texto_suave' => '#5F6F69',       // metadatos, etiquetas
    'texto_tenue' => '#8C9A94',       // notas, folios, placeholders
    'borde' => '#E7DED1',
    'borde_claro' => '#E0E7E2',

    // --- Estados / semáforo (contraste suficiente en escala de grises) ---
    'exito' => '#3F7558',             // = verde institucional
    'exito_suave' => '#DCE8DF',
    'exito_texto' => '#2F5D46',
    'alerta' => '#C7A66B',            // = dorado institucional
    'alerta_suave' => '#F3E3B8',
    'alerta_texto' => '#8A6A3A',
    'peligro' => '#9B4A41',
    'peligro_suave' => '#EECBC7',
    'peligro_texto' => '#9B4A41',

    // --- Secuencia categórica para gráficas de reporte (ChartRenderer) ---
    // Ordenada por lustre distinto para que las series se distingan también
    // impresas en escala de grises.
    'grafica' => ['#315B59', '#C7A66B', '#6E9B8F', '#A98252', '#3F7558'],

];
