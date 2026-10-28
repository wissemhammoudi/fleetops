import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class PlaceFormComponent extends Component {
    @tracked coordinatesInput;

    get extraPhonesText() {
        const extra = this.args.resource?.meta?.phones;
        return Array.isArray(extra) ? extra.join(', ') : '';
    }

    @action setExtraPhones(event) {
        const phones = String(event.target.value ?? '')
            .split(/[,;\n]/)
            .map((item) => item.trim())
            .filter(Boolean);
        this.args.resource.meta = { ...(this.args.resource.meta ?? {}), phones };
    }

    @action onAutocomplete(selected) {
        this.args.resource.setProperties({ ...selected });

        if (this.coordinatesInput && selected.location) {
            this.coordinatesInput.updateCoordinates(selected.location);
        }
    }
}
