# Migración inicial de colaboradores y expedientes históricos

Carga la base general de colaboradores (Excel) y vincula los expedientes que ya existían antes del sistema. RH → Expedientes → **«Migración inicial de expedientes»** (permiso `expedientes.migrar`: `super_admin` y `rh_admin`), o por consola con el mismo servicio.

Flujo obligatorio: **Subir Excel → Analizar (no modifica nada) → Revisar → Resolver conflictos → Confirmar → Ejecutar (Job en cola) → Resultado + accesos**.

Código: `App\Services\Expedientes\MigracionInicial\*`:

| Clase | Responsabilidad |
|---|---|
| `LectorExcelMigracion` | Lee la hoja `BASE_GENERAL` (encabezados por nombre, con sinónimos) |
| `Normalizador` | Normaliza para comparar (mayúsculas, sin acentos, sin fechas ni «(1)»), CURP/RFC, fechas, similitud de nombres |
| `InventarioNasHistorico` | Recorre el NAS en solo lectura (whitelist de sucursales) |
| `PlanificadorMigracion` | Identidad de personas, match de carpetas y plan (dry run) |
| `EjecutorMigracion` | Ejecuta con checkpoints, cuentas de acceso y manifiesto |
| `ExpedientesInitialMigrationService` | Fachada para la UI y Artisan |

## 1. Excel

Hoja **`BASE_GENERAL`**. La hoja `CONTACTOS_SIN_MATCH` **no se lee**. Sus 5 personas también quedan excluidas por nombre (`config/expedientes.php → personas_excluidas`) hasta que RH las revise.

| Columna (cualquiera de estos encabezados) | Destino |
|---|---|
| Clave | `colaboradores.clave_legacy`: solo referencia, **no es única** (la 130 está repetida) |
| Nombre / Apellido paterno / Apellido materno / Nombre completo | `name`, `apellidos` |
| Correo electrónico | `correo_personal` y usuario de la cuenta de acceso |
| Teléfono / Teléfono personal | `telefono` (personal) y `telefono_corporativo` |
| RFC, CURP, NSS / Afiliación IMSS | `rfc`, `curp`, `nss` |
| Fecha de nacimiento, Fecha de alta | `fecha_nacimiento`, `fecha_ingreso` |
| Sexo | `genero`. «M» sola es ambigua: se toma de la CURP |
| Estatus laboral | `estatus`, y el texto original va en `estatus_origen` (ver abajo) |
| Sucursal | Sucursal de la whitelist (aliases explícitos) |
| Puesto | Catálogo `puestos`: match exacto o alias explícito (`alias_puestos`); **sin match = conflicto** |
| Contacto de emergencia, Parentesco, Teléfono del contacto, Dirección del contacto | `contacto_emergencia_*` |
| Condición médica, Alergias | `colaborador_datos_medicos` (**cifrado**; solo se ve con `expedientes.datos_medicos.ver`) |

Una celda vacía queda NULL. Un vacío del Excel **nunca borra** un dato que ya existe.

**Estatus:**
- Alta, Activo, Reingreso, Incapacidad, Permiso y Vacaciones → **activo**. «Reingreso» dice cómo entró; la incapacidad no es baja.
- Baja, Renuncia, Despido, Inactivo y Liquidado → **baja**.
- Otro texto → activo, con advertencia.

## 2. Identidad (nunca por Clave)

1. CURP válida y única en el Excel.
2. RFC con homoclave, único en el Excel.
3. Correo, único en el Excel.
4. Nombre normalizado + sucursal + fecha de nacimiento.

La operación es **conflicto** (no se aplica) en estos casos:
- CURP repetida en el Excel.
- Una llave que coincide con varios colaboradores.
- Llaves que apuntan a personas distintas.
- Dos filas que caen en el mismo colaborador.
- Sucursal fuera de la whitelist.
- Puesto inexistente.
- Nombre incompleto.

Reimportar el mismo Excel da «sin cambios»: no se duplica nada.

Número de empleado: `EMP-XXXX` consecutivo (`NumeroEmpleadoService`, la misma regla que el alta). La Clave nunca se convierte en número de empleado.

## 3. Sucursales

**Whitelist** (`sucursales_permitidas`), todas de la empresa **Mr. Lana**: Atlacomulco, Ixtlahuaca, Tula, Cuernavaca, Miacatlán, Atlixco, San Luis Potosí, Huamantla, Tlaxcala, Córdoba, Orizaba y **Corporativo** (Subida del Club 114, Cuernavaca, Morelos, C.P. 62260).

- La sucursal se llama **Tula**; Tula de Allende es la ciudad.
- **Excluidas** (no se buscan ni se reportan): Aguascalientes, Tulancingo, Colombia.
- **Aliases** (solo typos de la whitelist): `ATLACOMULC → Atlacomulco`, `HUMANTLA → Huamantla`, `SAN LUIS P`/`SLP → San Luis Potosí`, `TULA DE ALLENDE → Tula`.
- **«MR. LANA» no es Corporativo.** Esa carpeta, igual que Pachuca, Veracruz, Tenango o San Juan del Río, se reporta como *no autorizada* y no se vincula.
- Nunca se crea una sucursal sola.

## 4. Origen de los expedientes

`EXPEDIENTES_MIGRACION_ORIGEN`:

- **`sitio`** (por defecto): los PDFs ya están en su lugar definitivo, `expedientes/Mr. Lana/{SUCURSAL}/{CARPETA}/*.pdf` en el disco `nas`. Con `NAS_ROOT` apuntando a `MrLanaPeople`, físicamente queda en `MrLanaPeople/expedientes/Mr. Lana/…`.
  - Solo se calcula el SHA-256 y se registra en BD.
  - **No se copia, no se renombra y no se borra.**
  - Esa carpeta pasa a ser el `expediente_storage_path` del colaborador, así que sus documentos nuevos se guardan ahí también.
  - Solo se toman los PDF del primer nivel de la carpeta que el sistema no tenga ya registrados.
- **`legacy`**: copia desde `EXPEDIENTES_LEGACY_DISK` (`nas_legacy`, `NAS_LEGACY_ROOT`) en `EXPEDIENTES_LEGACY_RUTA` (`RH/Martha/EXPEDIENTES DIGITALES`) a `{expediente}/Historico/Expediente historico unificado.pdf`. El proceso es copiar → verificar SHA-256 → registrar en BD → (solo en modo *mover*) borrar el origen.

## 5. Match de carpetas

Para comparar se usan los tokens del nombre de la carpeta **y** del PDF, sin fechas, «(1)», números ni palabras vacías.

| Tipo | Regla | ¿Automático? |
|---|---|---|
| Match exacto | Los mismos tokens | Sí |
| Match alto | Un nombre contenido en el otro con ≥ 3 palabras (p. ej. «ALBERTO CARLOS BUENO» ⊂ «JOSE ALBERTO CARLOS BUENO»), o una letra de diferencia en palabras largas | Sí, solo si es de la **misma sucursal** y sin competencia |
| Revisión manual | Parecido, de otra sucursal, o varias carpetas/personas compiten | **No**: RH elige en la tabla |
| Sin match | — | — |

## 6. Expediente histórico (PDF único)

- El PDF viejo trae todos los documentos juntos. Se conserva **íntegro**: sin cortarlo, sin OCR, sin generar 14 archivos y sin copias.
- Si hay varios PDFs, se conservan todos.
- Se guarda en la tabla `expedientes_historicos`. **No** es un tipo de documento, **no** cuenta en el checklist y **no** marca ninguno de los 14 como entregado.
- En el expediente aparece en la pestaña Documentos, sección **«Expediente histórico»**, con Ver y Descargar.

**Checklist** (exactamente estos 14, `document_types.orden`):
1. Solicitud de empleo
2. 2 fotografías
3. Acta de nacimiento
4. Identificación oficial
5. NSS
6. CURP
7. Constancia de situación fiscal (RFC)
8. Comprobante de domicilio
9. Comprobante de estudios
10. 2 cartas de recomendación
11. Datos bancarios
12. Contrato laboral
13. Contrato / carta de confidencialidad
14. Contrato de No Competencia

Los demás tipos siguen existiendo para otros módulos, pero ya no son obligatorios.

## 7. Expedientes del NAS que no vienen en el Excel

Normalmente son bajas. **Nunca se borran.** La acción propuesta, que se puede cambiar en la pantalla, es una de estas:

- **Histórico (baja)**: el nombre de la carpeta es claro (≥ 3 palabras). Se crea un colaborador inactivo con **solo** el nombre y la sucursal (`importado_de = nas_historico`), sin CURP, correo ni nada inventado.
- **Vincular**: la carpeta coincide con un colaborador que ya existe.
- **Pendiente de vincular**: el caso es dudoso. Se registra sin persona y después RH lo vincula (`POST /rh/expedientes/historico/{id}/vincular`), por ejemplo en un reingreso.

Como el histórico es un colaborador real (de baja), un reingreso lo recupera por el flujo normal sin crear otro expediente.

## 8. Cuentas de acceso

Al terminar, cada colaborador **activo** procesado sin cuenta recibe una:

- Usuario = su **correo real del Excel** (el login es por correo).
- Contraseña temporal aleatoria (`Lana-XXXX-9999`).
- Rol `colaborador`.

Sin correo **no se inventa uno**: queda en la lista como «sin correo» para darle acceso después. Si el correo ya lo usa otra cuenta, se marca para revisión.

La lista (persona, sucursal, puesto, usuario, contraseña) se guarda **cifrada** (`storage/app/private/migraciones-expedientes/credenciales-{id}.enc`). Se ve en el resultado y se descarga en CSV solo con `expedientes.migrar`.

## 9. Ejecución, auditoría y reintento

- La ejecución es un **Job en cola** (`AplicarMigracionExpedientesJob`, requiere `queue:work`). La pantalla muestra una pantalla de carga con la etapa y el avance. Con la cola `database`, `DB_QUEUE_RETRY_AFTER` debe ser mayor que el timeout del job (7200 s).
- Lock: no puede haber dos ejecuciones a la vez.
- Cada corrida queda en `migraciones_expedientes`: usuario, fecha, archivo, SHA-256 del Excel, totales, plan, decisiones, resultado y manifiesto (`storage/app/private/expedientes-migrations/{fecha}-inicial-{id}.json`, que se escribe de forma incremental).
- **Nunca se sobrescribe**: si el destino existe con el mismo SHA-256 es *duplicado*; si existe con otro SHA-256 es *conflicto* y no se toca.
- Un fallo a la mitad no deja una fila apuntando a un archivo inexistente (la copia nueva se borra) ni un origen borrado antes de registrar.
- **Reintentar** = volver a ejecutar el mismo análisis o subir el mismo Excel. Lo ya hecho se marca duplicado o sin cambios.
- Reporte CSV del plan y el resultado desde la pantalla.

## 10. Consola

```bash
php artisan expedientes:importar-inicial base.xlsx                      # dry run
php artisan expedientes:importar-inicial base.xlsx --apply --confirm    # ejecutar
php artisan expedientes:importar-inicial base.xlsx --apply --confirm --mover --usuario=rh@mrlana.com  # (solo origen legacy)
```

## 11. BD inicial de producción

`php artisan migrate --seed` deja **solo** los catálogos: roles/permisos, empresa Mr. Lana, las 12 sucursales de la whitelist, departamentos, puestos y tipos de documento. Los datos demo (colaboradores y cuentas) solo se siembran en `local`/`testing` o con `SEED_DEMO_DATA=true`, así que nunca se mezclan con la importación real.

Pruebas: `tests/Feature/Expedientes/MigracionInicialExpedientesTest.php`.
