<?php

return ['default' => env('CACHE_STORE', 'database'), 'stores' => ['array' => ['driver' => 'array', 'serialize' => false], 'database' => ['driver' => 'database', 'table' => 'cache', 'lock_table' => 'cache_locks']], 'prefix' => 'online_model_'];
