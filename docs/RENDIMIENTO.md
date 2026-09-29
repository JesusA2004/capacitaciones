# Rendimiento del portal web

Cómo medir (no optimizar a ciegas) y qué se corrigió en el cierre del
2026-09-28, cuando producción reportó lentitud al abrir Dashboard, Empresas,
Usuarios, Sucursales, Departamentos y otros módulos.

## Medir: `people:perfilar` (solo local/testing)

```bash
php artisan people:perfilar                         # 21 pantallas principales, 3 corridas c/u (mediana)
php artisan people:perfilar --ruta=dashboard --ruta=administracion.usuarios.index --detalle
php artisan people:perfilar --email=rh.admin@mrlana.test
```

Ejecuta cada ruta GET dentro del mismo proceso como un usuario real y reporta,
de la más lenta a la más rápida: HTTP, **ms totales**, **número de consultas**,
**ms en SQL**, **consultas duplicadas** (mismo SQL y bindings) y **payload
Inertia** (KB del objeto de página). `--detalle` lista las consultas más
repetidas de cada ruta (así se encuentran los N+1). Se niega a correr en
producción (`App\Console\Commands\PerfilarRutasCommand`,
`App\Support\Perfilado\PerfiladorConsultas` sobre `DB::listen`). No hay
Debugbar ni nada público.

Para medir con volumen parecido a producción, usar una base aparte (nunca la
de desarrollo): `DB_DATABASE=capacitaciones_perf php artisan migrate --seed`,
cargar volumen y correr el perfilador con la misma variable. Para números
comparables con producción, sin Xdebug y con OPcache:
`php -d xdebug.mode=off -d opcache.enable_cli=1 artisan people:perfilar`.

## Resultados (2026-09-28)

Base de perfilado con ~2,700 colaboradores, ~2,500 cuentas, ~26,000
documentos de expediente, ~2,800 movimientos, ~850 solicitudes y 400
candidatos. Sin Xdebug, OPcache activo, super_admin (alcance global).
Mismo equipo y misma base para antes y después.

| Pantalla | Antes ms / consultas | Después ms / consultas | Causa corregida |
|---|---|---|---|
| Dashboard | 7,598 / 5,262 | 1,547 / 50 | N+1 de avance de expediente por colaborador; conteos cargando colecciones completas; 12 `count()` de tendencia; aniversarios con Carbon por persona |
| Organigrama | 2,668 / 34 | 551 / 34 | `Collection::where()` sobre modelos Eloquent por cada persona (O(n²)) |
| Cumpleaños | 751 / 33 | 273 / 33 | se hidrataba toda la plantilla para ventanas de 30 días |
| Aniversarios | 643 / 27 | 161 / 28 | ídem |
| Usuarios | 141 / 14 | 78 / 12 | catálogo de colaboradores sin cuenta viajaba en cada visita; 2 conteos → 1 |
| Sucursales | 134 / 18 | 71 / 15 | headcount de TODA la plantilla para pintar 15 filas; todas las cuentas como responsables; 3 conteos → 1 (payload 159 → 14 KB) |
| Roles y permisos | 94 / 9 | 68 / 9 | ~1,300 modelos Permission hidratados solo para leer su nombre |
| Expedientes | 59 / 42 | 45 / 17 | N+1 de avance de expediente |
| Empresas | 70 / 12 | 29 / 10 | conteo de colaboradores de todas las empresas; 3 conteos → 1 |
| Departamentos | 40 / 11 | 29 / 9 | 3 conteos → 1 |
| Puestos | 33 / 13 | 28 / 11 | 3 conteos → 1 |

Nota honesta sobre el SQL local: en este equipo un `COUNT(*)` simple sobre
26,000 filas tarda ~170 ms (MariaDB de WAMP), así que los "ms en SQL" locales
están inflados; el número de consultas y el tiempo de PHP son la señal
confiable. En producción el SQL debería ser bastante más rápido.

Sin cambios (siguientes candidatos, ya medidos): Solicitudes RH (~280 ms,
payload 637 KB) y Candidatos (payload 388 KB) mandan listas grandes al
frontend; Reportes (~220 ms).

## Reglas que quedaron

- Avance de expediente de muchos colaboradores: `ExpedienteService::resumenesCompletitud()`
  (una consulta por 1,000 colaboradores, filas planas) — nunca
  `resumenCompletitud()` dentro de un loop. Ambas usan la misma regla
  (`ProgresoExpediente::calcularDesdeEstados()`).
- Conteos para tarjetas: agregados SQL (`EstadisticasActivoInactivo::de()`,
  `selectRaw(... count(*) ...)->groupBy()`), no `->get()->count()`.
- Ventanas de fechas de celebraciones: `FechasCelebracion::limitarAMesesDeVentana()`
  prefiltra en SQL (portable, `whereMonth`) antes del cálculo exacto en PHP.
- Catálogos que solo usa un diálogo cerrado: `Inertia::optional()` + recarga
  parcial al abrirlo (Usuarios, Sucursales).
- Listados nunca consultan el NAS (fotos = URL firmada por `foto_path`, sin
  `exists()`); el NAS solo se toca al abrir, descargar o subir un archivo.
- Gráficas: `VueApexCharts` de `@/lib/apexDiferido` (componente asíncrono),
  nunca `import VueApexCharts from 'vue3-apexcharts'` directo. JS estático
  del Dashboard: 1,676 → 776 KB; Reportes: 1,588 → 689 KB. Las pantallas sin
  gráficas (Empresas, Usuarios…) nunca descargan ApexCharts.
