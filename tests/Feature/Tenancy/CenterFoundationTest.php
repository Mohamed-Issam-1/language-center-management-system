<?php

namespace Tests\Feature\Tenancy;

use App\Models\Center;
use App\Support\Enums\CenterStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CenterFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_language_center_can_be_created(): void
    {
        $center = Center::query()->create([
            'code' => 'GZA-001',
            'identifier_code' => '01',
            'name' => 'Gaza Language Center',
            'email' => 'contact@gaza-center.test',
            'phone' => '+970000000000',
            'address' => 'Gaza',
            'timezone' => 'Asia/Gaza',
        ]);

        $this->assertDatabaseHas('centers', [
            'id' => $center->id,
            'identifier_code' => '01',
            'code' => 'GZA-001',
            'name' => 'Gaza Language Center',
            'status' => CenterStatus::Suspended->value,
        ]);
    }

    public function test_a_new_center_is_suspended_by_default(): void
    {
        $center = Center::query()->create([
            'code' => 'GZA-002',
            'name' => 'Second Language Center',
            'timezone' => 'Asia/Gaza',
        ]);

        $this->assertSame(
            CenterStatus::Suspended,
            $center->fresh()->status,
        );
    }

    public function test_center_status_is_cast_to_center_status_enum(): void
    {
        $center = Center::factory()->active()->create();

        $this->assertInstanceOf(
            CenterStatus::class,
            $center->status,
        );

        $this->assertSame(
            CenterStatus::Active,
            $center->status,
        );
    }

    public function test_center_code_must_be_unique(): void
    {
        Center::factory()->create([
            'code' => 'CENTER-001',
        ]);

        $this->expectException(QueryException::class);

        Center::factory()->create([
            'code' => 'CENTER-001',
        ]);
    }

    public function test_a_center_can_be_suspended_without_deleting_its_record(): void
    {
        $center = Center::factory()->active()->create();

        $center->update([
            'status' => CenterStatus::Suspended,
        ]);

        $this->assertDatabaseHas('centers', [
            'id' => $center->id,
            'status' => CenterStatus::Suspended->value,
        ]);
    }

    public function test_a_center_can_store_its_operating_currency_code(): void
    {
        $center = Center::factory()->create([
            'operating_currency_code' => 'ILS',
        ]);

        $this->assertDatabaseHas('centers', [
            'id' => $center->id,
            'operating_currency_code' => 'ILS',
        ]);
    }

    public function test_center_identifier_code_must_be_unique_when_present(): void
    {
        Center::factory()->create([
            'identifier_code' => '01',
        ]);

        $this->expectException(
            QueryException::class
        );

        Center::factory()->create([
            'identifier_code' => '01',
        ]);
    }

    public function test_existing_center_records_may_temporarily_have_no_identifier_code(): void
    {
        $center = Center::factory()
            ->create([
                'identifier_code' => null,
            ]);

        $this->assertNull(
            $center->identifier_code
        );
    }
}
