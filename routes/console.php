<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recordatorios automaticos (Fase 7). Cada comando es idempotente por si
// mismo (marca recordatorio_enviado_en o revisa que haya algo pendiente),
// asi que una ejecucion manual de mas no duplica notificaciones.
Schedule::command('capacitacion:recordar-fechas-limite')->dailyAt('07:00');
Schedule::command('capacitacion:recordar-sesiones-proximas')->everyFifteenMinutes();
Schedule::command('capacitacion:recordar-calificaciones-pendientes')->dailyAt('08:00');

// Carga de video por bloques (Fase 9): limpia cargas abandonadas (nunca
// completadas antes de expira_en) y sus bloques temporales del disco.
Schedule::command('capacitacion:limpiar-cargas-expiradas')->hourly();

// Modulo de cumpleanos (docs/CUMPLEANOS.md): ambos commands son idempotentes
// (BirthdayGreeting.enviada_at / no hay nada nuevo que avisar), asi que
// correrlos manualmente de mas no duplica notificaciones. Zona horaria fija
// aunque APP_TIMEZONE cambie, porque el horario de envio es un acuerdo de
// negocio con RH, no un dato de usuario.
Schedule::command('cumpleanos:enviar-felicitaciones')->dailyAt('08:00')->timezone('America/Mexico_City');
Schedule::command('cumpleanos:recordar-rh')->dailyAt('07:30')->timezone('America/Mexico_City');

// Celebraciones (docs/CELEBRACIONES.md): deja listos los eventos y tarjetas
// de cumpleaños y aniversarios del día antes del horario laboral. Idempotente.
Schedule::command('celebraciones:preparar')->dailyAt('06:30')->timezone('America/Mexico_City')->withoutOverlapping();

// Aniversarios laborales: felicitación automática (in-app + push) a la misma
// hora que los cumpleaños. Antes solo se PREPARABAN y nunca se enviaban.
// Idempotente y a prueba de carreras (enviada_at se reclama antes de avisar).
Schedule::command('aniversarios:enviar-felicitaciones')->dailyAt('08:00')->timezone('America/Mexico_City')->withoutOverlapping();

// Ciclo laboral (docs/backend-rh-completion.md): vencimientos de contratos
// (evaluación de periodo de prueba, tareas y avisos N días antes, sin
// duplicar) y barrido de pendientes de expediente. Idempotente;
// withoutOverlapping/onOneServer evitan dos ejecuciones simultáneas.
Schedule::command('contratos:revisar-vencimientos')
    ->dailyAt('06:30')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping()
    ->onOneServer();

// Cierre laboral: cierres pagados cuya fecha efectiva ya llegó → pendiente
// "concluir cierre" para RH. Idempotente (un pendiente por cierre).
Schedule::command('cierres:revisar-fechas')
    ->dailyAt('06:45')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping()
    ->onOneServer();

// Jefe directo = organigrama: red de seguridad por si un cambio entró por
// un camino que no dispara la sincronización inmediata (SQL manual, etc.).
Schedule::command('organigrama:sincronizar-jefes')
    ->dailyAt('05:30')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping()
    ->onOneServer();

// Nómina quincenal (docs/NOMINA_QUINCENAL.md): prepara los recibos como
// borrador unos días antes del pago y los emite (PDF + aviso) en la fecha
// de pago. Idempotente.
Schedule::command('nomina:procesar-quincenas')
    ->dailyAt('06:15')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping()
    ->onOneServer();

// Limpia tokens de Sanctum ya vencidos (config/sanctum.php: expiration ya
// no es null). Comando propio del paquete, solo borra filas cuyo
// expires_at ya pasó — nunca toca un token todavía vigente.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
