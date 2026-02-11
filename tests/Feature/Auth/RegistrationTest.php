<?php

// Registration is disabled - admins create users through Team Management
test('registration screen returns 404 when disabled', function () {
    $response = $this->get('/register');

    $response->assertStatus(404);
});

test('registration post returns 404 when disabled', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertStatus(404);
});
