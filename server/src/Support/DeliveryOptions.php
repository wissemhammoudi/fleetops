<?php

namespace Fleetbase\FleetOps\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeliveryOptions
{
    public const REVISION = 2;
    public const STANDARD_PRIORITY = 50;
    public const EXPRESS_PRIORITY = 100;

    public static function catalogue(): array
    {
        return [
            'revision' => self::REVISION,
            'purposes' => [
                ['value' => 'transportation', 'label' => 'Transport'],
                ['value' => 'return', 'label' => 'Return'],
            ],
            'service_levels' => [
                ['value' => 'standard', 'label' => 'Standard', 'available' => true, 'description' => 'Normal delivery service. Get a quote before confirming.'],
                ['value' => 'express', 'label' => 'Express', 'available' => false, 'description' => 'Priority handling at a higher delivery price. Pricing is being configured; unavailable for new orders.'],
            ],
            'packaging_types' => [
                ['value' => 'padded_envelope', 'label' => 'Padded paper/plastic envelope'],
                ['value' => 'plain_envelope', 'label' => 'Unpadded paper/plastic envelope (not compliant)'],
                ['value' => 'reinforced_envelope', 'label' => 'Paper/plastic envelope with cardboard inside'],
                ['value' => 'colab_packaging', 'label' => 'Official Colab Express packaging'],
                ['value' => 'box', 'label' => 'Cardboard box'],
                ['value' => 'textile_bag', 'label' => 'Textile bag (not compliant)'],
            ],
            'notice' => 'Express gives dispatch priority, not a guaranteed delivery time. Prepare every parcel according to the Colab Express packaging rules. The driver must refuse pickup of non-compliant packaging and record the reason. Repackage the goods for a new check; the order is not canceled.',
            'customer_instructions' => [
                'Choose an approved outer container suitable for the size, shape, and weight of the contents.',
                'Never use a plain unpadded envelope or a textile bag as the outer shipping container.',
                'Fragile goods, glassware, perfume, jewelry, ceramics, fragile toys, figurines, CDs, and delicate electronics require a rigid cardboard box with internal cushioning.',
                'Wrap and separate fragile items. Use bubble wrap or other cushioning and fill empty spaces so nothing moves or strikes the walls.',
                'Seal the parcel securely with strong adhesive tape so it cannot open accidentally during normal transport.',
                'Official Colab Express packaging is accepted only for the categories listed in the guide.',
            ],
            'driver_checks' => self::driverChecks(),
            'damage_guidance' => 'If damage is found, record the observed condition and whether inadequate packaging, handling, suspected tampering, or defective repackaging may be involved. Do not assume fraud or open sealed contents without authorization. If internal protection cannot be verified, ask the sender to demonstrate compliant preparation before acceptance.',
            'categories' => [
                self::category('clothing', 'Clothing', self::standardPackaging(), 'Clothes and textiles.', [
                    'Use a padded envelope, an envelope reinforced with cardboard, official Colab Express packaging, or a cardboard box.',
                    'Protect the clothing from moisture, stabilize the contents, and seal the outer packaging securely.',
                ], 'Plain unpadded envelopes and textile outer bags are not compliant.'),
                self::category('shoes', 'Shoes', self::standardPackaging(), 'Shoes and footwear.', [
                    'Use a padded envelope, an envelope reinforced with cardboard, official Colab Express packaging, or a cardboard box.',
                    'Protect and stabilize each shoe; use a container that will not tear under the weight.',
                ], 'Plain unpadded envelopes and textile outer bags are not compliant.'),
                self::category('cosmetics', 'Cosmetics', ['padded_envelope', 'reinforced_envelope', 'box'], 'Creams and makeup. Perfume and glass containers follow the fragile-item rule.', [
                    'Close and protect each container against leaks, separate items, and add cushioning.',
                    'Use a padded envelope, an envelope reinforced with cardboard, or a cardboard box.',
                ], 'Official Colab Express packaging, plain envelopes, and textile bags are not approved for cosmetics. Fragile containers require a cushioned rigid box.'),
                self::category('toys', 'Toys', self::standardPackaging(), 'Non-fragile toys. Fragile toys and figurines follow the fragile-item rule.', [
                    'Protect protruding parts and stabilize the toy inside an approved container.',
                    'Use a padded envelope, a cardboard-reinforced envelope, official Colab Express packaging, or a cardboard box.',
                ], 'Use the Fragile items category for fragile toys and figurines.'),
                self::category('books', 'Books', self::standardPackaging(), 'Books and printed volumes.', [
                    'Protect corners and covers, prevent movement, and keep the contents dry.',
                    'Use a padded envelope, a cardboard-reinforced envelope, official Colab Express packaging, or a cardboard box.',
                ], 'Plain unpadded envelopes and textile outer bags are not compliant.'),
                self::category('furniture', 'Furniture', ['reinforced_envelope', 'box'], 'Furniture and furniture parts.', [
                    'Use an envelope reinforced with cardboard only when it fully supports the size, shape, and weight of the item; otherwise use a suitable cardboard box.',
                    'Protect corners, edges, and separate parts; immobilize the contents and seal securely.',
                ], 'Do not use undersized or collapsing packaging. Padded envelopes alone, official Colab Express packaging, and textile bags are not approved. Confirm service size and weight limits.'),
                self::category('fragile', 'Fragile items', ['box'], 'Glassware, dishes, perfume, jewelry, ceramics, fragile toys, figurines, and CDs.', [
                    'A rigid cardboard box with internal cushioning is mandatory.',
                    'Wrap each item separately and use separators, bubble wrap, or suitable cushioning on every side.',
                    'Fill empty spaces so nothing moves or strikes the walls, then seal securely.',
                ], 'All envelopes, including cardboard-reinforced envelopes, official Colab Express packaging, and textile bags are refused for fragile goods.'),
                self::category('documents', 'Documents', ['padded_envelope', 'reinforced_envelope', 'box'], 'Letters, paperwork, and printed documents.', [
                    'Use protective padded or cardboard-reinforced packaging, or a cardboard box. Keep dry and seal securely.',
                ], 'Plain unpadded envelopes and textile bags are not approved.'),
                self::category('electronics', 'Electronics', ['box'], 'Electronic devices and accessories.', [
                    'Use a rigid box with cushioning and fill empty spaces.',
                    'Protect accessories separately and use anti-static protection where appropriate.',
                    'Secure the contents so they cannot move inside the box.',
                ], 'Contact support about batteries and declared-value requirements before sending.'),
                self::category('food', 'Food', ['box'], 'Accepted food products in sealed food-safe packaging.', [
                    'Use sealed, food-safe inner packaging.',
                    'Prevent leakage and keep separate items protected.',
                    'Use outer packaging appropriate to the contents.',
                ], 'Confirm food acceptance with support. Insulated packaging does not provide a temperature-controlled delivery service.'),
                self::category('other', 'Other', ['box'], 'Other goods: describe the contents clearly.', [
                    'Describe the contents and any handling requirements.',
                    'Use strong, sealed packaging suitable for the contents.',
                    'Protect the contents against movement and moisture.',
                ], 'Contact support about restricted goods and applicable limits.'),
            ],
        ];
    }

    protected static function standardPackaging(): array
    {
        return ['padded_envelope', 'reinforced_envelope', 'colab_packaging', 'box'];
    }

    public static function driverChecks(): array
    {
        return [
            ['key' => 'category', 'label' => 'Contents category identified, including fragile, heavy, sharp, liquid, electronic, or furniture items'],
            ['key' => 'outer_packaging', 'label' => 'Approved outer packaging fits the size, shape, and weight; it is not undersized or collapsing'],
            ['key' => 'inner_protection', 'label' => 'Adequate cushioning, wrapping, separators, or supports verified; fragile items use a cushioned rigid box'],
            ['key' => 'stabilization', 'label' => 'Contents cannot slide excessively or strike the inside walls'],
            ['key' => 'sealing', 'label' => 'Parcel is securely closed and sealed for normal road transport'],
            ['key' => 'condition', 'label' => 'No unresolved damage, leakage, suspected tampering, or defective repackaging observed'],
        ];
    }

    protected static function category(string $code, string $label, array $packaging, string $examples, array $instructions, string $warning): array
    {
        return [
            'code' => $code, 'label' => $label, 'packaging_types' => $packaging,
            'examples' => $examples, 'instructions' => $instructions, 'warning' => $warning,
            'revision' => self::REVISION,
        ];
    }

    public static function validateOrderMeta($meta): void
    {
        if (data_get($meta, 'delivery') === null) {
            return;
        }

        Validator::make(['delivery' => data_get($meta, 'delivery')], [
            'delivery' => ['required', 'array'],
            'delivery.purpose' => ['required', Rule::in(['transportation', 'return'])],
            'delivery.service_level' => ['required', Rule::in(['standard', 'express'])],
            'delivery.opening_allowed' => ['sometimes', 'boolean'],
            'delivery.original_order_uuid' => ['nullable', 'string', 'max:64', Rule::prohibitedIf(data_get($meta, 'delivery.purpose') !== 'return')],
            'delivery.return_reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();
    }

    public static function validateParcelMeta($meta, bool $required = false): void
    {
        if (!$required && data_get($meta, 'parcel') === null) {
            return;
        }

        $categories = array_column(self::catalogue()['categories'], null, 'code');
        $code = data_get($meta, 'parcel.category');
        $category = is_string($code) ? ($categories[$code] ?? null) : null;
        Validator::make(['parcel' => data_get($meta, 'parcel')], [
            'parcel' => ['required', 'array'],
            'parcel.category' => ['required', Rule::in(array_keys($categories))],
            'parcel.packaging_type' => ['required', Rule::in($category['packaging_types'] ?? [])],
            'parcel.guide_revision' => ['required', 'integer', Rule::in([self::REVISION])],
        ])->validate();
    }

    public static function validateInput(array $input): void
    {
        self::validateOrderMeta($input['meta'] ?? []);
        self::validateNewService($input['meta'] ?? []);
        $payload = self::payloadInput($input);
        $required = data_get($input, 'meta.delivery') !== null;
        $entities = $payload['entities'] ?? [];
        $rules = ['entities' => ['array'], 'entities.*' => ['array']];
        if ($required) {
            $rules = array_merge($rules, [
                'entities' => ['required', 'array', 'min:1'],
                'entities.*.name' => ['required', 'string', 'max:255'],
                'entities.*.quantity' => ['sometimes', 'integer', 'min:1'],
                'entities.*.weight' => ['nullable', 'numeric', 'min:0'],
                'entities.*.length' => ['nullable', 'numeric', 'min:0'],
                'entities.*.width' => ['nullable', 'numeric', 'min:0'],
                'entities.*.height' => ['nullable', 'numeric', 'min:0'],
                'entities.*.declared_value' => ['nullable', 'numeric', 'min:0'],
                'entities.*.weight_unit' => ['nullable', 'string', 'max:32'],
                'entities.*.dimensions_unit' => ['nullable', 'string', 'max:32'],
                'entities.*.currency' => ['nullable', 'string', 'size:3'],
            ]);
            Validator::make($input, [
                'order_config' => ['nullable', 'string'],
                'type' => ['nullable', 'string'],
                'scheduled_at' => ['nullable', 'date'],
                'payload' => ['sometimes', 'array'],
                'pod_required' => ['sometimes', 'boolean'],
                'pod_method' => [Rule::requiredIf(filter_var($input['pod_required'] ?? false, FILTER_VALIDATE_BOOLEAN)), 'nullable', 'string'],
            ])->validate();
            Validator::make($payload, [
                'pickup' => ['required_without:waypoints', 'required_with:dropoff'],
                'dropoff' => ['required_without:waypoints', 'required_with:pickup'],
                'waypoints' => ['sometimes', 'array'],
                'waypoints.*' => ['required', 'array'],
                'waypoints.*.type' => ['sometimes', Rule::in(['pickup', 'dropoff'])],
                'cod_amount' => ['nullable', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'],
                'cod_currency' => ['required_with:cod_amount', 'nullable', 'string', 'regex:/^[A-Z]{3}$/'],
                'cod_payment_method' => ['required_with:cod_amount', 'nullable', Rule::in(['cash'])],
            ])->after(function ($validator) use ($payload) {
                if (empty($payload['pickup']) && empty($payload['dropoff']) && (!is_array($payload['waypoints'] ?? null) || count($payload['waypoints']) < 2)) {
                    $validator->errors()->add('waypoints', 'Select pickup and dropoff or at least two route stops.');
                }
                if (empty($payload['pickup']) && empty($payload['dropoff']) && is_array($payload['waypoints'] ?? null) && count($payload['waypoints']) >= 2) {
                    $waypoints = array_values($payload['waypoints']);
                    if (data_get($waypoints[0], 'type') !== 'pickup' || data_get($waypoints[count($waypoints) - 1], 'type', 'dropoff') !== 'dropoff') {
                        $validator->errors()->add('waypoints', 'A waypoint-only delivery must start with a pickup and end with a drop-off.');
                    }
                }
                if (empty($payload['cod_amount']) && (!empty($payload['cod_currency']) || !empty($payload['cod_payment_method']))) {
                    $validator->errors()->add('cod_amount', 'Enter the cash amount to collect or remove the COD settings.');
                }
            })->validate();
        }
        Validator::make(['entities' => $entities], $rules)->validate();
        foreach ($entities as $entity) {
            self::validateParcelMeta(data_get($entity, 'meta', []), $required);
        }
    }

    public static function payloadInput(array $input): array
    {
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        foreach (['pickup', 'dropoff', 'return', 'waypoints', 'entities', 'cod_amount', 'cod_currency', 'cod_payment_method'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            if (array_key_exists($key, $payload) && self::sortKeys($payload[$key]) !== self::sortKeys($input[$key])) {
                throw ValidationException::withMessages([$key => 'Conflicting top-level and payload delivery fields. Send one consistent value.']);
            }
            $payload[$key] = $input[$key];
        }

        return $payload;
    }

    public static function validateNewService($meta): void
    {
        Validator::make(['service_level' => data_get($meta, 'delivery.service_level')], [
            'service_level' => ['nullable', Rule::in(['standard'])],
        ], ['service_level.in' => 'Express pricing is not configured. Choose Standard and get a new delivery quote.'])->validate();
    }

    public static function fingerprint(array $input): string
    {
        $payload = self::payloadInput($input);
        $values = [
            'delivery' => data_get($input, 'meta.delivery'),
            'order_config' => $input['order_config'] ?? $input['type'] ?? null,
            'pickup' => $payload['pickup'] ?? null,
            'dropoff' => $payload['dropoff'] ?? null,
            'return' => $payload['return'] ?? null,
            'waypoints' => $payload['waypoints'] ?? [],
            'entities' => $payload['entities'] ?? [],
            'scheduled_at' => $input['scheduled_at'] ?? null,
            'cod_amount' => $payload['cod_amount'] ?? null,
            'cod_currency' => $payload['cod_currency'] ?? null,
            'cod_payment_method' => $payload['cod_payment_method'] ?? null,
        ];

        return hash('sha256', json_encode(self::sortKeys($values), JSON_THROW_ON_ERROR));
    }

    protected static function sortKeys($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map([self::class, 'sortKeys'], $value);
    }
}
