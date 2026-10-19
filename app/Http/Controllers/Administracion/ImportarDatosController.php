<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Configuración → «Importar datos»: HUB único que agrupa las
 * importaciones administrativas que ya existen dispersas por el sistema (base
 * de colaboradores + expedientes históricos, headcount autorizado, salarios y
 * vacaciones, rotación de personal y perfiles de puesto). NO reimplementa
 * ningún lector ni flujo: solo enlaza a cada pantalla existente y gatea cada
 * tarjeta por su permiso real. Cada importación conserva su propio flujo de
 * preview (subir → analizar → revisar → confirmar → aplicar).
 *
 * La autorización real vive en cada controlador de destino; aquí solo se
 * decide qué tarjetas mostrar (UX) y que el hub no se abra sin al menos un
 * permiso de importación.
 */
class ImportarDatosController extends Controller
{
    /**
     * Permiso que gatea cada tarjeta del hub. El usuario ve el hub si tiene
     * AL MENOS uno; cada tarjeta solo aparece con su permiso correspondiente.
     *
     * @var array<string, string>
     */
    private const PERMISOS = [
        'migracion' => 'expedientes.migrar',
        'headcount' => 'headcount.importar',
        'salarios_vacaciones' => 'colaboradores.importar_salarios_vacaciones',
        'rotacion' => 'rotacion.cargar',
        'perfiles_puesto' => 'perfiles_puesto.editar',
    ];

    public function index(Request $request): Response
    {
        $usuario = $request->user();

        $puede = [];
        $tieneAlguno = false;
        foreach (self::PERMISOS as $clave => $permiso) {
            $puede[$clave] = $usuario->can($permiso);
            $tieneAlguno = $tieneAlguno || $puede[$clave];
        }

        // Tarjeta principal «Carga inicial PEOPLE»: exige a la vez migrar
        // expedientes e importar headcount (CargaInicialController::PERMISOS).
        $puede['carga_inicial'] = CargaInicialController::puede($usuario);

        // Sin ningún permiso de importación no hay nada que mostrar: el hub
        // no es una pantalla genérica, es el índice de lo que este usuario
        // sí puede importar.
        abort_unless($tieneAlguno, 403);

        return Inertia::render('Administracion/ImportarDatos/Index', [
            'puede' => $puede,
        ]);
    }
}
