<?php

use Illuminate\Support\Facades\DB;

test('todos.status enum column includes in_review on mysql', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('enum CHECK not representable on sqlite harness');
    }

    $column = DB::selectOne("SHOW COLUMNS FROM todos LIKE 'status'");

    expect($column)->not->toBeNull();
    expect($column->Type)->toContain('in_review');
});
