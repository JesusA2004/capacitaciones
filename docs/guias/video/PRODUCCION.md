# Producción del video tutorial

**MR. LANA PEOPLE — Flujo completo de Recursos Humanos**: 16:9, 1080p, alrededor de 14 minutos, en español de México neutro.

## Qué ya está listo en este repositorio

| Pieza | Archivo |
|---|---|
| Video armado con capturas reales y voz sintética de referencia | `MR_LANA_PEOPLE_GUIA_COMPLETA.mp4` (también se sirve en Ayuda → Tutorial en video) |
| Seis videos cortos (1 a 3 min) | `cortos/*.mp4` |
| Miniatura | `miniatura.png` |
| Guion palabra por palabra | [GUION_VIDEO_COMPLETO.md](GUION_VIDEO_COMPLETO.md) |
| Storyboard escena por escena | [STORYBOARD.md](STORYBOARD.md) |
| Capturas necesarias y capturas reales | [CAPTURAS_NECESARIAS.md](CAPTURAS_NECESARIAS.md) · [capturas/](capturas/) |
| Transcripción, subtítulos y capítulos | [TRANSCRIPCION.md](TRANSCRIPCION.md) · `subtitulos.srt` · `subtitulos.vtt` · `capitulos.txt` |
| Guiones de los videos cortos | [MINI_VIDEOS.md](MINI_VIDEOS.md) |

Todo sale de **una sola fuente**: `resources/js/lib/guia/video.ts`. Si cambias una frase del guion, vuelves a generar y todo cuadra.

## La voz incluida es solo de referencia

El MP4 trae una voz sintética (espeak-ng + MBROLA, voz mexicana). Sirve para revisar el ritmo, los cortes y los subtítulos, pero no sustituye a un locutor. Para la versión final:

1. Graba la locución leyendo [GUION_VIDEO_COMPLETO.md](GUION_VIDEO_COMPLETO.md) con un tono claro, cálido y pausado, a unas 130 palabras por minuto. Cada escena empieza **1 segundo después** de su marca de tiempo.
2. Exporta un solo archivo WAV mono de 44.1 kHz, con la misma duración del video.
3. Compón el video con esa voz: `GUIA_VOZ=/ruta/locucion.wav node scripts/guia/componer-video.mjs --solo-completo`.

Si la voz humana va más rápida o más lenta, ajusta `PALABRAS_POR_SEGUNDO` en `video.ts` y vuelve a generar los documentos y el video, para que los subtítulos sigan alineados.

## Cómo se produce de punta a punta

```bash
# 1) Base local con datos ficticios (ver ../DATOS_DEMO.md) y servidor
php artisan migrate:fresh --seed && npm run build && php artisan serve --port=8123

# 2) Capturas reales (1920×1080) + capturas por artículo para la guía web
node scripts/guia/grabar-capturas.mjs

# 3) Guion, storyboard, subtítulos y capítulos desde la fuente única
npm run guia:docs

# 4) Video completo, miniatura y videos cortos
node scripts/guia/componer-video.mjs
```

Para publicarlo en el servidor, el video y los subtítulos se copian a `public/guia/video/`: `MR_LANA_PEOPLE_GUIA_COMPLETA.mp4` y `subtitulos.vtt`. En cuanto el archivo existe, la página Ayuda → Tutorial en video lo muestra con capítulos navegables.

```bash
mkdir -p public/guia/video
cp docs/guias/video/MR_LANA_PEOPLE_GUIA_COMPLETA.mp4 docs/guias/video/subtitulos.vtt public/guia/video/
```

## Orden de edición (para una versión editada a mano)

1. Portada (5 s de música sola) y luego la escena 1.
2. Escenas en el orden del storyboard. Corte seco entre pantallas del mismo módulo y desliz o fundido corto (0.3 s) al cambiar de capítulo.
3. Acercamientos (zoom) donde lo indique el storyboard, siempre de 1.0× a 1.3× como máximo y suaves.
4. Etiqueta inferior con «Capítulo · texto en pantalla», con la tipografía Inter sobre fondo marfil.
5. Música discreta a −28 dB bajo la voz. Sin efectos de sonido en los clics.
6. Cierre con «¿Dudas? Abre Ayuda» (escena final) y 3 s de fundido a crema.
7. Subtítulos `subtitulos.srt` como pista seleccionable (no quemados).

## Privacidad al grabar

- Solo local o testing, con los datos de [DATOS_DEMO.md](../DATOS_DEMO.md). Nunca contra producción.
- Nunca mostrar contraseñas, tokens, correos reales, CURP, RFC, NSS, teléfonos, domicilios ni rutas del NAS.
- Los códigos QR y los PDF siempre van difuminados. El script lo hace solo; en una grabación manual se difuminan en edición.
- Antes de publicar, revisa cuadro por cuadro que no haya notificaciones del sistema operativo, pestañas del navegador ni datos fuera del escenario demo.

## Descripción para publicar

> **MR. LANA PEOPLE — Flujo completo de Recursos Humanos**
>
> Recorre de principio a fin cómo se cubre una vacante real y qué pasa después en la vida laboral del colaborador:
>
> - Plantilla autorizada, vacantes y campañas.
> - Candidatos: filtro de RH, entrevista, psicométricos, socioeconómico, referencias y autorización.
> - Contratación con QR, registro en la app, expediente y contratos.
> - Onboarding, evaluación de la capacitación inicial y contrato indeterminado.
> - Solicitudes, nómina, avisos, baja con finiquito y reingreso.
>
> Grabado con datos ficticios de demostración. Los capítulos están en la descripción (`capitulos.txt`) y los subtítulos en español vienen incluidos.

## Miniatura

`miniatura.png` es la portada real del video con el sello de duración. Si se diseña otra, conserva la paleta de PEOPLE (crema, marfil, salvia, olivo claro, oro suave y petróleo para el texto), sin fotos de personas reales.
