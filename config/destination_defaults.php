<?php

/*
|--------------------------------------------------------------------------
| Default destination per country for quick estimates
|--------------------------------------------------------------------------
|
| Used by the Public Rate API when the visitor only picks a country: DHL's rate API rejects a
| destination without a real city / postal code ("420505: The destination location is
| invalid"), so the country's main hub is quoted instead. [city, postcode] — postcode null for
| countries without one. Countries not listed fall back to the country name as the city.
|
*/

return [
    'AE' => ['Dubai', null],
    'AR' => ['Buenos Aires', '1002'],
    'AT' => ['Vienna', '1010'],
    'AU' => ['Sydney', '2000'],
    'BD' => ['Dhaka', '1000'],
    'BE' => ['Brussels', '1000'],
    'BN' => ['Bandar Seri Begawan', 'BS8811'],
    'BR' => ['Sao Paulo', '01001-000'],
    'CA' => ['Toronto', 'M5H 2N2'],
    'CH' => ['Zurich', '8001'],
    'CL' => ['Santiago', '8320000'],
    'CN' => ['Shanghai', '200000'],
    'CZ' => ['Prague', '11000'],
    'DE' => ['Berlin', '10115'],
    'DK' => ['Copenhagen', '1050'],
    'EG' => ['Cairo', '11511'],
    'ES' => ['Madrid', '28001'],
    'FI' => ['Helsinki', '00100'],
    'FR' => ['Paris', '75001'],
    'GB' => ['London', 'EC1A 1BB'],
    'GR' => ['Athens', '10431'],
    'HK' => ['Hong Kong', null],
    'HU' => ['Budapest', '1051'],
    'ID' => ['Jakarta', '10110'],
    'IE' => ['Dublin', 'D01 F5P2'],
    'IL' => ['Tel Aviv', '6100000'],
    'IN' => ['New Delhi', '110001'],
    'IT' => ['Rome', '00118'],
    'JP' => ['Tokyo', '100-0001'],
    'KH' => ['Phnom Penh', '120101'],
    'KR' => ['Seoul', '04524'],
    'KW' => ['Kuwait City', null],
    'LA' => ['Vientiane', '01000'],
    'LK' => ['Colombo', '00100'],
    'MM' => ['Yangon', '11181'],
    'MO' => ['Macau', null],
    'MX' => ['Mexico City', '06000'],
    'MY' => ['Kuala Lumpur', '50000'],
    'NL' => ['Amsterdam', '1011'],
    'NO' => ['Oslo', '0150'],
    'NP' => ['Kathmandu', '44600'],
    'NZ' => ['Auckland', '1010'],
    'PH' => ['Manila', '1000'],
    'PK' => ['Karachi', '74000'],
    'PL' => ['Warsaw', '00-001'],
    'PT' => ['Lisbon', '1100-148'],
    'QA' => ['Doha', null],
    'RU' => ['Moscow', '101000'],
    'SA' => ['Riyadh', '11564'],
    'SE' => ['Stockholm', '111 20'],
    'SG' => ['Singapore', '018956'],
    'TR' => ['Istanbul', '34110'],
    'TW' => ['Taipei', '100'],
    'US' => ['New York', '10001'],
    'VN' => ['Ho Chi Minh City', '700000'],
    'ZA' => ['Johannesburg', '2000'],
];
