import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { format as formatDate, isValid as isValidDate } from 'date-fns';
import { DRIVER_IDENTITY_NAMESPACE, openDriverIdentityDocument } from '../../utils/driver-identity';

const STATUS_BADGES = { verified: 'success', outdated: 'warning', unverified: 'pending' };

export default class DriverDetailsComponent extends Component {
    @service resourceMetadata;
    @service fetch;
    @service notifications;
    @service intl;
    @tracked verification;

    constructor() {
        super(...arguments);
        this.loadVerification.perform();
    }

    get metadataButtons() {
        return [
            {
                type: 'default',
                text: 'Edit',
                icon: 'pencil',
                iconPrefix: 'fas',
                permission: 'fleet-ops update driver',
                onClick: () => {
                    this.resourceMetadata.edit(this.args.resource);
                },
            },
        ];
    }

    get identity() {
        return this.verification?.identity ?? {};
    }

    get statusBadge() {
        return STATUS_BADGES[this.verification?.status] ?? 'pending';
    }

    get problems() {
        return Object.values(this.verification?.problems ?? {});
    }

    get verifiedAt() {
        const date = this.verification?.verified_at ? new Date(this.verification.verified_at) : null;
        return date && isValidDate(date) ? formatDate(date, 'dd MMM yyyy HH:mm') : '-';
    }

    get canVerify() {
        return this.verification?.can_verify && this.verification.status !== 'verified' && this.problems.length === 0;
    }

    @task({ restartable: true }) *loadVerification() {
        if (!this.args.resource?.id) return;
        try {
            this.verification = yield this.fetch.get(`driver-identities/${this.args.resource.id}`, {}, { namespace: DRIVER_IDENTITY_NAMESPACE });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *verify() {
        try {
            this.verification = yield this.fetch.post(`driver-identities/${this.args.resource.id}/verification`, {}, { namespace: DRIVER_IDENTITY_NAMESPACE });
            this.notifications.success(this.intl.t('driver-identity.verified-success'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *revoke() {
        try {
            this.verification = yield this.fetch.delete(`driver-identities/${this.args.resource.id}/verification`, {}, { namespace: DRIVER_IDENTITY_NAMESPACE });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action viewDocument(uuid) {
        openDriverIdentityDocument(this.fetch, uuid).catch((error) => this.notifications.serverError(error));
    }
}
