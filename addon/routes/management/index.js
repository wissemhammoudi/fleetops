import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementIndexRoute extends Route {
    @service hostRouter;

    beforeModel() {
        return this.hostRouter.replaceWith('console.fleet-ops.management.drivers.index');
    }
}
