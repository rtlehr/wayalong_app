<?php

namespace Database\Factories;

use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Facility> */
class FacilityFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company().' Treatment Center';

        return [
            'name' => $name,
            'code' => strtoupper(Str::random(8)),
            'status' => 'active',
            'address_line_1' => fake()->streetAddress(),
            'address_line_2' => null,
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'phone' => fake()->numerify('##########'),
            'email' => fake()->safeEmail(),
            'timezone' => null,
        ];
    }
}
