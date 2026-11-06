import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class CollectionPlanningService extends Service {
    @service fetch;
    @service abilities;
    @service currentUser;
    @tracked rounds = [];
    @tracked drivers = [];
    @tracked depots = [];
    @tracked permissions = {};
    @tracked schedule = {};
    @tracked pickupDates = [];
    @tracked followup = [];
    @tracked followupPage = 1;
    @tracked followupLastPage = 1;
    @tracked followupTotal = 0;
    @tracked loaded = false;
    @tracked busy = false;
    @tracked error = null;
    @tracked warnings = [];
    pendingLoad = null;
    companyId = null;

    get canRead() {
        return !this.abilities.cannot('fleet-ops view pickup-round');
    }

    async load(page = this.followupPage) {
        // A component may request loading during construction; reset outside its render computation.
        await Promise.resolve();
        if (!Number.isInteger(page)) page = this.followupPage;
        if (!this.canRead) return;
        const company = this.currentUser.companyId;
        if (company !== this.companyId) {
            this.companyId = company;
            this.rounds = [];
            this.drivers = [];
            this.depots = [];
            this.permissions = {};
            this.followup = [];
            this.schedule = {};
            this.pickupDates = [];
            this.warnings = [];
            this.loaded = false;
            this.error = null;
            this.followupPage = page = 1;
            this.followupLastPage = 1;
            this.followupTotal = 0;
            this.pendingLoad = null;
        }
        if (this.pendingLoad) return this.pendingLoad;
        const loading = this.fetch
            .get('fleet-ops/collection-rounds', { followup_page: page })
            .then(async (data) => {
                if (!Array.isArray(data.rounds) || !Array.isArray(data.drivers) || !Array.isArray(data.depots) || !data.permissions) {
                    throw new Error('The collection planning response was invalid.');
                }
                let rounds = data.rounds;
                for (let roundPage = 2; roundPage <= (data.rounds_last_page ?? 1); roundPage++) {
                    if (company !== this.currentUser.companyId) return;
                    const next = await this.fetch.get('fleet-ops/collection-rounds', { followup_page: page, round_page: roundPage });
                    if (!Array.isArray(next.rounds)) throw new Error('The collection planning response was invalid.');
                    rounds = [...rounds, ...next.rounds];
                }
                if (this.isDestroying || this.isDestroyed || company !== this.currentUser.companyId) return;
                this.rounds = rounds;
                this.drivers = data.drivers;
                this.depots = data.depots;
                this.permissions = data.permissions;
                this.schedule = data.schedule ?? {};
                this.pickupDates = data.pickup_dates ?? [];
                this.followup = data.follow_up ?? [];
                this.followupPage = data.follow_up_page ?? 1;
                this.followupLastPage = data.follow_up_last_page ?? 1;
                this.followupTotal = data.follow_up_total ?? 0;
                this.loaded = true;
                this.error = null;
            })
            .catch((error) => {
                if (company === this.currentUser.companyId) {
                    this.loaded = false;
                    this.error = error.message;
                }
                throw error;
            })
            .finally(() => {
                if (this.pendingLoad === loading) this.pendingLoad = null;
            });
        this.pendingLoad = loading;
        return this.pendingLoad;
    }

    async plan(input) {
        return this.change('fleet-ops/collection-rounds/plan', input, true);
    }

    async operate(round, action, input = {}) {
        return this.change(`fleet-ops/collection-rounds/${encodeURIComponent(round.uuid)}`, { ...input, action, revision: round.revision });
    }

    async change(path, input, planning = false) {
        if (this.busy) throw new Error('Another planning action is in progress. Wait for it to finish.');
        this.busy = true;
        const company = this.currentUser.companyId;
        try {
            if (this.pendingLoad) await this.pendingLoad;
            if (company !== this.currentUser.companyId) throw new Error('The active company changed. Refresh planning before continuing.');
            const result = await this.fetch.post(path, input);
            if (company !== this.currentUser.companyId) throw new Error('The active company changed. Refresh planning to review the saved action.');
            if (planning) this.warnings = result.warnings ?? [];
            if (this.pendingLoad) await this.pendingLoad;
            await this.load();
            return result;
        } finally {
            this.busy = false;
        }
    }
}
