<?php

namespace Tests\Feature;

use App\Helpers\LeaveModuleHelpers;
use App\Models\LeaveEntitlement;
use App\Models\LeaveEntitlementLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeaveOverdraftTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        app('db')->purge('sqlite');
        Carbon::setTestNow('2026-06-01');
        Auth::shouldReceive('user')->andReturn((object) ['uuid' => 'test-user']);

        foreach (['leave_entitlements', 'leave_entitlement_logs'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                $table->id();
                $table->string('uuid')->nullable();
                $table->decimal('used_days', 10, 2)->default(0);
                $table->boolean('is_active')->default(true);
                foreach (['created_by', 'updated_by', 'created_at', 'updated_at'] as $column) {
                    $table->string($column)->nullable();
                }
                if ($tableName === 'leave_entitlements') {
                    $table->integer('year');
                    $table->decimal('balance_days', 10, 2)->default(0);
                    $table->decimal('carried_forward_days', 10, 2)->default(0);
                } else {
                    $table->integer('leave_entitlement_id');
                    $table->decimal('assigned_days', 10, 2)->default(0);
                    foreach (['assigned_at', 'available_at', 'expired_at'] as $column) {
                        $table->string($column)->nullable();
                    }
                    foreach (['is_manual', 'is_prorated', 'is_carry_forward'] as $column) {
                        $table->boolean($column)->default(false);
                    }
                }
            });
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_negative_balance_survives_repeated_synchronization_and_later_accrual(): void
    {
        Model::withoutEvents(function () {
            $entitlement = LeaveEntitlement::create(['year' => 2026, 'balance_days' => 1]);
            LeaveEntitlementLog::create([
                'leave_entitlement_id' => $entitlement->id,
                'assigned_days' => 1,
                'available_at' => '2026-01-01',
                'expired_at' => '2026-12-31',
            ]);

            LeaveModuleHelpers::deductLeaveDays($entitlement, 2.5, 'test-user');
            LeaveModuleHelpers::syncLeaveEntitlement($entitlement);
            LeaveModuleHelpers::syncLeaveEntitlement($entitlement);

            $this->assertEquals(-1.5, $entitlement->refresh()->balance_days);
            $this->assertEquals(2.5, $entitlement->used_days);
            $this->assertEquals(2.5, LeaveEntitlementLog::sum('used_days'));

            LeaveEntitlementLog::create([
                'leave_entitlement_id' => $entitlement->id,
                'assigned_days' => 1,
                'available_at' => '2026-06-01',
                'expired_at' => '2026-12-31',
            ]);
            LeaveModuleHelpers::syncLeaveEntitlement($entitlement);
            $this->assertEquals(-0.5, $entitlement->refresh()->balance_days);
        });
    }

    public function test_overdraft_is_recorded_when_no_available_logs_exist(): void
    {
        Model::withoutEvents(function () {
            $entitlement = LeaveEntitlement::create(['year' => 2026]);
            LeaveModuleHelpers::deductLeaveDays($entitlement, 2, 'test-user');
            LeaveModuleHelpers::syncLeaveEntitlement($entitlement);

            $this->assertEquals(-2, $entitlement->refresh()->balance_days);
            $this->assertEquals(2, $entitlement->used_days);
        });
    }
}
