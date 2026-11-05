<?php

test('redirects guests to the administrator login', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});
