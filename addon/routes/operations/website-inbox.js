import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

// Website requests moved next to Issues; keep old links working.
export default class OperationsWebsiteInboxRoute extends Route {
    @service hostRouter;

    beforeModel() {
        return this.hostRouter.transitionTo('console.fleet-ops.management.website-requests.index');
    }
}
