import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { next } from '@ember/runloop';
import Evented from '@ember/object/evented';

export const SERVICE_QUOTE_REFRESH_REQUESTED = 'service-quote-refresh-requested';

export default class OrderCreationService extends Service.extend(Evented) {
    @service orderActions;
    @service deliveryOptions;
    @tracked context;
    @tracked order;
    @tracked cfManager;
    @tracked serviceQuoteOverrides = {};
    @tracked uploadsPending = 0;
    orderValidationRules = {};

    newOrder(attrs = {}) {
        const order = this.orderActions.createNewInstance(attrs);
        this.order = order;

        next(() => {
            this.addContext('order', order);
        });

        return order;
    }

    getContext(key) {
        return key ? this.context?.[key] : this.context;
    }

    addContext(key, value) {
        this.context = {
            ...this.context,
            [key]: value,
        };
        this[key] = value;
    }

    removeContext(key) {
        delete this.context[key];
    }

    requestServiceQuoteRefresh(reason, order = this.order) {
        if (order && !this.getServiceQuoteOverride()) {
            order.service_quote_uuid = null;
        }
        this.trigger(SERVICE_QUOTE_REFRESH_REQUESTED, {
            reason,
            order,
        });
    }

    setServiceQuoteOverride(key, config = {}) {
        this.serviceQuoteOverrides = {
            ...this.serviceQuoteOverrides,
            [key]: {
                ...config,
                key,
            },
        };
    }

    getServiceQuoteOverride() {
        return Object.values(this.serviceQuoteOverrides ?? {}).find((override) => override?.mode === 'locked') ?? null;
    }

    clearServiceQuoteOverride(key) {
        const overrides = { ...(this.serviceQuoteOverrides ?? {}) };
        delete overrides[key];
        this.serviceQuoteOverrides = overrides;
    }

    setOrderValidationRule(key, fn) {
        if (typeof fn !== 'function') {
            return;
        }

        this.orderValidationRules = {
            ...(this.orderValidationRules ?? {}),
            [key]: fn,
        };
    }

    clearOrderValidationRule(key) {
        const rules = { ...(this.orderValidationRules ?? {}) };
        delete rules[key];
        this.orderValidationRules = rules;
    }

    validateOrderRules(order, cfManager = null) {
        const required = Boolean(order?.meta?.delivery);
        const entities = order?.payload?.entities ?? [];
        const parcelsValid = (!required || (this.deliveryOptions.catalogue && entities.length)) && entities.every((entity) => this.deliveryOptions.isValidEntity(entity, required));
        const payload = order?.payload ?? {};
        const codValid =
            !order?.isNew ||
            (!payload.cod_amount && !payload.cod_currency && !payload.cod_payment_method) ||
            this.deliveryOptions.isValidCod(payload.cod_amount, payload.cod_currency, payload.cod_payment_method);
        const quoteValid = !order?.isNew || !order.servicable || this.getServiceQuoteOverride() || Boolean(order.service_quote_uuid);
        const detailsValid =
            (!order?.pod_required || ['scan', 'signature', 'photo'].includes(order.pod_method)) &&
            (!order?.scheduled_at || Number.isFinite(new Date(order.scheduled_at).getTime())) &&
            (!order?.isNew || order?.meta?.delivery?.service_level !== 'express');
        return Boolean(
            !this.uploadsPending && detailsValid && quoteValid && codValid && parcelsValid && Object.values(this.orderValidationRules ?? {}).every((fn) => fn(order, cfManager) !== false)
        );
    }
}
