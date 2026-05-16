<?php

it('returns an empty array from /pireps/latest', function () {
    $response = $this->getJson('/api/stratos/pireps/latest');
    $response->assertOk();
    expect($response->json())->toBeArray();
});

it('returns an array from /pireps/search', function () {
    $response = $this->getJson('/api/stratos/pireps/search');
    $response->assertOk();
    expect($response->json())->toBeArray();
});

it('returns the canned sample from /pireps/details', function () {
    $response = $this->getJson('/api/stratos/pireps/details');
    $response->assertOk();
    $response->assertJsonStructure(['flight_log', 'flight_data', 'location_data']);
});
