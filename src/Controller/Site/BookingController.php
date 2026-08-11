<?php

namespace App\Controller\Site;

use App\Controller\BaseController;
use App\Controller\LoginUserTrait;
use App\Entity\Booking;
use App\Entity\BookingResource;
use App\Exception\BookingException;
use App\Service\BookingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

#[Route(path: '/booking', name: 'booking')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
class BookingController extends BaseController
{
    use LoginUserTrait;

    private const CSRF_TOKEN_ID = 'booking_action';

    private readonly BookingService $bookingService;
    private readonly CsrfTokenManagerInterface $csrfTokenManager;

    public function __construct(
        SerializerInterface $serializer,
        BookingService $bookingService,
        CsrfTokenManagerInterface $csrfTokenManager
    ) {
        parent::__construct($serializer);
        $this->bookingService = $bookingService;
        $this->csrfTokenManager = $csrfTokenManager;
    }

    #[Route(path: '', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->requireDomainUser();
        $resources = $this->bookingService->getActiveResources();
        $myBookings = $this->bookingService->getUserBookings($user);

        return $this->render('site/booking/index.html.twig', [
            'resources' => $resources,
            'myBookings' => $myBookings,
        ]);
    }

    #[Route(path: '/csrf-token', name: '_csrf_token', methods: ['GET'])]
    public function csrfToken(): JsonResponse
    {
        $token = $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID);

        return $this->apiResponse([
            'token' => $token->getValue(),
        ]);
    }

    #[Route(path: '/{id}/availability', name: '_availability', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function availability(Request $request, BookingResource $resource): JsonResponse
    {
        if (!$resource->isActive()) {
            return $this->apiError('Ressource ist nicht verfügbar.', Response::HTTP_NOT_FOUND);
        }

        $from = $this->parseDate($request->query->get('from'));
        $to = $this->parseDate($request->query->get('to'));

        $slots = $this->bookingService->getAvailability($resource, $from, $to);

        $payload = array_map(static fn(array $slot) => [
            'start' => $slot['start']->format(DATE_ATOM),
            'end' => $slot['end']->format(DATE_ATOM),
            'free' => $slot['free'],
            'total' => $slot['total'],
        ], $slots);

        return $this->apiResponse($payload, true);
    }

    #[Route(path: '/{id}/book', name: '_book', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function book(Request $request, BookingResource $resource): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            return $this->apiError('Ungültige Anfrage.', Response::HTTP_BAD_REQUEST);
        }

        $csrfToken = (string) ($body['csrfToken'] ?? '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $csrfToken))) {
            return $this->apiError('Ungültiges CSRF-Token.', Response::HTTP_FORBIDDEN);
        }

        $start = $this->parseDate($body['start'] ?? null);
        if (!$start) {
            return $this->apiError('Ungültiger Zeitpunkt.', Response::HTTP_BAD_REQUEST);
        }

        $user = $this->requireDomainUser();

        try {
            $booking = $this->bookingService->bookSlot($resource, $start, $user);
        } catch (BookingException $e) {
            return $this->apiError($this->translateBookingError($e), Response::HTTP_CONFLICT);
        }

        return $this->apiResponse([
            'id' => $booking->getId(),
            'unitNumber' => $booking->getUnitNumber(),
            'start' => $booking->getStartAt()->format(DATE_ATOM),
            'end' => $booking->getEndAt()->format(DATE_ATOM),
        ]);
    }

    #[Route(path: '/reservation/{id}/cancel', name: '_reservation_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        $csrfToken = (string) (is_array($body) ? ($body['csrfToken'] ?? '') : '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $csrfToken))) {
            return $this->apiError('Ungültiges CSRF-Token.', Response::HTTP_FORBIDDEN);
        }

        $user = $this->requireDomainUser();

        try {
            $this->bookingService->cancelBooking($booking, $user);
        } catch (BookingException $e) {
            return $this->apiError($this->translateBookingError($e), Response::HTTP_CONFLICT);
        }

        return $this->apiResponse(['cancelled' => true]);
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function translateBookingError(BookingException $e): string
    {
        return match ($e->getMessage()) {
            BookingException::CODE_SLOT_FULL => 'Dieser Zeitslot ist bereits ausgebucht.',
            BookingException::CODE_INVALID_SLOT => 'Dieser Zeitpunkt ist nicht buchbar.',
            BookingException::CODE_RESOURCE_INACTIVE => 'Diese Ressource ist nicht verfügbar.',
            BookingException::CODE_LIMIT_REACHED => 'Du hast bereits die maximale Anzahl an Buchungen erreicht.',
            BookingException::CODE_NOT_OWNER => 'Diese Buchung gehört dir nicht.',
            BookingException::CODE_ALREADY_CANCELLED => 'Diese Buchung wurde bereits storniert.',
            default => 'Buchung nicht möglich.',
        };
    }
}
