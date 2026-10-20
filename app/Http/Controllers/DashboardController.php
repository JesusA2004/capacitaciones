<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Expedientes\DatosFaltantesService;
use App\Services\Navigation\NavigationService;
use App\Services\Reportes\MetricasRhDashboardService;
use App\Services\Reportes\TableroRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly MetricasRhDashboardService $metricas,
        private readonly NavigationService $navegacion,
        private readonly TableroRhService $tablero,
        private readonly TareaService $tareas,
        private readonly DatosFaltantesService $datosFaltantes,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $usuario = $request->user();
        $modo = $this->navegacion->modoActual($usuario, NavigationService::cookieDe($request));

        abort_if($modo === '', 403, 'Tu cuenta no tiene un modo de acceso configurado (ni operativo ni colaborador). Contacta a un administrador.');

        // "Inicio" respeta el modo elegido en el selector: una cuenta
        // operativa + colaborador que está en "Mi espacio" ve su inicio
        // personal, no el tablero de RH (ver NavigationService).
        if ($modo === 'operativo') {
            // Sin tablero de RH (Jurídico / Contraloría): su inicio es la bandeja de bajas.
            if (! $usuario->can('dashboard.global.ver') && ! $usuario->can('dashboard.sucursal.ver')) {
                return to_route('rh.bajas.index');
            }

            $vista = $usuario->can('dashboard.global.ver') ? 'Dashboard/Global' : 'Dashboard/Sucursal';

            // Inicio operativo = SOLO el tablero de RH definido por el negocio
            // (8 indicadores, embudo por hito máximo, tiempo de contratación
            // por nivel y rotación mensual), con filtros de mes y sucursal
            // acotados al alcance. Nada más en la pantalla.
            return Inertia::render($vista, [
                // Arriba: qué necesita atención hoy y 4 cifras para decidir.
                'inicio' => $this->inicioOperativo($usuario),
                // El tablero completo (8 indicadores, embudo, tiempos,
                // rotación) queda en «Ver indicadores».
                'tablero' => $this->tablero->construir($usuario, [
                    'mes' => $request->string('tablero_mes')->toString() ?: null,
                    'sucursal_id' => $request->integer('tablero_sucursal_id') ?: null,
                    'empresa_id' => $request->integer('tablero_empresa_id') ?: null,
                    'meses' => $request->integer('tablero_meses') ?: null,
                ]),
            ]);
        }

        return Inertia::render('Dashboard/Colaborador', $this->metricas->colaborador($usuario));
    }

    /**
     * @return array{nombre: string, pendientes: int, vencidos: int, indicadores: array{vacantes: int, en_proceso: int, por_vencer: int, expedientes_incompletos: int|null}}
     */
    private function inicioOperativo(User $usuario): array
    {
        $conteos = $this->tareas->conteos($usuario);

        return [
            'nombre' => trim((string) strtok((string) $usuario->name, ' ')),
            'pendientes' => (int) $conteos['abiertas'],
            'vencidos' => (int) $conteos['vencidas'],
            'indicadores' => [
                ...$this->tablero->inicio($usuario),
                'expedientes_incompletos' => $usuario->can('expedientes.revisar') ? $this->datosFaltantes->conteoIncompletos($usuario) : null,
            ],
        ];
    }
}
