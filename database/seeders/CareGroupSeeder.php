<?php

namespace Database\Seeders;

use App\Models\CareGroup;
use Illuminate\Database\Seeder;

class CareGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['Bärengruppe', 'Fuchsgruppe'] as $name) {
            CareGroup::firstOrCreate(['name' => $name]);
        }
    }
}
