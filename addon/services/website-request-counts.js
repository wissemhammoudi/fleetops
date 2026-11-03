import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { API } from '../utils/website-request';

const POLL_MS = 60000;

export default class WebsiteRequestCountsService extends Service {
    @service fetch;
    @service abilities;
    @service universe;
    @tracked counts = { new: 0, contact: 0, driver: 0 };
    timer = null;
    started = false;

    get canList() {
        return !this.abilities.cannot('fleet-ops list contact-enquiry') || !this.abilities.cannot('fleet-ops list driver-application');
    }

    get badge() {
        const count = this.counts.new ?? 0;
        if (count <= 0) return null;
        return count > 99 ? '99+' : String(count);
    }

    onNotification = () => this.refresh();

    start() {
        if (this.started || !this.canList) return;
        this.started = true;
        this.refresh();
        this.timer = setInterval(() => this.refresh(), POLL_MS);
        this.universe.on?.('notification.received', this.onNotification);
    }

    async refresh() {
        if (!this.canList) return;
        try {
            const result = await this.fetch.get('website-inbox/counts', {}, API);
            if (this.isDestroying || this.isDestroyed || typeof result?.new !== 'number') return;
            this.counts = { new: result.new, contact: result.contact ?? 0, driver: result.driver ?? 0 };
        } catch {
            // Keep the last known badge; the page itself reports load errors.
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);
        clearInterval(this.timer);
        if (this.started) this.universe.off?.('notification.received', this.onNotification);
    }
}
