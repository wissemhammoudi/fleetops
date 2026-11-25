import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class CustomerAdminSettingsComponent extends Component {
    @service fetch;
    @service store;
    @service notifications;
    @tracked orderConfigs = [];
    @tracked enabledOrderConfigs = [];

    constructor() {
        super(...arguments);
        this.loadOrderConfigs.perform();
    }

    @task *loadOrderConfigs() {
        try {
            this.orderConfigs = this.store.findAll('order-config');
        } catch (error) {
            this.notifications.serverError(error);
        }

        try {
            this.enabledOrderConfigs = yield this.fetch.get('fleet-ops/settings/customer-enabled-order-configs');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *toggleOrderConfig(orderConfig) {
        const includesOrderConfig = this.enabledOrderConfigs.find((id) => id === orderConfig.id);
        if (includesOrderConfig) {
            this.enabledOrderConfigs.removeObject(orderConfig.id);
        } else {
            this.enabledOrderConfigs.pushObject(orderConfig.id);
        }

        try {
            yield this.fetch.post('fleet-ops/settings/customer-enabled-order-configs', { enabledOrderConfigs: this.enabledOrderConfigs });
            this.notifications.success('Settings saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
