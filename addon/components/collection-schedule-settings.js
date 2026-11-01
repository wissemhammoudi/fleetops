import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { dropTask } from 'ember-concurrency';

export default class CollectionScheduleSettingsComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;
    @tracked timezone = '';
    @tracked operatingDays = [];
    @tracked cutoffTime = '';
    @tracked departureTime = '';
    @tracked closedDates = '';
    @tracked loaded = false;
    @tracked pickupWindows = [];
    @tracked capacityPlanning = false;
    @tracked fallbackCapacity = '';
    @tracked maxStops = '';

    @action toggleCapacity(event) {
        this.capacityPlanning = event.target.checked;
    }

    @action editCapacity(field, event) {
        this[field] = event.target.value;
    }

    @action addWindow() {
        this.pickupWindows = [...this.pickupWindows, { start: '', end: '' }];
    }

    @action removeWindow(index) {
        this.pickupWindows = this.pickupWindows.filter((_, position) => position !== index);
    }

    @action editWindow(index, field, event) {
        this.pickupWindows = this.pickupWindows.map((window, position) => position === index ? { ...window, [field]: event.target.value } : window);
    }

    constructor() {
        super(...arguments);
        this.loadSettings.perform();
    }

    get days() {
        return ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].map((day, index) => ({
            value: index + 1,
            label: this.intl.t(`settings.collection.${day}`),
            selected: this.operatingDays.includes(index + 1),
        }));
    }

    get payload() {
        return {
            timezone: this.timezone.trim(),
            operating_days: this.operatingDays,
            cutoff_time: this.cutoffTime,
            departure_time: this.departureTime,
            pickup_windows: this.pickupWindows,
            capacity_planning: this.capacityPlanning,
            fallback_parcel_capacity: this.fallbackCapacity === '' ? null : Number(this.fallbackCapacity),
            max_pickup_stops: this.maxStops === '' ? null : Number(this.maxStops),
            closed_dates: this.closedDates
                .split(/\r?\n/)
                .map((date) => date.trim())
                .filter(Boolean),
        };
    }

    @action setOperatingDay(day, enabled) {
        this.operatingDays = enabled ? [...new Set([...this.operatingDays, day])].sort((a, b) => a - b) : this.operatingDays.filter((value) => value !== day);
    }

    @dropTask *loadSettings() {
        this.loaded = false;
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/collection-schedule');
            this.timezone = settings.timezone;
            this.operatingDays = settings.operating_days;
            this.cutoffTime = settings.cutoff_time;
            this.departureTime = settings.departure_time;
            this.closedDates = settings.closed_dates.join('\n');
            this.pickupWindows = settings.pickup_windows ?? [];
            this.capacityPlanning = settings.capacity_planning ?? false;
            this.fallbackCapacity = String(settings.fallback_parcel_capacity ?? '');
            this.maxStops = String(settings.max_pickup_stops ?? '');
            this.loaded = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @dropTask *saveSettings() {
        if (!this.loaded) {
            this.notifications.warning(this.intl.t('settings.collection.load-before-saving'));
            return;
        }
        try {
            yield this.fetch.post('fleet-ops/settings/collection-schedule', this.payload);
            this.notifications.success(this.intl.t('settings.collection.saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
