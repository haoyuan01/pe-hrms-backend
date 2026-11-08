<?php

namespace Tests\Feature;

use App\Helpers\LeaveModuleHelpers;
use App\Models\LeaveEntitlement;
use App\Models\LeaveEntitlementLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NegativeLeaveBalanceTest extends TestCase
{
    public function test_excess_usage_survives_repeated_balance_recalculations(): void
    {
        // Use an isolated in-memory database; no production data or migrations.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        app('db')->purge('sqlite');

        foreach ([new LeaveEntitlement(), new LeaveEntitlementLog()] as $model) {
            Schema::create($model->getTable(), function (Blueprint $table) use ($model) {
                $table->id();
                foreach ($model->getFillable() as $column) {
                    if (str_ends_with($column, '_days')) {
                        $table->double($column)->default(0);
                    } else {
                        $table->string($column)->nullable();
                    }
                }
            });
        }

        Auth::shouldReceive('user')->andReturn(new User(['uuid' => 'test-user']));

        LeaveEntitlement::withoutEvents(function () {
            LeaveEntitlementLog::withoutEvents(function () {
                foreach ([[2, 3, -1], [0, 1.5, -1.5], [2, 1, 1], [2, 2, 0]] as [$assigned, $requested, $expected]) {
                    $entitlement = LeaveEntitlement::create([
                        'used_days' => 0,
                        'balance_days' => $assigned,
                        'carried_forward_days' => 0,
                    ]);

                    if ($assigned > 0) {
                        LeaveEntitlementLog::create([
                            'leave_entitlement_id' => $entitlement->id,
                            'assigned_days' => $assigned,
                            'used_days' => 0,
                            'available_at' => now()->toDateString(),
                            'is_active' => 1,
                            'is_carry_forward' => 0,
                        ]);
                    }

                    LeaveModuleHelpers::deductLeaveDays($entitlement, $requested, 'test-user');

                    for ($i = 0; $i < 2; $i++) {
                        LeaveModuleHelpers::syncLeaveEntitlement($entitlement);
                        $entitlement->refresh();
                        $this->assertEquals($expected, $entitlement->balance_days);
                        $this->assertEquals($requested, $entitlement->used_days);
                    }
                }
            });
        });
    }
}
