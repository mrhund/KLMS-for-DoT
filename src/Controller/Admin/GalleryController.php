<?php

namespace App\Controller\Admin;

use App\Entity\GalleryImage;
use App\Repository\GalleryImageRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\File;
use Psr\Log\LoggerInterface;

use Doctrine\ORM\EntityManagerInterface;
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
    public function upload(Request $request, GalleryImageRepository $repository): Response
    {
        $galleryImage = new GalleryImage();

        $form = $this->createFormBuilder($galleryImage)
            ->add('event', TextType::class, [
                'label' => 'Event Name',
                'attr' => ['placeholder' => 'z.B. lan-party-2023']
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
            // Create event directory if it doesn't exist
            $eventFolder = $this->getParameter('kernel.project_dir') . '/public/images/gallery/' . $galleryImage->getEvent();
            if (!is_dir($eventFolder)) {
                mkdir($eventFolder, 0755, true);
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
    public function bulkUpload(Request $request, GalleryImageRepository $repository, EntityManagerInterface $em, LoggerInterface $logger): Response
    {
        if ($request->isMethod('POST')) {
            // Increase limits for bulk upload
            set_time_limit(0); // No time limit
            ini_set('memory_limit', '1G');
            
            // Additional debugging - check if this is a proper multipart request
            $contentType = $request->headers->get('Content-Type');
            $isMultipart = strpos($contentType, 'multipart/form-data') !== false;
            $contentLength = $request->headers->get('Content-Length');
            
            // Check PHP limits
            $postMaxSize = ini_get('post_max_size');
            $uploadMaxFilesize = ini_get('upload_max_filesize');
            $maxFileUploads = ini_get('max_file_uploads');
            
            $logger->info('Request preprocessing', [
                'is_multipart' => $isMultipart,
                'content_type' => $contentType,
                'content_length' => $contentLength,
                'content_length_mb' => round($contentLength / 1024 / 1024, 2),
                'has_files' => !empty($_FILES),
                'has_post' => !empty($_POST),
                'php_post_max_size' => $postMaxSize,
                'php_upload_max_filesize' => $uploadMaxFilesize,
                'php_max_file_uploads' => $maxFileUploads
            ]);
            
            // If content is too large, reject immediately
            $postMaxBytes = $this->parseSize($postMaxSize);
            if ($contentLength > $postMaxBytes) {
                $logger->error('Content too large for PHP limits', [
                    'content_length' => $contentLength,
                    'post_max_size' => $postMaxSize,
                    'post_max_bytes' => $postMaxBytes
                ]);
                return $this->json(['error' => 'Upload zu groß. Maximum: ' . $postMaxSize . ', empfangen: ' . round($contentLength / 1024 / 1024, 2) . 'MB'], 413);
            }
            
            // Try multiple ways to get the event parameter
            $event = $request->request->get('event') 
                  ?? $request->get('event') 
                  ?? $request->query->get('event')
                  ?? ($_POST['event'] ?? null);
            
            $files = $request->files->get('images') ?? ($_FILES['images'] ?? null);
            
            // Debug logging using Symfony logger
            $logger->info('=== BULK UPLOAD DEBUG ===', [
                'request_method' => $request->getMethod(),
                'content_type' => $request->headers->get('Content-Type'),
                'content_length' => $request->headers->get('Content-Length'),
                'post_data' => $request->request->all(),
                'get_data' => $request->query->all(),
                'files_data' => array_keys($request->files->all()),
                'raw_content_length' => strlen($request->getContent()),
                'event_request' => $request->request->get('event'),
                'event_get' => $request->get('event'),
                'event_query' => $request->query->get('event'),
                'final_event' => $event,
                'files_count' => is_array($files) ? count($files) : 'not array or null'
            ]);
            
            // Validate event name
            if (!$event || trim($event) === '') {
                $logger->error('VALIDATION FAILED: Event name empty or null', [
                    'post_data' => $request->request->all(),
                    'files_keys' => array_keys($request->files->all())
                ]);
                return $this->json(['error' => 'Event name is required. Debug: POST=' . json_encode($request->request->all()) . ', FILES=' . json_encode(array_keys($request->files->all()))], 400);
            }
            
            $event = trim($event);
            if (strlen($event) < 3) {
                return $this->json(['error' => 'Event name must be at least 3 characters long'], 400);
            }
            
            // Validate files
            if (!$files || !is_array($files) || count($files) === 0) {
                return $this->json(['error' => 'No files uploaded'], 400);
            }

            $uploaded = 0;
            $errors = [];
            $batchSize = 10; // Process in smaller batches

            // Create event directory if it doesn't exist
            $eventFolder = $this->getParameter('kernel.project_dir') . '/public/images/gallery/' . $event;
            if (!is_dir($eventFolder)) {
                mkdir($eventFolder, 0755, true);
            }

            $totalFiles = count($files);
            $processedInBatch = 0;
            
            foreach ($files as $index => $file) {
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
                    $galleryImage->setEvent($event);
                    $galleryImage->setTitle(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
                    $galleryImage->setImageFile($file);

                    $em->persist($galleryImage);
                    $uploaded++;
                    $processedInBatch++;

                    // Flush in batches to avoid memory issues
                    if ($processedInBatch >= $batchSize || $index === $totalFiles - 1) {
                        $em->flush();
                        $em->clear(); // Clear entities from memory
                        $processedInBatch = 0;
                        
                        // Force garbage collection
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                    }

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
        $events = $repository->findEvents();
        return $this->render('admin/gallery/bulk-upload.html.twig', [
            'events' => $events
        ]);
    }

    private function parseSize(string $size): int
    {
        $size = trim($size);
        $value = (int) $size;
        $unit = strtolower(substr($size, -1));
        
        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }
        
        return $value;
    }

    #[Route('/edit/{uuid}', name: '_edit', methods: ['GET', 'POST'])]
    public function edit(GalleryImage $galleryImage, Request $request, GalleryImageRepository $repository): Response
    {
        $form = $this->createFormBuilder($galleryImage)
            ->add('event', TextType::class, [
                'label' => 'Event Name',
                'attr' => ['placeholder' => 'z.B. lan-party-2023']
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
}