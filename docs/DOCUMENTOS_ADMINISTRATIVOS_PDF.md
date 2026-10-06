# Documentos administrativos (HTML → PDF)

Recibo de nómina, finiquito, comprobante de solicitud y constancia laboral se generan en HTML y se imprimen a PDF. Su **diseño** se administra en la web; sus **datos** los calcula siempre el proceso de negocio.

## Dónde se administra

**Administración → Documentos maestros**, en tres secciones:

1. **Plantillas jurídicas**: contratos, convenios, etc. (DOCX / PDF overlay, sin cambios: ver `docs/MOTOR_DOCUMENTOS_MAESTROS.md`).
2. **Documentos administrativos**: Recibo de nómina, Finiquito, Comprobante de solicitud, Constancia laboral.
3. **Fondos y recursos**: fondos de página, logos, sellos, marcas de agua e imágenes (`DocumentAsset`, versionados por SHA-256).

Cada familia administrativa muestra: estado, versión activa, motor, documentos generados, **Editar diseño**, **Vista previa**, **Historial** y **Activar versión**.

## Datos vs. presentación

| Lo decide el proceso (nunca la plantilla) | Lo decide el diseño |
|---|---|
| `ReciboNominaService`: conceptos, percepciones, deducciones, totales, neto, periodo, fecha de pago, folio | Página (carta/A4, orientación, márgenes) |
| `FiniquitoService`: desglose y montos del cálculo | Tipografía (fuente, tamaño, interlineado, color, peso) |
| `SolicitudInterna`: folio, periodo, días elegidos, quién autorizó | Párrafo (sangrías, primera línea, espacio antes/después, alineación) |
| Datos laborales del colaborador | Encabezado (logo, tamaño, posición, altura, marca), pie (texto, numeración, distancia) |
| | Fondo (imagen, primera página / todas, contain/cover/stretch, opacidad, posición, área segura) |
| | Tablas (fuente, relleno, borde, encabezado, repetir encabezado, no cortar filas, filas alternadas) |
| | Firmas (bloques, ancho de línea, separación, posición), secciones visibles, títulos, divisores, colores |
| | Textos: título, subtítulo, leyenda, nota (admiten `{{ campo }}`) |

Las tablas de conceptos son bloques fijos (no sustitución de texto). `DisenoAdministrativoService::normalizar()` descarta cualquier clave que no sea de diseño y acota cada valor a un rango seguro.

## Versionado

- Se edita un **borrador**; la versión **activa** sigue generando documentos hasta que el borrador se activa. Una versión activa nunca se edita; la anterior pasa a **archivada** (reactivable).
- Cada PDF generado guarda en `generated_documents`: `master_familia = administrativo.{familia}`, `master_version`, `master_hash` (diseño + SHA-256 de recursos), `conversion_engine` (motor real) y `layout_snapshot` (diseño completo, recursos con id y SHA-256, motor configurado y real, fecha y usuario). **Cambiar el diseño hoy no altera ningún PDF histórico.**
- Sin versiones, la familia usa el diseño de fábrica (equivalente al formato anterior del sistema) y su versión es `0`.
- Auditoría: `plantilla_administrativa_borrador`, `_diseno` (antes/después, cambio de fondo), `_activada`, `_descartada`; la generación queda en `documento_generado`.

## Motores

| Documento | Motor |
|---|---|
| Contratos, convenios, renuncias… (plantillas jurídicas) | DOCX (Word/LibreOffice → PDF) o PDF overlay sobre el original de Jurídico — **sin cambios** |
| Formatos oficiales con overlay (vacaciones, baja, finiquito oficial si está configurado) | PDF overlay |
| Recibo de nómina, finiquito (sin formato oficial), comprobante de solicitud, constancia laboral | HTML + **Chrome (Browsershot)** por defecto, o **DomPDF** si la versión lo elige |

`App\Services\Pdf\PdfRendererInterface` con `BrowsershotRenderer` y `DomPdfRenderer`; `PdfRendererFactory` elige por versión. Si Chrome falla, el error se reporta; **solo** cae a DomPDF si `PDF_FALLBACK_DOMPDF=true`.

## `.env`

```dotenv
PDF_RENDERER=browsershot          # motor por defecto (browsershot | dompdf)
PDF_FALLBACK_DOMPDF=false         # true = si Chrome falla, usar DomPDF
BROWSERSHOT_NODE_BINARY=/usr/bin/node
BROWSERSHOT_NPM_BINARY=/usr/bin/npm
BROWSERSHOT_CHROME_PATH=/usr/bin/chromium-browser   # o /usr/bin/google-chrome
BROWSERSHOT_NO_SANDBOX=true       # PHP-FPM como www-data
BROWSERSHOT_TIMEOUT=60
# BROWSERSHOT_NODE_MODULES_PATH=/var/www/people/node_modules   (por defecto, el del proyecto)
```

Vacíos = se buscan en el `PATH` del proceso.

## Servidor (Ubuntu)

```bash
sudo apt-get install -y nodejs npm chromium-browser \
  fonts-liberation fonts-dejavu-core libnss3 libatk-bridge2.0-0 libgbm1 libxkbcommon0 libasound2t64
# (en Ubuntu 22.04 el paquete es libasound2; si chromium-browser es snap, usa Google Chrome .deb)
cd /var/www/people && PUPPETEER_SKIP_DOWNLOAD=1 npm ci
php artisan people:diagnostico-pdf
```

`people:diagnostico-pdf` muestra las rutas detectadas y hace una impresión real de prueba con cada motor (no guarda nada).
