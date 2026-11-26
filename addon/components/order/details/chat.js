import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class OrderDetailsChatComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked chatData = null;
    @tracked loading = true;
    @tracked sending = false;
    @tracked content = '';
    @tracked imageBase64 = null;
    @tracked imageFilename = null;
    @tracked imageContentType = null;
    @tracked previewUrl = null;
    @tracked viewerUrl = null;
    pollTimer = null;

    constructor() {
        super(...arguments);
        this.loadChat();
        this.pollTimer = setInterval(() => this.loadChat(true), 10000);
    }

    willDestroy() {
        super.willDestroy(...arguments);
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }

    get orderId() {
        return this.args.resource?.id ?? this.args.resource?.uuid;
    }

    get isActive() {
        return Boolean(this.chatData?.active);
    }

    get participants() {
        return this.chatData?.participants ?? [];
    }

    get messages() {
        return this.chatData?.messages ?? [];
    }

    get canSend() {
        return !this.sending && (this.content.trim().length > 0 || Boolean(this.imageBase64));
    }

    @action async loadChat(silent = false, open = false) {
        if (!this.orderId) {
            this.loading = false;
            return;
        }

        if (!silent) {
            this.loading = true;
        }

        try {
            const params = open ? { open: 1 } : {};
            this.chatData = await this.fetch.get(`orders/${this.orderId}/chat`, params);
        } catch (error) {
            if (!silent) {
                this.notifications.serverError(error);
            }
        } finally {
            this.loading = false;
        }
    }

    @action openChannel() {
        return this.loadChat(false, true);
    }

    @action updateContent(event) {
        this.content = event.target.value;
    }

    @action selectImage(event) {
        const file = event.target?.files?.[0];
        if (!file) {
            return;
        }

        if (!file.type?.startsWith('image/')) {
            this.notifications.warning('Please select an image file (PNG, JPG, WEBP, GIF, or HEIC).');
            event.target.value = '';
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            this.notifications.warning('Image must be 10 MB or smaller.');
            event.target.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = () => {
            const result = typeof reader.result === 'string' ? reader.result : '';
            this.previewUrl = result;
            this.imageBase64 = result;
            this.imageFilename = file.name || 'photo.jpg';
            this.imageContentType = file.type || 'image/jpeg';
        };
        reader.readAsDataURL(file);
        event.target.value = '';
    }

    @action clearImage() {
        this.previewUrl = null;
        this.imageBase64 = null;
        this.imageFilename = null;
        this.imageContentType = null;
    }

    @action openImage(url) {
        this.viewerUrl = url;
    }

    @action closeViewer() {
        this.viewerUrl = null;
    }

    @action async sendMessage(event) {
        event?.preventDefault?.();

        const trimmed = this.content.trim();
        if (!trimmed && !this.imageBase64) {
            this.notifications.warning('Enter a message or attach an image.');
            return;
        }

        this.sending = true;
        try {
            const payload = {
                content: trimmed,
                ...(this.imageBase64
                    ? {
                          image_base64: this.imageBase64,
                          image_filename: this.imageFilename || 'photo.jpg',
                          image_content_type: this.imageContentType || 'image/jpeg',
                      }
                    : {}),
            };
            this.chatData = await this.fetch.post(`orders/${this.orderId}/chat/messages`, payload);
            this.content = '';
            this.clearImage();
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.sending = false;
        }
    }
}
