<?php

use Illuminate\Support\Facades\DB;

test('credentials.username column is text on mysql', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('varchar length not enforced on sqlite harness');
    }

    $column = DB::selectOne("SHOW COLUMNS FROM credentials LIKE 'username'");

    expect($column)->not->toBeNull();
    expect($column->Type)->toBe('text');
});
