import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { dropTask } from 'ember-concurrency';
import polyline from '@fleetbase/ember-core/utils/polyline';

const COLORS = ['#2563eb', '#dc2626', '#0d9488', '#9333ea', '#ea580c', '#0891b2'];

function localDate(date) {
    return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
}

export default class CollectionRoutePlannerComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;
    @service modalsManager;
    @tracked chosenKind = 'pickup';
    @tracked date = '';
    @tracked startTime = '09:00';
    @tracked endTime = '18:00';
    @tracked drivers = {};
    @tracked proposal = null;
    @tracked error = null;
    @tracked panelCollapsed = false;
    revision = 0;
    requestId = null;

    constructor() {
        super(...arguments);
        if (!this.args.permissions?.optimize_pickup && this.args.permissions?.optimize_delivery) this.chosenKind = 'delivery';
    }

    get kind() {
        return this.args.kind ?? this.chosenKind;
    }

    get quickDates() {
        const today = new Date();
        const tomorrow = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1);
        return [
            { value: localDate(today), label: this.intl.t('planning.orchestrator.today') },
            { value: localDate(tomorrow), label: this.intl.t('planning.orchestrator.tomorrow') },
        ].map((date) => ({ ...date, active: date.value === this.date }));
    }

    get selectedDriverCount() {
        return Object.keys(this.drivers).length;
    }

    get selectedCapacity() {
        return (this.args.drivers ?? []).filter((driver) => this.drivers[driver.uuid]).reduce((sum, driver) => sum + (driver.parcel_capacity ?? 0), 0);
    }

    get stepOneDone() {
        return Boolean(this.date && this.selectedDriverCount);
    }

    get steps() {
        const active = this.proposal ? 3 : this.stepOneDone ? 2 : 1;
        return ['step-team', 'step-optimize', 'step-confirm'].map((key, index) => ({
            number: index + 1,
            label: this.intl.t(`planning.orchestrator.${key}`),
            active: active === index + 1,
            done: active > index + 1,
        }));
    }

    get totals() {
        const routes = this.proposal?.routes ?? [];
        return {
            routes: routes.length,
            stops: routes.reduce((sum, route) => sum + route.stops.length, 0),
            kilometers: (routes.reduce((sum, route) => sum + route.distance_meters, 0) / 1000).toFixed(1),
            minutes: Math.ceil(routes.reduce((sum, route) => sum + route.duration_seconds, 0) / 60),
        };
    }

    get permitted() {
        return this.kind === 'pickup' ? this.args.permissions?.optimize_pickup : this.args.permissions?.optimize_delivery;
    }

    get driverRows() {
        return (this.args.drivers ?? []).map((driver) => ({
            ...driver,
            checked: Boolean(this.drivers[driver.uuid]),
            origin: this.drivers[driver.uuid]?.start_place_uuid ?? this.args.depotUuid,
            vehicleLabel: [driver.vehicle_name, driver.plate_number].filter(Boolean).join(' - '),
        }));
    }

    get routes() {
        const vehicles = new Map((this.args.drivers ?? []).map((driver) => [driver.uuid, [driver.vehicle_name, driver.plate_number].filter(Boolean).join(' - ')]));
        return (this.proposal?.routes ?? this.args.savedRoutes ?? [])
            .filter((route) => this.proposal || !this.date || route.date === this.date)
            .map((route, index) => ({
                ...route,
                color: COLORS[index % COLORS.length],
                vehicleLabel: vehicles.get(route.driver_uuid) ?? '',
                stops: (route.stops ?? []).map((stop) => ({ ...stop, time: this.formatTime(stop.arrival_at) })),
                coordinates: route.geometry ? polyline.decode(route.geometry) : [],
                kilometers: (route.distance_meters / 1000).toFixed(1),
                minutes: Math.ceil(route.duration_seconds / 60),
            }));
    }

    formatTime(value) {
        const date = value ? new Date(value) : null;
        if (!date || Number.isNaN(date.getTime())) return value ?? '';
        try {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: this.currentUser.company?.timezone || undefined });
        } catch {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
    }

    get expiresAt() {
        return this.proposal?.expires_at ? this.formatTime(this.proposal.expires_at) : '';
    }

    get busy() {
        return this.orchestrate.isRunning || this.save.isRunning;
    }

    @action togglePanel() {
        this.panelCollapsed = !this.panelCollapsed;
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
        if (field === 'date') this.keepDepartureAhead();
        this.invalidate();
    }

    @action setKind(event) {
        this.chosenKind = event.target.value;
        this.invalidate();
    }

    @action setDate(value) {
        this.date = value;
        this.keepDepartureAhead();
        this.invalidate();
    }

    keepDepartureAhead() {
        const now = new Date();
        if (this.date !== localDate(now)) return;
        const current = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
        if (this.startTime > current) return;
        const nextHour = Math.min(now.getHours() + 1, 23);
        this.startTime = `${String(nextHour).padStart(2, '0')}:00`;
        if (this.endTime <= this.startTime) this.endTime = '23:59';
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
