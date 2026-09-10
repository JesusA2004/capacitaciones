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
