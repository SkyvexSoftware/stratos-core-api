<?php

namespace Modules\StratosCore\Actions;

/**
 * Shape the aircraft inside already-filtered subfleets into the flat,
 * snake_case list the Stratos client expects. The filtering itself is done
 * by the native App\Services\FlightService::filterSubfleets — this action
 * only flattens + de-duplicates the result. No business logic lives here.
 */
class EligibleAircraftList
{
    /**
     * @param  iterable  $subfleets  Subfleets whose ->aircraft have already been filtered.
     * @return array<int, array{id:int,name:string,registration:string,icao:string,subfleet:string}>
     */
    public static function shape(iterable $subfleets): array
    {
        $seen = [];
        $out = [];

        foreach ($subfleets as $subfleet) {
            foreach ($subfleet->aircraft as $acf) {
                $id = (int) $acf->id;
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $out[] = [
                    'id' => $id,
                    'name' => (string) $acf->name,
                    'registration' => (string) $acf->registration,
                    'icao' => (string) ($acf->icao ?? ''),
                    'subfleet' => (string) ($subfleet->type ?? ''),
                ];
            }
        }

        return $out;
    }
}
