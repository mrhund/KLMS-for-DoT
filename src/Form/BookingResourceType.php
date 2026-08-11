<?php

namespace App\Form;

use App\Entity\BookingResource;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BookingResourceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('priority', IntegerType::class, [
                'label' => 'Reihenfolge',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'zb. Dusche',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
            ])
            ->add('unitCount', IntegerType::class, [
                'label' => 'Anzahl Einheiten',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                ],
            ])
            ->add('slotDurationMinutes', IntegerType::class, [
                'label' => 'Slot-Dauer (Minuten)',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                ],
            ])
            ->add('availableFrom', DateTimeType::class, [
                'label' => 'Buchbar von',
            ])
            ->add('availableUntil', DateTimeType::class, [
                'label' => 'Buchbar bis',
            ])
            ->add('maxBookingsPerUser', IntegerType::class, [
                'label' => 'Max. Buchungen pro User',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                    'placeholder' => 'leer = unbegrenzt',
                ],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Aktiv',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BookingResource::class,
        ]);
    }
}
