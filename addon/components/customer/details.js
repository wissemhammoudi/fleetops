import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { format as formatDate, isValid as isValidDate } from 'date-fns';
import { IDENTITY_NAMESPACE, openIdentityDocument } from '../../utils/customer-identity';

const STATUS_BADGES = { verified: 'success', outdated: 'warning', unverified: 'pending' };

export default class CustomerDetailsComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;
    @tracked verification;

    constructor() {
        super(...arguments);
        this.loadVerification.perform();
    }

    get identity() {
        return this.args.resource?.meta?.identity ?? {};
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
            this.verification = yield this.fetch.get(`customer-identities/${this.args.resource.id}`, {}, { namespace: IDENTITY_NAMESPACE });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *verify() {
        try {
            this.verification = yield this.fetch.post(`customer-identities/${this.args.resource.id}/verification`, {}, { namespace: IDENTITY_NAMESPACE });
            this.notifications.success(this.intl.t('customer.fields.verified-success'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *revoke() {
        try {
            this.verification = yield this.fetch.delete(`customer-identities/${this.args.resource.id}/verification`, {}, { namespace: IDENTITY_NAMESPACE });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action viewDocument(uuid) {
        openIdentityDocument(this.fetch, uuid).catch((error) => this.notifications.serverError(error));
    }
}
