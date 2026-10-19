<?php

return ['default' => env('QUEUE_CONNECTION', 'database'), 'connections' => ['media' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'media', 'retry_after' => 1000, 'after_commit' => true], 'sync' => ['driver' => 'sync'], 'database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 7500, 'after_commit' => true]], 'failed' => ['driver' => 'database-uuids', 'database' => env('DB_CONNECTION', 'mysql'), 'table' => 'failed_jobs']];
