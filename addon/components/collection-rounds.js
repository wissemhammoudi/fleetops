import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { dropTask } from 'ember-concurrency';
import pickupCallLink from '../utils/pickup-call-link';

export default class CollectionRoundsComponent extends Component {
    @service collectionPlanning;
    @service notifications;
    @service modalsManager;
    @service intl;
    @tracked selections = {};
    @tracked selectedOrders = new Set();
    @tracked pickupDate = '';
    @tracked selectedId = null;
    @tracked detailTab = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get depotView() {
        return this.args.view === 'depot';
    }

    get showDepotDetails() {
        return this.detailTab ? this.detailTab === 'depot' : this.depotView;
    }

    @action resetPanel() {
        this.detailTab = null;
        this.selectedId = null;
    }

    get permissions() {
        return this.collectionPlanning.permissions;
    }

    get followupCards() {
        return this.collectionPlanning.followup.map((order) => ({
            ...order,
            ready: order.state === 'ready_for_pickup' && !order.error,
            selected: this.selectedOrders.has(order.order_uuid),
            stops: order.stops.map((stop) => ({ ...stop, callUrl: pickupCallLink(stop.contact?.phone) })),
        }));
    }

    get cards() {
        return this.collectionPlanning.rounds
            .filter((round) => !this.depotView || round.custody)
            .map((round) => ({
                ...round,
                stops: round.stops.map((stop) => ({ ...stop, callUrl: pickupCallLink(stop.contact?.phone) })),
                departure: new Date(round.scheduled_at).toLocaleString(undefined, { timeZone: round.timezone }),
                editable: ['draft', 'released'].includes(round.status),
                assignable: round.status === 'draft' && this.permissions.assign,
                draft: round.status === 'draft',
                driverName: this.collectionPlanning.drivers.find((driver) => driver.uuid === round.driver_uuid)?.name,
                selectedDriver: this.selections[round.uuid]?.driver ?? round.driver_uuid ?? '',
                selectedDepot: this.selections[round.uuid]?.depot ?? round.depot?.uuid ?? '',
                selectedDate: this.selections[round.uuid]?.date ?? '',
            }));
    }

    get selected() {
        return this.cards.find((round) => round.uuid === (this.args.roundId ?? this.selectedId));
    }

    @action open(round) {
        this.detailTab = this.depotView ? 'depot' : 'review';
        this.selectedId = round.uuid;
    }

    @action close() {
        this.selectedId = null;
        this.args.onClose?.();
    }

    @action tab(value) {
        this.detailTab = value;
    }

    @action toggle(order, event) {
        const selected = new Set(this.selectedOrders);
        if (event.target.checked) selected.add(order.order_uuid);
        else selected.delete(order.order_uuid);
        this.selectedOrders = selected;
    }

    @action setDate(event) {
        this.pickupDate = event.target.value;
    }

    @action select(id, key, event) {
        this.selections = { ...this.selections, [id]: { ...this.selections[id], [key]: event.target.value } };
    }

    @action pageFollowup(offset) {
        return this.load.perform(this.collectionPlanning.followupPage + offset);
    }

    @dropTask *load(page = this.collectionPlanning.followupPage) {
        try {
            yield this.collectionPlanning.load(page);
            this.selections = {};
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action reviewPlan() {
        if (!this.selectedOrders.size || !this.pickupDate) return;
        const input = { order_uuids: [...this.selectedOrders], pickup_date: this.pickupDate };
        this.modalsManager.confirm({
            title: this.intl.t('planning.create-draft'),
            body: this.intl.t('planning.review-plan', { count: this.selectedOrders.size, date: this.pickupDate }),
            confirm: () => this.plan.perform(input),
        });
    }

    @dropTask *plan(input) {
        try {
            yield this.collectionPlanning.plan(input);
            this.selectedOrders = new Set();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action confirm(round, action) {
        this.modalsManager.confirm({
            title: this.intl.t(action === 'reschedule' ? 'planning.move-draft' : `collection-rounds.${action}`),
            body:
                action === 'reschedule'
                    ? `${this.intl.t('planning.new-date', { date: round.selectedDate })} ${this.intl.t('planning.move-help')}`
                    : this.intl.t(`collection-rounds.${action}-help`),
            confirm: () => this.update.perform(round, action),
        });
    }

    @dropTask *update(round, action) {
        try {
            const input = action === 'assign' ? { driver_uuid: round.selectedDriver, depot_uuid: round.selectedDepot } : action === 'reschedule' ? { pickup_date: round.selectedDate } : {};
            yield this.collectionPlanning.operate(round, action, input);
            this.selections = {};
        } catch (error) {
            this.notifications.serverError(error);
            yield this.load.perform();
        }
    }
}
