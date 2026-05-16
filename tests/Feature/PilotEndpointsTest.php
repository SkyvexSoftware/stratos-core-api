<?php

it('verifies a pilot via Bearer token and returns snake_case profile', function () {
    // Hits /api/stratos/pilot/verify with a valid Bearer; expects snake_case
    // pilot profile (db_id, pilot_id, first_name, last_name, email, rank,
    // rank_level, rank_image, avatar).
})->skip('Needs phpVMS User factory registered in Testbench — deferred to v0.2 (covered by live curl smoke in Task 17)');

it('returns pilot statistics for an authed pilot', function () {
    // Hits /api/stratos/pilot/statistics with a valid Bearer; expects 200 OK
    // with the statistics shape (hours_flown, flights_flown, average_landing_rate,
    // pireps_filed plus a handful of optional fields).
})->skip('Needs phpVMS User factory registered in Testbench — deferred to v0.2');

it('logs a pilot in via credentials and returns a session token', function () {
    // POSTs to /api/stratos/pilot/login?username=... with {password: ...};
    // expects 200 OK with a `session` field containing the api_key.
})->skip('Needs phpVMS User factory + Hash::make support in Testbench — deferred to v0.2');
