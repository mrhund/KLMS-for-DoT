<?php

namespace App\Controller\Admin;

use App\Entity\GalleryEvent;
use App\Repository\GalleryEventRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[IsGranted('ROLE_ADMIN_MEDIA')]
#[Route('/admin/gallery/events', name: 'admin_gallery_events')]
class GalleryEventController extends AbstractController
{
    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function events(Request $request, GalleryEventRepository $repository): Response
    {
        $events = $repository->findAllOrderedByPriority();

        $form = $this->createFormBuilder(['events' => $events])
            ->add('events', CollectionType::class, [
                'entry_type' => HiddenType::class,
                'label' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
                'by_reference' => false,
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $eventsData = $data['events'] ?? [];

            // Remove all existing events and recreate from form data
            foreach ($events as $event) {
                if ($event->getGalleryImages()->count() === 0) {
                    $repository->remove($event);
                }
            }

            // Create new events from form data
            foreach ($eventsData as $priority => $eventName) {
                if (!empty(trim($eventName))) {
                    $event = new GalleryEvent();
                    $event->setName(trim($eventName));
                    $event->setPriority($priority);
                    $repository->save($event);
                }
            }

            $repository->getEntityManager()->flush();
            $this->addFlash('success', 'Events wurden erfolgreich gespeichert!');

            return $this->redirectToRoute('admin_gallery_events');
        }

        return $this->render('admin/gallery/events.html.twig', [
            'form' => $form->createView(),
            'events' => $events,
        ]);
    }
}