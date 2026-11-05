<?php

use App\Models\CareGroup;
use App\Models\Child;
use App\Models\EmergencyAllocation;
use App\Models\EmergencyDay;
use App\Models\KitaYear;
use App\Models\RegularCareAssignment;
use App\Services\EmergencyAllocationService;
use App\Services\WaitingListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** @return array{0: KitaYear, 1: Collection, 2: EmergencyAllocation} */
function careScenario(array $patterns, int $capacity = 1): array
{
    $year = KitaYear::factory()->create();
    $group = CareGroup::factory()->create();
    $children = collect();
    foreach ($patterns as $index => $pattern) {
        $child = Child::factory()->for($year)->for($group, 'group')->create(['first_name' => ['Anna', 'Ben', 'Clara', 'David', 'Emil'][$index], 'last_name' => 'Beispiel']);
        RegularCareAssignment::factory()->for($child)->create(['pattern' => $pattern]);
        $children->push($child);
    }
    app(WaitingListService::class)->initialize($year, $group);
    $day = EmergencyDay::factory()->for($year)->for($group, 'group')->create(['capacity' => $capacity]);
    $allocation = app(EmergencyAllocationService::class)->start($day, $capacity);

    return [$year, $children, $allocation];
}
