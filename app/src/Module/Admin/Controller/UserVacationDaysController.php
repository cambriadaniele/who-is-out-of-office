<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Infrastructure\Doctrine\Entity\User;
use App\Shared\Enum\BalanceLedgerEntryTypeEnum;
use App\Shared\Enum\BalanceLedgerSourceEnum;
use App\Shared\Facade\UserFacadeInterface;
use App\Shared\Handler\User\Command\RecordBalanceChangeCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fork: one page to manage a person's vacation days per year (current year and the next two).
 * Replaces the "Saldo anpassen" form and the "2027: 24" text field.
 */
#[IsGranted('ROLE_ADMIN')]
class UserVacationDaysController extends AbstractController
{
    public function __construct(private readonly UserFacadeInterface $userFacade)
    {
    }

    #[Route('/app/my-organization/{id}/vacation-days', name: 'app_user_vacation_days', methods: ['GET'])]
    public function show(string $id): Response
    {
        $targetUser = $this->userFacade->getUser($id) ?? throw new NotFoundHttpException();

        return $this->render('@AppAdmin/user/vacation_days.html.twig', [
            'target_user' => $targetUser,
            'cards' => $this->userFacade->getVacationOverview($targetUser->id),
        ]);
    }

    #[Route('/app/my-organization/{id}/vacation-days/{year<\d{4}>}/correct', name: 'app_user_vacation_days_correct', methods: ['POST'])]
    public function correct(#[CurrentUser] User $currentUser, Request $request, string $id, int $year): Response
    {
        $targetUser = $this->userFacade->getUser($id) ?? throw new NotFoundHttpException();
        $this->checkToken($request, $targetUser->id);

        $days = (int) $request->request->get('days');
        $direction = 'remove' === $request->request->get('direction') ? -1 : 1;
        $reason = trim((string) $request->request->get('reason'));
        $delta = $direction * abs($days);

        if (0 === $delta || $days > 365 || '' === $reason) {
            $this->addFlash('danger', 'user.vacation_days.flash.invalid');

            return $this->redirectToRoute('app_user_vacation_days', ['id' => $targetUser->id]);
        }

        $cards = $this->userFacade->getVacationOverview($targetUser->id);
        $card = $this->findCard($cards, $year);

        if ($card['isCurrent']) {
            // The running year lives in the ledger, like the old "Saldo anpassen".
            $this->userFacade->recordBalanceChange(new RecordBalanceChangeCommand(
                userId: $targetUser->id,
                delta: $delta,
                type: BalanceLedgerEntryTypeEnum::ManualAdjustment,
                source: BalanceLedgerSourceEnum::App,
                reason: $reason,
                actorUserId: $currentUser->id->toString(),
            ));
        } else {
            $this->userFacade->addFutureYearCorrection($targetUser->id, $year, $delta, $reason, $currentUser->id->toString());
        }

        $this->addFlash('success', 'user.vacation_days.flash.corrected');

        return $this->redirectToRoute('app_user_vacation_days', ['id' => $targetUser->id]);
    }

    #[Route('/app/my-organization/{id}/vacation-days/{year<\d{4}>}/allowance', name: 'app_user_vacation_days_allowance', methods: ['POST'])]
    public function allowance(Request $request, string $id, int $year): Response
    {
        $targetUser = $this->userFacade->getUser($id) ?? throw new NotFoundHttpException();
        $this->checkToken($request, $targetUser->id);

        $card = $this->findCard($this->userFacade->getVacationOverview($targetUser->id), $year);
        if ($card['isCurrent']) {
            throw new NotFoundHttpException('The allowance of the running year is already granted; use a correction.');
        }

        $raw = trim((string) $request->request->get('days'));
        if ('' === $raw || 'reset' === $request->request->get('action')) {
            $this->userFacade->setYearAllowance($targetUser->id, $year, null);
        } elseif (ctype_digit($raw) && (int) $raw <= 365) {
            $this->userFacade->setYearAllowance($targetUser->id, $year, (int) $raw);
        } else {
            $this->addFlash('danger', 'user.vacation_days.flash.invalid');

            return $this->redirectToRoute('app_user_vacation_days', ['id' => $targetUser->id]);
        }

        $this->addFlash('success', 'user.vacation_days.flash.allowance');

        return $this->redirectToRoute('app_user_vacation_days', ['id' => $targetUser->id]);
    }

    private function checkToken(Request $request, string $userId): void
    {
        if (!$this->isCsrfTokenValid('vacation-days'.$userId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /** @param list<array<string, mixed>> $cards */
    private function findCard(array $cards, int $year): array
    {
        foreach ($cards as $card) {
            if ($card['year'] === $year) {
                return $card;
            }
        }

        throw new NotFoundHttpException(sprintf('Year %d cannot be managed here.', $year));
    }
}
