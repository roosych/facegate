<?php

return [
    'wsdl' => env('RUSGUARD_WSDL'),
    'login' => env('RUSGUARD_LOGIN'),
    'password' => env('RUSGUARD_PASSWORD'),

    // Employee group IDs to exclude from sync (e.g. fired employees)
    'excluded_group_ids' => array_filter(explode(',', env('RUSGUARD_EXCLUDED_GROUP_IDS', ''))),
];
