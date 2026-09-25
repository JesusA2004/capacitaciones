<?php

namespace Database\Seeders;

use App\Enums\TipoNodoComercial;
use App\Models\AsignacionNodoComercial;
use App\Models\NodoComercial;
use App\Models\Sucursal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carga la matriz comercial / territorial REAL (MATRIZ -> Región -> Zona ->
 * Ruta) tal como la entregó dirección. Idempotente: usa updateOrCreate por
 * (parent_id, tipo, nombre_original), así que correrlo de nuevo no duplica
 * nodos ni pierde ediciones manuales de `activa`/`responsable_user_id` que
 * ya haya hecho un ruta (solo actualiza nombre/orden/metadata).
 *
 * No confundir con el headcount por Excel (App\Services\Headcount\*): ese
 * sigue siendo por Sucursal (nivel "zona" aquí), el Excel real no trae
 * detalle por ruta individual. Cada "zona" de la matriz referencia la
 * Sucursal real correspondiente vía `sucursal_id` cuando existe.
 */
class MatrizComercialSeeder extends Seeder
{
    /**
     * Región Q1 -> Zona -> [rutas]. El texto tal cual viene de dirección;
     * parsearNombre() extrae "(INACTIVA)"/"(VENCIDOS)"/"(CASTIGO)".
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const REGIONES = [
        'REGION Q1' => [
            'CUERNAVACA' => ['VOLANTE CUERNAVACA', 'YAUTEPEC', 'BARONA', 'JIUTEPEC', 'HUITZILAC', 'CUERNAVACA SUR', 'CUERNAVACA CENTRO', 'CUERNAVACA GTE', 'TEMIXCO', 'ZAPATA', 'CUERNAVACA ORIENTE', 'CUERNAVACA SUBGTE'],
            'SAN JUAN DEL RIO' => ['SJR - GTE', 'ESEQUIEL MONTES', 'SAN JUAN DEL RIO I', 'TEQUISQUIAPA', 'SJR II'],
            'MIACATLAN' => ['PNT-IXTLA', 'EML-ZAPATA', 'VOLANTE MIACATLAN', 'ZACATEPEC (INACTIVA)', 'MAZATEPEC', 'JOJUTLA (INACTIVA)', 'XOCHITEPEC', 'XOXOCOTLA - JOJUTLA', 'TETECALA', 'MIACATLAN GERENCIA', 'MIACATLAN SUBGERENCIA'],
            'ATLACOMULCO' => ['VOLANTE ATLACOMULCO', 'ATLACOMULCO CENTRO', 'ACAMBAY', 'SAN FELIPE', 'TECOAC', 'TEMASCALCINGO', 'SAN LORENZO TLACOTEPEC', 'SAN BARTOLO MORELOS', 'ATLACOMULCO GERENCIA', 'ATLACOMULCO SUBGERENCIA'],
            'IXTLAHUACA' => ['TEMOAYA', 'SAN PEDRO', 'IXTLAHUACA CENTRO', 'JIQUIPILCO', 'IXT - SANTA ANA', 'ALMOLOYA DE JUARES', 'IXTLAHUACA GERENCIA', 'IXTLAHUACA SUBGERENCIA'],
            'TENANGO' => ['VOLANTE TENANGO', 'CAPULHUAC', 'CHAPULTEPEC', 'TENANGO-2', 'TENANGO GERENCIA', 'TENANGO SUBGERENCIA', 'TENANCINGO-1', 'TENANGO-1', 'CALIMAYA-2', 'SANTIAGO', 'METEPEC-1 (INACTIVA)', 'METEPEC-2'],
            'SAN LUIS POTOSI' => ['VOLANTE SLP2', 'SAN LUIS SUBGERENCIA', 'SOLEDAD', 'VOLANTE SLP', 'MUÑOZ', 'MAGUEYES', 'ORIENTE-SLP', 'CENTRO-SLP', 'ZONA SUR', 'SAN LUIS GERENCIA'],
        ],
        'REGION Q3' => [
            'ATLIXCO' => ['ATLIX-CEN', 'ATLIX-SUBGTE', 'VOLANTE ATLIXCO', 'ATLIX-SUR', 'ATLIX-NOR', 'TOCH', 'TLAX', 'SACHOL', 'HUAQ', 'SJ', 'MAT-CEN', 'ATLIX-MATP', 'ATLIX-MATS', 'ATLIX-GTE', 'CHOL'],
            'CORDOBA' => ['VOLANTE CORDOBA', 'CORDOBA FORTIN', 'CORDOBA PADELMA', 'VILLA JARA', 'CORDOBA CENTRO', 'CORDOBA CENTRO 2', '20 DE NOVIEMBRE', 'CALZADAS', 'CHOCAMAN', 'COSCOMATEPEC', 'CUITLAHUAC', 'FORTN', 'LOPEZ ARIAS', 'PASO DEL MACHO', 'SAN ROMAN', 'TECAMA TOXPAN', 'YANGA', 'CORDOBA GERENCIA', 'CORDOBA SUBGERENCIA', 'CORDOBA ALAMEDA'],
            'HUAMANTLA' => ['GRAJ (INACTIVA)', 'APIZ-CEN', 'HUAMANTLA (VENCIDOS)', 'HUAMANTLA (CASTIGO)', 'APIX-NORESTE', 'APIZACO', 'HUAM-CEN (INACTIVA)', 'HUAM-NOR (INACTIVA)', 'HUAM-SUR (INACTIVA)', 'HUAM-EST (INACTIVA)', 'CUAPI (INACTIVA)', 'ORIEN (INACTIVA)', 'LIBR', 'ACAJ', 'APIZ-NOR', 'APIX-SUR', 'XALO (INACTIVA)', 'TLAXCO (INACTIVA)', 'HUAM-GTE', 'IXTENGO (INACTIVA)', 'TEAC (INACTIVA)', 'SAN COSME (INACTIVA)', 'HUAM-SUBGTE', 'VOLANTE HUAMANTLA'],
            'ORIZABA' => ['CENTRO C', 'VOLANTE ORIZABA', 'GRUPALES SUR', 'GRUPALES NORTE', 'VOLANTE 2 ORIZABA', 'ATZACAN', 'CENTRO A', 'CENTRO B', 'CENTRO D', 'CIUDAD MENDOZA', 'CUAUTLAPAN / DOS RIOS', 'IXHUATLANCILLO', 'IXTACZOQUITLAM NORTE', 'IXTACZOQUITLAM SUR', 'JALAPILLA', 'LA PERLA', 'NOGALES', 'ORIZABA SUR', 'RIO BLANCO NORTE', 'RIO BLANCO SUR', 'ORIZABA GERENCIA', 'ORIZABA SUBGERENCIA'],
            'TLAXCALA' => ['VOLANTE TLAXCALA', 'VOLANTE 2 TLAXCALA', 'TLAXCALA CENTRO', 'CONTLA', 'NATIVITAS', 'ZACATELCO', 'VILLA ALTA', 'CHIAUTEMPAN', 'TOTOLAC', 'SAN MATIAS', 'SAN PABLO', 'TETLANOHCAN', 'TLAXCALA GERENCIA', 'TLAXCALA SUBGERENCIA'],
            'TULA' => ['VOLANTE TULA', 'ATITALAQUIA', 'TULA GERENCIA', 'TULA SUBGERENCIA', 'TLAXCOAPAN', 'TLAHUELILPAN', 'TEZONTEPEC', 'QUMA', 'TULA CENTRO', 'TEPEJI', 'SANTA ANA', 'CRUZ AZUL', 'ATOTONILCO'],
        ],
    ];

    /**
     * Zona (nombre tal cual arriba) -> Sucursal::nombre real, para poder
     * cruzar con headcount/vacantes (que siguen siendo por Sucursal). Nunca
     * se crean sucursales nuevas aquí — si no hay match, sucursal_id queda
     * null (auditable, no adivinado).
     *
     * @var array<string, string>
     */
    private const ZONA_A_SUCURSAL = [
        'CUERNAVACA' => 'Cuernavaca',
        'SAN JUAN DEL RIO' => 'San Juan del Río',
        'MIACATLAN' => 'Miacatlan',
        'ATLACOMULCO' => 'Atlacomulco',
        'IXTLAHUACA' => 'Ixtlahuaca',
        'TENANGO' => 'Tenango del Valle',
        'SAN LUIS POTOSI' => 'San Luis Potosí',
        'ATLIXCO' => 'Atlixco',
        'CORDOBA' => 'Córdoba',
        'HUAMANTLA' => 'Huamantla',
        'ORIZABA' => 'Orizaba',
        'TLAXCALA' => 'Tlaxcala',
        'TULA' => 'Tula de Allende',
    ];

    public function run(): void
    {
        $sucursalesPorNombre = Sucursal::query()->get(['id', 'nombre'])->keyBy('nombre');

        $matriz = $this->upsert(null, TipoNodoComercial::Matriz, 'MATRIZ', 0);

        $ordenRegion = 0;

        foreach (self::REGIONES as $regionCruda => $zonas) {
            $codigoRegion = trim(str_replace('REGION', '', $regionCruda));
            $region = $this->upsert($matriz->id, TipoNodoComercial::Region, "Región {$codigoRegion}", $ordenRegion++, region: $codigoRegion);

            $ordenZona = 0;

            foreach ($zonas as $nombreZona => $rutas) {
                $sucursal = $sucursalesPorNombre->get(self::ZONA_A_SUCURSAL[$nombreZona]);

                $zona = $this->upsert(
                    $region->id,
                    TipoNodoComercial::Zona,
                    $nombreZona,
                    $ordenZona++,
                    region: $codigoRegion,
                    sucursalId: $sucursal?->id,
                );

                $this->sembrarRutas($zona, $rutas);
            }
        }

        // Q2 (Aguascalientes) desapareció: dirección pidió que no exista en
        // ningún lado. Se elimina si una base anterior todavía la tiene.
        $this->eliminarRama($matriz->id, TipoNodoComercial::Region, 'Región Q2');
        $this->eliminarRama($matriz->id, TipoNodoComercial::Zona, 'AGUASCALIENTES');

        // La asignación de responsables por ruta NUNCA se adivina aquí: en
        // producción debe hacerla RH explícitamente. Los datos de demostración
        // (OrganigramaDemoSeeder) sí dan a cada ruta de cobro su gestor demo,
        // pero solo corren en local/testing — ver DemoSeeder.
    }

    /**
     * @param  array<int, string>  $rutas
     */
    private function sembrarRutas(NodoComercial $zona, array $rutas, bool $forzarInactiva = false): void
    {
        foreach ($rutas as $orden => $nombreRuta) {
            $this->upsert($zona->id, TipoNodoComercial::Ruta, $nombreRuta, $orden, forzarInactiva: $forzarInactiva);
        }
    }

    /**
     * Elimina un nodo y toda su descendencia (zonas, rutas y sus
     * asignaciones). Solo para ramas que dirección dio de baja por
     * completo, como Q2/Aguascalientes.
     */
    private function eliminarRama(int $parentId, TipoNodoComercial $tipo, string $nombre): void
    {
        $nodo = NodoComercial::query()
            ->where('parent_id', $parentId)
            ->where('tipo', $tipo->value)
            ->where('nombre', $nombre)
            ->first();

        if ($nodo === null) {
            return;
        }

        $ids = [$nodo->id];
        $pendientes = [$nodo->id];

        while ($pendientes !== []) {
            $hijos = NodoComercial::query()->whereIn('parent_id', $pendientes)->pluck('id')->all();
            $ids = [...$ids, ...$hijos];
            $pendientes = $hijos;
        }

        DB::transaction(function () use ($ids): void {
            AsignacionNodoComercial::query()->whereIn('nodo_comercial_id', $ids)->delete();
            // De las hojas hacia la raíz, para no chocar con parent_id.
            foreach (array_reverse($ids) as $id) {
                NodoComercial::query()->whereKey($id)->delete();
            }
        });

        $this->command->warn(sprintf('Matriz comercial: se eliminó «%s» y su descendencia (%d nodos).', $nombre, count($ids)));
    }

    private function upsert(
        ?int $parentId,
        TipoNodoComercial $tipo,
        string $nombreCrudo,
        int $orden,
        ?string $region = null,
        ?int $sucursalId = null,
        bool $forzarInactiva = false,
    ): NodoComercial {
        ['nombre' => $nombre, 'activa' => $activa, 'estado_operativo' => $estadoOperativo] = $this->parsearNombre($nombreCrudo);

        return NodoComercial::query()->updateOrCreate(
            ['parent_id' => $parentId, 'tipo' => $tipo->value, 'nombre' => $nombre],
            [
                'clave' => Str::slug($nombre),
                'region' => $region,
                'activa' => $forzarInactiva ? false : $activa,
                'orden' => $orden,
                'sucursal_id' => $sucursalId,
                'metadata' => $estadoOperativo !== null ? ['estado_operativo' => $estadoOperativo] : null,
            ],
        );
    }

    /**
     * Extrae "(INACTIVA)" del texto original de dirección y regresa el
     * nombre limpio + el estado que representa. "(VENCIDOS)"/"(CASTIGO)" se
     * conservan tal cual DENTRO del nombre (solo se limpía may/min) porque
     * en los datos reales existen pares como "HUAMANTLA (VENCIDOS)" /
     * "HUAMANTLA (CASTIGO)": son dos nodos DISTINTOS dentro de la misma
     * zona que, si se les quitara el sufijo, colisionarían en el mismo
     * nombre y updateOrCreate() perdería uno de los dos silenciosamente.
     * "(INACTIVA)" nunca coexiste así (no hay dos "X (INACTIVA)" distintas
     * en la misma zona en los datos entregados), así que sí se limpia.
     *
     * @return array{nombre: string, activa: bool, estado_operativo: string|null}
     */
    private function parsearNombre(string $crudo): array
    {
        $nombre = $crudo;
        $activa = true;
        $estadoOperativo = null;

        if (str_contains($nombre, '(INACTIVA)')) {
            $activa = false;
            $nombre = trim(str_replace('(INACTIVA)', '', $nombre));
        }

        foreach (['VENCIDOS' => 'vencidos', 'CASTIGO' => 'castigo'] as $marca => $valor) {
            if (str_contains($nombre, "({$marca})")) {
                $estadoOperativo = $valor;
            }
        }

        return ['nombre' => trim($nombre), 'activa' => $activa, 'estado_operativo' => $estadoOperativo];
    }
}
