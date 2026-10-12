# Datos de demostración para la guía y el video

Las capturas de la guía (`public/guia/capturas/`) y del video (`docs/guias/video/capturas/`) salen del **sistema real corriendo en local**, con datos **ficticios**. Ninguna pantalla se arma a mano y ningún dato real de colaboradores aparece en ellas.

## Reglas

- **Solo local o testing.** Los datos demo los siembra `DemoSeeder`, que `DatabaseSeeder` ejecuta únicamente con `APP_ENV=local|testing` (o `SEED_DEMO_DATA=true`, que **nunca** se activa en producción). No existe ningún seeder demo que corra en producción.
- **Nunca contra producción.** `scripts/guia/escenario-demo.php` y `scripts/guia/grabar-capturas.mjs` se niegan a correr fuera de local: el primero revisa `APP_ENV` y el segundo solo acepta `localhost`, `127.0.0.1` o un dominio `*.test`.
- **Nada sensible en cuadro.** Antes de cada captura, el script oculta correos, CURP, RFC, NSS, teléfonos, domicilios, tokens y códigos de invitación, y rutas del NAS. También difumina los códigos QR y los visores de PDF (recibos y contratos traen RFC, CURP y NSS). La contraseña se escribe fuera de cuadro y nunca se captura.
- **Personas ficticias.** Todas las cuentas son `@mrlana.test`. Las personas del ciclo laboral se llaman «Demo … Ciclo» y los candidatos de reclutamiento llevan «(demo-recl)». Plantillas, módulos de onboarding y activos demo están marcados «DEMO —».

## Preparar la base local

```bash
cp .env.example .env              # APP_ENV=local, base SQLite o MariaDB local
php artisan key:generate
php artisan migrate:fresh --seed  # catálogos + DemoSeeder (solo local)
npm run build
php artisan serve --port=8123
```

La contraseña de todas las cuentas demo es la de los seeders de desarrollo (ver `README.md`). Es exclusiva de local y no existe en producción.

## Escenario que usa la grabación

`php scripts/guia/escenario-demo.php` imprime en JSON qué registro abre cada escena. Lo necesario lo toma de lo que siembra `CicloLaboralDemoSeeder`, y si algo falta lo produce con los mismos services que usa RH en pantalla:

| Escena | Registro demo |
|---|---|
| Plantilla y vacantes | Sucursal del «Gerente de Sucursal» demo (puesto Gestor) |
| Filtro RH, entrevista, psicométricos, socioeconómico, referencias | «Demo Perfil / Entrevista / Psicometricas / Socioeconomico / Preseleccion Ciclo» |
| Intervención | Candidato «(demo-recl)» rechazado por RH |
| Generar QR de contratación | Candidato autorizado por RH **con plaza libre**. Si no lo hay, RH autoriza a «Demo Esperando rh Ciclo» con `CandidatoWorkflowService::autorizarRh()` |
| Registro con el QR | La invitación recién generada (token difuminado en la captura) |
| Expediente, documentos de alta | «Demo Persona1 Ciclo» (pendiente de documentos) |
| Contratos, firma, onboarding | «Demo Persona2 Ciclo» (en onboarding) |
| Evaluación y contrato indeterminado | Evaluación capturada que RH autoriza con `EvaluacionPeriodoPruebaService::autorizar()` |
| Nómina | Lote quincenal del periodo actual preparado con `LoteNominaService::preparar()` |
| Baja y finiquito | Cierres «pendiente de RH» y «pago programado» del seeder |

El nombre «Juan Pérez Demo» solo se escribe en el formulario de *Nuevo candidato*, que no se guarda.

> Grabar modifica la base local (genera una contratación con su QR, autoriza una renovación y prepara un lote). Para repetir la grabación desde cero, vuelve a correr `php artisan migrate:fresh --seed`.
