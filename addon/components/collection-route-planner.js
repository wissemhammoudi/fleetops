import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { dropTask } from 'ember-concurrency';
import polyline from '@fleetbase/ember-core/utils/polyline';

export default class CollectionRoutePlannerComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;
    @service modalsManager;
    @service mapSettings;
    @tracked kind = 'pickup';
    @tracked date = '';
    @tracked startTime = '09:00';
    @tracked endTime = '18:00';
    @tracked drivers = {};
    @tracked proposal = null;
    @tracked error = null;
    revision = 0;
    requestId = null;

    constructor() {
        super(...arguments);
        if (!this.args.permissions?.optimize_pickup && this.args.permissions?.optimize_delivery) this.kind = 'delivery';
    }

    get permitted() {
        return this.kind === 'pickup' ? this.args.permissions?.optimize_pickup : this.args.permissions?.optimize_delivery;
    }

    get driverRows() {
        return (this.args.drivers ?? []).map((driver) => ({
            ...driver,
            checked: Boolean(this.drivers[driver.uuid]),
            origin: this.drivers[driver.uuid]?.start_place_uuid ?? this.args.depotUuid,
        }));
    }

    get routes() {
        return (this.proposal?.routes ?? this.args.savedRoutes ?? [])
            .filter((route) => this.proposal || !this.date || route.date === this.date)
            .map((route, index) => ({
                ...route,
                color: ['#2563eb', '#dc2626', '#0d9488', '#9333ea'][index % 4],
                coordinates: route.geometry ? polyline.decode(route.geometry) : [],
                kilometers: (route.distance_meters / 1000).toFixed(1),
                minutes: Math.ceil(route.duration_seconds / 60),
            }));
    }

    get mapCenter() {
        return this.routes[0]?.origin?.location ?? { lat: 36.8, lng: 10.18 };
    }

    get busy() {
        return this.orchestrate.isRunning || this.save.isRunning;
    }

    @action invalidate() {
        this.revision++;
        this.proposal = null;
        this.requestId = null;
        this.error = null;
        this.args.onPreviewChange?.(false);
    }

    @action resetCompany() {
        this.drivers = {};
        this.date = '';
        this.invalidate();
    }

    @action setField(field, event) {
        this[field] = event.target.value;
        this.invalidate();
    }

    @action toggleDriver(driver, event) {
        const drivers = { ...this.drivers };
        if (event.target.checked) drivers[driver.uuid] = { start_place_uuid: this.args.depotUuid };
        else delete drivers[driver.uuid];
        this.drivers = drivers;
        this.invalidate();
    }

    @action setOrigin(driver, event) {
        this.drivers = { ...this.drivers, [driver.uuid]: { start_place_uuid: event.target.value } };
        this.invalidate();
    }

    @dropTask *orchestrate() {
        this.invalidate();
        const company = this.currentUser.companyId;
        const revision = this.revision;
        try {
            if (!this.permitted || !this.args.selection?.size || !this.date || !Object.keys(this.drivers).length) {
                throw new Error(this.intl.t('planning.optimizer.required'));
            }
            const result = yield this.fetch.post('fleet-ops/planning/proposals', {
                kind: this.kind,
                date: this.date,
                start_time: this.startTime,
                end_time: this.endTime,
                depot_uuid: this.args.depotUuid,
                order_uuids: [...this.args.selection],
                drivers: Object.entries(this.drivers).map(([driver_uuid, origin]) => ({ driver_uuid, ...origin })),
            });
            if (company !== this.currentUser.companyId || revision !== this.revision) return;
            if (
                typeof result.plan_uuid !== 'string' ||
                !Array.isArray(result.routes) ||
                !Array.isArray(result.unassigned) ||
                result.routes.some(
                    (route) =>
                        !Array.isArray(route.stops) ||
                        !route.stops.length ||
                        route.stops.some((stop) => !Number.isFinite(stop.location?.lat) || !Number.isFinite(stop.location?.lng)) ||
                        !route.origin?.location ||
                        !route.destination?.location ||
                        !Number.isFinite(route.distance_meters) ||
                        !Number.isFinite(route.duration_seconds)
                )
            ) {
                throw new Error(this.intl.t('planning.invalid-response'));
            }
            // Decode before presenting a committable plan, not while rendering its map.
            result.routes.forEach((route) => {
                if (route.geometry) polyline.decode(route.geometry);
            });
            this.proposal = result;
            this.requestId = crypto.randomUUID();
            this.args.onPreviewChange?.(true);
        } catch (error) {
            if (company !== this.currentUser.companyId || revision !== this.revision) return;
            this.error = error.message;
            this.notifications.serverError(error);
        }
    }

    @action reviewSave() {
        const company = this.currentUser.companyId;
        const proposal = this.proposal;
        const requestId = this.requestId;
        this.modalsManager.confirm({
            title: this.intl.t('planning.optimizer.save'),
            body: this.intl.t('planning.optimizer.confirm'),
            confirm: () => {
                if (company !== this.currentUser.companyId || proposal !== this.proposal) {
                    this.notifications.warning(this.intl.t('planning.company-changed'));
                    return;
                }
                return this.save.perform(company, proposal, requestId);
            },
        });
    }

    @dropTask *save(company, proposal, requestId) {
        if (!this.permitted || !proposal?.routes?.length) return;
        try {
            const receipt = yield this.fetch.post(`fleet-ops/planning/proposals/${encodeURIComponent(proposal.plan_uuid)}/commit`, {
                kind: proposal.kind,
                request_id: requestId,
            });
            if (company !== this.currentUser.companyId) return;
            if (receipt.dispatch !== false || !Array.isArray(receipt.rounds) || !Array.isArray(receipt.deliveries)) {
                throw new Error(this.intl.t('planning.invalid-response'));
            }
            this.invalidate();
            const refreshed = yield this.args.onSaved();
            if (refreshed && company === this.currentUser.companyId) this.notifications.success(this.intl.t('planning.optimizer.saved'));
        } catch (error) {
            if (company !== this.currentUser.companyId) return;
            this.error = error.message;
            this.notifications.serverError(error);
        }
    }
}
