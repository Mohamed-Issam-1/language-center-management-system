<?php

namespace Tests\Feature\Tenancy;

use App\Models\Center;
use App\Models\Person;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_belongs_to_exactly_one_language_center(): void
    {
        $center = Center::factory()->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $this->assertTrue(
            $person->center->is($center)
        );

        $this->assertDatabaseHas('people', [
            'id' => $person->id,
            'center_id' => $center->id,
        ]);
    }

    public function test_a_person_receives_a_system_generated_internal_id(): void
    {
        $person = Person::factory()->create();

        $this->assertNotNull($person->id);
        $this->assertIsInt($person->id);
    }

    public function test_national_id_number_is_required_at_database_level(): void
    {
        $center = Center::factory()->create();

        $this->expectException(QueryException::class);

        Person::query()->create([
            'center_id' => $center->id,
        ]);
    }

    public function test_the_same_national_id_cannot_create_two_people_in_the_same_center(): void
    {
        $center = Center::factory()->create();

        Person::factory()->create([
            'center_id' => $center->id,
            'national_id_number' => '123456789',
        ]);

        $this->expectException(QueryException::class);

        Person::factory()->create([
            'center_id' => $center->id,
            'national_id_number' => '123456789',
        ]);
    }

    public function test_the_same_national_id_can_exist_in_different_centers(): void
    {
        $centerA = Center::factory()->create();
        $centerB = Center::factory()->create();

        $personA = Person::factory()->create([
            'center_id' => $centerA->id,
            'national_id_number' => '987654321',
        ]);

        $personB = Person::factory()->create([
            'center_id' => $centerB->id,
            'national_id_number' => '987654321',
        ]);

        $this->assertNotSame(
            $personA->id,
            $personB->id,
        );

        $this->assertDatabaseCount('people', 2);
    }

    public function test_a_center_exposes_only_its_own_people_through_the_relationship(): void
    {
        $centerA = Center::factory()->create();
        $centerB = Center::factory()->create();

        $personA = Person::factory()
            ->for($centerA)
            ->create();

        Person::factory()
            ->for($centerB)
            ->create();

        $this->assertCount(1, $centerA->people);
        $this->assertTrue(
            $centerA->people->first()->is($personA)
        );
    }

    public function test_a_center_with_people_cannot_be_deleted_by_database_cascade(): void
    {
        $center = Center::factory()->create();

        Person::factory()
            ->for($center)
            ->create();

        $this->expectException(QueryException::class);

        $center->delete();
    }

    public function test_person_can_store_shared_registration_identity_information(): void
    {
        $center = Center::factory()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '111222333',

                'full_name' =>
                'Ahmad Mohammed',

                'date_of_birth' =>
                '2001-05-15',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                'ahmad@example.test',

                'phone_number' =>
                '+970599123456',

                'personal_picture_path' =>
                'people/photos/example.jpg',
            ]);

        $this->assertDatabaseHas(
            'people',
            [
                'id' => $person->id,

                'full_name' =>
                'Ahmad Mohammed',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                'ahmad@example.test',

                'phone_number' =>
                '+970599123456',

                'personal_picture_path' =>
                'people/photos/example.jpg',
            ]
        );

        $this->assertSame(
            '2001-05-15',
            $person
                ->date_of_birth
                ->format('Y-m-d')
        );
    }

    public function test_legacy_person_creation_can_temporarily_omit_registration_identity_fields(): void
    {
        $center = Center::factory()
            ->create();

        $person = Person::query()
            ->create([
                'center_id' =>
                $center->id,

                'national_id_number' =>
                '444555666',
            ]);

        $this->assertDatabaseHas(
            'people',
            [
                'id' => $person->id,

                'national_id_number' =>
                '444555666',

                'full_name' =>
                null,
            ]
        );
    }
}
