<?php

namespace Tests\Feature;

use Tests\TestCase;

class ShareTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetTempDir();
        mkdir(TEST_REPOSITORY . '/john');
        mkdir(TEST_REPOSITORY . '/john/projects');
        file_put_contents(TEST_REPOSITORY . '/john/projects/readme.txt', 'shared content');
    }

    protected function tearDown(): void
    {
        $this->resetTempDir();
    }

    public function testUserCanCreateAndGuestCanBrowseSharedFolder()
    {
        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('POST', '/createshare', ['path' => '/projects']);

        $this->assertOk();
        $share = $this->decodeResponseJson()['data'];
        $this->assertRegExp('/^[a-f0-9]{64}$/', $share['token']);

        $this->signOut();
        $this->sendRequest('GET', '/shared/' . $share['token']);

        $this->assertOk();
        $this->assertResponseJsonHas([
            'data' => [
                'share' => ['name' => 'projects'],
                'location' => '/',
                'files' => [
                    0 => ['type' => 'file', 'path' => '/readme.txt', 'name' => 'readme.txt'],
                ],
            ],
        ]);

    }

    public function testOwnerCanRevokeSharedFolder()
    {
        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('POST', '/createshare', ['path' => '/projects']);
        $token = $this->decodeResponseJson()['data']['token'];

        $this->sendRequest('POST', '/revokeshare/' . $token);
        $this->assertOk();

        $this->signOut();
        $this->sendRequest('GET', '/shared/' . $token);
        $this->assertStatus(404);
    }
}
