<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Domain\Host\TlsMode;
use App\Tests\Support\WizardTestCase;

final class TlsStepTest extends WizardTestCase
{
    private const string KEY = 'AppKeyAAAA11';
    private const string SECRET = 'AppSecretBBBB22xyz';
    private const string CONSUMER = 'ConsumerCCCC33';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function ovh(array $override = []): array
    {
        return $override + [
            'tls[mode]' => 'ovh_dns',
            'tls[ovhEndpoint]' => 'ovh-eu',
            'tls[ovhApplicationKey]' => self::KEY,
            'tls[ovhApplicationSecret]' => self::SECRET,
            'tls[ovhConsumerKey]' => self::CONSUMER,
        ];
    }

    public function testThePageShowsTheDomainAndTheTwoModes(): void
    {
        $crawler = $this->open('/setup/tls');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('fleet.example.com', trim($crawler->filter('[data-testid=setup-domain]')->text()));
        self::assertCount(2, $crawler->filter('input[type=radio][name="tls[mode]"]'));
        self::assertSame('http01', $crawler->filter('input[type=radio][name="tls[mode]"][checked]')->attr('value'));
        self::assertCount(3, $crawler->filter('input[type=password]'));
        self::assertSame(['ovh-eu', 'ovh-ca', 'ovh-us'], $crawler->filter('select[name="tls[ovhEndpoint]"] option')->extract(['value']));
    }

    public function testHttp01Saves(): void
    {
        $this->open('/setup/tls');
        $this->submit($this->client->getCrawler(), 'Save and continue', ['tls[mode]' => 'http01']);

        self::assertResponseRedirects('/setup/ssh');
        self::assertSame(TlsMode::Http01, $this->host()->tlsMode());
    }

    public function testOvhRequiresAllFourWhenNothingStored(): void
    {
        $this->open('/setup/tls');
        $this->submit($this->client->getCrawler(), 'Save and continue', [
            'tls[mode]' => 'ovh_dns',
            'tls[ovhApplicationKey]' => self::KEY,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter the OVH application secret', $this->body());
        self::assertStringContainsString('Enter the OVH consumer key', $this->body());
        self::assertStringNotContainsString('Enter the OVH application key', $this->body());
        self::assertSame(TlsMode::Http01, $this->host()->tlsMode(), 'nothing changed');
        self::assertFalse($this->secretStore()->hasFleetWide('ovh_application_key'), 'nothing was stored');
        self::assertStringNotContainsString(self::KEY, $this->body());
    }

    public function testOvhValuesAreStoredEncryptedAndNotEchoed(): void
    {
        $this->open('/setup/tls');
        $this->submit($this->client->getCrawler(), 'Save and continue', $this->ovh());

        self::assertResponseRedirects('/setup/ssh');
        self::assertSame(TlsMode::OvhDns, $this->host()->tlsMode());
        $store = $this->secretStore();
        self::assertSame(self::SECRET, $store->getFleetWide('ovh_application_secret'));
        self::assertSame('ovh-eu', $store->getFleetWide('ovh_endpoint'));

        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT name, ciphertext FROM secret');
        self::assertCount(4, $rows);
        foreach ($rows as $row) {
            foreach ([self::KEY, self::SECRET, self::CONSUMER] as $plain) {
                self::assertStringNotContainsString($plain, (string) $row['ciphertext']);
            }
        }

        $this->open('/setup/tls');
        self::assertResponseStatusCodeSame(200);
        foreach ([self::KEY, self::SECRET, self::CONSUMER] as $plain) {
            self::assertStringNotContainsString($plain, $this->body());
        }
        self::assertStringContainsString('Stored. Leave blank to keep it.', $this->body());
        self::assertSame('ovh_dns', $this->client->getCrawler()->filter('input[type=radio][checked]')->attr('value'));
    }

    public function testBlankKeepsStoredValues(): void
    {
        $this->open('/setup/tls');
        $this->submit($this->client->getCrawler(), 'Save and continue', $this->ovh());

        $this->open('/setup/tls');
        $this->submit($this->client->getCrawler(), 'Save and continue', ['tls[mode]' => 'ovh_dns', 'tls[ovhEndpoint]' => 'ovh-ca']);

        self::assertResponseRedirects('/setup/ssh');
        $store = $this->secretStore();
        self::assertSame(self::KEY, $store->getFleetWide('ovh_application_key'));
        self::assertSame(self::SECRET, $store->getFleetWide('ovh_application_secret'));
        self::assertSame(self::CONSUMER, $store->getFleetWide('ovh_consumer_key'));
        self::assertSame('ovh-ca', $store->getFleetWide('ovh_endpoint'));
    }

    public function testSwitchingBackToHttp01KeepsTheStoredValues(): void
    {
        $this->open('/setup/tls');
        $this->submit($this->client->getCrawler(), 'Save and continue', $this->ovh());
        $this->open('/setup/tls');
        $this->submit($this->client->getCrawler(), 'Save and continue', ['tls[mode]' => 'http01']);

        self::assertSame(TlsMode::Http01, $this->host()->tlsMode());
        self::assertTrue($this->secretStore()->hasFleetWide('ovh_consumer_key'));
    }

    public function testAMalformedCredentialIsAFormErrorNotAnException(): void
    {
        $this->open('/setup/tls');
        $this->submit($this->client->getCrawler(), 'Save and continue', $this->ovh(['tls[ovhConsumerKey]' => "bad value\nOVH_X=1"]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('consumer key must be 8 to 128 characters', $this->body());
        self::assertStringNotContainsString('OVH_X=1', $this->body());
        self::assertFalse($this->secretStore()->hasFleetWide('ovh_consumer_key'));
    }

    public function testAnUnknownEndpointIsRefused(): void
    {
        $this->open('/setup/tls');
        $this->post('/setup/tls', ['tls' => [
            'mode' => 'ovh_dns',
            'ovhEndpoint' => 'evil.example.com',
            'ovhApplicationKey' => self::KEY,
            'ovhApplicationSecret' => self::SECRET,
            'ovhConsumerKey' => self::CONSUMER,
            '_token' => 'csrf-token',
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->secretStore()->hasFleetWide('ovh_endpoint'));
    }

    public function testACrossOriginPostChangesNothing(): void
    {
        $this->open('/setup/tls');
        $this->post('/setup/tls', ['tls' => ['mode' => 'ovh_dns', 'ovhEndpoint' => 'ovh-eu', '_token' => 'csrf-token']], fetchSite: 'cross-site');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(TlsMode::Http01, $this->host()->tlsMode());
    }
}
