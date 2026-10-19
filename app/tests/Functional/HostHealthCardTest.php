<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HostHealthCardTest extends WebTestCase
{
    use FleetDatabase;

    public function testHomeShowsTheHostCardToAdministratorsOnly(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'admin']);

        self::assertResponseStatusCodeSame(200);
        $card = $crawler->filter('[data-testid=host-health]');
        self::assertCount(1, $card);
        self::assertCount(1, $card->filter('[data-testid=host-memory]'));
        self::assertCount(1, $card->filter('[data-testid=host-disk]'));
        self::assertCount(1, $card->filter('[data-testid=host-reboot]'));
        self::assertMatchesRegularExpression('/(\d+% used|Not reported)/', $card->filter('[data-testid=host-disk]')->text());
        self::assertCount(1, $crawler->filter('h1'));
        self::ensureKernelShutdown();

        $client = self::createClient();
        $crawler = $client->request('GET', '/', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'member']);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $crawler->filter('[data-testid=host-health]'));
        self::assertStringNotContainsString('Reboot required', (string) $client->getResponse()->getContent());
    }

    public function testShowsRunningOfTotalForTheUser(): void
    {
        $em = $this->setUpFleetDatabase();
        $project = new Project('acme', 'https://git.example.com/acme/blog.git');
        new ProjectRepository($em)->save($project);
        $instances = [];
        foreach ([InstanceState::Running, InstanceState::Running, InstanceState::Stopped] as $i => $state) {
            $instance = new Instance($project, 'i'.($i + 1), 'full', 'develop', 'admin');
            $instance->changeState($state);
            $instances[] = $instance;
        }
        new InstanceRepository($em)->createMany($instances);
        self::ensureKernelShutdown();

        try {
            $client = self::createClient();
            $crawler = $client->request('GET', '/', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'admin']);

            self::assertResponseStatusCodeSame(200);
            $card = $crawler->filter('[data-testid=host-health] [data-testid=host-instances]');
            self::assertCount(1, $card);
            self::assertStringContainsString('2 running of 3', (string) preg_replace('/\s+/', ' ', $card->text()));
        } finally {
            $this->tearDownFleetDatabase();
        }
    }
}
