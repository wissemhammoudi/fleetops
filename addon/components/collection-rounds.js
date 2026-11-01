import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { dropTask } from 'ember-concurrency';
import pickupCallLink from '../utils/pickup-call-link';

export default class CollectionRoundsComponent extends Component {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service intl;
    @tracked rounds = [];
    @tracked drivers = [];
    @tracked depots = [];
    @tracked selections = {};
    @tracked loaded = false;
    @tracked warnings = [];
    @tracked followup = [];
    @tracked followupPage = 1;
    @tracked followupLastPage = 1;
    @tracked followupTotal = 0;

    get followupCards() {
        return this.followup.map(order => ({
            ...order,
            pickupDate: order.pickup_window ? new Date(order.pickup_window.start).toLocaleDateString(undefined, { timeZone: order.timezone }) : null,
            stops: order.stops.map(stop => ({ ...stop, callUrl: pickupCallLink(stop.contact?.phone) })),
        }));
    }

    get firstFollowupPage() { return this.followupPage <= 1; }
    get lastFollowupPage() { return this.followupPage >= this.followupLastPage; }

    @action pageFollowup(offset) {
        this.followupPage += offset;
        return this.load.perform();
    }

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get cards() {
        return this.rounds.map((round) => ({
            ...round,
            stops: round.stops.map(stop => ({ ...stop, callUrl: pickupCallLink(stop.contact?.phone) })),
            departure: new Date(round.scheduled_at).toLocaleString(undefined, { timeZone: round.timezone }),
            editable: ['draft', 'released'].includes(round.status),
            draft: round.status === 'draft',
            selectedDriver: this.selections[round.uuid]?.driver ?? round.driver_uuid ?? '',
            selectedDepot: this.selections[round.uuid]?.depot ?? round.depot?.uuid ?? '',
        }));
    }

    @action select(id, key, event) {
        this.selections = { ...this.selections, [id]: { ...this.selections[id], [key]: event.target.value } };
    }

    @dropTask *load() {
        try {
            const data = yield this.fetch.get('fleet-ops/collection-rounds', { followup_page: this.followupPage });
            this.rounds = data.rounds;
            this.drivers = data.drivers;
            this.depots = data.depots;
            this.selections = {};
            this.loaded = true;
            this.followup = data.follow_up ?? [];
            this.followupPage = data.follow_up_page ?? 1;
            this.followupLastPage = data.follow_up_last_page ?? 1;
            this.followupTotal = data.follow_up_total ?? 0;
        } catch (error) {
            this.loaded = false;
            this.notifications.serverError(error);
        }
    }

    @dropTask *plan() {
        try {
            const data = yield this.fetch.post('fleet-ops/collection-rounds/plan', {});
            this.warnings = data.warnings;
            yield this.load.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action confirm(round, action) {
        this.modalsManager.confirm({
            title: this.intl.t(`collection-rounds.${action}`),
            body: this.intl.t(`collection-rounds.${action}-help`),
            confirm: () => this.update.perform(round, action),
        });
    }

    @dropTask *update(round, action) {
        try {
            yield this.fetch.post(`fleet-ops/collection-rounds/${encodeURIComponent(round.uuid)}`, {
                action,
                revision: round.revision,
                ...(action === 'assign' ? { driver_uuid: round.selectedDriver, depot_uuid: round.selectedDepot } : {}),
            });
            yield this.load.perform();
        } catch (error) {
            this.notifications.serverError(error);
            yield this.load.perform();
        }
    }
}
