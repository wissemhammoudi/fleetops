import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { dropTask } from 'ember-concurrency';

export default class CollectionDepotComponent extends Component {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service intl;
    @tracked barcode = '';
    @tracked reason = '';
    @tracked condition = 'good';
    @tracked depot = '';
    @tracked labels = [];
    retry = null;

    get receiving() {
        return ['awaiting_depot', 'received_at_depot'].includes(this.args.round.status);
    }

    get units() {
        return this.args.round.custody?.units ?? [];
    }

    @action selectCondition(event) {
        this.condition = event.target.value;
    }

    @action confirm(action, order) {
        this.modalsManager.confirm({
            title: this.intl.t(`collection-depot.${action}`),
            body: this.intl.t(`collection-depot.${action}-help`),
            confirm: () => this.save.perform(action, order.order_uuid),
        });
    }

    @dropTask *save(action, orderUuid) {
        const payload = {
            action,
            depot_uuid: this.depot.trim(),
            reason: this.reason.trim(),
            ...(action === 'scan' ? { barcode: this.barcode.trim(), condition: this.condition } : { order_uuid: orderUuid }),
        };
        try {
            const hash = JSON.stringify(payload);
            if (this.retry?.hash !== hash) {
                this.retry = { hash, id: crypto.randomUUID() };
            }
            yield this.fetch.post(`fleet-ops/collection-rounds/${encodeURIComponent(this.args.round.uuid)}/depot`, {
                ...payload, revision: this.args.round.revision, request_id: this.retry.id,
            });
            this.retry = null;
            this.barcode = '';
            this.reason = '';
            yield this.args.onChanged();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @dropTask *loadLabels() {
        try {
            const response = yield this.fetch.get(`fleet-ops/collection-rounds/${encodeURIComponent(this.args.round.uuid)}/labels`);
            this.labels = response.labels;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
