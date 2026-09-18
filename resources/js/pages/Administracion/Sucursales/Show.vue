<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Building, Users } from '@lucide/vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';
import { index } from '@/routes/administracion/sucursales';

type PlantillaPorPuesto = {
    puesto_id: number;
    puesto: string;
    plantilla_autorizada: number;
    plantilla_actual: number;
    faltante: number;
    excedente: number;
};

defineProps<{
    sucursal: {
        id: number;
        nombre: string;
        clave: string;
        ciudad: string | null;
        estado: string | null;
        direccion: string | null;
        activo: boolean;
        empresa: { id: number; nombre: string } | null;
        responsable: { id: number; name: string; apellidos: string | null } | null;
    };
    plantillaPorPuesto: PlantillaPorPuesto[];
    totales: {
        plantilla_autorizada: number;
        plantilla_actual: number;
        vacantes: number;
        cobertura: number;
    };
    departamentos: number;
}>();

// `layout` recibe una función en vez de un objeto estático porque
// `defineOptions()` se compila fuera del scope de setup() y no puede
// referenciar variables locales como `props`; Inertia la invoca con las
// props actuales de la página en cada render (ver @inertiajs/vue3).
defineOptions({
    layout: (pageProps: { sucursal: { nombre: string } }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Sucursales', href: index.url() },
            { title: pageProps.sucursal.nombre, href: '#' },
        ],
    }),
});
</script>

<template>
    <Head :title="sucursal.nombre" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center gap-3">
            <Button variant="ghost" size="icon" as-child>
                <Link :href="index.url()">
                    <ArrowLeft class="size-4" />
                </Link>
            </Button>
            <div class="flex flex-col">
                <div class="flex items-center gap-2">
                    <Building class="size-5 text-muted-foreground" />
                    <h1 class="text-xl font-semibold">{{ sucursal.nombre }}</h1>
                    <EstadoBadge :estado="sucursal.activo ? 'activo' : 'inactivo'" />
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ sucursal.clave }}
                    <template v-if="sucursal.empresa"> · {{ sucursal.empresa.nombre }}</template>
                    <template v-if="sucursal.ciudad"> · {{ sucursal.ciudad }}</template>
                </p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Plantilla permitida</CardTitle>
                </CardHeader>
                <CardContent class="text-2xl font-semibold">{{ totales.plantilla_autorizada }}</CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Plantilla cubierta</CardTitle>
                </CardHeader>
                <CardContent class="text-2xl font-semibold">{{ totales.plantilla_actual }}</CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Vacantes</CardTitle>
                </CardHeader>
                <CardContent class="text-2xl font-semibold">{{ totales.vacantes }}</CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Cobertura</CardTitle>
                </CardHeader>
                <CardContent class="text-2xl font-semibold">{{ totales.cobertura }}%</CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Departamentos</CardTitle>
                </CardHeader>
                <CardContent class="text-2xl font-semibold">{{ departamentos }}</CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Información general</CardTitle>
            </CardHeader>
            <CardContent class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div>
                    <p class="text-muted-foreground">Responsable</p>
                    <p>
                        {{
                            sucursal.responsable
                                ? `${sucursal.responsable.name} ${sucursal.responsable.apellidos ?? ''}`
                                : 'Sin asignar'
                        }}
                    </p>
                </div>
                <div>
                    <p class="text-muted-foreground">Dirección</p>
                    <p>{{ sucursal.direccion ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-muted-foreground">Estado</p>
                    <p>{{ sucursal.estado ?? '—' }}</p>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Users class="size-4" />
                    Plantilla por puesto
                </CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Puesto</TableHead>
                            <TableHead class="text-right">Permitidos</TableHead>
                            <TableHead class="text-right">Cubiertos</TableHead>
                            <TableHead class="text-right">Vacantes</TableHead>
                            <TableHead class="text-right">Excedente</TableHead>
                            <TableHead class="text-right">Cobertura</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-if="plantillaPorPuesto.length === 0">
                            <TableCell colspan="6" class="text-center text-muted-foreground">
                                Esta sucursal todavía no tiene plantilla autorizada.
                            </TableCell>
                        </TableRow>
                        <TableRow v-for="fila in plantillaPorPuesto" :key="fila.puesto_id">
                            <TableCell>{{ fila.puesto }}</TableCell>
                            <TableCell class="text-right">{{ fila.plantilla_autorizada }}</TableCell>
                            <TableCell class="text-right">{{ fila.plantilla_actual }}</TableCell>
                            <TableCell class="text-right">{{ fila.faltante }}</TableCell>
                            <TableCell class="text-right">{{ fila.excedente }}</TableCell>
                            <TableCell class="text-right">
                                {{
                                    fila.plantilla_autorizada > 0
                                        ? Math.round((fila.plantilla_actual / fila.plantilla_autorizada) * 1000) / 10
                                        : 0
                                }}%
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
