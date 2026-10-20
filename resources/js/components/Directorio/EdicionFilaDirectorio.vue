<script setup lang="ts">
import { Check, LoaderCircle, X } from '@lucide/vue';
import { reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { leerCookie } from '@/lib/http';
import { actualizar } from '@/routes/directorio';

/**
 * Edición rápida de una fila del Directorio (solo super_admin, nunca la
 * propia fila: lo decide el backend). Guarda por fila con las mismas
 * reglas del expediente; el jefe directo no se edita, sale del organigrama.
 */
type Opcion = { id: number; nombre: string };

export type OpcionesEdicionDirectorio = {
    puestos: Opcion[];
    sucursales: Opcion[];
    departamentos: Opcion[];
};

export type FilaEditableDirectorio = {
    id: number;
    nombre: string;
    puesto_id: number | null;
    sucursal_id: number | null;
    departamento_id: number | null;
    correo_corporativo: string | null;
    telefono_corporativo: string | null;
};

const props = defineProps<{
    fila: FilaEditableDirectorio;
    opciones: OpcionesEdicionDirectorio;
}>();

const emit = defineEmits<{
    cancelar: [];
    /** Fila recién leída del backend; null si dejó de aparecer en el directorio. */
    guardado: [fila: Record<string, unknown> | null];
}>();

const SIN_VALOR = '';

const form = reactive({
    correo_corporativo: props.fila.correo_corporativo ?? '',
    telefono_corporativo: props.fila.telefono_corporativo ?? '',
    puesto_id: props.fila.puesto_id ? String(props.fila.puesto_id) : SIN_VALOR,
    sucursal_principal_id: props.fila.sucursal_id
        ? String(props.fila.sucursal_id)
        : SIN_VALOR,
    departamento_id: props.fila.departamento_id
        ? String(props.fila.departamento_id)
        : SIN_VALOR,
});

const guardando = ref(false);
const errores = ref<Record<string, string>>({});
const errorGeneral = ref<string | null>(null);

function id(valor: string): number | null {
    return valor === SIN_VALOR ? null : Number(valor);
}

async function guardar() {
    guardando.value = true;
    errores.value = {};
    errorGeneral.value = null;

    try {
        const respuesta = await fetch(actualizar.url(props.fila.id), {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                correo_corporativo: form.correo_corporativo.trim() || null,
                telefono_corporativo: form.telefono_corporativo.trim() || null,
                puesto_id: id(form.puesto_id),
                sucursal_principal_id: id(form.sucursal_principal_id),
                departamento_id: id(form.departamento_id),
            }),
        });

        const datos = (await respuesta.json().catch(() => null)) as {
            message?: string;
            mensaje?: string;
            errors?: Record<string, string[]>;
            colaborador?: Record<string, unknown> | null;
        } | null;

        if (!respuesta.ok) {
            if (respuesta.status === 422 && datos?.errors) {
                errores.value = Object.fromEntries(
                    Object.entries(datos.errors).map(([campo, mensajes]) => [
                        campo,
                        mensajes[0] ?? '',
                    ]),
                );
                errorGeneral.value = 'Revisa los campos marcados.';
            } else if (respuesta.status === 403) {
                errorGeneral.value = 'No tienes permiso para editar esta fila.';
            } else {
                errorGeneral.value =
                    datos?.message ?? 'No se pudieron guardar los cambios.';
            }

            return;
        }

        toast.success(
            `${props.fila.nombre}: ${datos?.mensaje ?? 'Cambios guardados.'}`,
        );
        emit('guardado', datos?.colaborador ?? null);
    } catch {
        errorGeneral.value =
            'No se pudo conectar con el servidor. Intenta de nuevo.';
    } finally {
        guardando.value = false;
    }
}
</script>

<template>
    <form class="flex flex-col gap-3" @submit.prevent="guardar">
        <div class="grid gap-1.5">
            <Label :for="`dir-correo-${fila.id}`">Correo corporativo</Label>
            <Input
                :id="`dir-correo-${fila.id}`"
                v-model="form.correo_corporativo"
                type="email"
                autocomplete="off"
                placeholder="nombre@mr-lana.com"
            />
            <InputError :message="errores.correo_corporativo" />
        </div>
        <div class="grid gap-1.5">
            <Label :for="`dir-telefono-${fila.id}`">Teléfono corporativo</Label>
            <Input
                :id="`dir-telefono-${fila.id}`"
                v-model="form.telefono_corporativo"
                type="tel"
                autocomplete="off"
            />
            <InputError :message="errores.telefono_corporativo" />
        </div>
        <div class="grid gap-1.5">
            <Label>Puesto</Label>
            <Select
                :model-value="form.puesto_id"
                @update:model-value="(v) => (form.puesto_id = String(v ?? ''))"
            >
                <SelectTrigger class="w-full">
                    <SelectValue placeholder="Selecciona un puesto" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="puesto in opciones.puestos"
                        :key="puesto.id"
                        :value="String(puesto.id)"
                    >
                        {{ puesto.nombre }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="errores.puesto_id" />
        </div>
        <div class="grid gap-1.5">
            <Label>Sucursal</Label>
            <Select
                :model-value="form.sucursal_principal_id"
                @update:model-value="
                    (v) => (form.sucursal_principal_id = String(v ?? ''))
                "
            >
                <SelectTrigger class="w-full">
                    <SelectValue placeholder="Selecciona una sucursal" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="sucursal in opciones.sucursales"
                        :key="sucursal.id"
                        :value="String(sucursal.id)"
                    >
                        {{ sucursal.nombre }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="errores.sucursal_principal_id" />
        </div>
        <div class="grid gap-1.5">
            <Label>Departamento</Label>
            <Select
                :model-value="form.departamento_id"
                @update:model-value="
                    (v) => (form.departamento_id = String(v ?? ''))
                "
            >
                <SelectTrigger class="w-full">
                    <SelectValue placeholder="Selecciona un departamento" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="departamento in opciones.departamentos"
                        :key="departamento.id"
                        :value="String(departamento.id)"
                    >
                        {{ departamento.nombre }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="errores.departamento_id" />
        </div>

        <p class="text-xs text-muted-foreground">
            El jefe directo no se edita: sale del organigrama según el puesto y
            la sucursal.
        </p>

        <p
            v-if="errorGeneral"
            class="rounded-lg bg-danger-soft px-3 py-2 text-xs text-[var(--mrl-texto)]"
            role="alert"
        >
            {{ errorGeneral }}
        </p>

        <div class="flex justify-end gap-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="guardando"
                @click="emit('cancelar')"
            >
                <X class="size-4" /> Cancelar
            </Button>
            <Button type="submit" size="sm" :disabled="guardando">
                <LoaderCircle v-if="guardando" class="size-4 animate-spin" />
                <Check v-else class="size-4" />
                {{ guardando ? 'Guardando…' : 'Guardar' }}
            </Button>
        </div>
    </form>
</template>
