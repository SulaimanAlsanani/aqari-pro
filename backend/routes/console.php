<?php
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('Aqari Pro API');
})->purpose('Display application information');

// Owner provisioning is implemented only in app/Console/Commands/CreateOwner.php.
// Do not register a duplicate owner:create command here.
