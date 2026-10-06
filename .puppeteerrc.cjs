const { join } = require('path');

/**
 * Sin esto, Puppeteer descarga chrome-headless-shell bajo el HOME del
 * usuario que corrió `npm ci`/`npm install` (p. ej. root en el deploy) y
 * luego PHP-FPM (www-data, HOME distinto o sin HOME) no lo encuentra ahí:
 * "Could not find chrome-headless-shell" aunque la instalación fue
 * correcta. Fijar la carpeta dentro del proyecto hace que AMBOS usuarios
 * lean la misma ruta. Ver docs/DOCUMENTOS_ADMINISTRATIVOS_PDF.md.
 *
 * @type {import("puppeteer").Configuration}
 */
module.exports = {
    cacheDirectory: join(__dirname, '.cache', 'puppeteer'),
};
