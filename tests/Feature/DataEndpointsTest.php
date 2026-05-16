<?php

it('returns aircraft list', function () {
    // GET /api/stratos/data/aircraft as authed pilot; expects an array of
    // {id, code, name, ...} entries.
})->skip('Needs phpVMS Aircraft factory registered in Testbench — deferred to v0.2');

it('returns airports list', function () {
    // GET /api/stratos/data/airports as authed pilot; expects {id, code, name,
    // latitude, longitude} array.
})->skip('Needs phpVMS Airport factory registered in Testbench — deferred to v0.2');

it('returns the latest news item', function () {
    // GET /api/stratos/data/news as authed pilot; expects {title, body,
    // posted_at, posted_by}.
})->skip('Needs phpVMS News factory registered in Testbench — deferred to v0.2');
