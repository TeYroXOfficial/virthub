<?php

namespace Database\Factories;

use App\Models\OsTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OsTemplate> */
class OsTemplateFactory extends Factory
{
    protected $model = OsTemplate::class;

    public function definition(): array
    {
        return [
            'name' => 'Ubuntu 24.04 LTS',
            'family' => 'ubuntu',
            'version' => '24.04',
            'image_file' => 'ubuntu-24.04.qcow2',
            'min_disk_gb' => 10,
            'cloud_init_support' => true,
            'is_active' => true,
        ];
    }

    /** Szablon bez cloud-init (np. Windows) — nie da się go zamówić samodzielnie. */
    public function manualInstall(): static
    {
        return $this->state(fn () => [
            'name' => 'Windows Server 2022',
            'family' => 'windows',
            'version' => '2022',
            'image_file' => 'windows-2022.qcow2',
            'cloud_init_support' => false,
            'min_disk_gb' => 40,
        ]);
    }
}
