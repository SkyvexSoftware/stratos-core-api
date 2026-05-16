<?php

it('returns api_version and handler on the root path', function () {
    $response = $this->getJson('/api/stratos/');
    $response->assertOk();
    $response->assertJsonStructure(['api_version', 'handler']);
    expect($response->json('handler'))->toBe('Stratos');
});
