import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | order-actions', function (hooks) {
    setupTest(hooks);

    test('schedulePickup plans collection-ready orders through collectionPlanning and direct orders via save', async function (assert) {
        let modalOptions = null;
        let plannedPayload = null;
        let savedScheduledAt = null;
        let refreshed = 0;

        class ModalsManagerStub extends Service {
            show(_name, options) {
                modalOptions = options;
            }
            setOption(key, value) {
                modalOptions[key] = value;
            }
            setOptions(hash) {
                Object.assign(modalOptions, hash);
            }
            done() {}
        }

        class CollectionPlanningStub extends Service {
            loaded = true;
            pickupDates = ['2026-12-01', '2026-12-02'];
            async plan(input) {
                plannedPayload = input;
                return { rounds: [] };
            }
        }

        class NotificationsStub extends Service {
            success() {}
            warning() {}
            serverError() {}
        }

        class HostRouterStub extends Service {
            async refresh() {
                refreshed += 1;
            }
        }

        this.owner.register('service:modals-manager', ModalsManagerStub);
        this.owner.register('service:collection-planning', CollectionPlanningStub);
        this.owner.register('service:notifications', NotificationsStub);
        this.owner.register('service:host-router', HostRouterStub);

        const service = this.owner.lookup('service:order-actions');
        assert.ok(service);

        const collectionOrder = {
            id: 'order-uuid-1',
            tracking: 'TRK-100',
            meta: { collection_workflow: { version: 1, state: 'ready_for_pickup' } },
        };
        await service.schedulePickup(collectionOrder);

        assert.strictEqual(modalOptions.mode, 'collection-ready');
        assert.deepEqual(modalOptions.pickupDates, ['2026-12-01', '2026-12-02']);

        const fakeModal = { startLoading() {}, stopLoading() {}, done() {}, getOption: (key) => modalOptions[key] };
        await modalOptions.confirm(fakeModal);

        assert.deepEqual(plannedPayload, { order_uuids: ['order-uuid-1'], pickup_date: '2026-12-01' });
        assert.strictEqual(refreshed, 1);

        const directDate = new Date('2026-12-02T09:30:00Z');
        const directOrder = {
            id: 'order-uuid-2',
            tracking: 'TRK-200',
            scheduled_at: null,
            driver_assigned: null,
            set(key, value) {
                this[key] = value;
            },
            async save() {
                savedScheduledAt = this.scheduled_at;
            },
        };
        await service.schedulePickup(directOrder);

        assert.strictEqual(modalOptions.mode, 'direct');
        modalOptions.setScheduledAt(directDate);
        await modalOptions.confirm(fakeModal);

        assert.strictEqual(savedScheduledAt, directDate);
    });
});
