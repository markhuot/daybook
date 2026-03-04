<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('loads the editor without JavaScript errors', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/');

    $page->assertNoJavaScriptErrors();
    $page->assertScript('document.querySelector(".ProseMirror") !== null', true);
});
