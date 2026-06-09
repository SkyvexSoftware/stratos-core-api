<?php

use Modules\StratosCore\Actions\EligibleAircraftList;

function fakeAircraft(int $id, string $name, string $reg, string $icao): object
{
    return (object) ['id' => $id, 'name' => $name, 'registration' => $reg, 'icao' => $icao];
}

function fakeSubfleet(string $type, array $aircraft): object
{
    return (object) ['type' => $type, 'aircraft' => collect($aircraft)];
}

it('flattens filtered subfleets into a snake_case aircraft list', function () {
    $subfleets = collect([
        fakeSubfleet('B738', [fakeAircraft(3, 'Boeing 737-800', 'VH-VXA', 'B738')]),
    ]);

    expect(EligibleAircraftList::shape($subfleets))->toBe([
        ['id' => 3, 'name' => 'Boeing 737-800', 'registration' => 'VH-VXA', 'icao' => 'B738', 'subfleet' => 'B738'],
    ]);
});

it('de-duplicates an aircraft that appears in more than one subfleet', function () {
    $shared = fakeAircraft(7, 'Airbus A320', 'VH-XYZ', 'A320');
    $subfleets = collect([
        fakeSubfleet('A320-CFM', [$shared]),
        fakeSubfleet('A320-IAE', [$shared, fakeAircraft(8, 'Airbus A321', 'VH-ABC', 'A321')]),
    ]);

    $result = EligibleAircraftList::shape($subfleets);

    expect($result)->toHaveCount(2);
    expect(array_column($result, 'id'))->toBe([7, 8]);
});

it('returns an empty array when there are no subfleets', function () {
    expect(EligibleAircraftList::shape(collect()))->toBe([]);
});
