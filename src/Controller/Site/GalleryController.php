<?php

namespace App\Controller\Site;

use App\Service\GalleryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/gallery', name: 'gallery')]
class GalleryController extends AbstractController
{
    #[Route('', name: '', methods: ['GET'])]
    public function index(GalleryService $galleryService): Response
    {
        $eventsWithImages = $galleryService->getAllEventsWithImages();

        return $this->render('site/gallery/index.html.twig', [
            'eventsWithImages' => $eventsWithImages,
        ]);
    }

    #[Route('/event/{eventName}', name: '_event', methods: ['GET'])]
    public function event(string $eventName, GalleryService $galleryService): Response
    {
        $eventsWithImages = $galleryService->getAllEventsWithImages();
        
        if (!isset($eventsWithImages[$eventName])) {
            throw $this->createNotFoundException('Event not found');
        }
        
        $eventData = $eventsWithImages[$eventName];

        return $this->render('site/gallery/event.html.twig', [
            'eventName' => $eventName,
            'eventData' => $eventData,
            'photos' => $eventData['images'],
        ]);
    }
}