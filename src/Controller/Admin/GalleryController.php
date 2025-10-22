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
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\File;

#[Route('/admin/gallery', name: 'admin_gallery')]
#[IsGranted('ROLE_ADMIN')]
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

    #[Route('/delete/{id}', name: '_delete', methods: ['POST'])]
    public function delete(GalleryImage $galleryImage, Request $request, GalleryImageRepository $repository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$galleryImage->getId(), $request->request->get('_token'))) {
            $repository->remove($galleryImage, true);
            $this->addFlash('success', 'Bild wurde erfolgreich gelöscht!');
        }

        return $this->redirectToRoute('admin_gallery');
    }
}