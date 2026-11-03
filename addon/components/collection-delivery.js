import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { dropTask } from 'ember-concurrency';

export default class CollectionDeliveryComponent extends Component {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service intl;
    @tracked driver = '';
    @tracked barcode = '';
    @tracked reason = '';
    @tracked deliveryDate = '';
    retry = null;

    get leg() {
        return this.args.order.delivery;
    }

    get assignable() {
        return !this.leg || ['staging', 'recalled', 'held'].includes(this.leg.status);
    }

    get released() {
        return this.leg?.status === 'released';
    }

    get staging() {
        return this.leg?.status === 'staging';
    }

    get accepted() {
        return this.leg?.status === 'accepted';
    }

    get movable() {
        return this.staging && !(this.leg.manifest?.units ?? []).some((unit) => unit.staged_at);
    }

    @action selectDate(event) {
        this.deliveryDate = event.target.value;
    }

    @action selectDriver(event) {
        this.driver = event.target.value;
    }

    @action confirm(action) {
        this.modalsManager.confirm({
            title: this.intl.t(action === 'reschedule' ? 'planning.move-draft' : `collection-delivery.${action}`),
            body:
                action === 'reschedule'
                    ? `${this.intl.t('planning.new-date', { date: this.deliveryDate })} ${this.intl.t('planning.delivery-date-help')}`
                    : this.intl.t(`collection-delivery.${action}-help`),
            confirm: () => this.save.perform(action),
        });
    }

    @dropTask *save(action) {
        const input = {
            action,
            ...(action === 'assign' ? { driver_uuid: this.driver } : {}),
            ...(action === 'load' ? { barcode: this.barcode.trim() } : {}),
            ...(action === 'reschedule' ? { delivery_date: this.deliveryDate } : {}),
            reason: this.reason.trim(),
        };
        try {
            const hash = JSON.stringify(input);
            if (this.retry?.hash !== hash) {
                this.retry = { hash, id: crypto.randomUUID() };
            }
            yield this.fetch.post(`fleet-ops/collection-deliveries/${encodeURIComponent(this.args.order.order_uuid)}`, {
                ...input,
                revision: this.leg?.revision ?? 0,
                request_id: this.retry.id,
            });
            this.retry = null;
            this.barcode = '';
            yield this.args.onChanged();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
