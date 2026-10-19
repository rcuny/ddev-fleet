<?php

declare(strict_types=1);

namespace App\Controller\Setup;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Host\TlsMode;
use App\Fleet\Secrets\SecretConflict;
use App\Fleet\Secrets\SecretOpenFailed;
use App\Fleet\Secrets\SecretStore;
use App\Fleet\System\ConfigValue;
use App\Form\Setup\TlsType;
use App\Setup\SetupActor;
use App\Setup\SetupStep;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Step 2: how certificates are obtained. The domain was fixed at install time and is shown, not edited. OVH values
 * are validated with ConfigValue (the same rules the renderers enforce) so a bad one is a form error here, never an
 * exception when Finish renders the config; they are stored sealed and never shown again.
 */
final class TlsController extends AbstractSetupController
{
    private const array CREDENTIALS = [
        'ovhApplicationKey' => ['ovh_application_key', 'application key'],
        'ovhApplicationSecret' => ['ovh_application_secret', 'application secret'],
        'ovhConsumerKey' => ['ovh_consumer_key', 'consumer key'],
    ];

    public function __construct(
        private readonly HostSettingsRepository $settings,
        private readonly SecretStore $secrets,
        private readonly SetupActor $actor,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/setup/tls', name: 'setup_tls', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if (null !== $refusal = $this->guard($request)) {
            return $refusal;
        }

        $host = $this->settings->get() ?? throw $this->createNotFoundException();
        $stored = array_values(array_filter(
            array_map(static fn (array $c): string => $c[0], array_values(self::CREDENTIALS)),
            fn (string $name): bool => $this->secrets->hasFleetWide($name),
        ));

        $form = $this->createForm(TlsType::class, [
            'mode' => $host->tlsMode()->value,
            'ovhEndpoint' => $this->storedEndpoint(),
        ], ['stored' => $stored]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $mode = TlsMode::tryFrom((string) $data['mode']) ?? TlsMode::Http01;

            [$values, $failures] = TlsMode::OvhDns === $mode ? $this->checkOvh($data, $stored) : [[], []];
            if ([] === $failures) {
                try {
                    $this->secrets->putManyFleetWide($values, $this->actor->name());
                    $host->changeTlsMode($mode);
                    $this->settings->save($host);
                    $this->addFlash('success', 'Certificate settings saved.');

                    return $this->redirectToRoute(SetupStep::Tls->next()?->route() ?? 'setup_finish');
                } catch (SecretConflict|\RuntimeException $e) {
                    $this->logger->error('The TLS step could not store its settings.', ['exception' => $e::class]);
                    $form->addError(new FormError('The settings could not be stored. Try again.'));
                }
            }
            foreach ($failures as $field => $message) {
                $form->get($field)->addError(new FormError($message));
            }
        }

        return $this->page('setup/tls.html.twig', SetupStep::Tls, [
            'form' => $form,
            'domain' => $host->domain(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $stored names of the credentials already in the store
     *
     * @return array{array<string, string>, array<string, string>} the secrets to store (secret name => value; blank
     *                                                             fields keep what is stored) and the form errors
     *                                                             (field name => message)
     */
    private function checkOvh(array $data, array $stored): array
    {
        $values = [];
        $failures = [];
        try {
            $values['ovh_endpoint'] = ConfigValue::ovhEndpoint((string) $data['ovhEndpoint']);
        } catch (\InvalidArgumentException $e) {
            $failures['ovhEndpoint'] = $e->getMessage();
        }

        foreach (self::CREDENTIALS as $field => [$name, $label]) {
            $value = trim((string) ($data[$field] ?? ''));
            if ('' === $value) {
                if (!\in_array($name, $stored, true)) {
                    $failures[$field] = \sprintf('Enter the OVH %s.', $label);
                }
                continue;
            }
            try {
                $values[$name] = ConfigValue::ovhCredential($name, $value);
            } catch (\InvalidArgumentException) {
                $failures[$field] = \sprintf('The OVH %s must be 8 to 128 characters of letters, digits, ".", "_" or "-".', $label);
            }
        }

        return [$values, $failures];
    }

    private function storedEndpoint(): string
    {
        try {
            $endpoint = $this->secrets->getFleetWide('ovh_endpoint');
        } catch (SecretOpenFailed) {
            $endpoint = null;
        }

        return null !== $endpoint && \in_array($endpoint, ConfigValue::OVH_ENDPOINTS, true) ? $endpoint : ConfigValue::OVH_ENDPOINTS[0];
    }
}
