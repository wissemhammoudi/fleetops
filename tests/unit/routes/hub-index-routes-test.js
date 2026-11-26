import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class DocsPanelStubService extends Service {
    open() {}
}

module('Unit | Route | fleet-ops hub index routes', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:docs-panel', DocsPanelStubService);
    });

    test('it registers the FleetOps hub and redirect route templates', function (assert) {
        assert.ok(this.owner.resolveRegistration('route:management/index'), 'management redirect route is registered');
        assert.ok(this.owner.resolveRegistration('route:analytics/index'), 'analytics hub route is registered');
        assert.ok(this.owner.resolveRegistration('template:analytics/index'), 'analytics hub template is registered');
        assert.ok(this.owner.resolveRegistration('route:settings/index'), 'settings redirect route is registered');
    });
});
