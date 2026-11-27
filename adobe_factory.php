<?php

/** @var \Illuminate\Database\Eloquent\Factory $factory */
$factory->define(Adobe_model::class, function (Faker\Generator $faker) {
    $apps = [
        ['name' => 'Photoshop', 'sapcode' => 'PHSP', 'desc' => 'Adobe Photoshop 2025'],
        ['name' => 'Illustrator', 'sapcode' => 'ILST', 'desc' => 'Adobe Illustrator 2025'],
        ['name' => 'InDesign', 'sapcode' => 'IDSN', 'desc' => 'Adobe InDesign 2025'],
        ['name' => 'Acrobat DC', 'sapcode' => 'APRO', 'desc' => 'Adobe Acrobat DC'],
        ['name' => 'Premiere Pro', 'sapcode' => 'PPRO', 'desc' => 'Adobe Premiere Pro 2025'],
    ];
    $app = $faker->randomElement($apps);
    $installed = $faker->numerify('##.#.#.###');
    $latest = $faker->boolean(70) ? $installed : $faker->numerify('##.#.#.###');

    return [
        'app_name' => $app['name'],
        'sapcode' => $app['sapcode'],
        'base_version' => $faker->numerify('##.#'),
        'year_edition' => $faker->randomElement(['2024', '2025', '2026', '']),
        'installed_version' => $installed,
        'latest_version' => $latest,
        'description' => $app['desc'],
        'is_up_to_date' => version_compare($installed, $latest, '>=') ? 1 : 0,
    ];
});
