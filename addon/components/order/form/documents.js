import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';
import { orderDocumentAccept, validateOrderDocument } from '@fleetbase/ember-ui/utils/order-document-policy';

export default class OrderFormDocumentsComponent extends Component {
    @service fetch;
    @service notifications;
    @service orderCreation;
    @tracked uploadQueue = [];
    acceptedFileTypes = orderDocumentAccept;

    @task *queueFile(file) {
        if (['queued', 'failed', 'timed_out', 'aborted'].indexOf(file.state) === -1) return;

        this.orderCreation.uploadsPending++;
        try {
            validateOrderDocument(file);
            this.uploadQueue.pushObject(file);
            const uploadOptions = {
                path: 'uploads/fleet-ops/order-files',
                type: 'order_file',
            };

            yield this.fetch.uploadFile.perform(
                file,
                uploadOptions,
                (uploadedFile) => {
                    this.args.resource.files.pushObject(uploadedFile);
                    this.uploadQueue.removeObject(file);
                },
                () => {
                    this.uploadQueue.removeObject(file);
                    if (file.queue && typeof file.queue.remove === 'function') {
                        file.queue.remove(file);
                    }
                }
            );
        } catch (err) {
            debug('Order document upload failed: ' + err.message);
            this.notifications.serverError(err);
        } finally {
            this.uploadQueue.removeObject(file);
            this.orderCreation.uploadsPending--;
        }
    }

    @task *removeFile(file) {
        if (!this.args.resource.isNew && file.subject_uuid === this.args.resource.id) {
            if (file.type !== 'order_file') {
                return this.notifications.warning('Generated delivery documents cannot be removed.');
            }
            yield this.fetch.delete(`orders/${this.args.resource.id}/files/${file.id}`);
        }
        this.args.resource.files.removeObject(file);
    }
}
