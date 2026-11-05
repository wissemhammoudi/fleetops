import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { DRIVER_IDENTITY_DOCUMENTS, DRIVER_IDENTITY_NAMESPACE, DRIVER_TAX_REGIMES, driverDocumentAccept, openDriverIdentityDocument } from '../../utils/driver-identity';

export default class DriverFormComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @tracked loadedIdentity = null;

    taxRegimes = DRIVER_TAX_REGIMES;

    constructor() {
        super(...arguments);
        const resource = this.args.resource;
        if (resource && !resource.isNew && resource.id && !resource.identity) {
            this.loadIdentity.perform();
        }
    }

    // The saved identity is only copied onto the driver once edited, so editing a legacy driver never requires identity.
    get identity() {
        return this.args.resource?.identity ?? this.loadedIdentity ?? {};
    }

    get identityKind() {
        return this.identity.kind ?? null;
    }

    get identityDocuments() {
        const documents = this.identity.documents ?? {};
        return (DRIVER_IDENTITY_DOCUMENTS[this.identityKind] ?? []).map((kind) => ({ kind, uuid: documents[kind] ?? null, accept: driverDocumentAccept(kind) }));
    }

    updateIdentity(changes) {
        this.args.resource.set('identity', { ...this.identity, ...changes });
    }

    @action setIdentityKind(kind) {
        if (this.identityKind !== kind) {
            const documents = this.identity.documents ?? {};
            const kept = Object.fromEntries((DRIVER_IDENTITY_DOCUMENTS[kind] ?? []).filter((document) => documents[document]).map((document) => [document, documents[document]]));
            this.updateIdentity({ kind, documents: kept });
        }
    }

    @action setIdentityField(field, event) {
        this.updateIdentity({ [field]: event.target.value });
    }

    @action viewIdentityDocument(uuid) {
        openDriverIdentityDocument(this.fetch, uuid).catch((error) => this.notifications.serverError(error));
    }

    @task *loadIdentity() {
        try {
            const result = yield this.fetch.get(`driver-identities/${this.args.resource.id}`, {}, { namespace: DRIVER_IDENTITY_NAMESPACE });
            this.loadedIdentity = result?.identity?.kind ? result.identity : null;
        } catch (error) {
            this.loadedIdentity = null;
        }
    }

    @task *uploadIdentityDocument(kind, file) {
        try {
            const body = new FormData();
            body.append('file', file.file);
            body.append('kind', kind);
            const headers = this.fetch.getHeaders();
            delete headers['Content-Type'];
            const result = yield this.fetch.request('driver-identities/documents', 'POST', { body, headers }, { namespace: DRIVER_IDENTITY_NAMESPACE });
            this.updateIdentity({ documents: { ...(this.identity.documents ?? {}), [kind]: result.document.uuid } });
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
                    path: `uploads/${this.currentUser.companyId}/drivers/${this.args.resource.id}`,
                    subject_uuid: this.args.resource.id,
                    subject_type: 'fleet-ops:driver',
                    type: 'driver_photo',
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
