import { toRaw } from 'vue';

/**
 * Copia profunda de un DTO serializable (props de Inertia, respuestas JSON,
 * estado de un formulario). `structuredClone()` NO sirve sobre un Proxy
 * reactivo de Vue (lanza DataCloneError: "#<Object> could not be cloned"),
 * y los props de una página Inertia y cualquier `ref()` lo son. Los DTO del
 * portal son JSON puro por contrato (salen de un `JsonResponse`/props), así
 * que la ida y vuelta por JSON es exacta y desconecta la copia de la
 * reactividad del original.
 */
export function clonarSerializable<T>(valor: T): T {
    return JSON.parse(JSON.stringify(toRaw(valor))) as T;
}
