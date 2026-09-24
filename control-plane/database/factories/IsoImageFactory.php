<?php

namespace Database\Factories;

use App\Models\IsoImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<IsoImage> */
class IsoImageFactory extends Factory
{
    protected $model = IsoImage::class;

    public function definition(): array
    {
        $name = 'Debian '.fake()->unique()->numberBetween(10, 99).' netinst';

        return [
            'name' => $name,
            'filename' => Str::slug($name).'.iso',
            'url' => 'https://cdimage.example.org/'.Str::slug($name).'.iso',
            'sha256' => null,
            'is_public' => true,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['is_public' => false]);
    }
}
