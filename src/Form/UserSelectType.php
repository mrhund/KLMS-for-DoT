<?php

namespace App\Form;

use App\Entity\User;
use App\Repository\UserRepository;
use Ramsey\Uuid\Exception\InvalidUuidStringException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserSelectType extends AbstractType
{
    private readonly UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addViewTransformer(
            new CallbackTransformer(
                $this->transform(...),
                $options['hydrate'] ? $this->reverseTransform(...) : $this->reverseTransformUuid(...)
            )
        );
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['remoteController'] = 'api_users';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'multiple' => false,
            'compound' => false,
            'hydrate' => true,
        ]);
        $resolver->setAllowedTypes('hydrate', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'select2';
    }

    public function transform($entity): array
    {
        $data = [];
        if (empty($entity)) {
            return $data;
        }

        switch (true) {
            case $entity instanceof User:
                $data[$entity->getUuid()->toString()] = $entity->getNickname() ?: $entity->getEmail();
                break;
            case $entity instanceof UuidInterface:
                $user = $this->userRepository->findOneByUuid($entity);
                if (!$user) {
                    throw new TransformationFailedException('Unknown user');
                }
                $data[$entity->toString()] = $user->getNickname() ?: $user->getEmail();
                break;
            default:
                throw new TransformationFailedException('Unknown type to convert');
        }

        return $data;
    }

    public function reverseTransform($value): ?User
    {
        if (empty($value)) {
            return null;
        }

        try {
            $value = $value instanceof UuidInterface ? $value : Uuid::fromString($value);
            return $this->userRepository->findOneByUuid($value);
        } catch (InvalidUuidStringException) {
            throw new TransformationFailedException('Unknown type to convert');
        }
    }

    public function reverseTransformUuid($value): ?UuidInterface
    {
        if (empty($value)) {
            return null;
        }

        try {
            return $value instanceof UuidInterface ? $value : Uuid::fromString($value);
        } catch (InvalidUuidStringException) {
            throw new TransformationFailedException('Unknown type to convert');
        }
    }
}
