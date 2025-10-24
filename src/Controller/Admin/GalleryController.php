<?php

namespace App\Controller\Admin;

use App\Entity\GalleryImage;
use App\Entity\GalleryEvent;
use App\Repository\GalleryImageRepository;
use App\Repository\GalleryEventRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

#[IsGranted('ROLE_ADMIN_MEDIA')]
#[Route('gallery', name: 'gallery')]
class GalleryController extends AbstractController
{
    #[Route('', name: '', methods: ['GET'])]
    public function index(GalleryImageRepository $repository): Response
    {
        $photosByEvent = $repository->findAllGroupedByEvent();
        $events = $repository->findEvents();

        return $this->render('admin/gallery/index.html.twig', [
            'photosByEvent' => $photosByEvent,
            'events' => $events,
        ]);
    }

    #[Route('/upload', name: '_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request, GalleryImageRepository $repository, GalleryEventRepository $eventRepository): Response
    {
        $galleryImage = new GalleryImage();

        $form = $this->createFormBuilder($galleryImage)
            ->add('galleryEvent', EntityType::class, [
                'class' => GalleryEvent::class,
                'choice_label' => 'name',
                'label' => 'Event auswählen',
                'placeholder' => '-- Event auswählen --',
                'query_builder' => function (GalleryEventRepository $er) {
                    return $er->createQueryBuilder('e')->orderBy('e.priority', 'ASC');
                }
            ])
            ->add('title', TextType::class, [
                'label' => 'Titel (optional)',
                'required' => false
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung (optional)',
                'required' => false,
                'attr' => ['rows' => 3]
            ])
            ->add('imageFile', FileType::class, [
                'label' => 'Bild auswählen',
                'constraints' => [
                    new File([
                        'maxSize' => '10M',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/jpg',
                            'image/png',
                            'image/gif',
                            'image/webp',
                        ],
                        'mimeTypesMessage' => 'Bitte wählen Sie eine gültige Bilddatei (JPEG, PNG, GIF, WebP)'
                    ])
                ]
            ])
            ->add('submit', SubmitType::class, ['label' => 'Bild hochladen'])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Set legacy event field from selected GalleryEvent
            if ($galleryImage->getGalleryEvent()) {
                $galleryImage->setEvent($galleryImage->getGalleryEvent()->getName());
            }

            $repository->save($galleryImage, true);

            $this->addFlash('success', 'Bild wurde erfolgreich hochgeladen!');
            return $this->redirectToRoute('admin_gallery');
        }

        return $this->render('admin/gallery/upload.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/bulk-upload', name: '_bulk_upload', methods: ['GET', 'POST'])]
    public function bulkUpload(Request $request, GalleryImageRepository $repository, GalleryEventRepository $eventRepository, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $eventName = $request->request->get('event');
            $files = $request->files->get('images');
            
            if (!$eventName || trim($eventName) === '') {
                return $this->json(['error' => 'Event name is required'], 400);
            }
            
            // Find or create GalleryEvent
            $galleryEvent = $eventRepository->findOneBy(['name' => $eventName]);
            if (!$galleryEvent) {
                $galleryEvent = new GalleryEvent();
                $galleryEvent->setName($eventName);
                $galleryEvent->setPriority(999); // Set high priority for new events
                $eventRepository->save($galleryEvent);
            }
            
            if (!$files || !is_array($files) || count($files) === 0) {
                return $this->json(['error' => 'No files uploaded'], 400);
            }

            $uploaded = 0;
            $errors = [];
            
            foreach ($files as $file) {
                try {
                    // Validate file
                    $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                    if (!in_array($file->getMimeType(), $allowedMimes)) {
                        $errors[] = $file->getClientOriginalName() . ': Invalid file type';
                        continue;
                    }

                    if ($file->getSize() > 10 * 1024 * 1024) { // 10MB
                        $errors[] = $file->getClientOriginalName() . ': File too large';
                        continue;
                    }

                    // Create GalleryImage entity
                    $galleryImage = new GalleryImage();
                    $galleryImage->setEvent($eventName); // Legacy field
                    $galleryImage->setGalleryEvent($galleryEvent); // New relation
                    $galleryImage->setTitle(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
                    $galleryImage->setImageFile($file);

                    $repository->save($galleryImage, true);
                    $uploaded++;

                } catch (\Exception $e) {
                    $errors[] = $file->getClientOriginalName() . ': ' . $e->getMessage();
                }
            }

            return $this->json([
                'success' => true,
                'uploaded' => $uploaded,
                'errors' => $errors
            ]);
        }

        // GET request - show bulk upload form
        $events = $eventRepository->findAllOrderedByPriority();
        return $this->render('admin/gallery/bulk-upload.html.twig', [
            'events' => $events
        ]);
    }

    #[Route('/edit/{uuid}', name: '_edit', methods: ['GET', 'POST'])]
    public function edit(GalleryImage $galleryImage, Request $request, GalleryImageRepository $repository, GalleryEventRepository $eventRepository): Response
    {
        $form = $this->createFormBuilder($galleryImage)
            ->add('galleryEvent', EntityType::class, [
                'class' => GalleryEvent::class,
                'choice_label' => 'name',
                'label' => 'Event auswählen',
                'placeholder' => '-- Event auswählen --',
                'query_builder' => function (GalleryEventRepository $er) {
                    return $er->createQueryBuilder('e')->orderBy('e.priority', 'ASC');
                }
            ])
            ->add('title', TextType::class, [
                'label' => 'Titel',
                'required' => false
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung',
                'required' => false,
                'attr' => ['rows' => 4]
            ])
            ->add('submit', SubmitType::class, ['label' => 'Änderungen speichern'])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Update legacy event field from selected GalleryEvent
            if ($galleryImage->getGalleryEvent()) {
                $galleryImage->setEvent($galleryImage->getGalleryEvent()->getName());
            }
            
            $repository->save($galleryImage, true);

            $this->addFlash('success', 'Bild wurde erfolgreich aktualisiert!');
            return $this->redirectToRoute('admin_gallery');
        }

        return $this->render('admin/gallery/edit.html.twig', [
            'form' => $form->createView(),
            'galleryImage' => $galleryImage,
        ]);
    }

    #[Route('/delete/{uuid}', name: '_delete', methods: ['POST'])]
    public function delete(GalleryImage $galleryImage, Request $request, GalleryImageRepository $repository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$galleryImage->getId(), $request->request->get('_token'))) {
            $repository->remove($galleryImage, true);
            $this->addFlash('success', 'Bild wurde erfolgreich gelöscht!');
        }

        return $this->redirectToRoute('admin_gallery');
    }

    #[Route('/events', name: '_events', methods: ['GET', 'POST'])]
    public function events(Request $request, GalleryEventRepository $repository, EntityManagerInterface $entityManager): Response
    {
        $events = $repository->findAllOrderedByPriority();
        
        // Convert events to array format for JavaScript (like sponsor categories)
        $eventArray = [];
        foreach ($events as $event) {
            $eventArray[] = [
                'name' => $event->getName(),
                'count' => $event->getGalleryImages()->count()
            ];
        }

        $form = $this->createFormBuilder()
            ->add('events', HiddenType::class, [
                'required' => true,
                'data' => json_encode($eventArray, JSON_THROW_ON_ERROR),
                'constraints' => [new Assert\Json()],
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $eventsJson = $data['events'] ?? '[]';
            $eventsData = json_decode($eventsJson, true, 512, JSON_THROW_ON_ERROR);

            // Remove all existing events and recreate from form data
            foreach ($events as $event) {
                if ($event->getGalleryImages()->count() === 0) {
                    $repository->remove($event);
                }
            }

            // Create new events from form data
            foreach ($eventsData as $priority => $eventData) {
                if (!empty(trim($eventData['name']))) {
                    $event = new GalleryEvent();
                    $event->setName(trim($eventData['name']));
                    $event->setPriority($priority);
                    $repository->save($event);
                }
            }

            $entityManager->flush();
            $this->addFlash('success', 'Events wurden erfolgreich gespeichert!');

            return $this->redirectToRoute('admin_gallery_events');
        }

        return $this->render('admin/gallery/events.html.twig', [
            'form' => $form->createView(),
            'events' => $events,
        ]);
    }
}