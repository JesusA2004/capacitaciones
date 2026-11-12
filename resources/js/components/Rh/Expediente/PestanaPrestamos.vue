<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import ConfirmarEntregaPrestamoDialog from '@/components/Rh/ConfirmarEntregaPrestamoDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useAlertas } from '@/composables/useAlertas';
import { vTablaTarjetas } from '@/lib/tablaTarjetas';
import { store as registrarMovimientoPrestamo } from '@/routes/rh/expedientes/prestamos/movimientos';
import type { PrestamoItem, PrestamoMovimientoTipo } from '@/types';

defineProps<{
    prestamos: PrestamoItem[];
    puedeEditar: boolean;
}>();

const { mostrarExito, mostrarError } = useAlertas();

function moneda(valor: number): string {
    return valor.toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    });
}

/** Movimientos del préstamo en español (el reverso compensa un recibo cancelado). */
const ETIQUETA_MOVIMIENTO: Record<PrestamoMovimientoTipo, string> = {
    nomina: 'Descuento de nómina',
    reverso_nomina: 'Reverso de recibo cancelado',
    manual: 'Abono manual',
    ajuste: 'Ajuste',
};

const prestamoConfirmarEntrega = ref<PrestamoItem | null>(null);

const formPago = useForm({
    monto: 0,
    tipo: 'manual',
});

function registrarPago(prestamoId: number) {
    formPago.post(registrarMovimientoPrestamo.url(prestamoId), {
        preserveScroll: true,
        onSuccess: () => {
            mostrarExito('Movimiento del préstamo registrado correctamente.');
            formPago.reset();
        },
        onError: () => mostrarError('No fue posible registrar el movimiento.'),
    });
}
</script>

<template>
    <Card class="rounded-2xl border-border/60">
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base">
                <Wallet class="size-4" />
                Préstamos
            </CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <EmptyState
                v-if="!prestamos.length"
                :icono="Wallet"
                titulo="Sin préstamos registrados"
                descripcion="Cuando se autorice un préstamo a este colaborador, aquí verás su saldo y sus movimientos."
            />
            <div
                v-for="prestamo in prestamos"
                :key="prestamo.id"
                class="rounded-xl border border-border/60 p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="font-medium">
                            {{ moneda(prestamo.monto_original) }}
                            · {{ prestamo.plazo }} pagos ({{
                                prestamo.periodicidad
                            }})
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Saldo:
                            {{ moneda(prestamo.saldo) }} · Pago programado:
                            {{ moneda(prestamo.pago_programado) }}
                            ·
                            {{ prestamo.porcentaje_pagado ?? 0 }}% pagado
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Próximo descuento:
                            {{ prestamo.fecha_primer_descuento ?? '—' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <EstadoBadge :estado="prestamo.estado" />
                        <Button
                            v-if="
                                puedeEditar &&
                                prestamo.estado === 'pendiente_entrega'
                            "
                            size="sm"
                            @click="prestamoConfirmarEntrega = prestamo"
                        >
                            Confirmar entrega
                        </Button>
                    </div>
                </div>

                <Table
                    v-if="prestamo.movimientos.length"
                    v-tabla-tarjetas
                    class="mt-3"
                >
                    <TableHeader>
                        <TableRow>
                            <TableHead>Fecha</TableHead>
                            <TableHead>Tipo</TableHead>
                            <TableHead class="text-right">Monto</TableHead>
                            <TableHead class="text-right">Saldo</TableHead>
                            <TableHead>Registró</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="movimiento in prestamo.movimientos"
                            :key="movimiento.id"
                        >
                            <TableCell>{{ movimiento.fecha }}</TableCell>
                            <TableCell>{{
                                ETIQUETA_MOVIMIENTO[movimiento.tipo] ??
                                movimiento.tipo
                            }}</TableCell>
                            <TableCell class="text-right">
                                {{ moneda(movimiento.monto) }}
                            </TableCell>
                            <TableCell class="text-right">
                                {{ moneda(movimiento.saldo_nuevo) }}
                            </TableCell>
                            <TableCell>{{
                                movimiento.registrado_por ?? '—'
                            }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <p v-else class="mt-3 text-xs text-muted-foreground">
                    Sin movimientos registrados todavía.
                </p>

                <form
                    v-if="puedeEditar && prestamo.estado === 'activo'"
                    class="mt-3 flex flex-wrap items-end gap-2"
                    @submit.prevent="registrarPago(prestamo.id)"
                >
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Monto del abono</Label>
                        <Input
                            v-model.number="formPago.monto"
                            type="number"
                            step="0.01"
                            min="0.01"
                            class="w-36"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Tipo</Label>
                        <Select v-model="formPago.tipo">
                            <SelectTrigger class="w-36">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="manual">Manual</SelectItem>
                                <SelectItem value="ajuste">Ajuste</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="formPago.processing"
                    >
                        Registrar movimiento
                    </Button>
                </form>
            </div>
        </CardContent>
    </Card>
    <ConfirmarEntregaPrestamoDialog
        v-if="prestamoConfirmarEntrega"
        :open="prestamoConfirmarEntrega !== null"
        :prestamo="prestamoConfirmarEntrega"
        @update:open="
            (v) =>
                (prestamoConfirmarEntrega = v ? prestamoConfirmarEntrega : null)
        "
    />
</template>
