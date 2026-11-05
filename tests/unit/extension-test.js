import { module, test } from 'qunit';
import extension from '@fleetbase/fleetops-engine/extension';

module('Unit | FleetOps extension', function () {
    test('it resolves intl from the application container when registering navigation', function (assert) {
        assert.expect(5);

        let headerMenuOptions;
        const requestedUniverseServices = [];
        const app = {
            lookup(name) {
                assert.strictEqual(name, 'service:intl', 'looks up the application intl service');

                return {
                    t(key) {
                        return `translated:${key}`;
                    },
                };
            },
        };
        const services = {
            menu: {
                registerHeaderMenuItem(_title, _route, options) {
                    headerMenuOptions = options;
                },
                registerAdminMenuPanel() {},
                registerMenuItem() {},
            },
            registry: {
                createRegistries() {},
                registerRenderableComponent() {},
            },
            widget: {
                registerDashboard() {},
                registerWidgets() {},
                registerDefaultWidgets() {},
            },
        };
        const universe = {
            extensionManager: {
                isInstalled() {
                    return false;
                },
            },
            getService(name) {
                requestedUniverseServices.push(name);
                return services[name];
            },
        };

        extension.setupExtension(app, universe);

        const shortcutRoutes = headerMenuOptions.shortcuts.map((shortcut) => shortcut.route);
        assert.deepEqual(requestedUniverseServices, ['menu', 'registry', 'widget'], 'only asks Universe for Universe-owned services');
        assert.ok(shortcutRoutes.includes('console.fleet-ops.management.vehicles'), 'registers the vehicles shortcut');
        assert.notOk(shortcutRoutes.includes('console.fleet-ops.management.trailers'), 'trailers are hidden');
        assert.notOk(shortcutRoutes.includes('console.fleet-ops.connectivity.devices'), 'connectivity is hidden');
    });

    test('it registers the FleetOps analytics dashboard', function (assert) {
        const dashboards = [];
        const registrations = {};
        const defaultRegistrations = {};
        const widgetService = {
            registerDashboard(id) {
                dashboards.push(id);
            },
            registerWidgets(id, widgets) {
                registrations[id] = widgets;
            },
            registerDefaultWidgets(id, widgets) {
                defaultRegistrations[id] = widgets;
            },
        };

        extension.registerWidgets(widgetService);

        assert.deepEqual(dashboards, ['fleet-ops']);
        assert.ok(registrations.dashboard, 'global dashboard widgets remain registered');
        assert.ok(registrations['fleet-ops'], 'FleetOps dashboard widgets are registered');
        assert.strictEqual(registrations['fleet-ops'], registrations.dashboard, 'FleetOps dashboard reuses the same widget suite');
        assert.ok(defaultRegistrations['fleet-ops']?.length > 0, 'FleetOps default dashboard widgets are explicitly registered');
        assert.ok(
            defaultRegistrations['fleet-ops'].every((widget) => widget.default === true),
            'only default widgets are registered as dashboard defaults'
        );
        assert.ok(
            defaultRegistrations['fleet-ops'].some((widget) => widget.id === 'fleet-ops-live-fleet-widget' && widget.widgetId === 'fleet-ops-live-fleet-widget'),
            'default analytics widgets are available'
        );
    });
});
