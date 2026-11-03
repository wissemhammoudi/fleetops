import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { restartableTask, dropTask } from 'ember-concurrency';

export default class PlanningWorkspaceComponent extends Component {
    @service collectionPlanning;
    @service modalsManager;
    @service intl;
    @service fetch;
    @service notifications;
    @service currentUser;
    @tracked data = null;
    @tracked error = null;
    @tracked stage = 'all';
    @tracked search = '';
    @tracked page = 1;
    @tracked depot = '';
    @tracked pickupDate = '';
    @tracked selected = new Set();
    @tracked roundId = null;
    @tracked showDirectPlanner = false;
    @tracked hasCollectionPreview = false;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get rows() {
        return (this.data?.orders ?? []).map((order) => ({
            ...order,
            selected: this.selected.has(order.uuid),
            selectable:
                order.route_type === 'collection' &&
                ((this.data.permissions.plan_pickup && order.state === 'ready_for_pickup') || (this.data.permissions.optimize_delivery && order.state === 'received_at_depot')),
            canOpenRound: this.data.permissions.collection && this.collectionPlanning.rounds.some((round) => round.uuid === order.round_uuid),
            stateLabel: this.intl.exists(`planning.states.${order.state}`) ? this.intl.t(`planning.states.${order.state}`) : order.state,
        }));
    }

    get stages() {
        return ['all', 'waiting', 'pickup', 'depot', 'delivery', 'attention', 'complete', 'other'].map((value) => ({
            value,
            label: this.intl.t(`planning.stages.${value}`),
        }));
    }

    get places() {
        return (this.data?.places ?? []).filter((place) => place.routable);
    }

    get savedRoutes() {
        const routes = new Map();
        for (const round of this.collectionPlanning.rounds) {
            if (round.routing?.plan_uuid && round.stops.length) routes.set(`${round.routing.plan_uuid}:${round.driver_uuid}`, round.routing);
            for (const order of round.depot_orders ?? []) {
                const leg = order.delivery;
                const route = leg?.manifest?.routing;
                if (route?.plan_uuid && ['staging', 'accepted', 'released'].includes(leg.status)) routes.set(`${route.plan_uuid}:${leg.driver_uuid}`, route);
            }
        }
        return [...routes.values()];
    }

    @action previewChanged(value) {
        this.hasCollectionPreview = value;
    }

    get defaultDepot() {
        return this.data?.places.find((place) => place.uuid === this.data.configuration.default_depot_uuid && place.routable);
    }

    @action companyChanged() {
        this.data = null;
        this.selected = new Set();
        this.roundId = null;
        this.showDirectPlanner = false;
        this.hasCollectionPreview = false;
        this.pickupDate = '';
        this.stage = 'all';
        this.search = '';
        this.load.perform(1);
    }

    @restartableTask *load(page = this.page) {
        const company = this.currentUser.companyId;
        this.error = null;
        this.selected = new Set();
        try {
            const result = yield this.fetch.get('fleet-ops/planning', { page, stage: this.stage, search: this.search });
            if (company !== this.currentUser.companyId) return;
            if (
                !Array.isArray(result.orders) ||
                !Array.isArray(result.places) ||
                !result.configuration ||
                !result.permissions ||
                !Number.isInteger(result.page) ||
                !Number.isInteger(result.last_page)
            ) {
                throw new Error(this.intl.t('planning.invalid-response'));
            }
            if (result.permissions.collection) yield this.collectionPlanning.load();
            if (company !== this.currentUser.companyId) return;
            this.data = result;
            this.page = result.page;
            this.depot = result.configuration.default_depot_uuid ?? '';
            return true;
        } catch (error) {
            if (company !== this.currentUser.companyId) return;
            this.data = null;
            this.error = error.message;
            this.notifications.serverError(error);
            return false;
        }
    }

    @action filter(event) {
        this.stage = event.target.value;
        this.load.perform(1);
    }

    @action setSearch(event) {
        this.search = event.target.value;
    }

    @action find() {
        this.load.perform(1);
    }

    @action selectDepot(event) {
        this.depot = event.target.value;
    }

    @action selectDate(event) {
        this.pickupDate = event.target.value;
    }

    @action toggle(order, event) {
        const selected = new Set(this.selected);
        if (event.target.checked) selected.add(order.uuid);
        else selected.delete(order.uuid);
        this.selected = selected;
    }

    @action pageBy(offset) {
        this.load.perform(this.page + offset);
    }

    @action openRound(order) {
        this.roundId = order.round_uuid;
    }

    @action closeRound() {
        this.roundId = null;
        this.load.perform();
    }

    @action toggleDirectPlanner() {
        const company = this.currentUser.companyId;
        if (this.showDirectPlanner) {
            this.modalsManager.confirm({
                title: this.intl.t('planning.switch-title'),
                body: this.intl.t('planning.switch-help'),
                confirm: () => {
                    if (company !== this.currentUser.companyId) return;
                    this.showDirectPlanner = false;
                    this.load.perform();
                },
            });
        } else if (this.hasCollectionPreview) {
            this.modalsManager.confirm({
                title: this.intl.t('planning.switch-title'),
                body: this.intl.t('planning.switch-help'),
                confirm: () => {
                    if (company !== this.currentUser.companyId) return;
                    this.showDirectPlanner = true;
                    this.hasCollectionPreview = false;
                },
            });
        } else {
            this.showDirectPlanner = true;
        }
    }

    @dropTask *saveDepot() {
        const company = this.currentUser.companyId;
        try {
            yield this.fetch.post('fleet-ops/planning/configuration', {
                default_depot_uuid: this.depot,
                revision: this.data.configuration.revision,
            });
            if (company !== this.currentUser.companyId) return;
            const refreshed = yield this.load.perform();
            if (refreshed) this.notifications.success(this.intl.t('planning.depot-saved'));
        } catch (error) {
            if (company === this.currentUser.companyId) this.notifications.serverError(error);
        }
    }

    @action reviewPickups() {
        if (!this.selected.size || !this.pickupDate || !this.defaultDepot || this.rows.some((order) => order.selected && order.state !== 'ready_for_pickup')) {
            this.notifications.warning(this.intl.t('planning.pickup-required'));
            return;
        }
        const company = this.currentUser.companyId;
        const input = { order_uuids: [...this.selected], pickup_date: this.pickupDate };
        this.modalsManager.confirm({
            title: this.intl.t('planning.create-draft'),
            body: this.intl.t('planning.review-plan', { count: input.order_uuids.length, date: input.pickup_date }),
            confirm: () => this.createPickups.perform(company, input),
        });
    }

    @dropTask *createPickups(company, input) {
        if (company !== this.currentUser.companyId) {
            this.notifications.warning(this.intl.t('planning.company-changed'));
            return;
        }
        try {
            yield this.collectionPlanning.plan(input);
            yield this.load.perform();
        } catch (error) {
            if (company === this.currentUser.companyId) this.notifications.serverError(error);
        }
    }
}
