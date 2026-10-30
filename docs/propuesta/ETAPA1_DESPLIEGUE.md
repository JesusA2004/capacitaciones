# MR. LANA PEOPLE — Etapa 1 · Procedimiento de despliegue y reversión

> Este documento no autoriza el despliegue. La aprobación es del responsable del proyecto y de RH.
> Complementa `docs/DEPLOY.md`. Nunca usar `migrate:fresh`, `migrate:refresh` ni `db:wipe` en el VPS.
> **Estado al redactarlo: hay bloqueantes abiertos.** Ver `ETAPA1_CIERRE_TECNICO.md` § Bloqueantes.

## 1. Antes de la ventana

| Paso | Comando o acción | Criterio para seguir |
|---|---|---|
| SHA aprobado | Anotar SHA de backend y app; `tests` y `Mobile CI` en verde sobre esos SHA | Sin SHA verde no se despliega |
| App | No publicar ningún build que incluya `c411241` o posterior antes de desplegar el backend | Las rutas de agenda no existen en el backend actual |
| Motor de tablas | `SELECT engine, COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() GROUP BY engine;` | Solo InnoDB. Con MyISAM las transacciones no deshacen nada y las llaves foráneas no se crean: **abortar** y convertir por separado |
| Versiones | `php -v`, `mariadb --version`, `node -v` | PHP 8.3 u 8.4. La matriz de CI no cubre 8.5 |
| Espacio | `df -h` en disco y en `NAS_ROOT` | Espacio libre mayor a dos respaldos completos |
| NAS | Crear, leer y borrar un archivo de prueba propio dentro de `NAS_ROOT` con el usuario del servidor web | Las tres operaciones funcionan |
| Chrome | `php artisan people:diagnostico-pdf` y revisar `BROWSERSHOT_CHROME_PATH` | Genera un PDF. Sin Chrome los reportes y el tabulador muestran error, no usan otro motor |
| Scheduler | `grep schedule:run` en el cron del servidor | El scheduler corre cada minuto |

### Comprobar la migración `2026_10_30_120000` (se corrigió después de escribirse)

Su primera versión ponía en `NULL` `juridico_por` y `juridico_en` en las bajas que convertía. La versión actual conserva esos datos. Corregir el archivo no recupera lo que ya se haya borrado.

```bash
php artisan migrate:status | grep 2026_10_30_120000
```

- **Pendiente:** correrá la versión corregida. No hay nada que recuperar.
- **Ya aplicada:** revisar qué bajas convirtió:

```sql
SELECT baja_id, created_at FROM baja_bitacora WHERE accion = 'conversion_flujo';
```

Si hay renglones, comparar esas bajas contra el respaldo anterior a la migración y restaurar únicamente `juridico_por` y `juridico_en`. Sin renglones, no se perdió nada.

## 2. Respaldos (obligatorios, en este orden)

```bash
mariadb-dump --single-transaction --routines --triggers "$DB_DATABASE" | gzip > people-AAAAMMDD-HHMM.sql.gz
gzip -t people-AAAAMMDD-HHMM.sql.gz
sha256sum people-AAAAMMDD-HHMM.sql.gz > people-AAAAMMDD-HHMM.sql.gz.sha256
```

1. Restaurar el respaldo en una base temporal y comparar conteos de `colaboradores`, `bajas`, `finiquito_calculos` y `solicitudes_internas`. Si no coinciden, **abortar**.
2. Copiar `.env` y la configuración de Nginx y Supervisor a una ubicación restringida. No subirlos al repositorio ni a artefactos.
3. El NAS no cambia de estructura en esta etapa: basta con confirmar su snapshot o respaldo más reciente.
4. Anotar el SHA actual del VPS: `git rev-parse HEAD`.

## 3. Despliegue

```bash
php artisan down --retry=60          # solo durante migraciones y cachés
git fetch origin && git checkout <SHA aprobado>
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
php artisan migrate --pretend        # revisar el SQL antes de aplicarlo
php artisan migrate --force
php artisan people:sincronizar-permisos
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
sudo supervisorctl restart <programas de People>   # nunca "all": el VPS aloja otros proyectos
php artisan up
```

Notas:

- `people:sincronizar-permisos` es obligatorio. Sin él nadie ve Incapacidades, Agenda ni Salarios, y RH auxiliar y Dirección pierden el sueldo del expediente.
- `2026_11_03_100000` retira a Sistemas los permisos de contratos, recibos y finiquitos. Si alguna cuenta los necesita, se asignan de forma expresa en Roles y permisos.
- Después de migrar, `php artisan etapa1:diagnostico` es de solo lectura y sirve como foto de conciliación.
- El scheduler ya incluye `agenda:enviar-recordatorios` (minuto 30 de cada hora, de 7:00 a 12:00, America/Mexico_City). Confirmar con `php artisan schedule:list`.
- Variables nuevas opcionales: `CALENDARIO_DIAS_INHABILES` (fechas separadas por coma, las entrega Jurídico) y `BROWSERSHOT_*` si Chrome no está en la ruta por defecto.

## 4. Pruebas de humo (con cuentas reales, sin crear datos de prueba en producción)

| # | Acción | Esperado |
|---|---|---|
| 1 | Entrar con RH y abrir el Tablero | Carga sin 500; plantilla igual a la del Directorio |
| 2 | Directorio, exportar Excel y PDF | Descargan y las personas activas coinciden |
| 3 | Bajas: las cuatro pestañas | Abren; ninguna acción de Jurídico |
| 4 | Vista previa de un finiquito existente | PDF; nada se guarda |
| 5 | Rotación: las cinco vistas y un PDF | Sin 500; octubre con índice calculado |
| 6 | Incapacidades, Agenda, Salarios y Mis reportes | Abren con RH |
| 7 | Vista previa de PDF en Mis reportes | Lo genera Chrome |
| 8 | Gerente: URL directa de `/organizacion/salarios` y `/rh/bajas/finiquitos` | 403 en ambas |
| 9 | `tail storage/logs/laravel.log` | Sin errores nuevos |
| 10 | `php artisan queue:failed` | Sin trabajos nuevos fallidos |

## 5. Criterios de aborto

Revertir (sección 6) si ocurre cualquiera de estos casos:

- El respaldo no se restaura o sus conteos no coinciden.
- Una migración falla, o cambian los conteos de una tabla existente fuera de `baja_bitacora`, `permissions`, `role_has_permissions`, `causales_rotacion` y `migrations`.
- Cualquier prueba de humo de los puntos 1, 3, 8 o 9 falla.
- Un usuario sin permiso ve sueldos, documentos o expedientes.
- El NAS deja de responder al leer o guardar.

## 6. Reversión: son tres cosas distintas

**Volver al commit anterior no revierte el esquema ni los datos.**

### Código

`git checkout <SHA anterior>`, `composer install --no-dev`, `npm ci && npm run build`, limpiar cachés, reiniciar colas. Las tablas y columnas nuevas no estorban al código anterior, excepto que **no** se debe ejecutar `people:sincronizar-permisos` con un código que no conozca los permisos nuevos.

### Esquema (`php artisan migrate:rollback --step=N`, solo si no hay datos capturados)

Probado en MariaDB 11.4 con InnoDB sobre copia. Resultado por migración:

| Migración | `down()` | Clasificación |
|---|---|---|
| `2026_10_30_120000` bajas sin Jurídico | No hace nada, a propósito | **Solo respaldo** |
| `2026_10_30_130000` indemnización y OT | Quita la columna; las causales OT se quedan | Esquema reversible; datos solo por respaldo |
| `2026_10_31_090000` documentos de salida | Conserva la tabla de avisos si tiene datos; quita columnas | Esquema reversible; quita `tipo_documento` y `clasificacion` capturados |
| `2026_10_31_100000` metas de rotación | Borra la tabla | Reversible; pierde metas capturadas |
| `2026_10_31_110000` incapacidades | Borra tablas | **Con capturas: solo respaldo** |
| `2026_11_01_100000` catálogo y ediciones | Borra tablas y columnas | **Con capturas: solo respaldo** |
| `2026_11_01_200000` tabulador y matriz | Borra tablas | **Con capturas: solo respaldo** |
| `2026_11_02_200000` reportes e históricas | Borra tablas | **Con capturas: solo respaldo** |
| `2026_11_02_300000` agenda | Borra tablas | **Con capturas: solo respaldo** |
| `2026_11_03_100000` Sistemas sin importes | Devuelve los permisos al rol | Reversible |

### Datos

Si ya se capturó información, no hacer rollback de esas tablas:

1. Mantener el esquema y revertir solo el código, o
2. Modo mantenimiento, restaurar el respaldo en base temporal, extraer lo capturado desde la ventana con `SELECT ... INTO OUTFILE`, y restaurar la base. Cualquier captura posterior al respaldo se pierde si no se extrae antes.

Archivos en NAS: un rollback de base no los borra. Quedan sin registro que los apunte. Se conservan y se listan para RH; no se borran.

## 7. Piloto controlado (antes de abrir a todos)

Participan RH, un gerente, un subgerente y un usuario sin permisos especiales. Cada escenario se marca solo si se verificó el resultado, no porque la pantalla cargue.

| Escenario | Quién | Qué verificar |
|---|---|---|
| Alta y plantilla | RH | Ingreso, sucursal, puesto, plaza ocupada y aparición en reportes |
| Incapacidad | RH | Registrar, certificado en NAS, subsecuente, historial y efecto en borrador del recibo. Requiere la regla de días pagados aprobada |
| Baja | RH | Causal, documento, firmado en NAS, finiquito y conteo en rotación |
| Salarios | RH y usuario sin permiso | Consulta del tabulador; denegación por URL y API |
| Reportes | RH | Filtros y exportaciones contra un conteo manual |
| Agenda | RH, gerente, subgerente | Cita web, push en Android real, resultado desde la app, candidato e indicadores |

El piloto se aborta si aparece una fuga de datos, una pérdida de documento o un importe incorrecto en un recibo.
