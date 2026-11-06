import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { restartableTask } from 'ember-concurrency';

const DEFAULT_STATE_CLASS = 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600';
const STATE_CLASSES = {
    awaiting_preparation: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-900 dark:text-amber-200 dark:ring-amber-700',
    ready_for_pickup: 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-900 dark:text-emerald-200 dark:ring-emerald-700',
    pickup_scheduled: 'bg-blue-50 text-blue-800 ring-blue-200 dark:bg-blue-900 dark:text-blue-200 dark:ring-blue-700',
    pickup_in_progress: 'bg-sky-50 text-sky-800 ring-sky-200 dark:bg-sky-900 dark:text-sky-200 dark:ring-sky-700',
    collected: 'bg-indigo-50 text-indigo-800 ring-indigo-200 dark:bg-indigo-900 dark:text-indigo-200 dark:ring-indigo-700',
    received_at_depot: 'bg-violet-50 text-violet-800 ring-violet-200 dark:bg-violet-900 dark:text-violet-200 dark:ring-violet-700',
    ready_for_delivery_dispatch: 'bg-purple-50 text-purple-800 ring-purple-200 dark:bg-purple-900 dark:text-purple-200 dark:ring-purple-700',
    out_for_delivery: 'bg-cyan-50 text-cyan-800 ring-cyan-200 dark:bg-cyan-900 dark:text-cyan-200 dark:ring-cyan-700',
    delivered: 'bg-green-50 text-green-800 ring-green-200 dark:bg-green-900 dark:text-green-200 dark:ring-green-700',
    pickup_exception: 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-900 dark:text-red-200 dark:ring-red-700',
    depot_hold: 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-900 dark:text-red-200 dark:ring-red-700',
};

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
    @tracked selected = new Set();
    @tracked plannerKind = null;
    @tracked roundId = null;
    @tracked showDirectPlanner = false;
    @tracked hasCollectionPreview = false;
    @tracked leftCollapsed = false;
    @tracked rightCollapsed = false;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get selectionKind() {
        const states = (this.data?.orders ?? []).filter((order) => this.selected.has(order.uuid)).map((order) => order.state);
        if (!states.length) return null;
        return states.every((state) => state === 'ready_for_pickup') ? 'pickup' : states.every((state) => state === 'received_at_depot') ? 'delivery' : 'mixed';
    }

    kindOf(order) {
        if (order.route_type !== 'collection') return null;
        if (this.data.permissions.plan_pickup && order.state === 'ready_for_pickup') return 'pickup';
        if (this.data.permissions.optimize_delivery && order.state === 'received_at_depot') return 'delivery';
        return null;
    }

    get rows() {
        const current = this.selectionKind;
        return (this.data?.orders ?? []).map((order) => {
            const kind = this.kindOf(order);
            return {
                ...order,
                kind,
                selected: this.selected.has(order.uuid),
                selectable: Boolean(kind) && (current !== 'mixed' || this.canPlanMixed || this.selected.has(order.uuid)),
                canOpenRound: this.data.permissions.collection && this.collectionPlanning.rounds.some((round) => round.uuid === order.round_uuid),
                stateLabel: this.intl.exists(`planning.states.${order.state}`) ? this.intl.t(`planning.states.${order.state}`) : order.state,
                stateClass: STATE_CLASSES[order.state] ?? (order.stage === 'attention' ? STATE_CLASSES.pickup_exception : DEFAULT_STATE_CLASS),
            };
        });
    }

    get selectAllKind() {
        if (this.selectionKind === 'pickup' || this.selectionKind === 'delivery') return this.selectionKind;
        const rows = this.data?.orders ?? [];
        const hasPickup = rows.some((order) => this.kindOf(order) === 'pickup');
        const hasDelivery = rows.some((order) => this.kindOf(order) === 'delivery');
        if (hasPickup && hasDelivery && this.mixedAllowed) return 'mixed';
        return hasPickup ? 'pickup' : hasDelivery ? 'delivery' : null;
    }

    get selectableForAll() {
        const kind = this.selectAllKind;
        if (!kind) return [];
        return (this.data?.orders ?? []).filter((order) => (kind === 'mixed' ? Boolean(this.kindOf(order)) : this.kindOf(order) === kind));
    }

    get mixedAllowed() {
        return Boolean(this.data?.permissions.optimize_pickup && this.data?.permissions.optimize_delivery);
    }

    get canPlanMixed() {
        return this.selectionKind === 'mixed' && this.mixedAllowed;
    }

    get allSelected() {
        return this.selectableForAll.length > 0 && this.selectableForAll.every((order) => this.selected.has(order.uuid));
    }

    get counts() {
        const counts = this.data?.counts ?? {};
        return [
            { key: 'ready_for_pickup', stage: 'pickup', value: counts.ready_for_pickup ?? 0, dot: 'bg-emerald-500' },
            { key: 'pickup_planned', stage: 'pickup', value: counts.pickup_planned ?? 0, dot: 'bg-blue-500' },
            { key: 'at_depot', stage: 'depot', value: counts.at_depot ?? 0, dot: 'bg-violet-500' },
            { key: 'attention', stage: 'attention', value: counts.attention ?? 0, dot: 'bg-red-500' },
        ].map((card) => ({ ...card, label: this.intl.t(`planning.orchestrator.counts.${card.key}`), active: this.stage === card.stage }));
    }

    get canPlanPickup() {
        return this.selectionKind === 'pickup' && this.data?.permissions.optimize_pickup;
    }

    get canPlanDelivery() {
        return this.selectionKind === 'delivery' && this.data?.permissions.optimize_delivery;
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

    get depots() {
        return (this.data?.depots ?? []).filter((place) => place.routable);
    }

    get selectedDepot() {
        return this.depots.find((place) => place.uuid === this.depot);
    }

    get selectedRows() {
        return this.rows.filter((order) => order.selected);
    }

    get mapOrders() {
        return this.selected.size ? this.selectedRows : this.rows.filter((order) => order.kind);
    }

    get idleDrivers() {
        return (this.data?.route_drivers ?? []).map((driver) => ({
            ...driver,
            vehicleLabel: [driver.vehicle_name, driver.plate_number].filter(Boolean).join(' - '),
        }));
    }

    @action toggleLeftPanel() {
        this.leftCollapsed = !this.leftCollapsed;
    }

    @action toggleRightPanel() {
        this.rightCollapsed = !this.rightCollapsed;
    }

    @action stopPropagation(event) {
        event.stopPropagation();
    }

    @action toggleCard(order) {
        if (!order.selectable || this.load.isRunning) return;
        this.toggle(order, { target: { checked: !order.selected } });
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
        this.plannerKind = null;
        this.stage = 'all';
        this.search = '';
        this.load.perform(1);
    }

    @restartableTask *load(page = this.page) {
        if (!Number.isInteger(page)) page = this.page;
        const company = this.currentUser.companyId;
        this.error = null;
        this.selected = new Set();
        this.plannerKind = null;
        try {
            const result = yield this.fetch.get('fleet-ops/planning', { page, stage: this.stage, search: this.search });
            if (company !== this.currentUser.companyId) return;
            if (
                !Array.isArray(result.orders) ||
                !Array.isArray(result.places) ||
                !Array.isArray(result.depots) ||
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
            this.depot = (result.depots ?? []).some((place) => place.uuid === this.depot && place.routable) ? this.depot : (result.configuration.default_depot_uuid ?? '');
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

    @action chooseStage(stage) {
        this.stage = stage;
        this.load.perform(1);
    }

    @action clearSelection() {
        this.selected = new Set();
        this.plannerKind = null;
    }

    @action toggleAll(event) {
        this.selected = event.target.checked ? new Set(this.selectableForAll.map((order) => order.uuid)) : new Set();
        if (!this.selected.size) this.plannerKind = null;
    }

    @action openPlanner(kind) {
        this.plannerKind = kind;
        this.showDirectPlanner = false;
        this.roundId = null;
    }

    @action closePlanner() {
        this.plannerKind = null;
        this.hasCollectionPreview = false;
    }

    @action async plannerSaved() {
        this.plannerKind = null;
        return this.load.perform();
    }

    @action setSearch(event) {
        this.search = event.target.value;
    }

    @action find(event) {
        event?.preventDefault();
        this.load.perform(1);
    }

    @action selectDepot(event) {
        this.depot = event.target.value;
    }

    @action toggle(order, event) {
        const selected = new Set(this.selected);
        if (event.target.checked) selected.add(order.uuid);
        else selected.delete(order.uuid);
        this.selected = selected;
        if (!selected.size) this.plannerKind = null;
        else if (this.plannerKind) this.plannerKind = this.selectionKind;
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
}
