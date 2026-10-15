# Flujo operativo y matriz de permisos

Documento generado a partir del código real (no teoría): `database/seeders/RolesYPermisosSeeder.php`, `config/permisos_modulos.php`, `app/Policies/*` y `app/Services/AlcanceOrganizacionalService.php`. Si este documento contradice el código, el código manda — actualiza este archivo, no al revés.

## 1. Flujo completo del colaborador en MR. LANA PEOPLE

### Reclutamiento
```
Vacante autorizada (headcount) → candidato → filtro RH → entrevista gerente
→ psicométricos → socioeconómico → referencias → contratación
```
Servicio: `App\Services\Reclutamiento\CandidatoWorkflowService` (permiso del paso + alcance + organigrama para preautorizar + RH para la autorización final). `App\Policies\CandidatoPolicy` y `VacantePolicy` controlan visibilidad/edición; los avances del pipeline los valida el Service, no la Policy.

### Alta
```
QR/alta digital → colaborador → usuario → expediente → documentos
→ datos faltantes/extracción NAS → contrato (capacitación) → inducción
→ evaluación de inducción → capacitación inicial → periodo de prueba
→ evaluación → validación RH → contrato indeterminado → Laborando
```
Servicios: `AltaColaboradorService`, `OnboardingService`, `DocumentExtractionService` (sugerencias desde documentos del expediente, nunca autoaplicadas), `ContratoLaboralService`, `EvaluacionPeriodoPruebaPolicy`/`Service`.

### Operación
```
Solicitudes internas (permiso, vacaciones unificadas, préstamo, baja)
→ revisión → aprobación/rechazo
```
Un solo motor (`App\Services\Solicitudes\SolicitudesService`) para web y API móvil — nunca se duplica la regla de negocio entre ambas capas. `SolicitudInternaPolicy` bloquea que alguien apruebe/revise su propia solicitud, **salvo `super_admin`** (ver §3).

### Disciplina
```
Acta → dos testigos (buscador de colaboradores, nunca texto libre)
→ manifestaciones → generar formato oficial → imprimir/firmar → archivar
```
`App\Services\Actas\ActaService` + motor documental (`documentos-proceso`, tipo `acta`). `GuardarActaRequest` exige que cada testigo venga de un colaborador real (`colaborador_id`), sea distinto del otro testigo y distinto del colaborador del acta.

### Nómina
```
Clasificación por sucursal.es_corporativo:
  Sucursales  → SEMANAL   (lunes→domingo)
  Corporativo → QUINCENAL (1-15, 16-fin de mes)
→ preparar (borrador) → recalcular si sigue en borrador → revisar
→ emitir (congela PDF, avisa) → RH descarga/imprime/entrega si no hay autoservicio
```
`App\Services\Nomina\LoteNominaService`. Un colaborador nunca entra al lote del grupo equivocado; sin sucursal asignada queda como error explícito ("sin clasificación"), nunca se adivina. Fuente del salario: `CompensacionService::diarioDe()` (salario_diario, nunca `sueldo_mensual` directo). Un lote con cualquier recibo emitido/cancelado es inmutable — `recalcular()` solo toca borradores.

### Baja
```
Solicitud de baja → revisión → renuncia/aviso de terminación → finiquito
→ pago → baja efectiva → expediente histórico (nunca se borra)
```
`App\Services\CierreLaboral\CierreLaboralService` + `FiniquitoService`. El colaborador **nunca** ve en la app su documento de renuncia/finiquito ni su recibo de nómina — se entregan impresos/en persona por RH (`GeneratedDocumentPolicy::esAutoservicioBloqueado()`, categoría `baja_finiquito` y `nomina_interna`).

## 2. Documentos: quién los ve

| Tipo | Categoría | Autoservicio del colaborador |
|---|---|---|
| Expediente (CURP, RFC, INE…) | `personales` | Sí, los suyos |
| Contrato | `contratos` | Sí, una vez firmado |
| Permiso / vacaciones (PDF oficial) | — (`SolicitudInterna`) | Sí, cuando RH lo autoriza |
| Actas | `actas` | **No** — nunca autoservicio |
| Recibo de nómina | `nomina_interna` | Solo si `config('features.recibos_colaborador')` está encendido (por defecto apagado: se entrega impreso) |
| Renuncia / finiquito | `baja_finiquito` | **Nunca** — siempre RH, impreso/en persona |
| Préstamo/pagaré | `prestamos` | Sí, los suyos |

La app móvil nunca recibe una ruta del NAS: todo documento pasa por un endpoint API Bearer con su propia Policy (`EmployeeDocumentPolicy`, `GeneratedDocumentPolicy`, `ReciboNominaPolicy`).

## 3. Super Admin: bypass operativo (nunca ciego)

Problema real que este documento corrige: `Gate::before` en `AppServiceProvider` solo reconoce **permisos del catálogo** (p. ej. `actas.crear`), no el **nombre del método** de una Policy (`crearActa`, `aprobar`, `gestionar`) ni las condiciones de segregación que cada Policy agrega a mano (`! $usuario->is($solicitud->usuario)`, `! $this->esPropio(...)`).

Solución: `App\Policies\Concerns\ConSuperAdminOperativo` — un trait con `before()` que devuelve `true` para `super_admin` en Policies **operativas**. Policies que lo usan: `ColaboradorPolicy`, `SolicitudInternaPolicy`, `SolicitudVacacionesPolicy`, `ActaAdministrativaPolicy`, `EmployeeDocumentPolicy`, `GeneratedDocumentPolicy`, `ReciboNominaPolicy`, `CierreLaboralPolicy`, `ContratoLaboralPolicy`, `FiniquitoCalculoPolicy`, `PrestamoPolicy`, `CandidatoPolicy`, `VacantePolicy`, `EvaluacionPeriodoPruebaPolicy`, `SolicitudExcepcionPeriodoPruebaPolicy`, `EvaluacionInduccionPolicy`, `CambioFotoPerfilPolicy`, `AltaDigitalPolicy`, `OfficialFormatPolicy`.

**Nunca** se usa en Policies personales/autoservicio (`UserPolicy`, `TareaRhPolicy`, `BirthdayGreetingPolicy`, y el `delete()` de `RolPolicy` que protege al propio rol `super_admin` de borrarse) — ahí la restricción de "no sobre uno mismo" es la regla de negocio real, no una fuga de permisos. Un `super_admin` sin `Colaborador` vinculado tampoco finge tener expediente propio: el Gate `modo-colaborador` sigue exigiendo el vínculo real.

## 4. Matriz de roles (alcance real, `config/permisos_modulos.php` → `alcance_por_rol`)

| Rol | Alcance | Puede |
|---|---|---|
| **super_admin** | todos | Toda acción operativa/administrativa de People, incluso sobre su propio Colaborador vinculado (auditado igual que cualquier otra acción). |
| **rh_admin** | todos | Expedientes, documentos, solicitudes, vacaciones, actas, nómina, candidatos, altas, onboarding, cierres, finiquitos — el catálogo RH completo. |
| **sistemas** | todos (solo lectura RH) | Usuarios, roles, releases de la app, auditoría, configuración técnica. Lectura global de expedientes/solicitudes/vacaciones/actas/nómina/cierres **para soporte**. Nunca aprueba vacaciones, nunca autoriza finiquitos, nunca ejecuta bajas, nunca decide candidatos — ver todo ≠ hacer todo. |
| **director_comercial** | todos (solo lectura) | Reportes y headcount globales, candidatos/vacantes de solo lectura, única autoridad para decidir una intervención de puesto superior a Gestor/Volante. No es RH: no aprueba solicitudes ni vacaciones. |
| **gerente_regional** | región | Igual catálogo funcional que gerente de sucursal, pero sobre varias sucursales de su región; única autoridad de intervención de Gestor/Volante de su región. |
| **gerente_sucursal** / **gerente** | sucursal | Aprueba vacaciones/solicitudes/candidatos de su sucursal, actas, cierres, ciclo laboral completo de su gente. |
| **subgerente** | sucursal | Mismo catálogo que gerente (apoyo/cobertura), sin `vacantes.crear` ni decidir candidatos/rechazos. |
| **jefe_directo** | equipo (subordinados directos) | Aprueba vacaciones/solicitudes de su equipo, preautoriza cierres, captura evaluaciones — nunca ve a quien no es su subordinado directo. |
| **coordinadora_regional** | propio | Desde 2026-10-15 usa PEOPLE como **colaboradora** (catálogo de `colaborador`): sin bandeja RH, sin alcance de región. Ver `docs/ROLES_Y_NAVEGACION.md`. |
| **coordinadora** | propio | Igual que coordinadora_regional: colaboradora. |
| **colaborador** | propio | Su expediente, sus solicitudes, sus vacaciones, sus documentos autorizados, sus notificaciones. Nunca ve recibos de nómina ni documentos de renuncia/finiquito (§2). |

Si una cuenta tiene varios roles (p. ej. `sistemas` + `super_admin`), **manda `super_admin`**: el bypass de `ConSuperAdminOperativo` no depende de qué otro rol tenga la cuenta, solo de `hasRole('super_admin')`.

## 5. Pruebas que validan esta matriz

- `tests/Feature/Permisos/SuperAdminOperativoTest.php` — super_admin nunca recibe 403 por ser el titular del recurso (solicitud, acta, vacación, documento propios); sistemas consulta todo pero no decide nada de RH; una cuenta con `sistemas`+`super_admin` no queda bloqueada.
- `tests/Feature/Permisos/RecorridoRutasPorRolTest.php` — recorre TODAS las pantallas GET del portal por rol, no una lista fija.
- `tests/Feature/DocumentosMaestros/RenunciaFiniquitoNuncaAutoservicioTest.php` — el colaborador nunca ve su propia renuncia/finiquito; RH y super_admin sí.
