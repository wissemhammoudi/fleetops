import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';
import { orderDocumentAccept, validateOrderDocument } from '@fleetbase/ember-ui/utils/order-document-policy';

export default class OrderDetailsDocumentsComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked uploadQueue = [];
    acceptedFileTypes = orderDocumentAccept;

    @task *queueFile(file) {
        if (['queued', 'failed', 'timed_out', 'aborted'].indexOf(file.state) === -1) return;

        try {
            validateOrderDocument(file);
            this.uploadQueue.pushObject(file);
            const uploadedFile = yield this.fetch.uploadFile.perform(
                file,
                {
                    path: 'uploads/fleet-ops/order-files',
                    type: 'order_file',
                },
                null,
                () => {
                    this.uploadQueue.removeObject(file);
                    if (file.queue && typeof file.queue.remove === 'function') {
                        file.queue.remove(file);
                    }
                }
            );
            const fileId = uploadedFile?.uuid ?? uploadedFile?.id;
            if (!fileId) {
                throw new Error('The upload returned no document reference.');
            }
            yield this.fetch.post(`orders/${this.args.resource.id}/files`, { files: [fileId] });
            this.args.resource.files.pushObject(uploadedFile);
        } catch (err) {
            debug('Order document upload failed: ' + err.message);
            this.notifications.serverError(err);
        } finally {
            this.uploadQueue.removeObject(file);
        }
    }

    @task *removeFile(file) {
        if (file.type !== 'order_file') {
            return this.notifications.warning('Generated delivery documents cannot be removed.');
        }
        try {
            yield this.fetch.delete(`orders/${this.args.resource.id}/files/${file.id}`);
            this.args.resource.files.removeObject(file);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
