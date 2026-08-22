<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Setting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$raw = DB::table('cache')->where('key', 'like', '%snt.settings%')->value('value');

if ($raw === null) {
    echo 'no cache row'.PHP_EOL;

    return;
}

$payload = unserialize($raw);
echo 'outer: '.get_class($payload).' count='.$payload->count().PHP_EOL;

$first = $payload->first();
echo 'first: '.get_class($first).PHP_EOL;

if ($first instanceof __PHP_Incomplete_Class) {
    echo 'incomplete class name: '.$first->__PHP_Incomplete_Class_Name.PHP_EOL;
} else {
    echo 'key: '.($first->key ?? '?').PHP_EOL;
}

// Now try the full Setting::get path in this same process.
echo 'Setting::get: ';
var_export(Setting::get('system.maintenance_mode'));
echo PHP_EOL;
