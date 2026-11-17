# Recibos de nómina quincenales

Recibo **interno** (no fiscal: no timbra, no calcula ISR/IMSS) que se entrega a cada colaborador por periodo (semanal: lunes → domingo; quincenal: ver abajo).

> **Regla vigente: preparar ≠ publicar.** Todo recibo nace como borrador dentro de un **lote** (`App\Services\Nomina\LoteNominaService`): no es visible para el colaborador, no hay notificación ni push. Solo cuando RH pulsa **«Emitir N recibos»** en el lote se publica, se congela el PDF en el expediente y se avisa. Guía de uso: `docs/guias/RH.md` (capítulo Nómina).

## Quincenas

| Quincena | Periodo | Pago | Clave |
|---|---|---|---|
| 1.ª | del 1 al 15 | día 15 | `2026-10-1` |
| 2.ª | del 16 al último día del mes | último día | `2026-10-2` |

## Ciclo automático

`nomina:procesar-quincenas` corre diario a las 06:15 (CDMX, `routes/console.php`). Es idempotente:

1. **Preparar** — cuando faltan `NOMINA_DIAS_ANTICIPACION` días (3 por defecto) o menos para el pago, crea un recibo **borrador** por colaborador activo con sueldo:
   - concepto «Sueldo quincenal» = sueldo mensual / 2;
   - si ingresó a media quincena, proporcional: sueldo mensual / 30 × días trabajados;
   - quien no tiene sueldo capturado **no** recibe recibo y se reporta (nunca se inventa un monto);
   - a quien ya tiene recibo del periodo no se le duplica.
2. **Emitir** — **apagado por defecto** (`NOMINA_EMISION_AUTOMATICA=false`, `config/nomina.php`): RH revisa el lote y lo emite a mano desde «Recibos de nómina». Solo si alguien activa `NOMINA_EMISION_AUTOMATICA=true`, el comando emite los borradores cuya fecha de pago ya llegó (PDF archivado en el expediente + aviso), a nombre del primer usuario activo con `nomina.recibos.crear`.

El colaborador **solo ve recibos emitidos** (`ReciboNominaPolicy::ver`, `ReciboNominaService::delColaborador`).

## Lotes — `/rh/nomina/lotes` (menú «Recibos de nómina», permiso `nomina.recibos.ver`)

Pantalla principal: **Preparar** (desde sueldos o importando el archivo de RH) → **Revisar** (esperados, preparados, errores, advertencias, percepciones, deducciones, neto; PDF real recibo por recibo con Anterior/Siguiente) → **Emitir** (publica + aviso). «Cancelar lote» con motivo cancela sus recibos y permite volver a preparar el periodo.

**Descargar todos para imprimir** (solo lote **emitido**): `GET /rh/nomina/lotes/{lote}/imprimir` (API: `GET /api/v1/rh/nomina/lotes/{lote}/imprimir`), permiso `nomina.recibos.ver`. Un solo PDF con los PDFs **congelados** al emitir (no se regenera nada; `CombinadorPdfRecibos`, FPDI), orden **sucursal → nombre del colaborador**, nombre `recibos_nomina_<periodicidad>_<inicio>_<fin>.pdf`. Si algún recibo no cancelado no tiene PDF (o quedó en borrador por una emisión cortada) se bloquea: «Hay N recibos sin PDF generado.». El PDF compuesto no se guarda; la descarga se audita (`descarga_masiva_recibos`: usuario, lote, cantidad).

## Recibos y descargas — `/rh/nomina` (permiso `nomina.recibos.ver`; botón «Recibos y descargas» del lote)

- Selector de quincena, resumen (total, borradores, emitidos, neto).
- **Preparar lote** ahora (sin esperar al comando): ya no crea recibos sueltos; llama a `LoteNominaService::preparar()` (grupo quincenal = sucursales corporativas, mismo alcance y control de duplicados que «Nuevo lote») y lleva a revisar el lote.
- **Editar uno por uno**: lista completa de conceptos. Si ya estaba emitido, se regenera el PDF (el anterior queda en el historial del expediente) y se audita.
- **Cambiar algo a todos** (o a los seleccionados): agregar/cambiar un concepto (si ya existe con ese nombre, solo cambia el importe — aplicarlo dos veces no lo duplica) o quitarlo.
- **Emitir** borradores (todos o seleccionados) o uno solo.
- **Centro de descarga**: ZIP con un PDF por persona (carpetas por sucursal) o **un solo PDF** para imprimir y recabar firmas de recibido.

### Una sola vía de creación (2026-11-17)

Todo recibo **nuevo** nace dentro de un lote. Antes había tres vías (lotes, «Preparar recibos» del centro y «Generar recibo» manual desde el expediente) y las dos últimas creaban recibos sin lote (`nomina_lote_id` nulo), el manual además emitido al instante:

| Vía | Estado |
|---|---|
| Lotes (`rh.nomina.lotes.store`, comando `nomina:procesar-quincenas`) | **Canónica** (`LoteNominaService`). |
| Centro «Preparar» (`POST rh/nomina/preparar`) | Delega en `LoteNominaService::preparar()`: crea el lote quincenal y redirige a él. |
| Expediente «Generar recibo» (`POST rh/expedientes/{id}/recibos-nomina`) | Retirado de la interfaz (la pestaña «Recibos» solo consulta, descarga y repara PDF, con enlace a «Recibos de nómina»). La ruta se conserva y ya no crea nada: redirige a `/rh/nomina?colaborador=…` con un aviso. La vista previa del formulario se conserva. |
| API `POST /api/v1/rh/colaboradores/{id}/recibos`, `POST /api/v1/rh/recibos/importar`, `POST /api/v1/rh/recibos/quincena/preparar` | **Sin cambios** (los usa la app y versiones viejas; el contrato no se toca). Pendiente: decidir con el negocio y la app si también pasan por lote. |

Folios: `RIN-{id}` (único por id) para el recibo y `NOM-{S|Q}-{inicio}-{aleatorio}` para el lote, con un solo lote vivo por periodo; nunca dos recibos no cancelados del mismo colaborador y periodo (`ReciboNominaService::generar`, con bloqueo). Los recibos existentes no se tocaron. «Rectificación» de un recibo emitido sigue igual (nuevo recibo ligado al original).

## Colaborador

- Web: «Mi recibo» (`/mis-recibos`, modo colaborador) **solo con `features.recibos_colaborador=true`**; por defecto está apagado y el recibo se entrega impreso.
- App: «Recibos» (ya existente, `GET /api/v1/colaborador/recibos`).

## API (misma lógica, `NominaQuincenalService` / `ReciboNominaService`)

| Método | Ruta | Uso |
|---|---|---|
| GET | `/api/v1/rh/recibos/quincena?periodo=` | Resumen de la quincena |
| POST | `/api/v1/rh/recibos/quincena/preparar` | Preparar borradores |
| POST | `/api/v1/rh/recibos/quincena/emitir` | Emitir (todos o `ids`) |
| POST | `/api/v1/rh/recibos/quincena/masivo` | Cambio en bloque |
| PUT | `/api/v1/rh/recibos/{recibo}` | Editar conceptos |
| POST | `/api/v1/rh/recibos/{recibo}/emitir` | Emitir uno |

La app RH muestra el estado (borrador/emitido) y permite «Emitir ahora»; los ajustes de conceptos se hacen en web.

## Configuración (`config/nomina.php`)

```env
NOMINA_EMISION_AUTOMATICA=false   # por defecto: RH emite a mano
NOMINA_DIAS_ANTICIPACION=3
```

Requiere el scheduler de Laravel activo en el servidor (`* * * * * php artisan schedule:run`).

Pruebas: `tests/Feature/Nomina/NominaQuincenalTest.php`.
