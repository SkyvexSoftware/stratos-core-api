<?php

it('lists eligible aircraft for a bid the pilot owns', function () {
    // GET /api/stratos/flights/bookings/{bid}/aircraft as the bid owner.
    // Expects { changeable: true, aircraft: [{id,name,registration,icao,subfleet}] }
    // narrowed by FlightService::filterSubfleets.
})->skip('Needs phpVMS User+Flight+Subfleet+Aircraft+Bid factories in Testbench — deferred to live curl smoke (plan Task B5).');

it('returns 403 listing aircraft for a bid owned by another pilot', function () {
    // GET .../{bid}/aircraft for someone else's bid → 403.
})->skip('Needs phpVMS factories in Testbench — deferred to live curl smoke (plan Task B5).');

it('reports changeable=false for a simbrief-locked flight', function () {
    // GET .../{bid}/aircraft when flight.simbrief is set → { changeable:false, aircraft:[] }.
})->skip('Needs phpVMS factories in Testbench — deferred to live curl smoke (plan Task B5).');

it('changes the aircraft on an owned bid and returns the updated booking', function () {
    // POST /api/stratos/flights/change-aircraft { bid_id, aircraft_id } as owner.
    // Persists Bid::aircraft_id; responds with the bookingPayload shape.
})->skip('Needs phpVMS factories in Testbench — deferred to live curl smoke (plan Task B5).');

it('returns 422 changing to an aircraft outside the flight subfleets', function () {
    // aircraft_id not in FlightService::filterSubfleets result → 422.
})->skip('Needs phpVMS factories in Testbench — deferred to live curl smoke (plan Task B5).');

it('returns 409 changing aircraft once a PIREP is in progress for the bid', function () {
    // An IN_PROGRESS pirep on the bid's flight blocks the change → 409.
})->skip('Needs phpVMS factories in Testbench — deferred to live curl smoke (plan Task B5).');
