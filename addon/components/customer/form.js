import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { IDENTITY_DOCUMENTS, IDENTITY_NAMESPACE, LEGAL_FORMS, openIdentityDocument } from '../../utils/customer-identity';

export default class CustomerFormComponent extends Component {
    @service customerActions;
    @service fetch;
    @service currentUser;
    @service notifications;
    @service('universe/extension-manager') extensionManager;

    legalForms = LEGAL_FORMS;

    get identity() {
        return this.args.resource?.meta?.identity ?? {};
    }

    get identityKind() {
        return this.identity.kind ?? null;
    }

    get identityDocuments() {
        const documents = this.identity.documents ?? {};
        return (IDENTITY_DOCUMENTS[this.identityKind] ?? []).map((kind) => ({ kind, uuid: documents[kind] ?? null }));
    }

    updateIdentity(changes) {
        const meta = this.args.resource.meta ?? {};
        this.args.resource.set('meta', { ...meta, identity: { ...this.identity, ...changes } });
    }

    @action setIdentityKind(kind) {
        if (this.identityKind !== kind) {
            this.updateIdentity({ kind, documents: {} });
        }
    }

    @action setIdentityField(field, event) {
        this.updateIdentity({ [field]: event.target.value });
    }

    @action viewIdentityDocument(uuid) {
        openIdentityDocument(this.fetch, uuid).catch((error) => this.notifications.serverError(error));
    }

    @task *uploadIdentityDocument(kind, file) {
        try {
            const body = new FormData();
            body.append('file', file.file);
            body.append('kind', kind);
            const headers = this.fetch.getHeaders();
            delete headers['Content-Type'];
            const result = yield this.fetch.request('customer-identities/documents', 'POST', { body, headers }, { namespace: IDENTITY_NAMESPACE });
            this.updateIdentity({ documents: { ...(this.identity.documents ?? {}), [kind]: result.document.uuid } });
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            file.queue?.remove(file);
        }
    }

    get showWelcomeEmailOption() {
        return this.args.resource?.isNew && this.extensionManager.isInstalled('@fleetbase/customer-portal-engine');
    }

    get sendWelcomeEmail() {
        return Boolean(this.args.resource?.meta?.customer_portal?.send_welcome_email);
    }

    @action toggleWelcomeEmail(value = !this.sendWelcomeEmail) {
        const meta = this.args.resource.meta ?? {};
        const customerPortal = meta.customer_portal ?? {};

        this.args.resource.set('meta', {
            ...meta,
            customer_portal: {
                ...customerPortal,
                send_welcome_email: value,
            },
        });
    }

    @action selectPlace(place) {
        if (!place) return;
        this.args.resource.setProperties({
            place,
            place_uuid: place.id,
        });
    }

    @action removePlace() {
        this.args.resource.setProperties({
            place: null,
            place_uuid: null,
        });
    }

    @action editPlace() {
        if (this.args.resource.has_place) {
            this.customerActions.editPlace(this.args.resource);
        } else {
            this.customerActions.createPlace(this.args.resource);
        }
    }

    @task *handlePhotoUpload(file) {
        try {
            yield this.fetch.uploadFile.perform(
                file,
                {
                    path: `uploads/${this.currentUser.companyId}/contacts/${this.args.resource.id}`,
                    subject_uuid: this.args.resource.id,
                    subject_type: 'fleet-ops:contact',
                    type: 'contact_photo',
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
