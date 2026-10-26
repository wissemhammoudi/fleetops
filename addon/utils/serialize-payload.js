import serializeModel from '@fleetbase/ember-core/utils/serialize-model';
import serializeArray from '@fleetbase/ember-core/utils/serialize-model-array';

export default function serializePayload(payload) {
    const serialized = {
        pickup: serializeModel(payload.pickup),
        dropoff: serializeModel(payload.dropoff),
        return: serializeModel(payload.return),
        cod_amount: payload.cod_amount,
        cod_currency: payload.cod_currency,
        cod_payment_method: payload.cod_payment_method,
        entities: serializeArray(payload.entities),
        waypoints: serializeArray(payload.waypoints),
    };

    return serialized;
}
