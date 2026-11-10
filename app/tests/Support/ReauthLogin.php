<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fleet\Auth\UserService;

/**
 * Opens the re-auth window of the stand-in admin for a test that is not an AdminTestCase (the host page tests):
 * makes the user "dev-admin" with a known password and confirms it on the re-auth page.
 */
trait ReauthLogin
{
    private function confirmPasswordOnHostPage(): void
    {
        $users = self::getContainer()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $users);
        $users->create('dev-admin', 'Dev Admin', 'dev-admin@example.com', 'correct horse battery staple', true);

        $this->client->request('POST', '/admin/reauth', ['password' => 'correct horse battery staple', 'next' => '/admin', '_token' => 'csrf-token'], server: ['HTTPS' => 'on', 'HTTP_X_FLEET_STAND_IN_ROLE' => 'admin', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects('/admin', 303);
    }
}
