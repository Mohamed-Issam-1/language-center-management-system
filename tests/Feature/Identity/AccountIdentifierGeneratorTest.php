<?php

namespace Tests\Feature\Identity;

use App\Models\Center;
use App\Models\User;
use App\Services\Accounts\AccountIdentifierGenerator;
use App\Support\Enums\SystemRole;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountIdentifierGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_identifier_uses_center_role_and_four_digit_sequence(): void
    {
        $center = Center::factory()
            ->create([
                'identifier_code' => '01',
            ]);

        $identifier =
            $this->generator()
            ->generate(
                $center,
                SystemRole::Teacher
            );

        $this->assertSame(
            '01120001',
            $identifier
        );

        $this->assertSame(
            8,
            strlen($identifier)
        );
    }

    public function test_repeated_generation_increments_sequence(): void
    {
        $center = Center::factory()
            ->create([
                'identifier_code' => '02',
            ]);

        $first =
            $this->generator()
            ->generate(
                $center,
                SystemRole::Teacher
            );

        $second =
            $this->generator()
            ->generate(
                $center,
                SystemRole::Teacher
            );

        $this->assertSame(
            '02120001',
            $first
        );

        $this->assertSame(
            '02120002',
            $second
        );

        $this->assertNotSame(
            $first,
            $second
        );
    }

    public function test_fixed_role_domains_are_mapped_correctly(): void
    {
        $center = Center::factory()
            ->create([
                'identifier_code' => '03',
            ]);

        $this->assertSame(
            '03100001',
            $this->generator()->generate(
                $center,
                SystemRole::CenterOwner
            )
        );

        $this->assertSame(
            '03110001',
            $this->generator()->generate(
                $center,
                SystemRole::BranchManager
            )
        );

        $this->assertSame(
            '03120001',
            $this->generator()->generate(
                $center,
                SystemRole::Teacher
            )
        );

        $this->assertSame(
            '03210001',
            $this->generator()->generate(
                $center,
                SystemRole::FinanceEmployee
            )
        );
    }

    public function test_platform_owner_uses_reserved_zero_center_and_role_domains(): void
    {
        $identifier =
            $this->generator()
            ->generate(
                null,
                SystemRole::PlatformOwner
            );

        $this->assertSame(
            '00000001',
            $identifier
        );

        $this->assertSame(
            8,
            strlen($identifier)
        );
    }

    public function test_center_scoped_role_requires_center(): void
    {
        $this->expectException(
            DomainException::class
        );

        $this->generator()
            ->generate(
                null,
                SystemRole::Teacher
            );
    }

    public function test_platform_owner_rejects_center_scope(): void
    {
        $center = Center::factory()
            ->create([
                'identifier_code' => '04',
            ]);

        $this->expectException(
            DomainException::class
        );

        $this->generator()
            ->generate(
                $center,
                SystemRole::PlatformOwner
            );
    }

    public function test_center_must_have_valid_identifier_code(): void
    {
        $center = Center::factory()
            ->create([
                'identifier_code' => null,
            ]);

        $this->expectException(
            DomainException::class
        );

        $this->generator()
            ->generate(
                $center,
                SystemRole::Teacher
            );
    }

    public function test_generator_uses_persisted_center_identifier_instead_of_tampered_memory_state(): void
    {
        $center = Center::factory()
            ->create([
                'identifier_code' => '05',
            ]);

        $center->identifier_code = '99';

        $identifier =
            $this->generator()
            ->generate(
                $center,
                SystemRole::Teacher
            );

        $this->assertSame(
            '05120001',
            $identifier
        );
    }

    public function test_student_sequence_rolls_from_domain_thirteen_to_fourteen(): void
    {
        $center = Center::factory()
            ->create([
                'identifier_code' => '06',
            ]);

        DB::table(
            'account_identifier_sequences'
        )->insert([
            'center_identifier_code' => '06',
            'role_code' =>
            SystemRole::Student->value,
            'last_sequence' => 9999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $identifier =
            $this->generator()
            ->generate(
                $center,
                SystemRole::Student
            );

        $this->assertSame(
            '06140001',
            $identifier
        );
    }

    public function test_last_student_identifier_uses_domain_twenty_and_sequence_9999(): void
    {
        $center = Center::factory()
            ->create([
                'identifier_code' => '07',
            ]);

        DB::table(
            'account_identifier_sequences'
        )->insert([
            'center_identifier_code' => '07',
            'role_code' =>
            SystemRole::Student->value,
            'last_sequence' => 79991,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $identifier =
            $this->generator()
            ->generate(
                $center,
                SystemRole::Student
            );

        $this->assertSame(
            '07209999',
            $identifier
        );
    }

    public function test_student_identifier_range_cannot_exceed_domain_twenty(): void
    {
        $center = Center::factory()
            ->create([
                'identifier_code' => '08',
            ]);

        DB::table(
            'account_identifier_sequences'
        )->insert([
            'center_identifier_code' => '08',
            'role_code' =>
            SystemRole::Student->value,
            'last_sequence' => 79992,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(
            DomainException::class
        );

        $this->generator()
            ->generate(
                $center,
                SystemRole::Student
            );
    }

    public function test_fixed_role_range_cannot_exceed_9999(): void
    {
        $center = Center::factory()
            ->create([
                'identifier_code' => '09',
            ]);

        DB::table(
            'account_identifier_sequences'
        )->insert([
            'center_identifier_code' => '09',
            'role_code' =>
            SystemRole::Teacher->value,
            'last_sequence' => 9999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(
            DomainException::class
        );

        $this->generator()
            ->generate(
                $center,
                SystemRole::Teacher
            );
    }

    public function test_existing_platform_wide_identifier_collision_is_skipped(): void
    {
        $center = Center::factory()
            ->create([
                'identifier_code' => '10',
            ]);

        User::factory()->create([
            'account_login_identifier' =>
            '10120001',
        ]);

        $identifier =
            $this->generator()
            ->generate(
                $center,
                SystemRole::Teacher
            );

        $this->assertSame(
            '10120002',
            $identifier
        );
    }

    public function test_sequences_are_isolated_by_center_and_role(): void
    {
        $centerA = Center::factory()
            ->create([
                'identifier_code' => '11',
            ]);

        $centerB = Center::factory()
            ->create([
                'identifier_code' => '12',
            ]);

        $teacherA =
            $this->generator()
            ->generate(
                $centerA,
                SystemRole::Teacher
            );

        $studentA =
            $this->generator()
            ->generate(
                $centerA,
                SystemRole::Student
            );

        $teacherB =
            $this->generator()
            ->generate(
                $centerB,
                SystemRole::Teacher
            );

        $this->assertSame(
            '11120001',
            $teacherA
        );

        $this->assertSame(
            '11130001',
            $studentA
        );

        $this->assertSame(
            '12120001',
            $teacherB
        );
    }

    private function generator(): AccountIdentifierGenerator
    {
        return app(
            AccountIdentifierGenerator::class
        );
    }
}
