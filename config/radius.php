<?php

return [
    'reload_command' => env('FREERADIUS_RELOAD_COMMAND', 'sudo systemctl reload freeradius'),
];
