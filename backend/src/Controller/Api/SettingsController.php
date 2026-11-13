<?php

namespace App\Controller\Api;

use App\Dto\SettingsInput;
use App\Service\PlannedMove;
use App\Service\ScheduleShifter;
use App\Service\SchedulingSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Einstellungen der Werkstatt: lesen darf jeder, aendern nur Chef und Sekretaerin.
 *
 * Wird paralleles Einplanen ausgeschaltet, sortiert das Backend den Kalender
 * neu: bestehende Ueberschneidungen offener Arbeiten ab jetzt werden durch
 * Verschieben aufgeloest. Mit "dryRun": true wird nur gemeldet, was
 * verschoben wuerde.
 */
#[Route('/api/settings')]
final class SettingsController extends AbstractApiController
{
    public function __construct(
        private readonly SchedulingSettings $settings,
        private readonly ScheduleShifter $shifter,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('', name: 'api_settings_show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json(['parallelScheduling' => $this->settings->allowsParallel()]);
    }

    #[Route('', name: 'api_settings_update', methods: ['PUT'])]
    #[IsGranted('ROLE_SEKRETAERIN')]
    public function update(#[MapRequestPayload] SettingsInput $input): JsonResponse
    {
        \assert(null !== $input->parallelScheduling);

        // Neu sortiert wird nur beim Wechsel von "erlaubt" auf "nicht erlaubt".
        $plan = null;
        if (!$input->parallelScheduling && $this->settings->allowsParallel()) {
            $plan = $this->shifter->planResort(\DateTimeImmutable::createFromInterface($this->clock->now()));
        }

        if (true === $input->dryRun) {
            return $this->result($plan?->moved ?? [], true);
        }

        $this->settings->setAllowsParallel($input->parallelScheduling, false);
        $plan?->applyMoved();
        $this->em->flush();

        return $this->result($plan?->moved ?? [], false);
    }

    /**
     * @param list<PlannedMove> $moved
     */
    private function result(array $moved, bool $preview): JsonResponse
    {
        return $this->json([
            'parallelScheduling' => $this->settings->allowsParallel(),
            'shifted' => array_map(
                fn (PlannedMove $move): mixed => $this->normalizer->normalize($preview ? $move->previewJob() : $move->job, 'json', ['groups' => JobController::GROUPS]),
                $moved,
            ),
        ]);
    }
}
