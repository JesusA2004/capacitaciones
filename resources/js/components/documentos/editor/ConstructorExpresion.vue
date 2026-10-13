<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, Type, Variable, X } from '@lucide/vue';
import { computed } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type {
    ExpresionCampo,
    GrupoVariablesEditor,
    PiezaExpresion,
} from '@/types';

/**
 * Constructor de campos COMPUESTOS sin programar: piezas de dato y de
 * texto fijo, en orden («Nombre (Parentesco) · Tel. Teléfono»). Un texto
 * marcado como separador solo aparece entre dos datos con valor; un dato
 * opcional se omite si está vacío. El backend valida la lista cerrada
 * (ExpresionesDocumentoService): nunca se ejecuta código.
 */
const props = defineProps<{
    expresion: ExpresionCampo;
    grupos: GrupoVariablesEditor[];
    editable: boolean;
}>();

const emit = defineEmits<{ cambiar: [expresion: ExpresionCampo] }>();

const opciones = computed(() =>
    props.grupos.flatMap((g) =>
        g.variables.map((v) => ({ value: v.clave, label: `${v.etiqueta} — ${g.etiqueta}` })),
    ),
);

const ejemplos = computed(() => {
    const mapa = new Map<string, string>();

    for (const g of props.grupos) {
        for (const v of g.variables) {
            mapa.set(v.clave, v.ejemplo || v.etiqueta);
        }
    }

    return mapa;
});

function transformar(texto: string, t: string | undefined): string {
    if (t === 'mayusculas') {
        return texto.toUpperCase();
    }

    if (t === 'minusculas') {
        return texto.toLowerCase();
    }

    if (t === 'titulo') {
        return texto.toLowerCase().replace(/(^|\s)\p{L}/gu, (m) => m.toUpperCase());
    }

    return texto;
}

// Misma regla que el backend, con los ejemplos del catálogo.
const vista = computed(() => {
    const piezas = props.expresion.partes;
    const valores = piezas.map((p) =>
        p.tipo === 'texto'
            ? null
            : `${p.prefijo ?? ''}${transformar(ejemplos.value.get(p.clave) ?? '', p.transformar)}${p.sufijo ?? ''}`,
    );

    return transformar(
        piezas
            .map((p, i) => {
                if (p.tipo === 'variable') {
                    return valores[i] ?? '';
                }

                if (!p.separador) {
                    return p.valor;
                }

                const antes = valores.slice(0, i).some((v) => v);
                const despues = valores.slice(i + 1).some((v) => v);

                return antes && despues ? p.valor : '';
            })
            .join('')
            .trim(),
        props.expresion.transformar,
    );
});

function actualizar(partes: PiezaExpresion[]) {
    emit('cambiar', { ...props.expresion, partes });
}

function cambiarPieza(i: number, cambios: Partial<PiezaExpresion>) {
    actualizar(props.expresion.partes.map((p, j) => (j === i ? ({ ...p, ...cambios } as PiezaExpresion) : p)));
}

function mover(i: number, delta: number) {
    const partes = [...props.expresion.partes];
    const destino = i + delta;

    if (destino < 0 || destino >= partes.length) {
        return;
    }

    [partes[i], partes[destino]] = [partes[destino], partes[i]];
    actualizar(partes);
}

function agregar(tipo: 'variable' | 'texto') {
    actualizar([
        ...props.expresion.partes,
        tipo === 'variable'
            ? { tipo: 'variable', clave: opciones.value[0]?.value ?? 'nombre_completo', opcional: true }
            : { tipo: 'texto', valor: ' · ', separador: true },
    ]);
}
</script>

<template>
    <div class="flex flex-col gap-2 rounded-lg border bg-crema/30 p-2">
        <ol class="flex flex-col gap-1.5">
            <li v-for="(p, i) in expresion.partes" :key="i" class="flex flex-col gap-1 rounded-md bg-white p-1.5 ring-1 ring-border">
                <div class="flex items-center gap-1">
                    <component :is="p.tipo === 'variable' ? Variable : Type" class="size-3.5 shrink-0 text-muted-foreground" />
                    <SelectSimple
                        v-if="p.tipo === 'variable'"
                        :model-value="p.clave"
                        :opciones="opciones"
                        size="sm"
                        class="min-w-0 flex-1"
                        :disabled="!editable"
                        aria-label="Dato"
                        @update:model-value="(v) => cambiarPieza(i, { clave: String(v) })"
                    />
                    <Input
                        v-else
                        :model-value="p.valor"
                        maxlength="120"
                        class="h-8 min-w-0 flex-1"
                        :disabled="!editable"
                        aria-label="Texto fijo"
                        @update:model-value="(v) => cambiarPieza(i, { valor: String(v) })"
                    />
                    <Button size="icon" variant="ghost" class="size-7" :disabled="!editable || i === 0" aria-label="Subir" @click="mover(i, -1)"><ArrowUp class="size-3.5" /></Button>
                    <Button size="icon" variant="ghost" class="size-7" :disabled="!editable || i === expresion.partes.length - 1" aria-label="Bajar" @click="mover(i, 1)"><ArrowDown class="size-3.5" /></Button>
                    <Button size="icon" variant="ghost" class="size-7" :disabled="!editable" aria-label="Quitar" @click="actualizar(expresion.partes.filter((_, j) => j !== i))"><X class="size-3.5" /></Button>
                </div>
                <div class="flex flex-wrap items-center gap-2 pl-5 text-xs text-muted-foreground">
                    <template v-if="p.tipo === 'variable'">
                        <label class="flex items-center gap-1">
                            <Checkbox :model-value="p.opcional === true" :disabled="!editable" @update:model-value="(v) => cambiarPieza(i, { opcional: v === true })" />
                            Omitir si está vacío
                        </label>
                        <SelectSimple
                            :model-value="p.formato ?? ''"
                            :opciones="[{ value: 'moneda', label: 'Moneda' }, { value: 'numero_letra', label: 'Número con letra' }]"
                            opcion-vacia="Sin formato"
                            size="sm"
                            class="w-36"
                            :disabled="!editable"
                            aria-label="Formato"
                            @update:model-value="(v) => cambiarPieza(i, { formato: (v || undefined) as 'moneda' | 'numero_letra' | undefined })"
                        />
                    </template>
                    <label v-else class="flex items-center gap-1">
                        <Checkbox :model-value="p.separador === true" :disabled="!editable" @update:model-value="(v) => cambiarPieza(i, { separador: v === true })" />
                        Solo entre datos con valor
                    </label>
                </div>
            </li>
        </ol>
        <div class="flex gap-1.5">
            <Button size="sm" variant="outline" class="h-7 flex-1" :disabled="!editable" @click="agregar('variable')"><Plus class="size-3.5" /> Dato</Button>
            <Button size="sm" variant="outline" class="h-7 flex-1" :disabled="!editable" @click="agregar('texto')"><Plus class="size-3.5" /> Texto</Button>
        </div>
        <p class="rounded-md bg-white px-2 py-1.5 text-xs ring-1 ring-border">
            <span class="text-muted-foreground">Vista:</span> {{ vista || '—' }}
        </p>
    </div>
</template>
