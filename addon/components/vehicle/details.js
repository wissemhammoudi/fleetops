import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { VEHICLE_TYPES, VEHICLE_FUELS, VEHICLE_OWNERSHIPS, VEHICLE_PAPER_DATES, VEHICLE_PAPER_DOCUMENTS, vehiclePapers, paperDateStatus, openVehiclePaper } from '../../utils/vehicle-tn';

export default class VehicleDetailsComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    #label(group, values, value) {
        return value && values.includes(value) ? this.intl.t(`vehicle-tn.${group}.${value}`) : value;
    }

    get typeLabel() {
        return this.#label(
            'types',
            VEHICLE_TYPES.map((type) => type.value),
            this.args.resource.type
        );
    }

    get fuelLabel() {
        return this.#label('fuels', VEHICLE_FUELS, this.args.resource.fuel_type);
    }

    get ownershipLabel() {
        return this.#label('ownerships', VEHICLE_OWNERSHIPS, this.args.resource.ownership_type);
    }

    get papers() {
        return vehiclePapers(this.args.resource);
    }

    get paperDates() {
        const papers = this.papers;
        return VEHICLE_PAPER_DATES.map((kind) => ({ kind, value: papers[kind] ?? null, status: paperDateStatus(papers[kind]) }));
    }

    get paperDocuments() {
        const documents = this.papers.documents ?? {};
        return VEHICLE_PAPER_DOCUMENTS.map((kind) => ({ kind, uuid: documents[kind] ?? null }));
    }

    @action viewPaper(uuid) {
        return openVehiclePaper(this.fetch, uuid).catch((error) => this.notifications.serverError(error));
    }
}
