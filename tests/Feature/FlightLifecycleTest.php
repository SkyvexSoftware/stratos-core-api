<?php

it('runs the happy-path flight lifecycle', function () {
    // End-to-end: book → start → update → complete → assert PIREP row exists.
    // Hits /api/stratos/flights/{start,update,complete} in sequence.
    // The hardest test to write — needs User, Airport, Aircraft, Flight, Bid
    // factories all wired up in Testbench.
})->skip('Full lifecycle test needs phpVMS factories for User+Airport+Aircraft+Flight+Bid — deferred to v0.2 (covered by Task 17 live integration smoke)');
