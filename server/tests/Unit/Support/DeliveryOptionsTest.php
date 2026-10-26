<?php

use Fleetbase\FleetOps\Support\DeliveryOptions;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../src/Support/DeliveryOptions.php';

class DeliveryOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container();
        $container->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $container));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    public function test_standard_is_available_for_both_purposes_and_legacy_express_meta_remains_readable(): void
    {
        foreach (['transportation', 'return'] as $purpose) {
            foreach (['standard', 'express'] as $level) {
                DeliveryOptions::validateOrderMeta(['delivery' => ['purpose' => $purpose, 'service_level' => $level]]);
            }
        }
        $this->addToAssertionCount(4);
    }

    public function test_new_express_requests_are_rejected_until_pricing_is_approved(): void
    {
        $this->assertFalse(DeliveryOptions::catalogue()['service_levels'][1]['available']);
        $this->expectException(ValidationException::class);
        DeliveryOptions::validateInput(['meta' => ['delivery' => ['purpose' => 'return', 'service_level' => 'express']]]);
    }

    public function test_opening_permission_must_be_boolean(): void
    {
        $this->expectException(ValidationException::class);
        DeliveryOptions::validateOrderMeta(['delivery' => ['purpose' => 'transportation', 'service_level' => 'standard', 'opening_allowed' => 'yes']]);
    }

    public function test_legacy_orders_and_parcels_remain_valid(): void
    {
        DeliveryOptions::validateInput(['payload' => ['entities' => [['name' => 'Legacy parcel']]]]);
        DeliveryOptions::validateInput(['entities' => null]);
        $this->addToAssertionCount(1);
    }

    public function test_transportation_cannot_link_an_original_return_order(): void
    {
        $this->expectException(ValidationException::class);
        DeliveryOptions::validateOrderMeta(['delivery' => ['purpose' => 'transportation', 'service_level' => 'standard', 'original_order_uuid' => 'original']]);
    }

    public function test_malformed_category_produces_a_validation_error(): void
    {
        $this->expectException(ValidationException::class);
        DeliveryOptions::validateParcelMeta(['parcel' => ['category' => ['documents'], 'packaging_type' => 'envelope', 'guide_revision' => 1]]);
    }

    public function test_invalid_service_level_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        DeliveryOptions::validateInput(['meta' => ['delivery' => ['purpose' => 'return', 'service_level' => 'overnight']]]);
    }

    public function test_new_delivery_options_require_classification_for_each_supplied_parcel(): void
    {
        $this->expectException(ValidationException::class);
        DeliveryOptions::validateInput([
            'meta' => ['delivery' => ['purpose' => 'transportation', 'service_level' => 'standard']],
            'entities' => [['name' => 'Unclassified parcel']],
        ]);
    }

    public function test_category_packaging_and_revision_are_validated(): void
    {
        foreach (DeliveryOptions::catalogue()['categories'] as $category) {
            foreach ($category['packaging_types'] as $packaging) {
                DeliveryOptions::validateParcelMeta(['parcel' => ['category' => $category['code'], 'packaging_type' => $packaging, 'guide_revision' => DeliveryOptions::REVISION]]);
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_fragile_items_cannot_use_an_envelope(): void
    {
        $this->expectException(ValidationException::class);
        DeliveryOptions::validateParcelMeta(['parcel' => ['category' => 'fragile', 'packaging_type' => 'reinforced_envelope', 'guide_revision' => DeliveryOptions::REVISION]]);
    }

    public function test_unknown_category_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        DeliveryOptions::validateParcelMeta(['parcel' => ['category' => 'unknown', 'packaging_type' => 'box', 'guide_revision' => DeliveryOptions::REVISION]]);
    }

    public function test_old_guidance_revision_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        DeliveryOptions::validateParcelMeta(['parcel' => ['category' => 'documents', 'packaging_type' => 'box', 'guide_revision' => 1]]);
    }

    public function test_every_disallowed_category_packaging_pair_is_rejected(): void
    {
        foreach (DeliveryOptions::catalogue()['categories'] as $category) {
            foreach (DeliveryOptions::catalogue()['packaging_types'] as $packaging) {
                if (in_array($packaging['value'], $category['packaging_types'], true)) {
                    continue;
                }
                try {
                    DeliveryOptions::validateParcelMeta(['parcel' => [
                        'category' => $category['code'],
                        'packaging_type' => $packaging['value'],
                        'guide_revision' => DeliveryOptions::REVISION,
                    ]]);
                    $this->fail($category['code'] . ' must reject ' . $packaging['value']);
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('parcel.packaging_type', $exception->errors());
                }
            }
        }
    }

    public function test_quote_fingerprint_handles_root_and_nested_payloads(): void
    {
        $draft = ['meta' => ['delivery' => ['purpose' => 'return', 'service_level' => 'express']], 'pickup' => ['uuid' => 'pickup'], 'dropoff' => ['uuid' => 'dropoff'], 'entities' => [['name' => 'Package']], 'scheduled_at' => null];
        $nested = ['meta' => $draft['meta'], 'payload' => ['entities' => $draft['entities'], 'dropoff' => $draft['dropoff'], 'pickup' => $draft['pickup']]];
        $this->assertSame(DeliveryOptions::fingerprint($draft), DeliveryOptions::fingerprint($nested));
        foreach (['purpose' => 'transportation', 'service_level' => 'standard', 'original_order_uuid' => 'different-order', 'opening_allowed' => true] as $field => $value) {
            $changed = $draft;
            $changed['meta']['delivery'][$field] = $value;
            $this->assertNotSame(DeliveryOptions::fingerprint($draft), DeliveryOptions::fingerprint($changed));
        }
        $changed = $draft;
        $changed['entities'][0]['meta']['parcel'] = ['category' => 'food'];
        $this->assertNotSame(DeliveryOptions::fingerprint($draft), DeliveryOptions::fingerprint($changed));
    }

    public function test_waypoint_only_modern_routes_require_a_pickup_start_and_dropoff_end(): void
    {
        $category = DeliveryOptions::catalogue()['categories'][0];
        $draft = [
            'meta' => ['delivery' => ['purpose' => 'transportation', 'service_level' => 'standard']],
            'waypoints' => [['place' => ['uuid' => 'sender'], 'type' => 'pickup'], ['place' => ['uuid' => 'recipient'], 'type' => 'dropoff']],
            'entities' => [['name' => 'Parcel', 'meta' => ['parcel' => [
                'category' => $category['code'], 'packaging_type' => $category['packaging_types'][0], 'guide_revision' => DeliveryOptions::REVISION,
            ]]]],
        ];
        DeliveryOptions::validateInput($draft);
        $this->addToAssertionCount(1);
        foreach (['dropoff', null] as $firstType) {
            $invalid = $draft;
            $invalid['waypoints'][0]['type'] = $firstType;
            try {
                DeliveryOptions::validateInput($invalid);
                $this->fail('A recipient stop cannot replace the route pickup.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('waypoints', $exception->errors());
            }
        }
        $draft['waypoints'][1]['type'] = 'pickup';
        $this->expectException(ValidationException::class);
        DeliveryOptions::validateInput($draft);
    }
}
