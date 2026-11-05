import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import {
    VEHICLE_TYPES,
    VEHICLE_FUELS,
    VEHICLE_OWNERSHIPS,
    VEHICLE_PAPER_DATES,
    VEHICLE_PAPER_DOCUMENTS,
    VEHICLE_PAPERS_NAMESPACE,
    vehiclePapers,
    paperDateStatus,
    openVehiclePaper,
} from '../../utils/vehicle-tn';

export default class VehicleFormComponent extends Component {
    @service store;
    @service fetch;
    @service intl;
    @service currentUser;
    @service notifications;
    @service modalsManager;
    @tracked statusOptions = ['available', 'pending'];

    get typeOptions() {
        return VEHICLE_TYPES.map((type) => ({ ...type, label: this.intl.t(`vehicle-tn.types.${type.value}`) }));
    }

    get fuelOptions() {
        return VEHICLE_FUELS.map((value) => ({ value, label: this.intl.t(`vehicle-tn.fuels.${value}`) }));
    }

    get ownershipOptions() {
        return VEHICLE_OWNERSHIPS.map((value) => ({ value, label: this.intl.t(`vehicle-tn.ownerships.${value}`) }));
    }

    get selectedType() {
        return this.typeOptions.find((option) => option.value === this.args.resource.type);
    }

    get selectedFuel() {
        return this.fuelOptions.find((option) => option.value === this.args.resource.fuel_type);
    }

    get selectedOwnership() {
        return this.ownershipOptions.find((option) => option.value === this.args.resource.ownership_type);
    }

    get papers() {
        return vehiclePapers(this.args.resource);
    }

    get paperDates() {
        const papers = this.papers;
        return VEHICLE_PAPER_DATES.map((kind) => ({ kind, value: papers[kind] ?? '', status: papers[kind] ? paperDateStatus(papers[kind]) : null }));
    }

    get paperDocuments() {
        const documents = this.papers.documents ?? {};
        return VEHICLE_PAPER_DOCUMENTS.map((kind) => ({ kind, uuid: documents[kind] ?? null }));
    }

    @action selectType(option) {
        this.args.resource.setProperties({
            type: option.value,
            payload_capacity_parcels: option.parcels,
            payload_capacity: option.weight,
            payload_capacity_volume: option.volume,
        });
    }

    @action selectFuel(option) {
        this.args.resource.fuel_type = option.value;
    }

    @action selectOwnership(option) {
        this.args.resource.ownership_type = option.value;
    }

    @action updatePapers(changes) {
        const meta = this.args.resource.meta ?? {};
        this.args.resource.meta = { ...meta, papers: { ...this.papers, ...changes } };
    }

    @action setPaper(key, event) {
        const value = String(event.target.value ?? '').trim();
        this.updatePapers({ [key]: value === '' ? null : value });
    }

    @action viewPaper(uuid) {
        return openVehiclePaper(this.fetch, uuid).catch((error) => this.notifications.serverError(error));
    }

    @action updateAvatarUrl(option) {
        this.args.resource.avatar_url = option.key === 'custom_avatar' ? option.value : [option.value];
    }

    @action updateSelectedImage(url) {
        this.args.resource.avatar_url = url;
    }

    @action assignDriver(driver) {
        this.args.resource.driver = driver;
        // trigger modified attributes via meta
        const meta = this.args.resource.meta ?? {};
        this.args.resource.meta = {
            ...meta,
        };
    }

    @task *uploadPaper(kind, file) {
        try {
            const body = new FormData();
            body.append('file', file.file);
            body.append('kind', kind);
            const headers = this.fetch.getHeaders();
            delete headers['Content-Type'];
            const result = yield this.fetch.request('vehicle-papers/documents', 'POST', { body, headers }, { namespace: VEHICLE_PAPERS_NAMESPACE });
            this.updatePapers({ documents: { ...(this.papers.documents ?? {}), [kind]: result.document.uuid } });
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            file.queue?.remove(file);
        }
    }

    @task *handlePhotoUpload(file) {
        try {
            yield this.fetch.uploadFile.perform(
                file,
                {
                    path: `uploads/${this.currentUser.companyId}/vehicles/${this.args.resource.id}`,
                    subject_uuid: this.args.resource.id,
                    subject_type: 'fleet-ops:vehicle',
                    type: 'vehicle_photo',
                },
                (uploadedFile) => {
                    this.args.resource.setProperties({
                        photo_uuid: uploadedFile.id,
                        photo_url: uploadedFile.url,
                        photo: uploadedFile,
                    });
                }
            );
        } catch (err) {
            this.notifications.error('Unable to upload photo: ' + err.message);
        }
    }
}
