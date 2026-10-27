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
