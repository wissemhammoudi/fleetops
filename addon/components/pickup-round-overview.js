import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { registerDestructor } from '@ember/destroyable';
import polyline from '@fleetbase/ember-core/utils/polyline';
import telemetryTimestamp from '../utils/telemetry-timestamp';
import pickupCallLink from '../utils/pickup-call-link';

function location(value) {
    const point = value?.location ?? value;
    const coordinates = point?.coordinates;
    const lat = coordinates ? coordinates[1] : (point?.lat ?? point?.latitude);
    const lng = coordinates ? coordinates[0] : (point?.lng ?? point?.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180 || (lat === 0 && lng === 0)) return null;
    return { lat, lng };
}

export default class PickupRoundOverviewComponent extends Component {
    @service fetch;
    @service intl;
    @service currentUser;
    @tracked snapshot = null;
    @tracked error = null;
    @tracked loading = false;
    @tracked clock = Date.now();
    generation = 0;
    interval = null;

    constructor() {
        super(...arguments);
        registerDestructor(this, () => {
            this.generation++;
            clearInterval(this.interval);
        });
    }

    @action start() {
        clearInterval(this.interval);
        this.generation++;
        this.snapshot = null;
        this.loading = false;
        this.error = null;
        this.refresh();
        this.interval = setInterval(() => {
            this.clock = Date.now();
            this.refresh();
        }, 10000);
    }

    @action async refresh() {
        if (this.loading || !this.args.round?.uuid) return;
        const generation = this.generation;
        const company = this.currentUser.companyId;
        const uuid = this.args.round.uuid;
        this.loading = true;
        try {
            const response = await this.fetch.get(`fleet-ops/collection-rounds/${encodeURIComponent(uuid)}/monitor`);
            if (!response?.round || response.round.uuid !== uuid || !Number.isFinite(telemetryTimestamp(response.observed_at))) {
                throw new Error(this.intl.t('pickup-details.load-error'));
            }
            if (this.isDestroying || this.isDestroyed || generation !== this.generation || company !== this.currentUser.companyId) return;
            this.snapshot = response;
            this.error = null;
            this.clock = Date.now();
        } catch (error) {
            if (!this.isDestroying && !this.isDestroyed && generation === this.generation && company === this.currentUser.companyId) {
                this.error = error.message || this.intl.t('pickup-details.load-error');
            }
        } finally {
            if (!this.isDestroying && !this.isDestroyed && generation === this.generation) this.loading = false;
        }
    }

    get round() {
        return this.snapshot?.round ?? this.args.round;
    }

    get driver() {
        return this.snapshot?.driver;
    }

    get driverCall() {
        return pickupCallLink(this.driver?.phone);
    }

    get onlineLabel() {
        if (!this.driver || !this.monitorFresh) return this.intl.t('pickup-details.unknown');
        return this.intl.t(this.driver.online ? 'pickup-details.online' : 'pickup-details.offline');
    }

    get monitorFresh() {
        const age = this.clock - telemetryTimestamp(this.snapshot?.observed_at);
        return !this.error && Number.isFinite(age) && age >= -30000 && age <= 30000;
    }

    get driverMarker() {
        const point = location(this.driver);
        const time = telemetryTimestamp(this.driver?.position_at);
        const age = this.clock - time;
        if (!this.monitorFresh || !this.driver?.online || !point || !Number.isFinite(age) || age < -30000 || age > 120000) return null;
        return { location: point, name: this.driver.name };
    }

    get positionLabel() {
        return this.intl.t(this.driverMarker ? 'pickup-details.position-live' : 'pickup-details.position-unavailable');
    }

    format(value) {
        const time = telemetryTimestamp(value);
        return Number.isFinite(time)
            ? this.intl.formatDate(time, { timeZone: this.round.timezone, year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
            : this.intl.t('pickup-details.unknown');
    }

    get updatedAt() {
        return this.format(this.snapshot?.observed_at);
    }

    get positionAt() {
        return this.format(this.driver?.position_at);
    }

    get departure() {
        return this.format(this.round.routing?.departure_at ?? this.round.scheduled_at);
    }

    get endTime() {
        return this.format(this.round.routing?.ends_at);
    }

    get completed() {
        return (this.round.stops ?? []).filter((stop) => stop.status !== 'pending').length;
    }

    get itinerary() {
        const stops = this.round.stops ?? [];
        const routing = this.round.routing;
        if (routing?.stops?.length) {
            return routing.stops.map((stop, index) => {
                const pickup = stop.kind === 'delivery' ? null : stops.find((item) => item.order_uuid === stop.order_uuid && (!stop.place_uuid || item.place_uuid === stop.place_uuid));
                return this.visit(stop, pickup, index);
            });
        }
        return stops.map((stop, index) => this.visit(stop, stop, index));
    }

    visit(stop, pickup, index) {
        return {
            ...stop,
            key: stop.task_id ?? stop.uuid ?? `${stop.order_uuid}-${index}`,
            sequence: stop.sequence ?? index + 1,
            label: stop.label ?? [stop.contact?.name, stop.contact?.street1, stop.contact?.city].filter(Boolean).join(' - '),
            time: stop.arrival_at ? this.format(stop.arrival_at) : null,
            location: location(stop),
            status: pickup?.status,
            handover: pickup?.handover,
            callUrl: pickupCallLink(pickup?.contact?.phone),
        };
    }

    get mapRoutes() {
        const routing = this.round.routing ?? {};
        const origin = { ...routing.origin, location: location(routing.origin) };
        const destination = { ...(routing.destination ?? this.round.depot), location: location(routing.destination ?? this.round.depot) };
        return [
            {
                color: '#6366F1',
                driver_name: this.driver?.name,
                origin,
                destination,
                coordinates: routing.geometry ? polyline.decode(routing.geometry) : [],
                stops: this.itinerary.filter((stop) => stop.location),
            },
        ];
    }

    get hasMap() {
        const route = this.mapRoutes[0];
        return Boolean(route.coordinates.length || route.stops.length || route.origin.location || route.destination.location || this.driverMarker);
    }

    get originLabel() {
        const origin = this.round.routing?.origin;
        return [origin?.name, origin?.street1].filter(Boolean).join(' - ') || this.intl.t('pickup-details.unknown');
    }

    get depotLabel() {
        const depot = this.round.routing?.destination ?? this.round.depot;
        return [depot?.name, depot?.street1].filter(Boolean).join(' - ') || this.intl.t('pickup-details.unknown');
    }
}
