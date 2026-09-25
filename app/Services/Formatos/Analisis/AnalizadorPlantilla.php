<?php

namespace App\Services\Formatos\Analisis;

use App\Enums\TipoArchivoFormato;

/**
 * Extrae texto y su posición de un archivo de plantilla oficial. Cada tipo
 * de archivo (PDF con texto, imagen/escaneo con OCR, Word) tiene su
 * implementación; el sistema NUNCA depende de que el análisis funcione:
 * si no se detecta nada, el mapeo manual en el editor sigue completo.
 *
 * Coordenadas en milímetros, origen arriba-izquierda (igual que el editor
 * y el renderizador).
 *
 * @phpstan-type Bloque array{pagina: int, texto: string, x: float, y: float, ancho: float, alto: float, confianza: float}
 * @phpstan-type Extraccion array{metodo: string, bloques: list<Bloque>, placeholders: list<string>, mensajes: list<string>, posiciones_aproximadas: bool}
 */
interface AnalizadorPlantilla
{
    public function soporta(TipoArchivoFormato $tipo): bool;

    /**
     * @param  string  $rutaLocal  Archivo fuente ya validado, en disco local.
     * @param  list<array{numero: int, ancho: float, alto: float}>  $paginas  Geometría del PDF base.
     * @return Extraccion
     */
    public function extraer(string $rutaLocal, array $paginas): array;
}
