<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\AdminTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * A secret value must never reach a log record, whichever channel writes it and whichever way a request goes (saved,
 * refused, revealed, a failed reveal, a bad CSRF token). A debug-level test handler is attached to every channel the
 * request touches; its records (message and context) must not contain the value.
 */
final class SecretLogsTest extends AdminTestCase
{
    private const string VALUE = 'LogLeakValue-9f2c1d7a';

    public function testNoValueReachesAnyLogRecord(): void
    {
        $handler = new TestHandler(Level::Debug);
        $attached = 0;
        foreach (['logger', 'monolog.logger.request', 'monolog.logger.security', 'monolog.logger.doctrine', 'monolog.logger.php', 'monolog.logger.event', 'monolog.logger.console', 'monolog.logger.deprecation'] as $id) {
            if ($this->container()->has($id)) {
                $logger = $this->container()->get($id);
                if ($logger instanceof Logger) {
                    $logger->pushHandler($handler);
                    ++$attached;
                }
            }
        }
        self::assertGreaterThan(0, $attached, 'at least one logger is reachable');

        $projects = $this->container()->get(ProjectService::class);
        self::assertInstanceOf(ProjectService::class, $projects);
        $projects->create(new ProjectInput('blog', 'https://git.example.com/acme/blog.git'));

        $this->post('/admin/secrets', ['name' => 'API_KEY', 'value' => self::VALUE]);
        $this->post('/admin/projects/blog/secrets', ['name' => 'API_KEY', 'value' => self::VALUE.'-project']);
        $this->post('/admin/secrets', ['name' => 'bad name', 'value' => '"'.self::VALUE.'"']);
        $this->post('/admin/secrets', ['name' => 'API_KEY', 'value' => self::VALUE], [], 'cross-site');
        $this->post('/admin/secrets/API_KEY/reveal', []);
        $this->post('/admin/projects/blog/secrets/API_KEY/reveal', []);
        $this->post('/admin/secrets/MISSING/reveal', []);
        $this->em()->getConnection()->executeStatement('UPDATE secret SET ciphertext = zeroblob(40) WHERE scope = ?', ['project']);
        $this->post('/admin/projects/blog/secrets/API_KEY/reveal', []);
        $this->post('/admin/projects/blog/secrets/API_KEY/delete', []);
        $this->open('/admin/secrets');
        $this->open('/admin/audit');

        foreach ($handler->getRecords() as $record) {
            $text = $record->message.json_encode($record->context, \JSON_PARTIAL_OUTPUT_ON_ERROR).json_encode($record->extra, \JSON_PARTIAL_OUTPUT_ON_ERROR);
            self::assertStringNotContainsString(self::VALUE, $text, 'a log record carries a secret value');
        }
    }
}
