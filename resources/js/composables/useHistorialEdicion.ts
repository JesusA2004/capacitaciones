import { computed, ref, shallowRef } from 'vue';
import type { Ref } from 'vue';

/**
 * Deshacer / rehacer para el editor visual de documentos: guarda
 * instantáneas (copias profundas) del estado editable. `registrar()` se
 * llama ANTES de cada cambio que el usuario podría querer revertir (mover,
 * redimensionar, asignar dato, eliminar…). Límite fijo para no crecer sin
 * control durante una sesión larga.
 */
export function useHistorialEdicion<T>(estado: Ref<T>, limite = 80) {
    const pasado = shallowRef<string[]>([]);
    const futuro = shallowRef<string[]>([]);
    const ultimo = ref<string | null>(null);

    function instantanea(): string {
        return JSON.stringify(estado.value);
    }

    function registrar(): void {
        const actual = instantanea();

        if (pasado.value[pasado.value.length - 1] === actual) {
            return;
        }

        pasado.value = [...pasado.value, actual].slice(-limite);
        futuro.value = [];
        ultimo.value = actual;
    }

    function deshacer(): void {
        const previo = pasado.value[pasado.value.length - 1];

        if (previo === undefined) {
            return;
        }

        futuro.value = [instantanea(), ...futuro.value].slice(0, limite);
        pasado.value = pasado.value.slice(0, -1);
        estado.value = JSON.parse(previo) as T;
    }

    function rehacer(): void {
        const siguiente = futuro.value[0];

        if (siguiente === undefined) {
            return;
        }

        pasado.value = [...pasado.value, instantanea()].slice(-limite);
        futuro.value = futuro.value.slice(1);
        estado.value = JSON.parse(siguiente) as T;
    }

    function reiniciar(): void {
        pasado.value = [];
        futuro.value = [];
        ultimo.value = null;
    }

    return {
        registrar,
        deshacer,
        rehacer,
        reiniciar,
        puedeDeshacer: computed(() => pasado.value.length > 0),
        puedeRehacer: computed(() => futuro.value.length > 0),
    };
}
