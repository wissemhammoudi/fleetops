import { helper } from '@ember/component/helper';
import { get } from '@ember/object';

// Primary phone first, then the extra phones saved in place meta.
export function placePhones(place) {
    if (!place) {
        return [];
    }

    const extra = get(place, 'meta.phones');
    const list = [get(place, 'phone'), ...(Array.isArray(extra) ? extra : [])];

    return [...new Set(list.filter((value) => typeof value === 'string' && value.trim()).map((value) => value.trim()))];
}

export default helper(function placePhonesHelper([place]) {
    return placePhones(place);
});
