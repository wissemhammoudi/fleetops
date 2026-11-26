import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class SettingsIndexRoute extends Route {
    @service hostRouter;

    beforeModel() {
        return this.hostRouter.replaceWith('console.fleet-ops.operations.order-config');
    }
}
