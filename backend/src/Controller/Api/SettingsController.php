<?php

namespace App\Controller\Api;

use App\Dto\SettingsInput;
use App\Service\SchedulingSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Einstellungen der Werkstatt: lesen darf jeder, aendern nur Chef und Sekretaerin. */
#[Route('/api/settings')]
final class SettingsController extends AbstractApiController
{
    public function __construct(private readonly SchedulingSettings $settings)
    {
    }

    #[Route('', name: 'api_settings_show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->current();
    }

    #[Route('', name: 'api_settings_update', methods: ['PUT'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
    public function update(#[MapRequestPayload] SettingsInput $input): JsonResponse
    {
        \assert(null !== $input->parallelScheduling);
        $this->settings->setAllowsParallel($input->parallelScheduling);

        return $this->current();
    }

    private function current(): JsonResponse
    {
        return $this->json(['parallelScheduling' => $this->settings->allowsParallel()]);
    }
}
