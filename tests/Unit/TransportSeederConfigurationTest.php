<?php

namespace Tests\Unit;

use Tests\TestCase;

class TransportSeederConfigurationTest extends TestCase
{
    public function test_demo_network_has_a_distinct_feeder_line_and_korhogo_line(): void
    {
        $routes = collect(config('transport.routes_par_defaut'))->keyBy('name');

        $this->assertSame(
            ['ABJ-NORD', 'YAK-CENTRE', 'DIV-MAIN', 'GAG-MAIN'],
            $routes['Abidjan ↔ Gagnoa via Yamoussoukro et Divo']['stops']
        );
        $this->assertSame(
            ['ABJ-NORD', 'YAK-CENTRE', 'BKE-MAIN', 'KAT-MAIN', 'KGO-MAIN'],
            $routes['Abidjan ↔ Korhogo']['stops']
        );
    }

    public function test_demo_network_exposes_named_stations_and_the_abidjan_divo_fare(): void
    {
        $stations = config('transport.gares_par_ville');
        $routes = collect(config('transport.routes_par_defaut'))->keyBy('name');

        $this->assertSame('Gare de Yamoussoukro', $stations['Yamoussoukro'][0]['name']);
        $this->assertSame('Gare de Divo', $stations['Divo'][0]['name']);
        $this->assertSame('Gare de Gagnoa', $stations['Gagnoa'][0]['name']);
        $this->assertSame(7500, $routes['Abidjan ↔ Gagnoa via Yamoussoukro et Divo']['fare']);
    }
}
