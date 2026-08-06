<?php

return [
    'wsdl' => env('RUSGUARD_WSDL'),
    'login' => env('RUSGUARD_LOGIN'),
    'password' => env('RUSGUARD_PASSWORD'),

    // Employee group IDs to exclude from sync (e.g. fired employees). Values are compared
    // against RusGuard uuids verbatim, so trim each one — "a, b" is the natural way to write
    // a list, and an untrimmed " b" silently matches nothing, quietly re-granting access to a
    // whole group. array_values() keeps the keys sequential for the positional SQL bindings.
    'excluded_group_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('RUSGUARD_EXCLUDED_GROUP_IDS', ''))
    ))),
];
