<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Gauge, History, Loader2, SlidersHorizontal, Target } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update as guardarConfiguracion } from '@/routes/seguimiento/nine-box/configuracion';
import type { ConfiguracionNineBox, ValoresNineBox } from '@/types';

/**
 * Pesos, metas y cortes de la Nine Box (nine_box.configurar). Guardar crea
 * una VERSIÓN nueva con motivo; nunca se edita la anterior.
 */
const props = defineProps<{ configuracion: ConfiguracionNineBox }>();
const abierto = defineModel<boolean>('abierto', { required: true });

const copiar = (v: ValoresNineBox): ValoresNineBox => JSON.parse(JSON.stringify(v)) as ValoresNineBox;
const valores = ref<ValoresNineBox>(copiar(props.configuracion.valores));
const motivo = ref('');
const enviando = ref(false);
const errores = ref<Record<string, string>>({});

watch(abierto, (visible) => {
    if (visible) {
        valores.value = copiar(props.configuracion.valores);
        motivo.value = '';
        errores.value = {};
    }
});

function guardar(): void {
    enviando.value = true;
    router.put(
        guardarConfiguracion.url(),
        { valores: valores.value, motivo: motivo.value },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['datos', 'configuracion'],
            onError: (e) => {
                errores.value = e;
            },
            onSuccess: () => {
                abierto.value = false;
            },
            onFinish: () => {
                enviando.value = false;
            },
        },
    );
}

/** Meta vacía = sin meta (se califica por percentil). */
function aMeta(valor: string | number): number | null {
    const numero = Number(valor);

    return valor === '' || !Number.isFinite(numero) || numero <= 0 ? null : numero;
}

const fecha = new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' });

function primerError(): string | null {
    const lista = Object.values(errores.value);

    return lista.length ? lista[0] : null;
}
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogScrollContent class="sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <SlidersHorizontal class="size-5 text-bronce" /> Configurar la Nine Box
                </DialogTitle>
                <DialogDescription>
                    Puntajes de 0 a 100. Los pesos se normalizan (no tienen que sumar 100). Cada cambio guarda una
                    versión nueva con su motivo.
                </DialogDescription>
            </DialogHeader>

            <form id="form-config-nine-box" class="grid gap-4 md:grid-cols-2" @submit.prevent="guardar">
                <fieldset class="flex flex-col gap-3 rounded-2xl border border-border bg-salvia/40 p-4">
                    <legend class="flex items-center gap-2 px-1 text-sm font-semibold">
                        <Gauge class="size-4 text-bronce" /> Desempeño (Reportes)
                    </legend>
                    <div class="grid grid-cols-3 gap-2">
                        <div v-for="(etiqueta, clave) in { colocacion: 'Colocación', recuperacion: 'Recuperación', mora: 'Mora' }" :key="clave">
                            <Label :for="`peso-${clave}`" class="text-xs">Peso {{ etiqueta.toLowerCase() }}</Label>
                            <Input :id="`peso-${clave}`" v-model.number="valores.desempeno.pesos[clave]" type="number" min="0" max="100" class="bg-card" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <Label for="meta-colocacion" class="text-xs">Meta mensual de colocación</Label>
                            <Input
                                id="meta-colocacion"
                                :model-value="valores.desempeno.meta_colocacion_mensual ?? ''"
                                @update:model-value="(v) => (valores.desempeno.meta_colocacion_mensual = aMeta(v))"
                                type="number"
                                min="0"
                                placeholder="Sin meta"
                                class="bg-card"
                            />
                        </div>
                        <div>
                            <Label for="meta-recuperacion" class="text-xs">Meta mensual de recuperación</Label>
                            <Input
                                id="meta-recuperacion"
                                :model-value="valores.desempeno.meta_recuperacion_mensual ?? ''"
                                @update:model-value="(v) => (valores.desempeno.meta_recuperacion_mensual = aMeta(v))"
                                type="number"
                                min="0"
                                placeholder="Sin meta"
                                class="bg-card"
                            />
                        </div>
                        <div>
                            <Label for="mora-excelente" class="text-xs">Mora excelente (%) = 100 pts</Label>
                            <Input id="mora-excelente" v-model.number="valores.desempeno.mora_excelente" type="number" min="0" max="100" step="0.5" class="bg-card" />
                        </div>
                        <div>
                            <Label for="mora-critica" class="text-xs">Mora crítica (%) = 0 pts</Label>
                            <Input id="mora-critica" v-model.number="valores.desempeno.mora_critica" type="number" min="0" max="100" step="0.5" class="bg-card" />
                        </div>
                    </div>
                    <p class="text-[11px] text-muted-foreground">
                        Sin meta, colocación y recuperación se califican por percentil contra todos los gestores de la
                        empresa en el mismo periodo.
                    </p>
                </fieldset>

                <fieldset class="flex flex-col gap-3 rounded-2xl border border-border bg-crema/50 p-4">
                    <legend class="flex items-center gap-2 px-1 text-sm font-semibold">
                        <Target class="size-4 text-bronce" /> Potencial
                    </legend>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <Label for="peso-evaluaciones" class="text-xs">Peso Seguimientos</Label>
                            <Input id="peso-evaluaciones" v-model.number="valores.potencial.pesos.evaluaciones" type="number" min="0" max="100" class="bg-card" />
                        </div>
                        <div>
                            <Label for="peso-calibracion" class="text-xs">Peso calibración</Label>
                            <Input id="peso-calibracion" v-model.number="valores.potencial.pesos.calibracion" type="number" min="0" max="100" class="bg-card" />
                        </div>
                        <div>
                            <Label for="ventana" class="text-xs">Ventana (meses)</Label>
                            <Input id="ventana" v-model.number="valores.potencial.ventana_evaluaciones_meses" type="number" min="1" max="24" class="bg-card" />
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div v-for="(etiqueta, clave) in { bajo: 'Bajo', medio: 'Medio', alto: 'Alto' }" :key="clave">
                            <Label :for="`cal-${clave}`" class="text-xs">Calibración «{{ etiqueta }}»</Label>
                            <Input :id="`cal-${clave}`" v-model.number="valores.potencial.valores_calibracion[clave]" type="number" min="0" max="100" class="bg-card" />
                        </div>
                    </div>
                    <p class="text-[11px] text-muted-foreground">
                        Seguimientos = promedio de las evaluaciones técnicas («Seguimiento 15/30/60…») contestadas en la
                        ventana.
                    </p>
                </fieldset>

                <fieldset class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 md:col-span-2">
                    <legend class="px-1 text-sm font-semibold">Cortes: menor que «Medio» = Bajo; desde «Alto» = Alto</legend>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div>
                            <Label for="corte-d-medio" class="text-xs">Desempeño medio desde</Label>
                            <Input id="corte-d-medio" v-model.number="valores.cortes.desempeno.medio" type="number" min="0" max="100" />
                        </div>
                        <div>
                            <Label for="corte-d-alto" class="text-xs">Desempeño alto desde</Label>
                            <Input id="corte-d-alto" v-model.number="valores.cortes.desempeno.alto" type="number" min="0" max="100" />
                        </div>
                        <div>
                            <Label for="corte-p-medio" class="text-xs">Potencial medio desde</Label>
                            <Input id="corte-p-medio" v-model.number="valores.cortes.potencial.medio" type="number" min="0" max="100" />
                        </div>
                        <div>
                            <Label for="corte-p-alto" class="text-xs">Potencial alto desde</Label>
                            <Input id="corte-p-alto" v-model.number="valores.cortes.potencial.alto" type="number" min="0" max="100" />
                        </div>
                    </div>
                </fieldset>

                <div class="md:col-span-2">
                    <Label for="motivo" class="text-xs">Motivo del cambio (obligatorio)</Label>
                    <Input id="motivo" v-model="motivo" maxlength="255" placeholder="Ej. Dirección fijó metas de colocación 2027" />
                </div>
                <p v-if="primerError()" class="text-sm text-danger md:col-span-2" role="alert">{{ primerError() }}</p>

                <details v-if="configuracion.historial.length" class="rounded-xl bg-muted/50 p-3 text-xs md:col-span-2">
                    <summary class="flex cursor-pointer items-center gap-1.5 font-medium">
                        <History class="size-3.5" /> Versiones anteriores
                    </summary>
                    <ul class="mt-2 flex flex-col gap-1 text-muted-foreground">
                        <li v-for="v in configuracion.historial" :key="v.id">
                            #{{ v.id }} · {{ v.guardada_en ? fecha.format(new Date(v.guardada_en)) : '' }} ·
                            {{ v.guardada_por ?? '—' }} — {{ v.motivo }}
                        </li>
                    </ul>
                </details>
            </form>

            <DialogFooter>
                <Button variant="outline" @click="abierto = false">Cancelar</Button>
                <Button type="submit" form="form-config-nine-box" :disabled="enviando || motivo.trim().length < 5">
                    <Loader2 v-if="enviando" class="size-4 animate-spin" /> Guardar versión nueva
                </Button>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
