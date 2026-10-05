<?php

declare(strict_types=1);

// A file-backed session store scoped to this PHP process (PID-based, not
// random, so every re-read of the config resolves the same path). Tests that
// boot this fixture remove the directory afterwards.
return [
    'driver' => 'file',
    'lifetime' => 120,
    'expire_on_close' => false,
    'path' => sys_get_temp_dir() . '/marko-testing-http-app/' . getmypid() . '/sessions',

    'cookie' => [
        'name' => 'marko_session',
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'lax',
    ],

    'gc_probability' => 0,
    'gc_divisor' => 100,
];
